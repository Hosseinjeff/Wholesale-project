<?php
declare(strict_types=1);
// Market hold clears on owner pick (G4 decision 2026-11-28) — behaviour harness.
//
// Owner decision (2026-11-28, his words: "Yes — my pick settles it, clear
// the hold"): when the owner picks a market quote for a product, the
// market-disagree hold clears. Until now the disagree hold was re-derived
// from scratch every tick from the live quote spread
// (molavi_domain_autopilot_rule_market), and the pinned source only
// overrode which quote is recommended — so a pick never cleared the hold.
//
// Design (see the log hidden_files/market-pick-clears-hold-2026-11-28.md):
// the rule rebuilds gate='market' holds from scratch each tick (DELETE +
// re-derive), so clearing the hold is a SKIP, not a delete: when the Q4
// market-first predicate passes for a product, the rule does not emit
// the disagree hold for it. The predicate is exactly auto-verify's
// market-first leg (molavi_domain_auto_verify_market_first_leg): a picked
// market source row for the current identity, an active kept market match
// whose identity proof matches the current identity and whose URL is the
// picked listing, and a source re-verified within 24h, with the display
// evidence current at exactly per-unit regular x pack. It applies to
// both disagree reasons (quotes_disagree and single_quote_no_reference)
// and to nothing else: the no-quotes / no-recommended / quota / provider
// / stale / cap holds are untouched, and only the market gate is ever
// written here — admission and publish holds on the same product are
// never touched. If the pick is unpinned, the kept match goes inactive
// or mismatched, or the source goes stale, the leg fails and the next
// tick re-derives the hold (fail-closed).
//
// Harness: synthetic runtime, no WP database, no network, no tick.
// Negative control: on the pristine base (b0147cc) the rule never calls
// the market-first leg, so the pick-settles checks (1, 7, 10, 11a) fail
// there while the hold-present checks pass. Run:
//   php tests/native_market_pick_clears_hold.php
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-market-pick-'.bin2hex(random_bytes(5)));
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
function molavi_domain_sheets($names,$fresh=false){$GLOBALS['reads']++;return $GLOBALS['sheets'];}
function molavi_domain_ai_review_cases(...$args){return [['offer_id'=>'pending-offer']];}
function molavi_domain_http($method,$url,$args){throw new RuntimeException('no provider calls in this harness');}
function molavi_domain_http_exact($method,$url,$args){throw new RuntimeException('no provider calls in this harness');}
$root=__DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-scheduler',
          'domain-products','domain-supplier-link-checks','domain-supplier-prices','domain-publisher',
          'domain-autopilot','domain-gateway','domain-ingestion','domain-market-basalam','domain-market-prices',
          'domain-market-sources','domain-market-agreement','domain-sale-offers','domain-reference-matches',
          'domain-supplier-auto-verify'] as $module) require $root.'molavi-'.$module.'.php';
$pass=0;$fail=0;
function check(bool $ok,string $label,string $detail=''): void {
    global $pass,$fail;
    if($ok){$pass++;echo "PASS $label\n";}
    else{$fail++;echo "FAIL $label".($detail!==''?" — $detail":'')."\n";}
}
$rootDir=molavi_state_root();
register_shutdown_function(static function()use($rootDir){foreach(glob($rootDir.'/*') as $file)unlink($file);@rmdir($rootDir);});
$GLOBALS['reads']=0;
foreach (['reference','pipeline'] as $store) {$db=new SQLite3(molavi_state_root().'/'.$store.'.sqlite3');$db->close();}
$GLOBALS['options']=['molavi_state_active'=>true];
molavi_domain_autopilot_ensure_tables();
$now=time();

$product=['Product Name'=>'چای بهاران ارل گری ۵۰۰ گرم، ۲۴ عددی','Brand'=>'بهاران','Variation Type'=>'ارل گری',
    'Weight'=>'۵۰۰ گرم','Package Count'=>24,'Latest Offer ID'=>'offer-tea','Consumer Price'=>'','Actual Price'=>'',
    'Product ID'=>'tea-1','Sale Price'=>1500000,'Image File ID'=>''];
$offer=['Offer ID'=>'offer-tea','Import Timestamp'=>gmdate('c',$now-3600)];
$GLOBALS['sheets']=['Products'=>[array_keys($product),array_values($product)],
    'Offers'=>[array_keys($offer),array_values($offer)]];
