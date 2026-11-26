<?php
declare(strict_types=1);
define('ABSPATH','/fixture/');
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-item-'.bin2hex(random_bytes(6)));
define('MOLAVI_REFERENCE_OWNER_IDS',[7]);
function add_action(...$args){}
function add_filter(...$args){}
function get_option($key,$fallback=false){return $GLOBALS['options'][$key]??$fallback;}
function molavi_domain_sheets($names,$fresh=false){return $GLOBALS['sheets'];}
function molavi_domain_registered_products(){return $GLOBALS['registered']??[];}
function molavi_domain_published_review_catalog(){return molavi_domain_registered_products();}
function check($condition,string $message){if(!$condition)throw new RuntimeException($message);}
function rejected(callable $work,string $code){
    try{$work();}catch(MolaviWorkflowError $error){check($error->getMessage()===$code,$error->getMessage());return;}
    throw new RuntimeException('Expected '.$code);
}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-products','domain-supplier-link-checks',
    'domain-supplier-prices','domain-publisher','domain-autopilot','domain-gateway','domain-ingestion'] as $file)
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();
register_shutdown_function(static function()use($root){foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);});
(new SQLite3($root.'/pipeline.sqlite3'))->close();
$GLOBALS['options']=['molavi_state_active'=>true,'molavi_autopilot_settings_v1'=>['master_enabled'=>true,'rule_modes'=>['gA'=>'live']]];
molavi_domain_autopilot_ensure_tables();
$now=time();
$product=['Product ID'=>'p1','Product Name'=>'Supplier item','Channel Username'=>'@supplier','Latest Offer ID'=>'o1',
    'Sale Price'=>'50000','Consumer Price'=>'100000','Status'=>'imported','Message Timestamp'=>gmdate('c',$now-3600),
    'Source Post URL'=>'https://t.me/supplier/7','Original Message'=>'Supplier item 50000','Stock Status'=>'Available'];
$offer=$product+['Offer ID'=>'o1','Batch ID'=>'','Message ID'=>'7'];
$GLOBALS['sheets']=['Products'=>[array_keys($product),array_values($product)],'Offers'=>[array_keys($offer),array_values($offer)]];
$revision=molavi_domain_source_revision($product);
$ref=molavi_domain_supplier_link_check_ref('p1',$now-15);
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,
    "INSERT INTO supplier_link_checks(product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,checked_at,next_at,created_at)
     VALUES('p1','o1','item','https://t.me/supplier/7',?,'50000','supplier','unchanged',?,?,?)",[$revision,$now-15,$now,$now]));
