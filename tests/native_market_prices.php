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
    $GLOBALS['requests'][]=[$method,$url,json_decode($args['body'],true)];
    return ['status'=>200,'body'=>json_encode(['results'=>array_map(static fn($url)=>['url'=>$url],array_keys($GLOBALS['pages']))])];
}
function molavi_domain_http_exact($method,$url,$args){return ['status'=>200,'body'=>$GLOBALS['pages'][$url]];}
$root=__DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-scheduler',
          'domain-publisher','domain-products','domain-market-prices'] as $module) require $root.'molavi-'.$module.'.php';
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
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7')['state']==='unconfigured'
    &&!$GLOBALS['requests'],'unconfigured lookup avoids provider calls');
putenv('MOLAVI_TAVILY_API_KEY=synthetic-key');
$eligible=molavi_domain_market_records($GLOBALS['sheets']);
check_market(array_keys($eligible)===['published-offer','pending-offer'],'missing prices in the catalog and inbox are eligible');
molavi_domain_market_seed($eligible,$now);
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7')['state']==='queued'&&!$GLOBALS['requests'],'dashboard reads never run a search');
$reads=$GLOBALS['reads'];molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7');
check_market($GLOBALS['reads']===$reads,'polling reuses the source identity');
$GLOBALS['pages']=[$first=>market_page('چای ارل گری بهاران ۵۰۰ گرم'),$second=>market_page('چای ارل گری بهاران ۵۰۰ گرم','82000','IRT')];
molavi_domain_market_run($now);molavi_domain_market_run($now+1);
check_market(count($GLOBALS['requests'])===1,'shared product identity consumes one web search per interval');
check_market($GLOBALS['requests'][0][1]==='https://api.tavily.com/search'&&str_contains($GLOBALS['requests'][0][2]['query'],'500 گرم'),'real provider contract receives Persian product identity');
$result=molavi_domain_market_price_api('GET',['offer_id'=>'pending-offer'],'wp:7');
check_market(count($result['candidates'])===2&&$result['suggested_price']['unit_price']===82000,'recommendation is a verified, linked market quote');
try {
    molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'select_market_price_source','url'=>'https://third.example/product'],'wp:7');
    throw new RuntimeException('unexpected acceptance');
} catch (MolaviWorkflowError $e) {check_market($e->getMessage()==='stale_market_price_source','selection requires a current verified source');}
$saved=molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'select_market_price_source','url'=>$first],'wp:7');
check_market($saved['selected_url']===$first&&$saved['suggested_price']['unit_price']===78000,'owner can select a source for later refresh');
molavi_domain_market_run($now+MOLAVI_REFERENCE_REFRESH_SECONDS-1);
check_market(count($GLOBALS['requests'])===1,'the existing reference interval is respected');
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
foreach(glob(MOLAVI_STATE_ROOT.'/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
