<?php
/** Plugin Name: Molavi Online Market Price References */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_market_key(): string {
    return trim((string)(defined('MOLAVI_TAVILY_API_KEY') ? MOLAVI_TAVILY_API_KEY
        : (getenv('MOLAVI_TAVILY_API_KEY') ?: getenv('TAVILY_API_KEY'))));
}

function molavi_domain_market_missing_price(array $row): bool {
    return !molavi_domain_positive_price($row['Consumer Price'] ?? null)
        && !molavi_domain_positive_price($row['Actual Price'] ?? null);
}

function molavi_domain_market_terms(string $text): array {
    foreach (['weight','volume'] as $kind) $text=preg_replace(molavi_domain_measure_pattern($kind),' ',molavi_domain_digits($text));
    $text=molavi_domain_clean_title($text);
    $text=preg_replace('~(*UCP)\b\d+\s*(?:تایی|طعم|مزه|رنگ|مدل|نوع)\b~u',' ',$text);
    return array_values(array_unique(array_diff(explode(' ',molavi_domain_normalize_name($text)),
        ['','خرید','فروش','قیمت','موجود','کارتن','باکس','بسته','تکی','عدد','عددی','تایی','در','با','طعم'])));
}

function molavi_domain_market_identity(array $row): ?array {
    $name=trim((string)($row['Product Name']??''));
    if ($name===''||molavi_domain_strlen($name)>180) return null;
    $brand=molavi_domain_normalize_name((string)($row['Brand']??''));
    $variant=array_values(array_filter(molavi_domain_market_terms((string)($row['Variation Type']??'')),
        static fn($term)=>!is_numeric($term)));
    $terms=molavi_domain_market_terms($name.' '.$brand.' '.implode(' ',$variant));
    if (!$terms) return null;
    $size=[];
    foreach (['volume'=>'Volume','weight'=>'Weight'] as $kind=>$field) {
        $display=molavi_domain_extract_measurement((string)($row[$field]??''),$kind)
            ?:molavi_domain_extract_measurement($name,$kind);
        if ($display!=='') $size[$kind]=$display;
    }
    // An assortment gives no evidence for an individual flavour or SKU.
    $assortment=(bool)preg_match('~(*UCP)\b(?:[2-9]|[1-9]\d+)\s*(?:طعم|مزه|رنگ|مدل|نوع)\b~u',molavi_domain_digits($name))&&!$variant;
    sort($terms);
    $identity=['terms'=>$terms,'sizes'=>$size,'assortment'=>$assortment];
    $declared=$row['Package Count']??'';
    $pack=molavi_domain_title_count($name,true)??molavi_domain_title_count($name)
        ??(is_numeric($declared)&&(int)$declared==(float)$declared?(int)$declared:0);
    if ($pack>1&&$pack<=10000) $identity['pack_count']=$pack;
    return $identity;
}

function molavi_domain_market_previous_key(array $identity): string {
    unset($identity['pack_count']);
    return molavi_domain_hash($identity);
}

function molavi_domain_market_keep_saved_source(SQLite3 $db,array $identity,int $now): void {
    if (!isset($identity['pack_count'])) return;
    // Earlier releases saved only single-unit quotes, which are still comparable.
    molavi_domain_sql($db,"INSERT INTO market_price_lookups(lookup_key,identity_json,state,next_at,checked_at,
        candidates_json,selected_url,selected_by,selected_at)
        SELECT ?,?,'ready',?,checked_at,candidates_json,selected_url,selected_by,selected_at
        FROM market_price_lookups WHERE lookup_key=? AND selected_url IS NOT NULL
        ON CONFLICT(lookup_key) DO NOTHING",
        [molavi_domain_hash($identity),molavi_domain_json($identity),$now,molavi_domain_market_previous_key($identity)]);
}

