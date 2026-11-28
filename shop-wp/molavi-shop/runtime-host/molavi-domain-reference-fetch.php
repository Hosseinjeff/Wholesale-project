<?php
/** Plugin Name: Molavi Native Reference Source Fetch */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** A refresh attempt that dies mid-fetch used to lose every page it had
 * already read: the next attempt restarted at page 1 and died at the
 * same place. Fetched pages are checkpointed here (one row per page, in
 * the reference store) so a later attempt resumes instead of restarting.
 * A checkpoint is trusted only while it matches the fresh page-1 totals
 * and is under six hours old; anything else is discarded. */
const MOLAVI_REFERENCE_CHECKPOINT_SECONDS = 21600;
/** Wall-clock budget for one fetch attempt. The staging host kills a PHP
 * process past its execution limit with no catch and no record, so the
 * fetch aborts itself cleanly (woocommerce_fetch_timeout) before that
 * limit, leaving the checkpoint for the next attempt to resume. */
const MOLAVI_REFERENCE_FETCH_BUDGET_SECONDS = 90.0;

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
function molavi_domain_reference_fetch_page(int $page,int $size,?int $total=null,?int $pages=null,int $timeout=30): array {
    for($attempt=0;$attempt<3;$attempt++){
        try{$response=molavi_domain_http('GET',molavi_domain_reference_page_url($page,$size),['timeout'=>$timeout]);
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
/** Checkpointed pages for one page size: rows already fetched and trusted. */
function molavi_domain_reference_checkpoint_load(int $size): array {
    return molavi_domain_read('reference',static function(SQLite3 $db)use($size):array {
        $rows=molavi_domain_rows($db,'SELECT page,total,pages,payload_json,fetched_at FROM reference_refresh_pages WHERE page_size=? ORDER BY page',[$size]);
        $out=['total'=>null,'pages'=>null,'rows'=>[]];$fresh=microtime(true)-MOLAVI_REFERENCE_CHECKPOINT_SECONDS;
        foreach($rows as $row){
            if((float)$row['fetched_at']<$fresh)continue;
            try{$decoded=molavi_domain_decode($row['payload_json']);}catch(Throwable $e){continue;}
            if(!is_array($decoded)||!array_is_list($decoded))continue;
            $expected=min($size,max(0,(int)$row['total']-((int)$row['page']-1)*$size));
            if(count($decoded)!==$expected)continue;
            if($out['total']===null){$out['total']=(int)$row['total'];$out['pages']=(int)$row['pages'];}
            if((int)$row['total']!==$out['total']||(int)$row['pages']!==$out['pages'])continue;
            $out['rows'][(int)$row['page']]=$decoded;
        }
        return $out;
    });
}
function molavi_domain_reference_checkpoint_store(int $size,int $page,array $rows,int $total,int $pages): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($size,$page,$rows,$total,$pages):void {
        molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_refresh_pages VALUES (?,?,?,?,?,?)',
            [$size,$page,$total,$pages,molavi_domain_json($rows),microtime(true)]);
    });
}
function molavi_domain_reference_checkpoint_clear(?int $size=null): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($size):void {
        if($size===null)molavi_domain_sql($db,'DELETE FROM reference_refresh_pages');
        else molavi_domain_sql($db,'DELETE FROM reference_refresh_pages WHERE page_size=?',[$size]);
    });
}
/** Eight bounded requests shorten the window for source changes between pages. */
function molavi_domain_reference_fetch_attempt(int $size=100,?callable $progress=null,array $checkpoint=[],float $budgetSeconds=MOLAVI_REFERENCE_FETCH_BUDGET_SECONDS,?callable $clock=null,bool $persistCheckpoint=false): array {
    if($size<1||$size>100)molavi_domain_fail('woocommerce_pagination_invalid',400);
    $startedAt=$clock?$clock():microtime(true);$deadline=$startedAt+$budgetSeconds;
    $budgetCheck=static function(int $completedPages,int $totalPages,int $records,int $totalRecords)use($clock,$deadline):void {
        if(($clock?$clock():microtime(true))<$deadline)return;
        molavi_domain_fail('woocommerce_fetch_timeout',503,['completed_pages'=>$completedPages,'total_pages'=>$totalPages,
            'records'=>$records,'total_records'=>$totalRecords]);
    };
    [$first,$total,$pages]=molavi_domain_reference_fetch_page(1,$size);$products=$first;$byPage=[1=>$first];
    // Page 1 is the authority for this generation: a checkpoint from a
    // changed source (different totals) is ignored, never trusted.
    if($checkpoint&&(($checkpoint['total']??null)!==$total||($checkpoint['pages']??null)!==$pages)){
        if($persistCheckpoint)molavi_domain_reference_checkpoint_clear($size);
        $checkpoint=['total'=>$total,'pages'=>$pages,'rows'=>[]];
    }
    $stored=$checkpoint['rows']??[];
    foreach($stored as $page=>$rows){$byPage[$page]=$rows;array_push($products,...$rows);}
    $completedPages=1+count($stored);$records=count($products);
    if ($progress) $progress('fetching',$completedPages,$pages,$records,$total);
    for($start=2;$start<=$pages;$start+=8){
        $end=min($pages,$start+7);
        $budgetCheck($completedPages,$pages,$records,$total);
        if(class_exists('WpOrg\Requests\Requests')){
            $requests=[];for($page=$start;$page<=$end;$page++)if(!isset($stored[$page]))$requests[$page]=['url'=>molavi_domain_reference_page_url($page,$size),'type'=>'GET'];
            $responses=[];
            if($requests){
                $options=['timeout'=>30,'connect_timeout'=>10,'follow_redirects'=>false,'verify'=>true];
                $bundle=defined('WPINC')?ABSPATH.WPINC.'/certificates/ca-bundle.crt':'';if(is_file($bundle))$options['verify']=$bundle;
                // A failed multi-request used to fail the whole generation
                // at once; retry the batch before giving up on it.
                for($tries=0;$tries<3;$tries++){
                    try{$responses=\WpOrg\Requests\Requests::request_multiple($requests,$options);break;}
                    catch(Throwable $e){
                        if($tries>=2)molavi_domain_fail('woocommerce_transport_failed',503);
                        usleep(1000000*($tries+1));
                    }
                }
            }
            for($page=$start;$page<=$end;$page++){
                if(isset($stored[$page]))continue;
                $response=$responses[$page]??null;
                if($response instanceof \WpOrg\Requests\Response&&!in_array($response->status_code,[429,500,502,503,504],true))
                    [$rows]=molavi_domain_reference_page(['status'=>$response->status_code,'body'=>$response->body,'headers'=>$response->headers],$page,$size,$total,$pages);
                else{$budgetCheck($completedPages,$pages,$records,$total);
                    // The parallel attempt already proved this page slow
                    // or failing; a short sequential retry, not another
                    // three 30-second attempts that eat the time budget.
                    [$rows]=molavi_domain_reference_fetch_page($page,$size,$total,$pages,15);}
                $byPage[$page]=$rows;
                if($persistCheckpoint)molavi_domain_reference_checkpoint_store($size,$page,$rows,$total,$pages);
                array_push($products,...$rows);$completedPages++;$records+=count($rows);
                if ($progress) $progress('fetching',$completedPages,$pages,$records,$total);
            }
        }else for($page=$start;$page<=$end;$page++){
            if(isset($stored[$page]))continue;
            $budgetCheck($completedPages,$pages,$records,$total);
            [$rows]=molavi_domain_reference_fetch_page($page,$size,$total,$pages);
            $byPage[$page]=$rows;
            if($persistCheckpoint)molavi_domain_reference_checkpoint_store($size,$page,$rows,$total,$pages);
            array_push($products,...$rows);$completedPages++;$records+=count($rows);
            if ($progress) $progress('fetching',$completedPages,$pages,$records,$total);
        }
    }
    // Reassemble in page order: checkpointed pages were merged in above,
    // fresh pages appended in batch order, so rebuild from the page map.
    $products=[];for($page=1;$page<=$pages;$page++)array_push($products,...($byPage[$page]??[]));
    if ($progress) $progress('validating',$pages,$pages,count($products),$total);
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
function molavi_domain_reference_fetch_products(int $size=100,?callable $progress=null,float $budgetSeconds=MOLAVI_REFERENCE_FETCH_BUDGET_SECONDS,?callable $clock=null,bool $persistCheckpoint=false): array {
    $checkpoint=$persistCheckpoint?molavi_domain_reference_checkpoint_load($size):['total'=>null,'pages'=>null,'rows'=>[]];
    for($generation=0;$generation<2;$generation++){
        try{return molavi_domain_reference_fetch_attempt($size,$progress,$checkpoint,$budgetSeconds,$clock,$persistCheckpoint);}
        catch(MolaviWorkflowError $e){$code=$e->getMessage();
            // Discard the entire incomplete generation, then start once from page 1.
            if($generation===0&&($code==='woocommerce_pagination_changed'||str_starts_with($code,'woocommerce_page_incomplete_'))){
                if($persistCheckpoint)molavi_domain_reference_checkpoint_clear($size);
                $checkpoint=['total'=>null,'pages'=>null,'rows'=>[]];
                if ($progress) $progress('retrying',0,0,0,0);
                continue;
            }
            // A budget abort is a pause, not a transport failure: resume
            // it from the checkpoint instead of retrying smaller pages.
            if($code==='woocommerce_fetch_timeout')throw $e;
            if($size<=50||!molavi_domain_reference_retryable($code))throw $e;
            if ($progress) $progress('retrying',0,0,0,0);
            return molavi_domain_reference_fetch_attempt(intdiv($size,2),$progress,['total'=>null,'pages'=>null,'rows'=>[]],$budgetSeconds,$clock,$persistCheckpoint);
        }
    }
    molavi_domain_fail('woocommerce_source_unstable',503);
}