$identity=molavi_domain_market_identity($product);
check(($identity['pack_count']??null)===24,'fixture identity is a 24-pack');
$lookupKey=molavi_domain_hash($identity);
function seed_quotes(array $quotes,int $now,string $lookupKey,array $identity): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($quotes,$now,$lookupKey,$identity): void {
        molavi_domain_sql($db,"INSERT INTO market_price_lookups(lookup_key,identity_json,state,next_at,checked_at,candidates_json)
            VALUES(?,?,'ready',?,?,?) ON CONFLICT(lookup_key) DO UPDATE SET state='ready',checked_at=excluded.checked_at,
            candidates_json=excluded.candidates_json",
            [$lookupKey,molavi_domain_json($identity),$now+3600,$now,molavi_domain_json($quotes)]);
    });
}
function seed_source(?string $url,?array $quote,int $now,string $lookupKey,array $identity,int $verifiedAt=null): void {
    $verifiedAt=$verifiedAt??$now;
    molavi_domain_tx('reference',static function(SQLite3 $db)use($url,$quote,$now,$identity,$verifiedAt): void {
        if ($url===null) { molavi_domain_sql($db,'DELETE FROM market_product_sources WHERE target=?',['product:tea-1']); return; }
        molavi_domain_sql($db,"INSERT INTO market_product_sources
            (target,product_id,offer_id,title,identity_json,url,quote_json,regular_price,next_at,checked_at,verified_at,changed_at)
            VALUES('product:tea-1','tea-1','offer-tea','tea',?, ?,?,?,?, ?,?,?)
            ON CONFLICT(target) DO UPDATE SET url=excluded.url,quote_json=excluded.quote_json,
            identity_json=excluded.identity_json,regular_price=excluded.regular_price,
            verified_at=excluded.verified_at,checked_at=excluded.checked_at",
            [molavi_domain_json($identity),$url,molavi_domain_json($quote),
                (int)($quote['regular_unit_price']??0),$now+86400,$now,$verifiedAt,$now]);
    });
}
function seed_kept(?string $url,?string $signature,string $status,int $now,array $identity,string $basis='backfill'): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($url,$signature,$status,$now,$identity,$basis): void {
        molavi_domain_sql($db,'DELETE FROM reference_product_matches WHERE product_id=? AND source=?',['tea-1','market']);
        if ($url===null) return;
        molavi_domain_sql($db,"INSERT INTO reference_product_matches
            (product_id,source,offer_id,signature,reference_id,match_basis,decision_id,url,regular_price,pack_count,identity_json,actor_id,status,created_at)
            VALUES('tea-1','market','offer-tea',?,'basalam-111',?,NULL,?,71500,24,?,'system:market-refresh',?,?)",
            [$signature??'', $basis, $url, molavi_domain_json($identity), $status, $now]);
    });
}
function seed_other_hold(string $gate,string $reason,int $now): void {
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($gate,$reason,$now): void {
        molavi_domain_autopilot_hold($db,$gate,'tea-1',$reason,0.0,1500000.0,0.0,(float)$now);
    });
}
function holds_for_product(): array {
    return molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        "SELECT gate,reason FROM autopilot_holds WHERE product_id='tea-1' ORDER BY gate"));
}
function g4_run(array $settings,array $tables,int $now): array {
    return molavi_domain_autopilot_rule_market($settings,$tables,$now,(float)strtotime('today UTC'),false,true,'test',false);
}
function g4_hold(): ?string {
    $row=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,
        "SELECT reason FROM autopilot_holds WHERE gate='market' AND product_id='tea-1'"));
    return $row['reason']??null;
}
function quote(string $url,int $unit,int $pack=24): array {
    return ['title'=>'چای ارل گری بهاران ۰٫۵ کیلوگرم','url'=>$url,'provider'=>'basalam','merchant'=>'باسلام',
        'pack_count'=>$pack,'regular_unit_price'=>$unit,'unit_price'=>$unit,'regular_price'=>$unit*$pack,'current_price'=>$unit*$pack];
}
$ok=quote('https://basalam.com/p/111',71500);
$wide=quote('https://basalam.com/p/444',200000);
$settings=['quote_band'=>0.05,'reference_margin'=>0.05,'cap_per_gate_per_day'=>20];
$sig=molavi_domain_hash($identity);