function molavi_domain_market_records(array $tables): array {
    $current=[];$eligible=[];
    foreach (molavi_domain_records($tables['Products']??[]) as $row) {
        $offer=trim((string)($row['Latest Offer ID']??''));
        if ($offer==='') continue;
        $current[$offer]=true;
        if (molavi_domain_market_missing_price($row)&&($identity=molavi_domain_market_identity($row))) $eligible[$offer]=$identity;
    }
    $reviewable=array_fill_keys(array_column(molavi_domain_ai_review_cases($tables,null,false,PHP_INT_MAX),'offer_id'),true);
    foreach (molavi_domain_records($tables['Offers']??[]) as $row) {
        $offer=(string)($row['Offer ID']??'');
        if (!isset($current[$offer])&&isset($reviewable[$offer])&&molavi_domain_market_missing_price($row)
            &&($identity=molavi_domain_market_identity($row))) $eligible[$offer]=$identity;
    }
    return $eligible;
}

function molavi_domain_market_seed(array $identities,int $now): void {
    $rows=[];
    foreach ($identities as $identity) if (!$identity['assortment']) $rows[molavi_domain_hash($identity)]=$identity;
    molavi_domain_tx('reference',static function(SQLite3 $db)use($rows,$now): void {
        molavi_domain_sql($db,'UPDATE market_price_lookups SET active=0 WHERE active=1');
        foreach ($rows as $key=>$identity) {
            molavi_domain_market_keep_saved_source($db,$identity,$now);
            molavi_domain_sql($db,'INSERT INTO market_price_lookups(lookup_key,identity_json,next_at) VALUES(?,?,?)
                ON CONFLICT(lookup_key) DO UPDATE SET active=1',[$key,molavi_domain_json($identity),$now]);
        }
    });
}

function molavi_domain_market_url($value): ?string {
    if (!is_string($value)||strlen($value)>1600||!filter_var($value,FILTER_VALIDATE_URL)) return null;
    $parts=parse_url($value);$host=strtolower($parts['host']??'');
    if (($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])
        ||($parts['port']??443)!==443||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP)
        ||rtrim($host,'.')==='molavisale.ir'||str_ends_with(rtrim($host,'.'),'.molavisale.ir')) return null;
    return $value;
}

function molavi_domain_market_product_nodes(array $schema): array {
    $nodes=[];
    foreach (array_is_list($schema)?$schema:[$schema] as $node) {
        if (!is_array($node)) continue;
        if (in_array('Product',(array)($node['@type']??[]),true)) $nodes[]=$node;
        if (is_array($node['@graph']??null)) foreach ($node['@graph'] as $child) {
            if (is_array($child)&&in_array('Product',(array)($child['@type']??[]),true)) $nodes[]=$child;
        }
    }
    return $nodes;
}

