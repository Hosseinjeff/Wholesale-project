<?php
declare(strict_types=1);
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-market-test-'.bin2hex(random_bytes(5)));
define('MOLAVI_REFERENCE_OWNER_IDS',[7]);
define('ABSPATH',MOLAVI_STATE_ROOT.'/wp/');
function add_action(...$args){}
function add_filter(...$args){}
function get_transient($key){return $GLOBALS['cache'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function add_option($key,$value,...$args){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
function wp_http_validate_url($url){return $url;}
function wp_remote_retrieve_response_code($response){return $response['status'];}
function wp_remote_retrieve_body($response){return $response['body'];}
function wp_tempnam($name){return tempnam(sys_get_temp_dir(),$name);}
function is_wp_error($value){return false;}
function media_handle_sideload($image,$post,$title){
    if (!is_file($image['tmp_name'])||$post!==0) throw new RuntimeException('invalid image attachment');
    unlink($image['tmp_name']);return 88;
}
function wp_get_attachment_image_url($id,$size){return 'https://staging.molavisale.ir/wp-content/uploads/photo-'.$id.'-'.$size.'.png';}
function wp_attachment_is_image($id){return $id===88;}
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
          'domain-publisher','domain-products','domain-market-basalam','domain-market-prices','domain-market-sources'] as $module) require $root.'molavi-'.$module.'.php';
function check_market($condition,string $label): void {if(!$condition)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function market_page(string $name,string $price='780000',string $currency='IRR',string $availability='InStock',?string $regular=null): string {
    $product=['@type'=>'Product','name'=>$name,'image'=>['contentUrl'=>'https://img.example/tea.png'],'offers'=>['@type'=>'Offer','price'=>$price,'priceCurrency'=>$currency,
        'availability'=>'https://schema.org/'.$availability]];
    if ($regular!==null) $product['offers']['priceSpecification']=['priceType'=>'https://schema.org/StrikethroughPrice','price'=>$regular,'priceCurrency'=>$currency];
    return '<script type="application/ld+json">'.json_encode(['@graph'=>[$product]],JSON_UNESCAPED_UNICODE).'</script>';
}
$now=time()-5;
$first='https://retailer-one.example/products/tea';$second='https://retailer-two.example/products/tea';
$record=['Product Name'=>'چای بهاران ارل گری ۵۰۰ گرم، ۲۴ عددی','Brand'=>'بهاران','Variation Type'=>'ارل گری','Weight'=>'۵۰۰ گرم'];
$identity=molavi_domain_market_identity($record);
$actual=molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۰٫۵ کیلوگرم'),$first,$identity);
check_market($actual['unit_price']===78000&&$actual['source_currency']==='IRR','matched equivalent size and converted rial to toman');
$og=preg_replace('/,"image":\{"contentUrl":"[^"]+"\}/','',market_page('چای ارل گری بهاران ۰٫۵ کیلوگرم'));
$og='<meta content="/photos/tea.jpg" property="og:image">'.$og;
check_market(molavi_domain_market_from_page($og,$first,$identity)['image_url']==='https://retailer-one.example/photos/tea.jpg',
    'the verified listing can use a relative social preview image when structured product imagery is missing');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۲۵۰ گرم'),$first,$identity),'different size is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای دارچین بهاران ۵۰۰ گرم'),$first,$identity),'different variant is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم ۱۲ عددی'),$first,$identity),'pack price is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم','2.50','USD'),$first,$identity),'unsupported currency is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم','780000','IRR','OutOfStock'),$first,$identity),'out-of-stock offer is excluded');
check_market(!molavi_domain_market_from_page(market_page('چای ارل گری بهاران ۵۰۰ گرم').market_page('چای ارل گری بهاران ۵۰۰ گرم','900000'),$first,$identity),'conflicting prices on one page are excluded');
check_market(!molavi_domain_market_url('https://staging.molavisale.ir/quote')&&!molavi_domain_market_url('https://127.0.0.1/quote'),'internal hosts are excluded');
$GLOBALS['sheets']=['Products'=>[array_merge(array_keys($record),['Latest Offer ID','Consumer Price','Product ID']),
    array_merge(array_values($record),['published-offer','','tea-a']),array_merge(array_values($record),['already-priced',80000,'tea-b'])],
    'Offers'=>[array_merge(array_keys($record),['Offer ID']),array_merge(array_values($record),['pending-offer'])]];
$GLOBALS['reads']=0;$GLOBALS['requests']=[];
foreach (['reference','pipeline'] as $store) {$db=new SQLite3(molavi_state_root().'/'.$store.'.sqlite3');$db->close();}
mkdir(ABSPATH.'wp-admin/includes/',0755,true);
foreach (['file','image','media'] as $stub) touch(ABSPATH.'wp-admin/includes/'.$stub.'.php');
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
$GLOBALS['pages']=[$first=>market_page('چای ارل گری بهاران ۵۰۰ گرم','780000','IRR','InStock','950000'),
    $second=>market_page('چای ارل گری بهاران ۵۰۰ گرم','82000','IRT','InStock','99000')];
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
check_market($saved['selected_url']===$first&&$saved['tracking']['regular_price']===95000,'explicit selection applies the tagged regular price');
check_market(!molavi_domain_market_price_api('GET',['offer_id'=>'pending-offer'],'wp:7')['selected_url'],
    'a shared search does not share the owner selection');
molavi_domain_market_refresh_source($saved['tracking']['next_at']-1);
check_market(count($GLOBALS['requests'])===2,'the existing reference interval is respected');
$GLOBALS['pages'][$first]=market_page('چای ارل گری بهاران ۵۰۰ گرم','810000','IRR','InStock','1010000');
molavi_domain_market_tick($saved['tracking']['next_at']);
$result=molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7');
check_market($result['selected_url']===$first&&$result['tracking']['regular_price']===101000,'daily check updates the selected regular price');
$events=molavi_domain_read('reference',static fn($db)=>molavi_domain_rows($db,'SELECT * FROM market_source_events WHERE kind=?',['changed']));
check_market(count($events)===1&&$events[0]['old_price']===95000&&$events[0]['regular_price']===101000&&$events[0]['publish_pending']===0,
    'a changed price creates one notification and dispatches a durable publication job');
$projection=array_column(molavi_domain_site_products($GLOBALS['sheets'],[],[]),null,'source_record_id');
check_market($projection['tea-a']['shelf']===101000&&$projection['tea-b']['shelf']===80000,
    'the storefront uses the selected regular price on the exact product only');
$GLOBALS['pages']['https://img.example/tea.png']=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf2QAAAAASUVORK5CYII=');
$photo=molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'use_market_product_image','url'=>$first],'wp:7');
check_market($photo['product']['selected_photo']&&str_contains($photo['product']['image_url'],'photo-88')
    &&array_column(molavi_domain_site_products($GLOBALS['sheets'],[],[]),null,'source_record_id')['tea-a']['market_image_id']===88,
    'an explicitly chosen image becomes a local attachment in the catalog payload');