// 1. Disagree + standing pick -> no market hold.
seed_quotes([$ok,$wide],$now,$lookupKey,$identity);
seed_source($ok['url'],$ok,$now,$lookupKey,$identity);
seed_kept($ok['url'],$sig,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===0&&g4_hold()===null,'1 disagree + standing pick: no market hold');
// 2. Disagree + no pick -> hold.
seed_source(null,null,$now,$lookupKey,$identity);
seed_kept(null,null,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&str_contains((string)g4_hold(),'اختلاف دارند'),'2 disagree + no pick: hold names the spread');
// 3. Disagree + pick, stale source (verified 25h ago) -> hold.
seed_source($ok['url'],$ok,$now,$lookupKey,$identity,$now-90000);
seed_kept($ok['url'],$sig,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&g4_hold()!==null,'3 disagree + stale pick: hold returns (fail-closed on staleness)');
// 4. Disagree + pick, no kept match -> hold.
seed_source($ok['url'],$ok,$now,$lookupKey,$identity);
seed_kept(null,null,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&g4_hold()!==null,'4 disagree + pick without kept match: hold stays');
// 5. Disagree + pick, kept match URL differs -> hold.
seed_kept($wide['url'],$sig,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&g4_hold()!==null,'5 disagree + pick, kept URL differs: hold stays');
// 6. Disagree + pick, kept signature differs -> hold.
seed_kept($ok['url'],molavi_domain_hash(['terms'=>['other']]),'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&g4_hold()!==null,'6 disagree + pick, kept identity differs: hold stays');
// 7. Disagree + pick, kept match needs_review -> hold.
seed_kept($ok['url'],$sig,'needs_review',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&g4_hold()!==null,'7 disagree + pick, kept match needs_review: hold stays');
// 8. Single quote + standing pick -> no hold.
seed_quotes([$ok],$now,$lookupKey,$identity);
seed_source($ok['url'],$ok,$now,$lookupKey,$identity);
seed_kept($ok['url'],$sig,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===0&&g4_hold()===null,'8 single quote + standing pick: no hold');
// 9. Single quote + no pick -> hold.
seed_source(null,null,$now,$lookupKey,$identity);
seed_kept(null,null,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&str_contains((string)g4_hold(),'فقط یک نقل‌قول هست'),'9 single quote + no pick: hold names the missing reference');
// 10. Other gates untouched: admission + publish holds survive a pick-settled run.
seed_quotes([$ok,$wide],$now,$lookupKey,$identity);
seed_source($ok['url'],$ok,$now,$lookupKey,$identity);
seed_kept($ok['url'],$sig,'active',$now,$identity);
seed_other_hold('admission','کالا در توقف است.',$now);
seed_other_hold('publish','حاشیه سود محاسبه‌شده کمتر از حداقل مقدار مجاز دوازده درصد است.',$now);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
$held=holds_for_product();
$gates=array_column($held,'gate');
check(g4_hold()===null&&in_array('admission',$gates,true)&&in_array('publish',$gates,true),
    '10 pick settles the market hold only; admission + publish holds survive');
$adm=array_values(array_filter($held,static fn($h)=>$h['gate']==='admission'));
$pub=array_values(array_filter($held,static fn($h)=>$h['gate']==='publish'));
check(($adm[0]['reason']??'')==='کالا در توقف است.'&&($pub[0]['reason']??'')==='حاشیه سود محاسبه‌شده کمتر از حداقل مقدار مجاز دوازده درصد است.',
    '10b the surviving holds keep their exact reasons');
// 11. Unpin returns the hold: pick-settled run, then delete the source, re-run.
seed_quotes([$ok,$wide],$now,$lookupKey,$identity);
seed_source($ok['url'],$ok,$now,$lookupKey,$identity);
seed_kept($ok['url'],$sig,'active',$now,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check(g4_hold()===null,'11a pick standing: no hold');
seed_source(null,null,$now,$lookupKey,$identity);
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check($counts['holds']===1&&str_contains((string)g4_hold(),'اختلاف دارند'),'11b same product unpinned: hold returns on the next tick');
seed_source(null,null,$now,$lookupKey,$identity);
seed_kept(null,null,'active',$now,$identity);
echo "MARKET PICK CLEARS HOLD: $pass PASS / $fail FAIL\n";
exit($fail>0?1:0);
