<?php
/** Plugin Name: Molavi Native Reference Source Fetch */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_reference_page(array $response,int $page,int $pageSize,?int $expectedTotal=null,?int $expectedPages=null): array {
    $status=(int)($response['status']??0);if($status!==200)molavi_domain_fail('woocommerce_http_'.$status,503);
    $headers=[];foreach($response['headers']??[] as $key=>$value)$headers[strtolower((string)$key)]=(string)$value;
    $total=filter_var($headers['x-wp-total']??null,FILTER_VALIDATE_INT);$pages=filter_var($headers['x-wp-totalpages']??null,FILTER_VALIDATE_INT);
    if($total===false||$pages===false||$total<1||$pages<1||$pages>500)molavi_domain_fail('woocommerce_pagination_invalid',503);
    if(($expectedTotal!==null&&$total!==$expectedTotal)||($expectedPages!==null&&$pages!==$expectedPages))molavi_domain_fail('woocommerce_pagination_changed',503);
    try{$products=json_decode((string)($response['body']??''),true,128,JSON_THROW_ON_ERROR);}
    catch(JsonException $e){molavi_domain_fail('woocommerce_json_invalid',503);}
    if(!is_array($products)||!array_is_list($products))molavi_domain_fail('woocommerce_page_invalid',503);
    if(count($products)!==min($pageSize,max(0,$total-($page-1)*$pageSize)))molavi_domain_fail('woocommerce_page_incomplete_'.$page,503);
    return [$products,$total,$pages];
}
function molavi_domain_reference_page_url(int $page,int $size): string {
    return molavi_domain_reference_source().'?'.http_build_query(['per_page'=>$size,'page'=>$page,'orderby'=>'id','order'=>'asc'],'','&',PHP_QUERY_RFC3986);
}
function molavi_domain_reference_fetch_page(int $page,int $size,?int $total=null,?int $pages=null): array {
    for($attempt=0;$attempt<3;$attempt++){
        try{$response=molavi_domain_http('GET',molavi_domain_reference_page_url($page,$size),['timeout'=>30]);
            $headers=wp_remote_retrieve_headers($response);
            return molavi_domain_reference_page(['status'=>wp_remote_retrieve_response_code($response),'body'=>wp_remote_retrieve_body($response),
                'headers'=>is_object($headers)&&method_exists($headers,'getAll')?$headers->getAll():(array)$headers],$page,$size,$total,$pages);
        }catch(MolaviWorkflowError $e){if($attempt>=2||!molavi_domain_reference_retryable($e->getMessage()))throw $e;usleep(200000*(2**$attempt));}
    }
    molavi_domain_fail('woocommerce_transport_failed',503);
}
function molavi_domain_reference_retryable(string $code): bool {
    return $code==='native_connector_unavailable'||str_starts_with($code,'woocommerce_transport_')
        ||in_array($code,['woocommerce_http_429','woocommerce_http_500','woocommerce_http_502','woocommerce_http_503','woocommerce_http_504'],true);
}
/** Four bounded requests at a time through WordPress's bundled HTTP transport. */
function molavi_domain_reference_fetch_attempt(int $size=100): array {
    if($size<1||$size>100)molavi_domain_fail('woocommerce_pagination_invalid',400);
    [$first,$total,$pages]=molavi_domain_reference_fetch_page(1,$size);$products=$first;
    for($start=2;$start<=$pages;$start+=4){
        $end=min($pages,$start+3);
        if(class_exists('WpOrg\Requests\Requests')){
            $requests=[];for($page=$start;$page<=$end;$page++)$requests[$page]=['url'=>molavi_domain_reference_page_url($page,$size),'type'=>'GET'];
            $options=['timeout'=>30,'connect_timeout'=>10,'follow_redirects'=>false,'verify'=>true];
            $bundle=defined('WPINC')?ABSPATH.WPINC.'/certificates/ca-bundle.crt':'';if(is_file($bundle))$options['verify']=$bundle;
            try{$responses=\WpOrg\Requests\Requests::request_multiple($requests,$options);}
            catch(Throwable $e){molavi_domain_fail('woocommerce_transport_failed',503);}
            for($page=$start;$page<=$end;$page++){$response=$responses[$page]??null;
                if(!($response instanceof \WpOrg\Requests\Response)||in_array($response->status_code,[429,500,502,503,504],true))
                    [$rows]=molavi_domain_reference_fetch_page($page,$size,$total,$pages);
                else [$rows]=molavi_domain_reference_page(['status'=>$response->status_code,'body'=>$response->body,'headers'=>$response->headers],$page,$size,$total,$pages);
                array_push($products,...$rows);
            }
        }else for($page=$start;$page<=$end;$page++){[$rows]=molavi_domain_reference_fetch_page($page,$size,$total,$pages);array_push($products,...$rows);}
    }
    if(count($products)!==$total)molavi_domain_fail('woocommerce_product_coverage_incomplete',503);
    $ids=[];$skus=[];$currency=null;
    foreach($products as $product){if(!is_array($product))molavi_domain_fail('woocommerce_product_invalid',503);
        $code=molavi_domain_reference_code($product);$id=(string)$product['id'];if(isset($ids[$id]))molavi_domain_fail('woocommerce_duplicate_product_id',503);$ids[$id]=true;
        $sku=(string)($product['sku']??'');if($sku!==''){$key=function_exists('mb_strtolower')?mb_strtolower(trim($sku),'UTF-8'):strtolower(trim($sku));
            if(isset($skus[$key]))molavi_domain_fail('woocommerce_duplicate_sku',503);$skus[$key]=true;}
        [, $currentCurrency]=molavi_domain_reference_price($product['prices']??null);
        if($currency!==null&&$currentCurrency!==$currency)molavi_domain_fail('woocommerce_currency_mismatch',503);$currency=$currentCurrency;
    }
    return molavi_domain_reference_candidate($products,$pages,(string)$currency,molavi_domain_now());
}
function molavi_domain_reference_fetch_products(int $size=100): array {
    try{return molavi_domain_reference_fetch_attempt($size);}
    catch(MolaviWorkflowError $e){if($size<=50||!molavi_domain_reference_retryable($e->getMessage()))throw $e;
        return molavi_domain_reference_fetch_attempt(intdiv($size,2));}
}
