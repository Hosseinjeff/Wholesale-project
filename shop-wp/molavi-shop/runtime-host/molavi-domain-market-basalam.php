<?php
/** Plugin Name: Molavi Basalam Market Quotes */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_basalam_listing_id(string $url): ?int {
    $parts=parse_url($url);
    if (!in_array(strtolower($parts['host']??''),['basalam.com','www.basalam.com'],true)) return null;
    return preg_match('~/(?:p|product)/([1-9][0-9]{0,11})/?$~D',$parts['path']??'',$match) ? (int)$match[1] : null;
}

function molavi_domain_basalam_data(string $path,?array $query=null): ?array {
    $args=['timeout'=>6,'limit_response_size'=>500000,'headers'=>['Accept'=>'application/json']];
    if ($query!==null) {
        $args['headers']['Content-Type']='application/json';
        $args['body']=molavi_domain_json($query);
    } else {
        $args['headers']['Prefer']='return=representation';
    }
    $response=molavi_domain_http($query===null?'GET':'POST','https://openapi.basalam.com'.$path,$args);
    $status=wp_remote_retrieve_response_code($response);
    if ($query===null&&in_array($status,[404,410],true)) return null;
    if ($status===429) molavi_domain_fail('market_price_quota',503);
    if (in_array($status,[401,403],true)) molavi_domain_fail('market_price_provider_auth',503);
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if ($status!==200||!is_array($data)) molavi_domain_fail('market_price_provider_unavailable',503);
    return is_array($data['data']??null)?$data['data']:$data;
}

function molavi_domain_basalam_pack(string $title,array $identity,string $category='',?string $description=null): ?int {
    if ($title===''||molavi_domain_strlen($title)>220
        ||array_diff($identity['terms'],molavi_domain_market_terms($title.' '.$category))) return null;
    foreach (['volume','weight'] as $kind) {
        if (molavi_domain_measure_key('',$title,$kind)!==molavi_domain_measure_key($identity['sizes'][$kind]??'','',$kind)) {
            return null;
        }
    }
    $count=molavi_domain_title_count($title,true)??molavi_domain_title_count($title)??0;
    if ($description!==null) {
        $text=molavi_domain_digits(strip_tags($description));
        preg_match_all('~(*UCP)(?:تعداد(?:\s+در\s+(?:هر\s+)?(?:بسته|کارتن|باکس))?|کارتن|باکس|بسته)\s*[:：]?\s*(\d{1,4})\s*(?:عددی|عدد|تایی)~u',$text,$counts);
        $evidence=array_unique(array_map('intval',$counts[1]));
        if (count($evidence)>1||($count&&$evidence&&$count!==reset($evidence))) return null;
        if (!$count) $count=$evidence?reset($evidence):0;
        if (!$count&&preg_match('~(*UCP)\b(?:کارتن|باکس|عمده|جعبه|شیرینگ|شل)\b~u',$title)) return null;
    }
    if ($count>1&&$count!==($identity['pack_count']??1)) return null;
    return max(1,$count);
}

function molavi_domain_basalam_quote(int $id,array $identity): ?array {
    $product=molavi_domain_basalam_data('/v1/products/'.$id);
    if (!$product||($product['id']??null)!==$id||($product['inventory']??0)<1
        ||($product['status']['value']??null)!==2976||!empty($product['variants'])) return null;
    $title=$product['title']??'';
    $count=is_string($title)?molavi_domain_basalam_pack($title,$identity,(string)($product['category']['title']??''),
        (string)($product['description']??'')):null;
    $regular=$product['primary_price']??null;$current=$product['price']??null;
    if (!$count||!is_int($regular)||!is_int($current)||$current<10||$regular<$current||$regular>1e13) return null;
    $vendor=$product['vendor']['id']??null;
    $shop=$product['vendor']['title']??'';
    return ['title'=>$title,'url'=>'https://basalam.com/p/'.$id,'provider'=>'basalam','listing_id'=>$id,
        'merchant'=>is_string($shop)&&trim($shop)!==''&&molavi_domain_strlen($shop)<=80?'باسلام · '.trim($shop):'باسلام',
        'seller_id'=>is_int($vendor)&&$vendor>0?$vendor:null,'pack_count'=>$count,
        'regular_price'=>(int)round($regular/10),'current_price'=>(int)round($current/10),
        'regular_unit_price'=>(int)round($regular/10/$count),'unit_price'=>(int)round($current/10/$count),
        'regular_source_price'=>$regular,'source_price'=>$current,'source_currency'=>'IRR'];
}

function molavi_domain_basalam_search(array $identity): array {
    $query=implode(' ',$identity['terms']);
    if (molavi_domain_strlen($query)>220) return [];
    $data=molavi_domain_basalam_data('/v1/products/search',['q'=>$query,'rows'=>12,'start'=>0]);
    if (!is_array($data['products']??null)) molavi_domain_fail('market_price_provider_unavailable',503);
    $ids=[];
    foreach (array_slice($data['products'],0,12) as $item) {
        $title=$item['name']??$item['title']??'';
        $id=$item['id']??null;
        if (is_int($id)&&$id>0&&is_string($title)
            &&($count=molavi_domain_basalam_pack($title,$identity,(string)($item['categoryTitle']??'')))) $ids[$id]=$count;
    }
    // Look at an exact carton first, while accepting a comparable single unit.
    arsort($ids,SORT_NUMERIC);
    $quotes=[];
    foreach (array_slice(array_keys($ids),0,3) as $id) {
        if ($quote=molavi_domain_basalam_quote($id,$identity)) {
            $seller=$quote['seller_id']??$id;
            $quotes[$seller]??=$quote;
        }
    }
    return array_values($quotes);
}
