<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-market-test-'.bin2hex(random_bytes(5)));
function add_action(...$args){}
function add_filter(...$args){}
function get_transient($key){return $GLOBALS['cache'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
function wp_http_validate_url($url){return $url;}
function wp_remote_retrieve_response_code($response){return $response['status'];}
function wp_remote_retrieve_body($response){return $response['body'];}
function molavi_domain_sheets($names,$fresh=false){$GLOBALS['reads']++;return $GLOBALS['sheets'];}
function molavi_domain_ai_review_cases(...$args){return [['offer_id'=>'pending-offer']];}
function molavi_domain_http($method,$url,$args){
    $GLOBALS['requests'][]=[$method,$url,json_decode($args['body']??'null',true)];
    if ($url==='https://openapi.basalam.com/v1/products/search')
        return ['status'=>200,'body'=>json_encode(['products'=>$GLOBALS['basalam_search']??[]])];
    if (str_starts_with($url,'https://openapi.basalam.com/v1/products/')) {
        $id=(int)basename($url);
        return isset($GLOBALS['basalam_products'][$id])
            ?['status'=>200,'body'=>json_encode($GLOBALS['basalam_products'][$id])]:['status'=>404,'body'=>'{}'];
    }
    return ['status'=>200,'body'=>json_encode(['results'=>array_map(static fn($url)=>['url'=>$url],array_keys($GLOBALS['pages']))])];
}
function molavi_domain_http_exact($method,$url,$args){return ['status'=>200,'body'=>$GLOBALS['pages'][$url]];}
$root=__DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-scheduler',
          'domain-publisher','domain-products','domain-market-basalam','domain-market-prices'] as $module) require $root.'molavi-'.$module.'.php';
function check_market($condition,string $label): void {if(!$condition)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function market_page(string $name,string $price='780000',string $currency='IRR',string $availability='InStock'): string {
    $product=['@type'=>'Product','name'=>$name,'offers'=>['@type'=>'Offer','price'=>$price,'priceCurrency'=>$currency,
        'availability'=>'https://schema.org/'.$availability]];
    return '<script type="application/ld+json">'.json_encode(['@graph'=>[$product]],JSON_UNESCAPED_UNICODE).'</script>';
}
$now=time()-5;
$first='https://retailer-one.example/products/tea';$second='https://retailer-two.example/products/tea';
$record=['Product Name'=>'چای بهاران ارل گری ۵۰۰ گرم، ۲۴ عددی','Brand'=>'بهاران','Variation Type'=>'ارل گری','Weight'=>'۵۰۰ گرم'];
$identity=molavi_domain_market_identity($record);
$actual=molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۰٫۵ کیلوگرم'),$first,$identity);
check_market($actual['unit_price']===78000&&$actual['source_currency']==='IRR','matched equivalent size and converted rial to toman');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۲۵۰ گرم'),$first,$identity),'different size is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای دارچین بهاران ۵۰۰ گرم'),$first,$identity),'different variant is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم ۱۲ عددی'),$first,$identity),'pack price is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم','2.50','USD'),$first,$identity),'unsupported currency is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم','780000','IRR','OutOfStock'),$first,$identity),'out-of-stock offer is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم').market_page('چای ارل گری بهاران ۵۰۰ گرم','900000'),$first,$identity),'conflicting prices on one page are excluded');
check_market(!molavi_domain_market_url('https://staging.molavisale.ir/quote')&&!molavi_domain_market_url('https://127.0.0.1/quote'),'internal hosts are excluded');
$GLOBALS['sheets']=['Products'=>[array_merge(array_keys($record),['Latest Offer ID','Consumer Price']),
    array_merge(array_values($record),['published-offer','']),array_merge(array_values($record),['already-priced',80000])],
    'Offers'=>[array_merge(array_keys($record),['Offer ID']),array_merge(array_values($record),['pending-offer'])]];
$GLOBALS['reads']=0;$GLOBALS['requests']=[];
$db=new SQLite3(molavi_state_root().'/reference.sqlite3');$db->close();
putenv('MOLAVI_TAVILY_API_KEY');
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7')['state']==='idle'
    &&!$GLOBALS['requests'],'Basalam is available without a key and reads avoid provider calls');