putenv('MOLAVI_TAVILY_API_KEY');
$requestCount=count($GLOBALS['requests']);
$manual=molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'add_market_price_url','url'=>$second],'wp:7');
check_market($manual['selected_url']===$first&&count($manual['candidates'])===2,'checking a retailer link leaves the chosen source in place');
$manual=molavi_domain_market_price_api('POST',['offer_id'=>'published-offer','action'=>'select_market_price_source','url'=>$second],'wp:7');
$GLOBALS['pages'][$second]=market_page('چای ارل گری بهاران ۵۰۰ گرم','83500','IRT','InStock','104000');
molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'INSERT OR REPLACE INTO market_price_budget VALUES(?,25)',[gmdate('Y-m-d',$manual['tracking']['next_at'])]));
molavi_domain_market_tick($manual['tracking']['next_at']);
$manual=molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7');
check_market($manual['tracking']['regular_price']===104000&&count($GLOBALS['requests'])===$requestCount,'pinned refresh is independent of the discovery credential and budget');

$rani=['Product Name'=>'آبمیوه رانی هلو ۲۴۰ میل','Brand'=>'رانی','Variation Type'=>'هلو','Package Count'=>24];
$raniIdentity=molavi_domain_market_identity($rani);
$carton=['id'=>62769014,'title'=>'رانی هلو 240 میل باکس 24 عددی','description'=>'تعداد ۲۴ عدد',
    'category'=>['title'=>'آبمیوه و شربت'],'primary_price'=>26260000,'price'=>21220000,'inventory'=>9,
    'photo'=>['lg'=>'https://img.example/rani.jpg','xs'=>'https://img.example/rani-xs.jpg'],
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
check_market($quotes[0]['image_url']==='https://img.example/rani.jpg'&&$quotes[0]['thumbnail_url']==='https://img.example/rani-xs.jpg',
    'the Basalam quote carries the listing preview and thumbnail');
check_market(molavi_domain_basalam_quote(4,$raniIdentity)['pack_count']===24
    &&!molavi_domain_basalam_quote(4,molavi_domain_market_identity(array_replace($rani,['Package Count'=>1]))),
    'explicit pack evidence in the description prevents a false single-unit quote');
$GLOBALS['basalam_products'][4]['description']='تعداد در هر بسته: ۱۲ عددی';
$GLOBALS['basalam_products'][4]['title']=$carton['title'];
check_market(!molavi_domain_basalam_quote(4,$raniIdentity),'conflicting package counts are excluded');
$GLOBALS['basalam_products'][3]['inventory']=0;
check_market(!molavi_domain_basalam_quote(3,$raniIdentity),'unavailable Basalam listings are excluded');

$GLOBALS['sheets']['Products'][]=array_map(static fn($field)=>array_merge($rani,['Latest Offer ID'=>'rani','Consumer Price'=>'','Product ID'=>'rani-1'])[$field]??'',
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
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7')['suggested_price']['url']===$second,
    'adding package identity preserves previous saved evidence');
molavi_domain_market_seed([$raniIdentity],time());
check_market(molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7')['tracking']===null,
    'a legacy evidence-only selection does not grant automatic pricing authority');
$linked=molavi_domain_market_price_api('POST',['offer_id'=>'rani','action'=>'add_market_price_url',
    'url'=>'https://basalam.com/shop/product/62769014'],'wp:7');
check_market(in_array('https://basalam.com/p/62769014',array_column($linked['candidates'],'url'),true),'a verified Basalam link uses its stable listing ID');
$linked=molavi_domain_market_price_api('POST',['offer_id'=>'rani','action'=>'select_market_price_source','url'=>'https://basalam.com/p/62769014'],'wp:7');
$GLOBALS['basalam_products'][62769014]['primary_price']=28800000;
molavi_domain_market_tick($linked['tracking']['next_at']);
$updated=molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7');
check_market($updated['suggested_price']['regular_unit_price']===120000,'scheduled refresh reads the saved listing directly');
unset($GLOBALS['basalam_products'][62769014]);
molavi_domain_market_tick($updated['tracking']['next_at']);
$removed=molavi_domain_market_price_api('GET',['offer_id'=>'rani'],'wp:7');
check_market($removed['tracking']['failure']==='listing_unavailable'&&$removed['selected_url']==='https://basalam.com/p/62769014'
    &&$removed['tracking']['regular_price']===120000,'a removed listing keeps the chosen link and last verified price');
check_market((bool)molavi_domain_read('reference',static fn($db)=>molavi_domain_row($db,
    "SELECT id FROM market_source_events WHERE kind='failed' AND product_id='rani-1'")),'a failed daily check stores its own dashboard event');
$today=gmdate('Y-m-d');
molavi_domain_tx('reference',static function(SQLite3 $db)use($today,$identity): void {
    molavi_domain_sql($db,'INSERT OR REPLACE INTO market_price_budget VALUES(?,?)',[$today,MOLAVI_MARKET_DAILY_LOOKUPS]);
    molavi_domain_sql($db,"UPDATE market_price_lookups SET state='queued',next_at=? WHERE lookup_key=?",[time(),molavi_domain_hash($identity)]);
});
$budgetWait=molavi_domain_market_price_api('GET',['offer_id'=>'pending-offer'],'wp:7');
check_market($budgetWait['state']==='quota'&&$budgetWait['next_at']===strtotime($today.' +1 day UTC'),
    'the card reports the daily discovery limit and when its deferred search can run');

require $root.'molavi-domain-sale-offers.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/sync.php';
class WC_Product {
    public function __construct(private array $meta,private int $regular=120000) {}
    public function get_meta(string $key,bool $single=true){return $this->meta[$key]??'';}
    public function get_regular_price(string $context='view'): string {return (string)$this->regular;}
}
$record=['Product ID'=>'rani-1','Sale Price'=>68750,'Latest Offer ID'=>'rani',
    // FIX no-picture gate: the fixture product needs a usable picture, as
    // the owner rule now refuses picture-less publication on every path.
    'Image File ID'=>'SyntheticPhoto_12345'];
$source=molavi_domain_market_source_rows()['product:rani-1'];
$expiry=$source['verified_at']+MOLAVI_MARKET_SOURCE_CHECK_SECONDS;
$supplier=['state'=>'current','verified_at'=>gmdate('c',$expiry-300)];
check_market($source['checked_at']===$expiry&&$source['verified_at']!==$source['checked_at'],
    'failed retailer checks preserve the successful verification deadline');
check_market(molavi_domain_sale_offer_evidence($record,[],$supplier,$source,$expiry-1)['sale_offer_expires_at']===$expiry,
    'an offer expires at the earlier verified price deadline');
check_market(molavi_domain_sale_offer_evidence($record,[],$supplier,$source,$expiry)['sale_offer_blocker']==='regular_price'
    &&molavi_domain_sale_offer_evidence($record,[],$supplier,null,$expiry)['regular_price_state']==='missing',
    'expired and absent regular prices both block publication');
$supplier['verified_at']=gmdate('c',$expiry-14*86400);
$renewed=array_replace($source,['verified_at'=>$expiry-1]);
check_market(molavi_domain_sale_offer_evidence($record,[],$supplier,$renewed,$expiry)['sale_offer_blocker']==='supplier_price',
    'a fresh retailer quote cannot renew a stale supplier price');
$supplier['verified_at']=gmdate('c',$expiry-90);
check_market(molavi_domain_sale_offer_evidence(array_replace($record,['Sale Price'=>120000]),[],$supplier,$renewed,$expiry)['sale_offer_blocker']==='no_spread',
    'equal supplier and regular prices cannot produce a dynamic sale offer');
$manual=['Offer ID'=>'rani','Consumer Price'=>125000,'Owner Decision ID'=>'manual','Owner Decision At'=>gmdate('c',$expiry)];
check_market(molavi_domain_sale_offer_evidence($record+['Consumer Price'=>125000],$manual,$supplier,$source,$expiry)['regular_price']===125000,
    'newly confirmed exact manual evidence can replace an expired retailer price');

require $root.'molavi-domain-order-catalog.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/pricing.php';
$manual['Owner Decision At']=gmdate('c',time()-120);
$record+=['Product Name'=>'Rani orange 240 ml','Consumer Price'=>125000];
$tables=['Products'=>[array_keys($record),array_values($record)],'Offers'=>[array_keys($manual),array_values($manual)]];
check_market(molavi_domain_order_catalog($tables)===[],'native order quotes require supplier verification');
$revision=molavi_domain_source_revision($record);
molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
    'INSERT OR REPLACE INTO supplier_price_evidence VALUES (?,?,?,?,?)',['rani-1',$revision,gmdate('c',time()-180),'manual','wp:7']));