function molavi_domain_market_from_page(string $html,string $url,array $identity): ?array {
    preg_match_all('~<script\b[^>]*\btype\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script\s*>~is',$html,$matches);
    $prices=[];
    foreach (array_slice($matches[1],0,15) as $json) {
        $schema=json_decode($json,true,48);
        if (!is_array($schema)) continue;
        foreach (molavi_domain_market_product_nodes($schema) as $product) {
            if (!is_string($product['name']??null)||!is_array($product['offers']??null)) continue;
            $name=trim(html_entity_decode(strip_tags($product['name']),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $brand=$product['brand']??'';$brand=is_array($brand)?($brand['name']??''):$brand;
            if (molavi_domain_strlen($name)>220||molavi_domain_pack_count('',$name)>1
                ||(molavi_domain_title_count($name,true)??0)>1||(molavi_domain_title_count($name)??0)>1
                ||preg_match('~(*UCP)\b(?:کارتن|باکس|عمده|جعبه)\b~u',$name)
                ||array_diff($identity['terms'],molavi_domain_market_terms($name.' '.(is_string($brand)?$brand:'')))) continue;
            foreach ($identity['sizes'] as $kind=>$size) {
                if (molavi_domain_measure_key('',$name,$kind)!==molavi_domain_measure_key($size,'',$kind)) continue 2;
            }
            $offers=array_is_list($product['offers'])?$product['offers']:[$product['offers']];
            foreach ($offers as $offer) {
                if (!is_array($offer)||!in_array('Offer',(array)($offer['@type']??[]),true)) continue;
                $availability=is_string($offer['availability']??null)?$offer['availability']:'';
                if (!preg_match('~(?:^|/)InStock$~i',$availability)) continue;
                $currency=strtoupper(is_string($offer['priceCurrency']??null)?trim($offer['priceCurrency']):'');
                if (!in_array($currency,['IRR','IRT','TMN','تومان','ریال'],true)) continue;
                $price=$offer['price']??null;
                if (is_string($price)) $price=str_replace(['٬',','], '',molavi_domain_digits($price));
                if (!is_numeric($price)||(float)$price>1e13||(float)$price<1) continue;
                $toman=(int)round((float)$price/(in_array($currency,['IRR','ریال'],true)?10:1));
                if ($toman>0) $prices[$toman]=['title'=>$name,'url'=>$url,'merchant'=>strtolower((string)parse_url($url,PHP_URL_HOST)),
                    'unit_price'=>$toman,'source_price'=>(float)$price,'source_currency'=>$currency];
            }
        }
    }
    return count($prices)===1?reset($prices):null;
}

function molavi_domain_market_fetch(array $identity,?string $pinned): array {
    if ($pinned) {
        $quote=molavi_domain_market_check_url($pinned,$identity);
        return $quote?[$quote]:[];
    }
    $fallback=molavi_domain_market_key()!=='';
    try {
        $quotes=molavi_domain_basalam_search($identity);
        if ($quotes||!$fallback) return $quotes;
    } catch (MolaviWorkflowError $e) {
        if (!$fallback) throw $e;
    }
    $urls=molavi_domain_market_discover($identity);
    $verified=[];$fetched=0;
    foreach (array_slice(array_values(array_unique($urls)),0,5) as $url) {
        try {
            $quote=molavi_domain_market_check_url($url,$identity);
            $fetched++;
            if ($quote) $verified[preg_replace('/^www\./','',$quote['merchant'])]??=$quote;
        } catch (MolaviWorkflowError $e) { continue; }
    }
    if ($urls&&!$fetched) molavi_domain_fail('market_price_sources_unavailable',503);
    $verified=array_values($verified);
    usort($verified,static fn($a,$b)=>$a['unit_price']<=>$b['unit_price']?:strcmp($a['url'],$b['url']));
    return $verified;
}

function molavi_domain_market_discover(array $identity): array {
    $query=implode(' ',array_merge($identity['terms'],array_values($identity['sizes']))).' قیمت خرید';
    $response=molavi_domain_http('POST','https://api.tavily.com/search',[
        'timeout'=>8,'limit_response_size'=>75000,
        'headers'=>['Authorization'=>'Bearer '.molavi_domain_market_key(),'Content-Type'=>'application/json'],
        'body'=>molavi_domain_json(['query'=>$query,'search_depth'=>'basic','max_results'=>5,
            'include_answer'=>false,'include_raw_content'=>false,'auto_parameters'=>false,'language'=>'fa'])]);
    $status=wp_remote_retrieve_response_code($response);
    if (in_array($status,[429,432,433],true)) molavi_domain_fail('market_price_quota',503);
    if (in_array($status,[401,403],true)) molavi_domain_fail('market_price_provider_auth',503);
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if ($status!==200||!is_array($data['results']??null)) molavi_domain_fail('market_price_provider_unavailable',503);
    $urls=[];
    foreach ($data['results'] as $result) if (is_array($result)&&($url=molavi_domain_market_url($result['url']??null))) $urls[]=$url;
    return $urls;
}

function molavi_domain_market_check_url(string $url,array $identity): ?array {
    if (!molavi_domain_market_url($url)||!wp_http_validate_url($url)) molavi_domain_fail('invalid_market_price_link',400);
    if ($id=molavi_domain_basalam_listing_id($url)) return molavi_domain_basalam_quote($id,$identity);
    $page=molavi_domain_http_exact('GET',$url,['timeout'=>8,'limit_response_size'=>600000]);
    if (wp_remote_retrieve_response_code($page)!==200) molavi_domain_fail('market_price_sources_unavailable',503);
    return molavi_domain_market_from_page(wp_remote_retrieve_body($page),$url,$identity);
}

function molavi_domain_market_run(int $now,?callable $fetch=null): void {
    $day=gmdate('Y-m-d',$now);
    $row=molavi_domain_tx('reference',static function(SQLite3 $db)use($now,$day): ?array {
        $used=(int)(molavi_domain_row($db,'SELECT used FROM market_price_budget WHERE day=?',[$day])['used']??0);
        if (molavi_domain_row($db,"SELECT 1 FROM market_price_lookups WHERE state='searching' AND attempted_at>? LIMIT 1",[$now-60])) return null;
        $row=molavi_domain_row($db,'SELECT * FROM market_price_lookups WHERE active=1 AND next_at<=? AND (?=1 OR selected_url IS NOT NULL)
            ORDER BY priority DESC,next_at,lookup_key LIMIT 1',[$now,$used<25]);
        if (!$row) return null;
        if (!$row['selected_url']) molavi_domain_sql($db,'INSERT INTO market_price_budget VALUES(?,1) ON CONFLICT(day) DO UPDATE SET used=used+1',[$day]);
        molavi_domain_sql($db,"UPDATE market_price_lookups SET state='searching',attempted_at=?,next_at=?,priority=0 WHERE lookup_key=?",
            [$now,$now+300,$row['lookup_key']]);
        return $row;
    });
    if (!$row) return;
    $pinned=$row['selected_url'];
    $update=['next'=>$now+MOLAVI_REFERENCE_REFRESH_SECONDS,'state'=>'retry','checked'=>$row['checked_at'],'quotes'=>$row['candidates_json']];
    try {
        $quotes=($fetch??'molavi_domain_market_fetch')(molavi_domain_decode($row['identity_json']),$pinned);
        $update=['next'=>$update['next'],'state'=>$quotes?'ready':'no_match','checked'=>$now,'quotes'=>molavi_domain_json($quotes)];
        if (!$quotes&&$pinned&&molavi_domain_basalam_listing_id($pinned)) {
            // A confirmed missing or changed listing goes back through discovery under its daily budget.
            $pinned=null;$update['state']='queued';$update['next']=$now;
        }
    } catch (MolaviWorkflowError $e) {
        if (in_array($e->getMessage(),['market_price_quota','market_price_provider_auth'],true)) {
            $update['state']=$e->getMessage()==='market_price_quota'?'quota':'provider_auth';
            molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'UPDATE market_price_budget SET used=25 WHERE day=?',[$day]));
            $update['next']=strtotime($day.' +1 day UTC');
        }
    }
    molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,
        'UPDATE market_price_lookups SET state=?,next_at=?,checked_at=?,candidates_json=?,selected_url=? WHERE lookup_key=? AND attempted_at=?',
        [$update['state'],$update['next'],$update['checked'],$update['quotes'],$pinned,$row['lookup_key'],$now]));
}