putenv('MOLAVI_TAVILY_API_KEY=synthetic-key');
$eligible=molavi_domain_market_records($GLOBALS['sheets']);
check_market(array_keys($eligible)===['published-offer','pending-offer'],'missing prices in the catalog and inbox are eligible');
molavi_domain_market_seed($eligible,$now);
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7')['state']==='queued'&&!$GLOBALS['requests'],'dashboard reads never run a search');
$reads=$GLOBALS['reads'];molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7');
check_market($GLOBALS['reads']===$reads,'polling reuses the source identity');
$GLOBALS['pages']=[$first=>market_page('چای ارل گری بهاران ۵۰۰ گرم'),$second=>market_page('چای ارل گری بهاران ۵۰۰ گرم','82000','IRT')];
molavi_domain_market_run($now);molavi_domain_market_run($now+1);
check_market(count($GLOBALS['requests'])===2,'shared product identity consumes one Basalam search and one optional fallback');
check_market($GLOBALS['requests'][1][1]==='https://api.tavily.com/search'&&str_contains($GLOBALS['requests'][1][2]['query'],'500 گرم'),'optional web search receives Persian product identity');
$result=molavi_domain_market_price_api('GET',['offer_id'=>'pending-offer'],'wp:7');
check_market(count($result['candidates'])===2&&$result['suggested_price']['unit_price']===82000,'recommendation is a verified, linked market quote');
try {
    molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'select_market_price_source','url'=>'https://third.example/product'],'wp:7');
    throw new RuntimeException('unexpected acceptance');
} catch (MolaviWorkflowError $e) {check_market($e->getMessage()==='stale_market_price_source','selection requires a current verified source');}
$saved=molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'select_market_price_source','url'=>$first],'wp:7');
check_market($saved['selected_url']===$first&&$saved['suggested_price']['unit_price']===78000,'owner can select a source for later refresh');
molavi_domain_market_run($now+MOLAVI_REFERENCE_REFRESH_SECONDS-1);
check_market(count($GLOBALS['requests'])===2,'the existing reference interval is respected');
$GLOBALS['pages'][$first]=market_page('چای ارل گری بهاران ۵۰۰ گرم','810000');
molavi_domain_market_run($now+MOLAVI_REFERENCE_REFRESH_SECONDS);
$result=molavi_domain_market_price_api('GET',['offer_id'=>'pending-offer'],'wp:7');
check_market($result['selected_url']===$first&&$result['suggested_price']['unit_price']===81000,'scheduled check refreshes the selected source price');
check_market($GLOBALS['sheets']['Products'][1][count($GLOBALS['sheets']['Products'][1])-1]==='','web evidence does not overwrite stored consumer price');
putenv('MOLAVI_TAVILY_API_KEY');
$requestCount=count($GLOBALS['requests']);
$manual=molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'add_market_price_url','url'=>$second],'wp:7');
check_market($manual['selected_url']===$second&&$manual['suggested_price']['unit_price']===82000,'a verified retailer link can be saved without search credentials');
$GLOBALS['pages'][$second]=market_page('چای ارل گری بهاران ۵۰۰ گرم','83500','IRT');
molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'INSERT OR REPLACE INTO market_price_budget VALUES(?,25)',[gmdate('Y-m-d',$manual['next_at'])]));
molavi_domain_market_tick($manual['next_at']);
$manual=molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7');
check_market($manual['suggested_price']['unit_price']===83500&&count($GLOBALS['requests'])===$requestCount,'pinned refresh is independent of the discovery credential and budget');

$rani=['Product Name'=>'آبمیوه رانی هلو ۲۴۰ میل','Brand'=>'رانی','Variation Type'=>'هلو','Package Count'=>24];
$raniIdentity=molavi_domain_market_identity($rani);
$carton=['id'=>62769014,'title'=>'رانی هلو 240 میل باکس 24 عددی','description'=>'تعداد ۲۴ عدد',
    'category'=>['title'=>'آبمیوه و شربت'],'primary_price'=>26260000,'price'=>21220000,'inventory'=>9,
    'status'=>['value'=>2976],'variants'=>null,'vendor'=>['id'=>2,'title'=>'فروشگاه نمونه']];
$single=array_replace($carton,['id'=>3,'title'=>'رانی هلو ۲۴۰ میل','description'=>'تکی',
    'primary_price'=>1265000,'price'=>1265000,'vendor'=>['id'=>3]]);
$hidden=array_replace($carton,['id'=>4,'title'=>'آبمیوه رانی هلو ۲۴۰ میل','description'=>'تعداد در هر بسته: ۲۴ عددی']);
$GLOBALS['basalam_products']=[62769014=>$carton,3=>$single,4=>$hidden];
$GLOBALS['basalam_search']=array_map(static fn($item)=>['id'=>$item['id'],'name'=>$item['title'],'categoryTitle'=>'آبمیوه و شربت'],
    [['id'=>1,'title'=>'رانی هلو ۲۵۰ میل'],['id'=>2,'title'=>'رانی هلو ۲۴۰ میل باکس ۱۲ عددی'],$single,$carton]);