check_market(molavi_domain_order_catalog($tables)[0]['regular']===125000,'native order quotes reuse valid regular and supplier evidence');

$GLOBALS['options']['molavi_state_active']=true;
$published=['_molavi_catalog_state'=>'sell','_molavi_market_shelf_toman'=>120000,'_molavi_supplier_cost_toman'=>68750];
check_market(molavi_supplier_offer_is_purchasable(true,new WC_Product($published))===false
    &&molavi_supplier_offer_is_purchasable(true,new WC_Product($published+['_molavi_sale_offer_expires_at'=>time()]))===false
    &&molavi_supplier_offer_is_purchasable(true,new WC_Product($published+['_molavi_sale_offer_expires_at'=>time()+600]))===true,
    'Woo checkout blocks missing and expired eligibility, and accepts the same listing before expiry');
require $root.'molavi-domain-gateway.php';
require $root.'molavi-domain-ai-published-reviews.php';
$GLOBALS['sheets']=$tables;
molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,"UPDATE jobs SET state='done' WHERE kind='publish'"));
$request=['action'=>'request_publication','product_id'=>'rani-1','offer_id'=>'rani'];
$receipt=molavi_domain_status_api('POST',[],$request,'wp:7');
check_market($receipt['state']==='queued'&&str_starts_with($receipt['publish_job'],'publish:owner-catalog:')
    &&molavi_domain_status_api('POST',[],$request,'wp:7')['publish_job']===$receipt['publish_job'],
    'a verified card can request publication and repeated requests reuse the active job');
$GLOBALS['sheets']['Products'][1][array_search('Sale Price',$tables['Products'][0],true)]=200000;
try {molavi_domain_status_api('POST',[],$request,'wp:7');$blocked=false;}
catch (MolaviWorkflowError $e) {$blocked=$e->getMessage()==='product_publication_prerequisite';}
check_market($blocked,'the publication action rechecks current prices and refuses a changed supplier quote');
foreach(glob(ABSPATH.'wp-admin/includes/*.php') as $file) unlink($file);
rmdir(ABSPATH.'wp-admin/includes');rmdir(ABSPATH.'wp-admin');rmdir(ABSPATH);
foreach(glob(MOLAVI_STATE_ROOT.'/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