$verify=['product_id'=>'p1','expected_revision'=>$revision,'command_id'=>str_repeat('a',64),'note'=>'Owner checked','check_ref'=>$ref];
rejected(fn()=>molavi_domain_supplier_prices_api('POST',array_merge($verify,['check_ref'=>'stale']),'wp:7'),'link_check_mismatch');
check(!molavi_domain_read('pipeline',fn($db)=>molavi_domain_row($db,"SELECT 1 FROM supplier_price_evidence_history WHERE method='manual'")),'stale approval wrote evidence');
rejected(fn()=>molavi_domain_supplier_link_check_confirm('p1',$ref,gmdate('c',$now),'wp:7',$now),'link_check_mismatch');
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_sql($db,"UPDATE supplier_link_checks SET lease='reading' WHERE product_id='p1'"));
rejected(fn()=>molavi_domain_supplier_prices_api('POST',$verify,'wp:7'),'link_check_mismatch');
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_sql($db,"UPDATE supplier_link_checks SET lease=NULL WHERE product_id='p1'"));
molavi_domain_tx('pipeline',fn($db)=>$db->exec("CREATE TRIGGER simulate_queue_failure BEFORE INSERT ON jobs
    BEGIN SELECT RAISE(ABORT, 'fixture queue failure'); END"));
try {
    molavi_domain_supplier_prices_api('POST',$verify,'wp:7');
    throw new RuntimeException('expected queue failure');
} catch (Exception $failure) {
    check(str_contains($failure->getMessage(),'fixture queue failure'),'unexpected database failure');
}
check(!molavi_domain_read('pipeline',fn($db)=>molavi_domain_row($db,"SELECT 1 FROM supplier_price_evidence_history WHERE method='manual'")),
    'queue failure left a partial owner approval');
molavi_domain_tx('pipeline',fn($db)=>$db->exec('DROP TRIGGER simulate_queue_failure'));
$receipt=molavi_domain_supplier_prices_api('POST',$verify,'wp:7');
$again=molavi_domain_supplier_prices_api('POST',$verify,'wp:7');
check($again['idempotent'] && $again['publish_job']===$receipt['publish_job'],'retry duplicated the verification or job');
check(molavi_domain_read('pipeline',fn($db)=>molavi_domain_supplier_link_check_row($db,'p1'))['confirmed_verified_at']===$receipt['verified_at'],'confirmation did not commit with evidence');
$projection=fn()=>molavi_domain_site_products($GLOBALS['sheets'])[0]['status'];
check($projection()==='hold','bulk publish skipped admission');
$request=['action'=>'request_publication','product_id'=>'p1','offer_id'=>'o1'];
rejected(fn()=>molavi_domain_request_product_publication($request,'wp:8'),'owner_required');
rejected(fn()=>molavi_domain_request_product_publication($request,'g5'),'product_publication_prerequisite');
$queued=molavi_domain_request_product_publication($request,'wp:7');
check($queued['state']==='queued' && $projection()==='sell','explicit approval did not reach the catalog projection');
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_autopilot_hold($db,'admission_owner','p1','owner hold',0,0,0,$now));
check(!molavi_domain_storefront_permits_sale('p1') && $projection()==='hold','owner hold did not reach checkout and publisher');
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE product_id='p1' AND gate='admission_owner'"));
unset($GLOBALS['molavi_storefront_blocks']);
check(molavi_domain_storefront_permits_sale('p1') && $projection()==='sell','released owner hold still blocks the permitted offer');
$original=['Batch ID'=>'b','ID'=>'8','Channel Username'=>'@supplier','Content'=>'exact message','URL'=>'https://t.me/supplier/7'];
$offer=['Offer ID'=>'o','Product ID'=>'p','Message ID'=>'8','Batch ID'=>'b','Channel Username'=>'@supplier','Original Message'=>'exact message'];
check(molavi_domain_supplier_recorded_links([$offer],[$original])['o']['url']===$original['URL'],'saved original link lost');
check(molavi_domain_supplier_recorded_links([$offer],[$original,array_merge($original,['URL'=>'https://t.me/supplier/9'])])['o']===null,'ambiguous source accepted');
$legacyOffer = array_merge($offer, ['Product ID'=>'']);
$currentProduct = ['Product ID'=>'p', 'Latest Offer ID'=>'o'];
$recover = fn($products, $messages, $offers) => molavi_domain_supplier_recorded_links($offers, $messages, $products)['o'];
check($recover([$currentProduct], [$original], [$legacyOffer])['product'] === 'p', 'legacy offer lost its uniquely referenced source');
check($recover([$currentProduct, ['Product ID'=>'other','Latest Offer ID'=>'o']], [$original], [$legacyOffer]) === null, 'shared legacy offer accepted');
check($recover([$currentProduct, ['Product ID'=>'p','Latest Offer ID'=>'other']], [$original], [$legacyOffer]) === null, 'duplicate legacy product accepted');
check($recover([$currentProduct], [$original], [$legacyOffer,$legacyOffer]) === null, 'duplicate legacy offer ID accepted');
check($recover([$currentProduct], [], [$legacyOffer]) === null, 'missing post URL invented');
check($recover([$currentProduct], [array_merge($original,['URL'=>'https://t.me/unrelated/7'])], [$legacyOffer]) === null, 'borrowed a link from another channel');
check($recover([$currentProduct], [$original], [array_merge($legacyOffer,['Source Post URL'=>'https://t.me/supplier/9'])]) === null, 'conflicting saved links accepted');
foreach(['telegram'=>'t.me','bale'=>'bale.ai'] as $channel=>$host){
    $message=['text'=>'fixture','message_id'=>90,'chat'=>['type'=>'private'],'forward_date'=>10,'forward_from_message_id'=>7,
        'forward_from_chat'=>['type'=>'channel','username'=>'supplier']];
    check(molavi_domain_ingest_payload($message,$channel)[0]['url']==="https://$host/supplier/7",'forward lost original post');
    unset($message['forward_from_message_id']);
    check(molavi_domain_ingest_payload($message,$channel)[0]['url']==='','guessed URL from recipient message');
}
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-extraction.php';
$source=['name'=>'نوشیدنی یک لیتری،۱۲ عددی','url'=>'https://t.me/supplier/7','sale_price'=>120000];
$source['message']=$source['name']."\n\nقیمت فروش ما: ۱۲۰/۰۰۰ تومان\n\nقیمت مصرف کننده: ندارد";
$edited=str_replace('۱۲۰/۰۰۰','۱۴۰/۰۰۰',$source['message']);
$compare=fn($text)=>molavi_domain_supplier_link_check_classify($source,['text'=>$text,'route'=>'railway']);
check($compare($edited)['live_price']==='140000','edited slash-separated supplier price lost');
check($compare($source['message']."\nتوضیح تازه")['outcome']==='unchanged','text-only edit changed the sale price');
check($compare("کالای دیگر\nقیمت فروش ما: ۱۲۰/۰۰۰ تومان")['outcome']==='uncheckable','borrowed unrelated item price');
check($compare($source['name']."\nقیمت مصرف کننده: ۱۲۰/۰۰۰ تومان")['outcome']==='uncheckable','borrowed retail price');
check($compare($source['name']."\nتماس ۱۲۳۴۵۶۷۸")['outcome']==='uncheckable','contact digits treated as money');
require __DIR__.'/../shop-wp/molavi-shop/includes/pricing.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-sale-offers.php';
function add_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
$product['Product ID']='new-product';
$product['Latest Offer ID']='new-offer';
$product['Source Timestamp Basis']='supplier_channel_update';
$product['Image File ID']='fixtureimage';
$offer=$product+['Offer ID'=>'new-offer','Message ID'=>'7'];
$GLOBALS['sheets']=['Products'=>[array_keys($product),array_values($product)],'Offers'=>[array_keys($offer),array_values($offer)]];
molavi_domain_freshness([$product]);
$run=fn()=>molavi_domain_autopilot_rule('gA',molavi_autopilot_settings(),$GLOBALS['sheets'],$now,(float)strtotime('today UTC'),'live');
$result=$run();
check($result['live']===1 && $projection()==='sell','eligible new supplier offer failed admission: '.json_encode($result));
check($run()['live']===0,'repeat admission consumed rollout capacity');
$GLOBALS['registered']=[['source_record_id'=>'new-product','woo_product_id'=>42,'published'=>true,'purchasable'=>true,'active_offer_id'=>'new-offer']];
check($run()['live']===0,'published offer consumed capacity');
$log=molavi_domain_read('pipeline',fn($db)=>molavi_domain_row($db,"SELECT id FROM autopilot_log WHERE action='live' AND result='admitted'"));
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_sql($db,"UPDATE autopilot_log SET verdict='flag' WHERE id=?",[$log['id']]));
unset($GLOBALS['molavi_storefront_blocks']);
check(!molavi_domain_storefront_permits_sale('new-product') && $projection()==='hold','live flag failed to revoke checkout');
echo "PASS native retrieval, owner permission, idempotence and publication workflow\n";