$quotes=molavi_domain_market_fetch($raniIdentity,null);
check_market(array_column($quotes,'listing_id')===[62769014,3],
    'Basalam verifies the exact carton and a single unit, excluding wrong size or pack');
check_market($quotes[0]['regular_price']===2626000&&$quotes[0]['current_price']===2122000
    &&$quotes[0]['regular_unit_price']===109417&&$quotes[0]['unit_price']===88417,
    'the Basalam carton keeps the regular and sale totals separate from unit prices');
check_market(molavi_domain_basalam_quote(4,$raniIdentity)['pack_count']===24
    &&!molavi_domain_basalam_quote(4,molavi_domain_market_identity(array_replace($rani,['Package Count'=>1]))),
    'explicit pack evidence in the description prevents a false single-unit quote');
$GLOBALS['basalam_products'][4]['description']='تعداد در هر بسته: ۱۲ عددی';
$GLOBALS['basalam_products'][4]['title']=$carton['title'];
check_market(!molavi_domain_basalam_quote(4,$raniIdentity),'conflicting package counts are excluded');
$GLOBALS['basalam_products'][3]['inventory']=0;
check_market(!molavi_domain_basalam_quote(3,$raniIdentity),'unavailable Basalam listings are excluded');

$GLOBALS['sheets']['Products'][]=array_map(static fn($field)=>array_merge($rani,['Latest Offer ID'=>'rani','Consumer Price'=>''])[$field]??'',
    $GLOBALS['sheets']['Products'][0]);
$GLOBALS['sheets']['Products'][0][]='Package Count';
$GLOBALS['sheets']['Products'][array_key_last($GLOBALS['sheets']['Products'])][]=24;
$legacy=$raniIdentity;
unset($legacy['pack_count']);
molavi_domain_tx('reference',static function(SQLite3 $db)use($legacy,$second): void {
    $quote=['title'=>'آبمیوه رانی هلو ۲۴۰ میل','url'=>$second,'merchant'=>'retailer-two.example','unit_price'=>90000];
    molavi_domain_sql($db,"INSERT INTO market_price_lookups(lookup_key,identity_json,state,next_at,checked_at,
        candidates_json,selected_url) VALUES(?,?,'ready',?,?,?,?)",
        [molavi_domain_hash($legacy),molavi_domain_json($legacy),time()+60,time(),molavi_domain_json([$quote]),$second]);
});
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7')['selected_url']===$second,
    'adding package identity preserves an existing saved unit-price reference');
molavi_domain_market_seed([$raniIdentity],time());
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7')['selected_url']===$second,
    'reconciling the queue keeps the existing reference under the new package identity');
$linked=molavi_domain_market_price_api('POST',['offer_id'=>'rani','action'=>'add_market_price_url',
    'url'=>'https://basalam.com/shop/product/62769014'],'wp:7');
check_market($linked['selected_url']==='https://basalam.com/p/62769014','a Basalam link saves its stable listing ID');
$GLOBALS['basalam_products'][62769014]['primary_price']=28800000;
molavi_domain_market_run($linked['next_at']);
$updated=molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7');
check_market($updated['suggested_price']['regular_unit_price']===120000,'scheduled refresh reads the saved listing directly');
unset($GLOBALS['basalam_products'][62769014]);
molavi_domain_market_run($updated['next_at']);
$removed=molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7');
check_market($removed['state']==='queued'&&!$removed['selected_url'],'a removed listing returns to the discovery queue');
$today=gmdate('Y-m-d');
molavi_domain_tx('reference',static function(SQLite3 $db)use($today,$raniIdentity): void {
    molavi_domain_sql($db,'INSERT OR REPLACE INTO market_price_budget VALUES(?,?)',[$today,MOLAVI_MARKET_DAILY_LOOKUPS]);
    molavi_domain_sql($db,'UPDATE market_price_lookups SET next_at=? WHERE lookup_key=?',[time(),molavi_domain_hash($raniIdentity)]);
});
$budgetWait=molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7');
check_market($budgetWait['state']==='quota'&&$budgetWait['next_at']===strtotime($today.' +1 day UTC'),
    'the card reports the daily discovery limit and when its deferred search can run');
foreach(glob(MOLAVI_STATE_ROOT.'/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