function molavi_domain_market_tick(int $now): void {
    if ((int)get_option('molavi_market_seed_at',0)<=$now) {
        molavi_domain_market_seed(molavi_domain_market_records(molavi_domain_sheets(['Products','Offers'],true)),$now);
        update_option('molavi_market_seed_at',$now+MOLAVI_REFERENCE_REFRESH_SECONDS,false);
    }
    molavi_domain_market_run($now);
}

function molavi_domain_market_price_api(string $method,array $input,string $actor): array {
    $offer=$input['offer_id']??'';
    if (!is_string($offer)||$offer===''||strlen($offer)>300) molavi_domain_fail('invalid_query',400);
    $action=$method==='POST'?($input['action']??''):'';
    $cache='molavi_market_identity_v2_'.hash('sha256',$offer);
    $cached=$method==='GET'?get_transient($cache):false;
    if (!is_array($cached)) {
        $identity=molavi_domain_market_records(molavi_domain_sheets(['Products','Offers'],$method==='POST'))[$offer]??null;
        set_transient($cache,['identity'=>$identity],90);
    } else $identity=$cached['identity'];
    if (!$identity) return ['ok'=>true,'state'=>'not_needed'];
    if ($identity['assortment']) return ['ok'=>true,'state'=>'ambiguous_identity'];
    $key=molavi_domain_hash($identity);$now=time();
    $provided=null;
    if ($action==='add_market_price_url') {
        $url=molavi_domain_market_url($input['url']??null);
        if (!$url) molavi_domain_fail('invalid_market_price_link',400);
        $provided=molavi_domain_market_check_url($url,$identity);
        if (!$provided) molavi_domain_fail('market_price_source_not_verified',422);
    }
    if ($action!=='') molavi_domain_tx('reference',static function(SQLite3 $db)use($key,$identity,$input,$actor,$now,$action,$provided): void {
        if ($provided) {
            molavi_domain_sql($db,"INSERT INTO market_price_lookups(lookup_key,identity_json,state,next_at,checked_at,candidates_json,selected_url,selected_by,selected_at)
                VALUES(?,?,'ready',?,?,?,?,?,?) ON CONFLICT(lookup_key) DO UPDATE SET active=1,state='ready',next_at=excluded.next_at,
                attempted_at=NULL,checked_at=excluded.checked_at,candidates_json=excluded.candidates_json,selected_url=excluded.selected_url,
                selected_by=excluded.selected_by,selected_at=excluded.selected_at",
                [$key,molavi_domain_json($identity),$now+MOLAVI_REFERENCE_REFRESH_SECONDS,$now,molavi_domain_json([$provided]),$provided['url'],$actor,$now]);
            return;
        }
        molavi_domain_market_keep_saved_source($db,$identity,$now);
        if ($action==='select_market_price_source') {
            $url=$input['url']??null;
            $row=molavi_domain_row($db,'SELECT checked_at,candidates_json FROM market_price_lookups WHERE lookup_key=?',[$key]);
            if (!molavi_domain_market_url($url)||!$row||$now-(int)$row['checked_at']>=MOLAVI_REFERENCE_REFRESH_SECONDS
                ||!in_array($url,array_column(molavi_domain_decode($row['candidates_json']),'url'),true)) molavi_domain_fail('stale_market_price_source',409);
            molavi_domain_sql($db,"UPDATE market_price_lookups SET selected_url=?,selected_by=?,selected_at=?,state='ready',attempted_at=NULL,next_at=?
                WHERE lookup_key=?",[$url,$actor,$now,(int)$row['checked_at']+MOLAVI_REFERENCE_REFRESH_SECONDS,$key]);
            return;
        }
        molavi_domain_sql($db,'INSERT INTO market_price_lookups(lookup_key,identity_json,next_at,priority) VALUES(?,?,?,1)
            ON CONFLICT(lookup_key) DO UPDATE SET active=1,priority=1 WHERE next_at<=?',[$key,molavi_domain_json($identity),$now,$now]);
    });
    $row=molavi_domain_read('reference',static fn(SQLite3 $db)=>
        molavi_domain_row($db,'SELECT * FROM market_price_lookups WHERE lookup_key=?',[$key])
        ??molavi_domain_row($db,'SELECT * FROM market_price_lookups WHERE lookup_key=? AND selected_url IS NOT NULL',
            [molavi_domain_market_previous_key($identity)]));
    if (!$row) return ['ok'=>true,'state'=>'idle'];
    $quotes=molavi_domain_decode($row['candidates_json']);$pinned=$row['selected_url'];
    usort($quotes,static fn($a,$b)=>($a['regular_unit_price']??$a['unit_price'])<=>($b['regular_unit_price']??$b['unit_price'])
        ?:strcmp($a['url'],$b['url']));
    $regular=array_values(array_filter($quotes,static fn($quote)=>isset($quote['regular_unit_price'])));
    $exact=array_values(array_filter($regular,static fn($quote)=>$quote['pack_count']===($identity['pack_count']??1)));
    $ranked=$exact?:($regular?:$quotes);
    $recommended=$ranked[intdiv(count($ranked),2)]??null;
    foreach ($quotes as $quote) if ($quote['url']===$pinned) $recommended=$quote;
    return ['ok'=>true,'state'=>$row['state'],'checked_at'=>$row['checked_at'],'next_at'=>$row['next_at'],
        'search_configured'=>true,'prioritized'=>(bool)$row['priority'],
        'stale'=>$row['checked_at']!==null&&$now-(int)$row['checked_at']>=MOLAVI_REFERENCE_REFRESH_SECONDS,
        'suggested_price'=>$recommended,'candidates'=>$quotes,'selected_url'=>$pinned];
}