$fetches = 0;
$fetch = static function () use (&$fetches) {
    $fetches++;
    return ['status'=>200,'body'=>'<div class="tgme_widget_message_text">original</div>'];
};
molavi_domain_tx('pipeline', fn($db)=>molavi_domain_sql($db, "INSERT INTO supplier_link_checks
    (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,lease,next_at,created_at)
    VALUES('timeout','old','item','https://t.me/supplier/8','rev','50000','original','abandoned',?,?)", [$now,$now]));
molavi_domain_supplier_link_check_run_product('timeout',$now-1,$fetch);
check($fetches===0,'manual check stole an active lease');
check(molavi_domain_supplier_link_check_run_one($now,$fetch)['reason_code']==='identical' && $fetches===1,'abandoned source check failed to resume');

require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-market-prices.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-market-sources.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-market-agreement.php';
(new SQLite3(molavi_state_root().'/reference.sqlite3'))->close();
$marketProduct = ['Product ID'=>'market-timeout','Latest Offer ID'=>'market-offer','Product Name'=>'چای بهاران',
    'Sale Price'=>'70000','Message Timestamp'=>gmdate('c',$now-25*86400)];
$marketOffer = $marketProduct + ['Offer ID'=>'market-offer'];
$GLOBALS['sheets'] = ['Products'=>[array_keys($marketProduct),array_values($marketProduct)],
    'Offers'=>[array_keys($marketOffer),array_values($marketOffer)]];
$marketRevision = molavi_domain_source_revision($marketProduct);
$identity = molavi_domain_market_identity($marketProduct);
$quotes = [['url'=>'https://one.example/tea','unit_price'=>100000],['url'=>'https://two.example/tea','unit_price'=>101000]];
molavi_domain_tx('reference',fn($db)=>molavi_domain_sql($db, "INSERT INTO market_price_lookups
    (lookup_key,identity_json,state,candidates_json,checked_at,next_at) VALUES(?,?,'ready',?,?,?)",
    [molavi_domain_hash($identity),molavi_domain_json($identity),molavi_domain_json($quotes),$now,$now+86400]));
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_sql($db, "INSERT INTO market_agreement_checks
    (product_id,offer_id,title,source_revision,stored_sale_price,lease,next_at,created_at)
    VALUES('market-timeout','market-offer','tea',?,'70000','interrupted',?,?)",[$marketRevision,$now,$now]));
$marketRow = molavi_domain_read('pipeline',fn($db)=>molavi_domain_market_agreement_row($db,'market-timeout'));
$evidenceCount = fn()=>molavi_domain_read('pipeline',fn($db)=>molavi_domain_row($db,
    "SELECT COUNT(*) AS count FROM supplier_price_evidence_history WHERE offer_id='market-timeout' AND method='market'")['count']);
molavi_domain_market_agreement_run_product('market-timeout',$now-1);
molavi_domain_market_agreement_execute($marketRow,'superseded',$now);
check($evidenceCount()===0,'a stale or parallel market check wrote evidence');
$iso = gmdate('c',$now);
$jobId = 'publish:supplier-verification:market-timeout:'.hash('sha256',$marketRevision.'|'.$iso);
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_sql($db,"INSERT INTO jobs(id,kind,payload,state,available)
    VALUES(?,'publish','{}','queued',?)",[$jobId,$now]));
rejected(fn()=>molavi_domain_market_agreement_run_one($now,$iso),'job_identity_changed');
check($evidenceCount()===0,'failed queue write left partial market evidence');
molavi_domain_tx('pipeline',fn($db)=>molavi_domain_sql($db,'DELETE FROM jobs WHERE id=?',[$jobId]));
$result = molavi_domain_market_agreement_run_product('market-timeout',$now+MOLAVI_MARKET_AGREEMENT_LEASE_SECONDS,$iso);
check($result['outcome']==='agree' && $evidenceCount()===1,'market check failed to resume and verify exactly once');
check(molavi_domain_read('pipeline',fn($db)=>molavi_domain_row($db,'SELECT state FROM jobs WHERE id=?',[$jobId]))['state']==='queued',
    'market verification failed to queue the matching publication job');
echo "PASS abandoned checks recover, live leases are respected and market evidence commits with its publish job\n";
