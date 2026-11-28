<?php
// Persistent matching slice 3 (feed + digest + card wiring) — behaviour harness.
// Run: php tests/native_match_feed_digest_card.php
// Negative control: MATCH_NEGATIVE=1 php tests/native_match_feed_digest_card.php
//   (same file against the unmodified wave-1 base deff6ce: every new-behaviour
//   check must report the behaviour ABSENT instead of passing).
// Proves, with no browser and no provider calls:
//   (a) activity: molavi_domain_dashboard_activity() returns match_events
//       with the feed kinds, the 14-day window and the 30-row limit, the
//       new price aliased to regular_price, and no price_updated/created
//       rows; market_events still render alongside;
//   (b) digest: molavi_domain_reference_match_digest_since() returns one
//       entry per product (latest event wins), ordered newest-first,
//       capped at 10 with total_new/omitted, and correct old->new,
//       change_ratio and action (accept/replace/re-search) fields;
//   (c) watch: record + re-record of the same digest_date replaces its
//       row (one row, watched_at advances) and the next read's window
//       advances, so nothing is double-reported; the POST action
//       record_match_digest_watch and the match_digest GET field behave
//       the same way;
//   (d) market tab: the market-prices view returns price_change (latest
//       'changed' market_source_events row) and a needs_review source
//       still exposes the quote picker (suggested_price + candidates).
declare(strict_types=1);
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-match-feed-'.bin2hex(random_bytes(5)));
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
function media_handle_sideload($image,$post,$title){return 88;}
function wp_get_attachment_image_url($id,$size){return 'https://staging.molavisale.ir/wp-content/uploads/photo-'.$id.'.png';}
function wp_attachment_is_image($id){return $id===88;}
function molavi_domain_sheets($names,$fresh=false){$GLOBALS['reads']++;return $GLOBALS['sheets'];}
function molavi_domain_ai_review_cases(...$args){return [['offer_id'=>'pending-offer']];}
function molavi_domain_http($method,$url,$args){throw new RuntimeException('no provider calls in this harness');}
function molavi_domain_http_exact($method,$url,$args){throw new RuntimeException('no provider calls in this harness');}
$root=__DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-scheduler',
          'domain-products','domain-supplier-link-checks','domain-supplier-prices','domain-publisher',
          'domain-autopilot','domain-gateway','domain-ingestion','domain-market-basalam','domain-market-prices',
          'domain-market-sources','domain-market-agreement','domain-sale-offers',
          'domain-bot-capabilities','domain-dashboard-activity'] as $module) require $root.'molavi-'.$module.'.php';

$negative=(bool)getenv('MATCH_NEGATIVE');
$pass=0;$fail=0;
function check_match(bool $condition,string $label): void {
    global $pass,$fail;
    if($condition){$pass++;echo 'PASS '.$label."\n";}
    else{$fail++;echo 'FAIL '.$label."\n";}
}
function check_new(bool $condition,string $label): void {
    // In negative mode the same check must show the behaviour ABSENT.
    global $negative;
    if($negative) check_match(!$condition,'NEGATIVE '.$label.' (absent on base)');
    else check_match($condition,$label);
}

$rootDir=molavi_state_root();
register_shutdown_function(static function()use($rootDir){foreach(glob($rootDir.'/*') as $file)unlink($file);@rmdir($rootDir);});
foreach (['reference','pipeline'] as $store) {$db=new SQLite3(molavi_state_root().'/'.$store.'.sqlite3');$db->close();}
$GLOBALS['options']=['molavi_state_active'=>true];
$GLOBALS['sheets']=[];
$GLOBALS['reads']=0;
$GLOBALS['options']['molavi_bot_capabilities_v1']=[
    'schema_version'=>1,'ai_configured'=>true,'extraction_policy_version'=>'gemini-'.str_repeat('a',16),
    'suggestion_policy_version'=>null,'image_extraction_enabled'=>false,
    'google_quota'=>['project'=>'project-fixture','models'=>[['model'=>'gemini-fixture','bucket'=>'shared',
        'tasks'=>['extract'],'rpm'=>5,'tpm'=>10000,'rpd'=>10,'reserve_rpd'=>0]]],
];
molavi_domain_autopilot_ensure_tables();
$now=1764200000; // fixed clock: 2026-11-27 ~01:33 UTC
$day=1209600; // 14 days, the feed window

// ---- seed: reference_match_events (feed + digest) -----------------------
function seed_match_event(string $id,string $product,string $kind,$old,$new,$ratio,string $source,int $at,?string $code=null): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($id,$product,$kind,$old,$new,$ratio,$source,$at,$code): void {
        molavi_domain_sql($db,'INSERT INTO reference_match_events
            (id,product_id,offer_id,title,kind,source,old_reference_id,new_reference_id,old_price,new_price,change_ratio,code,occurred_at,publish_pending)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
            [$id,$product,'offer-'.$product,'کالای '.$product,$kind,$source,null,null,$old,$new,$ratio,$code,$at]);
    });
}
seed_match_event('e1','p1','price_changed',100000,115000,0.15,'catalog',$now-100);
seed_match_event('e2','p1','price_updated',115000,116000,0.0087,'catalog',$now-50); // ledger-only: never in the feed
seed_match_event('e3','p2','reference_removed',80000,null,null,'catalog',$now-200);
seed_match_event('e4','p3','identity_changed',50000,52000,0.04,'catalog',$now-300);
seed_match_event('e5','p4','replaced',60000,61000,0.0167,'catalog',$now-400); // feed yes, digest no
seed_match_event('e6','p5','retired',70000,null,null,'catalog',$now-500); // feed yes, digest no
seed_match_event('e7','p6','created',null,90000,null,'catalog',$now-600); // neither feed nor digest
seed_match_event('e8','p7','price_changed',40000,44000,0.1,'catalog',$now-$day-60); // outside the 14-day window
for($i=0;$i<31;$i++) seed_match_event('cap'.$i,'cap'.$i,'price_changed',1000,1100,0.1,'catalog',$now-1000-$i);
// market_source_events: one changed row (feed + digest) and one failed row.
molavi_domain_tx('reference',static function(SQLite3 $db)use($now,$day): void {
    molavi_domain_sql($db,"INSERT INTO market_source_events (id,product_id,offer_id,title,kind,old_price,regular_price,code,occurred_at)
        VALUES ('m1','p10','offer-p10','کالای p10','changed',95000,101000,NULL,?)",[$now-100]);
    molavi_domain_sql($db,"INSERT INTO market_source_events (id,product_id,offer_id,title,kind,old_price,regular_price,code,occurred_at)
        VALUES ('m2','p10','offer-p10','کالای p10','failed',101000,NULL,'listing_unavailable',?)",[$now-50]);
    molavi_domain_sql($db,"INSERT INTO market_source_events (id,product_id,offer_id,title,kind,old_price,regular_price,code,occurred_at)
        VALUES ('m3','p11','offer-p11','کالای p11','changed',20000,21000,NULL,?)",[$now-$day-60]);
});

// ---- (a) activity feed ----------------------------------------------------
$activity=molavi_domain_dashboard_activity((float)$now);
$matchEvents=$activity['match_events']??null;
check_new(is_array($matchEvents),'activity returns match_events');
if(is_array($matchEvents)) {
    check_match(count($matchEvents)===30,'activity match_events is capped at 30 (36 qualifying rows)');
    $kinds=array_column($matchEvents,'kind');
    check_match(!in_array('price_updated',$kinds,true)&&!in_array('created',$kinds,true),
        'activity excludes price_updated and created kinds');
    check_match(!in_array('p7',array_column($matchEvents,'product_id'),true),
        'activity excludes rows older than 14 days');
    check_match($matchEvents[0]['occurred_at']>=$matchEvents[count($matchEvents)-1]['occurred_at'],
        'activity match_events are newest-first');
    $p1=array_values(array_filter($matchEvents,static fn($row)=>$row['product_id']==='p1'));
    check_match(count($p1)===1&&$p1[0]['kind']==='price_changed'&&$p1[0]['old_price']===100000
        &&$p1[0]['regular_price']===115000,
        'activity aliases new_price to regular_price (p1 latest qualifying row)');
    check_match(in_array('replaced',array_column($matchEvents,'kind'),true)
        &&in_array('retired',array_column($matchEvents,'kind'),true),
        'activity includes replaced and retired kinds');
}
check_match(is_array($activity['market_events']??null)
    &&($activity['market_events'][0]['kind']??'')==='failed',
    'activity still returns market_events alongside (latest first)');
if($negative) {
    // On the base the watch table does not exist either.
    $hasWatch=molavi_domain_read('reference',static fn(SQLite3 $db)=>
        molavi_domain_row($db,"SELECT 1 FROM sqlite_master WHERE type='table' AND name='reference_match_digest_watches'"));
    check_match($hasWatch===null,'NEGATIVE watch table absent on base');
}

// ---- (b) digest reader ----------------------------------------------------
$readerExists=function_exists('molavi_domain_reference_match_digest_since');
check_new($readerExists,'digest reader exists');
if($readerExists) {
    $digest=molavi_domain_read('reference',static fn(SQLite3 $db)=>
        molavi_domain_reference_match_digest_since($db,(float)($now-86400),10));
    check_match($digest['since_source']==='param'&&$digest['since']===(float)($now-86400),
        'digest reports its window (param since)');
    $ids=array_column($digest['entries'],'product_id');
    check_match(count($ids)===count(array_unique($ids)),'digest has one entry per product');
    check_match(!in_array('p4',$ids,true)&&!in_array('p5',$ids,true)&&!in_array('p6',$ids,true),
        'digest excludes replaced/retired/created kinds');
    check_match(!in_array('p11',$ids,true),'digest excludes rows before the window');
    $byId=array_column($digest['entries'],null,'product_id');
    check_match(($byId['p1']['kind']??'')==='price_changed'&&($byId['p1']['old_price']??null)===100000
        &&($byId['p1']['new_price']??null)===115000&&abs(($byId['p1']['change_ratio']??0)-0.15)<0.0001
        &&($byId['p1']['action']??'')==='accept',
        'digest p1: old->new 100000->115000, ratio 0.15, action accept');
    check_match(($byId['p2']['action']??'')==='replace'&&($byId['p2']['source']??'')==='catalog',
        'digest p2: reference_removed -> action replace');
    check_match(($byId['p3']['action']??'')==='accept'&&($byId['p3']['kind']??'')==='identity_changed',
        'digest p3: identity_changed -> action accept');
    check_match(($byId['p10']['kind']??'')==='failed'&&($byId['p10']['action']??'')==='re-search'
        &&($byId['p10']['source']??'')==='market',
        'digest p10: latest event wins (failed over changed) -> action re-search');
    check_match(($byId['p10']['old_price']??null)===101000&&($byId['p10']['new_price']??null)===null,
        'digest p10: failed row carries old price, no new price');
    check_match(count($digest['entries'])===10&&$digest['total_new']===35&&$digest['omitted']===25,
        'digest caps at 10 with total_new/omitted (35 qualifying products)');
    check_match($digest['entries'][0]['occurred_at']>=$digest['entries'][9]['occurred_at'],
        'digest entries are newest-first');
}

// ---- (c) digest watch: record / replace / window advance -----------------
$recordExists=function_exists('molavi_domain_reference_match_record_digest_watch');
check_new($recordExists,'digest watch record function exists');
if($recordExists) {
    $first=molavi_domain_tx('reference',static fn(SQLite3 $db)=>
        molavi_domain_reference_match_record_digest_watch($db,'2026-11-27','first run',(float)$now));
    $watches=molavi_domain_read('reference',static fn(SQLite3 $db)=>
        molavi_domain_reference_match_digest_watches($db,10));
    check_match(count($watches)===1&&$watches[0]['digest_date']==='2026-11-27'
        &&$watches[0]['note']==='first run'&&abs($watches[0]['watched_at']-$now)<0.001,
        'watch recorded once with date + note + watched_at');
    $second=molavi_domain_tx('reference',static fn(SQLite3 $db)=>
        molavi_domain_reference_match_record_digest_watch($db,'2026-11-27','re-run',(float)($now+60)));
    $watches=molavi_domain_read('reference',static fn(SQLite3 $db)=>
        molavi_domain_reference_match_digest_watches($db,10));
    check_match(count($watches)===1&&$watches[0]['note']==='re-run'
        &&abs($watches[0]['watched_at']-($now+60))<0.001,
        're-run of the same digest_date replaces its row (no duplicate)');
    // The next read's window advances past the recorded watch: with no
    // events after watched_at, nothing is double-reported.
    $after=molavi_domain_read('reference',static fn(SQLite3 $db)=>
        molavi_domain_reference_match_digest_since($db,null,10));
    check_match($after['since_source']==='watch'&&$after['count']===0&&$after['total_new']===0,
        'after the watch, the derived window reports nothing new');
    // The POST action records through the same API as the risk digest.
    $receipt=molavi_domain_autopilot_api('POST',[],['action'=>'record_match_digest_watch',
        'digest_date'=>'2026-11-26','note'=>'via api'],'wp:7');
    check_match($receipt['ok']===true&&$receipt['digest_date']==='2026-11-26',
        'POST record_match_digest_watch returns ok + date');
    $watches=molavi_domain_read('reference',static fn(SQLite3 $db)=>
        molavi_domain_reference_match_digest_watches($db,10));
    check_match(count($watches)===2,'POST watch adds a second date row');
    // The GET exposes match_digest on the same endpoint as risk_digest.
    $get=molavi_domain_autopilot_api('GET',[],null,'wp:7');
    check_match(array_key_exists('match_digest',$get)&&array_key_exists('risk_digest',$get),
        'GET /pipeline/autopilot exposes match_digest alongside risk_digest');
    check_match(($get['match_digest']['since_source']??'')==='watch'
        &&($get['match_digest']['count']??-1)===0,
        'GET match_digest derives its window from the latest watch');
} else {
    // Negative mode: the POST action must not exist on the base either.
    try {
        molavi_domain_autopilot_api('POST',[],['action'=>'record_match_digest_watch',
            'digest_date'=>'2026-11-26'],'wp:7');
        check_match(false,'NEGATIVE POST record_match_digest_watch is rejected on base');
    } catch(MolaviWorkflowError $e) {
        check_match($e->getMessage()==='invalid_body','NEGATIVE POST record_match_digest_watch is rejected on base');
    }
    $get=molavi_domain_autopilot_api('GET',[],null,'wp:7');
    check_match(!array_key_exists('match_digest',$get),'NEGATIVE GET has no match_digest on base');
}

// ---- (d) market tab: price_change + needs_review picker ------------------
$product=['Product Name'=>'چای بهاران ارل گری ۵۰۰ گرم، ۲۴ عددی','Brand'=>'بهاران','Variation Type'=>'ارل گری',
    'Weight'=>'۵۰۰ گرم','Package Count'=>24,'Latest Offer ID'=>'published-offer','Consumer Price'=>'','Actual Price'=>'',
    'Product ID'=>'tea-a','Sale Price'=>'','Image File ID'=>''];
$offer=['Offer ID'=>'published-offer','Import Timestamp'=>gmdate('c',$now-3600)];
$GLOBALS['sheets']=['Products'=>[array_keys($product),array_values($product)],
    'Offers'=>[array_keys($offer),array_values($offer)]];
$identity=molavi_domain_market_identity($product);
$first='https://retailer-one.example/products/tea';
$quote=['url'=>$first,'title'=>'چای ارل گری بهاران ۰٫۵ کیلوگرم','merchant'=>'Retailer One',
    'unit_price'=>78000,'regular_unit_price'=>95000,'regular_price'=>95000,'pack_count'=>1,
    'image_url'=>'https://img.example/tea.png','verified_at'=>$now-10];
molavi_domain_tx('reference',static function(SQLite3 $db)use($identity,$now,$quote,$first): void {
    $key=molavi_domain_hash($identity);
    molavi_domain_sql($db,"INSERT INTO market_price_lookups(lookup_key,identity_json,state,next_at,checked_at,candidates_json)
        VALUES(?,?,'ready',?,?,?)",[$key,molavi_domain_json($identity),$now+3600,$now,molavi_domain_json([$quote])]);
    molavi_domain_sql($db,"INSERT INTO market_product_sources (target,product_id,offer_id,title,identity_json,url,quote_json,regular_price,next_at,checked_at,verified_at,changed_at,match_status)
        VALUES ('product:tea-a','tea-a','published-offer','چای بهاران',?,? ,?,95000,?,?,?,?, 'active')",
        [molavi_domain_json($identity),$first,molavi_domain_json($quote),$now+3600,$now-10,$now-10,$now-10]);
    molavi_domain_sql($db,"INSERT INTO market_source_events (id,product_id,offer_id,title,kind,old_price,regular_price,code,occurred_at)
        VALUES ('tea-change','tea-a','published-offer','چای بهاران','changed',90000,95000,NULL,?)",[$now-100]);
});
$result=molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7');
check_new(array_key_exists('price_change',$result),'market view returns price_change');
if(array_key_exists('price_change',$result)) {
    check_match($result['price_change']==['old_price'=>90000,'regular_price'=>95000,'occurred_at'=>$now-100],
        'price_change is the latest changed event (old 90000 -> 95000)');
}
check_match(!empty($result['suggested_price'])&&count($result['candidates']??[])>=1,
    'market view still exposes the quote picker (suggested price + candidates)');
// A needs_review match must not hide the picker or the owner-entry paths:
// the tab renders from the same view, whose state stays usable.
molavi_domain_tx('reference',static function(SQLite3 $db): void {
    molavi_domain_sql($db,"UPDATE market_product_sources SET match_status='needs_review' WHERE target='product:tea-a'");
});
unset($GLOBALS['cache']);
$result2=molavi_domain_market_price_api('GET',['offer_id'=>'published-offer'],'wp:7');
check_match(!empty($result2['suggested_price'])&&count($result2['candidates']??[])>=1
    &&($result2['state']??'')!=='not_needed',
    'needs_review source still exposes the quote picker (no hidden tab)');
if($negative) {
    check_match(!array_key_exists('price_change',$result2),
        'NEGATIVE price_change stays absent after the needs_review update');
} else {
    check_match(($result2['price_change']['regular_price']??null)===95000,
        'price_change survives the needs_review update');
}

echo ($fail===0?"PASS all ($pass checks)\n":"FAIL $fail of ".($pass+$fail)." checks\n");
exit($fail===0?0:1);
