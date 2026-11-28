<?php
declare(strict_types=1);
// Basalam "very low regular price" sanity rule — behaviour harness.
//
// Pack basis throughout: the fixture product is a 24-pack with a sheet Sale
// Price of 1,500,000 toman (pack basis). Quotes (regular_unit_price):
//   ok    71,500 -> pack 1,716,000 -> implied 12.6%  (auto-pickable)
//   thin  69,000 -> pack 1,656,000 -> implied  9.4%  (flagged, listed, never auto-picked)
//   rej   60,000 -> pack 1,440,000 -> implied -4.2%  (dropped, never pickable)
//   wide 200,000 -> pack 4,800,000 -> implied 68.8%  (wide side: unchanged)
// The ok+thin pair agrees within the 5% quote band (spread 3.5%), so G4
// can pick on agreement; the wide pair disagrees, exactly as today.
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-basalam-sanity-'.bin2hex(random_bytes(5)));
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
          'domain-market-sources','domain-market-agreement','domain-sale-offers'] as $module) require $root.'molavi-'.$module.'.php';
function check_sanity($condition,string $label): void {if(!$condition)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
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
check_sanity(($identity['pack_count']??null)===24,'fixture identity is a 24-pack');
$lookupKey=molavi_domain_hash($identity);
function seed_quotes(array $quotes,int $now,string $lookupKey,array $identity): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($quotes,$now,$lookupKey,$identity): void {
        molavi_domain_sql($db,"INSERT INTO market_price_lookups(lookup_key,identity_json,state,next_at,checked_at,candidates_json)
            VALUES(?,?,'ready',?,?,?) ON CONFLICT(lookup_key) DO UPDATE SET state='ready',checked_at=excluded.checked_at,
            candidates_json=excluded.candidates_json",
            [$lookupKey,molavi_domain_json($identity),$now+3600,$now,molavi_domain_json($quotes)]);
    });
}
function seed_source(?string $url,?array $quote,int $now,string $lookupKey,array $identity): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($url,$quote,$now,$identity): void {
        if ($url===null) { molavi_domain_sql($db,'DELETE FROM market_product_sources WHERE target=?',['product:tea-1']); return; }
        molavi_domain_sql($db,"INSERT INTO market_product_sources
            (target,product_id,offer_id,title,identity_json,url,quote_json,regular_price,next_at,checked_at,verified_at,changed_at)
            VALUES('product:tea-1','tea-1','offer-tea','tea',?, ?,?,?,?, ?,?,?)
            ON CONFLICT(target) DO UPDATE SET url=excluded.url,quote_json=excluded.quote_json,
            identity_json=excluded.identity_json,regular_price=excluded.regular_price",
            [molavi_domain_json($identity),$url,molavi_domain_json($quote),
                (int)($quote['regular_unit_price']??0),$now+86400,$now,$now,$now]);
    });
}
function quote(string $url,int $unit,int $pack=24): array {
    return ['title'=>'چای ارل گری بهاران ۰٫۵ کیلوگرم','url'=>$url,'provider'=>'basalam','merchant'=>'باسلام',
        'pack_count'=>$pack,'regular_unit_price'=>$unit,'unit_price'=>$unit,'regular_price'=>$unit*$pack,'current_price'=>$unit*$pack];
}
$ok=quote('https://basalam.com/p/111',71500);
$ok2=quote('https://basalam.com/p/112',72000);
$thin=quote('https://basalam.com/p/222',69000);
$reject=quote('https://basalam.com/p/333',60000);
$wide=quote('https://basalam.com/p/444',200000);

// ---- Pure helper: the rule itself, including the exact boundaries. ----
if (!function_exists('molavi_domain_market_quote_sanity')) {
    echo "NEGATIVE CONTROL: molavi_domain_market_quote_sanity() is absent — the new behaviour is not present\n";
} else {
    $s=molavi_domain_market_quote_sanity($ok,1500000,24);
    check_sanity($s['status']==='ok'&&abs($s['implied_spread']-0.1259)<0.001,'implied 12.6% is unaffected');
    $s=molavi_domain_market_quote_sanity($thin,1500000,24);
    check_sanity($s['status']==='thin'&&$s['reason_code']==='spread_too_thin'&&abs($s['implied_spread']-0.0942)<0.001,
        'implied 9.4% is flagged spread_too_thin with the implied %');
    $s=molavi_domain_market_quote_sanity($reject,1500000,24);
    check_sanity($s['status']==='reject'&&$s['reason_code']==='regular_at_or_below_cost'&&$s['implied_spread']<0,
        'implied -4.2% is rejected as regular_at_or_below_cost');
    $s=molavi_domain_market_quote_sanity($wide,1500000,24);
    check_sanity($s['status']==='ok'&&$s['implied_spread']>0.60,'implied 68.8% (wide side) is unaffected by this rule');
    $s=molavi_domain_market_quote_sanity(quote('https://basalam.com/p/555',100000,10),880000,10);
    check_sanity($s['status']==='ok'&&abs($s['implied_spread']-0.12)<1e-9,'implied exactly 12% is unaffected (boundary is < 0.12)');
    $s=molavi_domain_market_quote_sanity(quote('https://basalam.com/p/666',88000,10),880000,10);
    check_sanity($s['status']==='reject','implied exactly 0% (regular == sale) is rejected');
    $s=molavi_domain_market_quote_sanity(['url'=>'https://x.example/p','unit_price'=>50000],1500000,24);
    check_sanity($s['status']==='unknown','a quote with no regular price passes through unchanged');
    $s=molavi_domain_market_quote_sanity($ok,0,24);
    check_sanity($s['status']==='unknown','no sale price means no cost context: pass-through');
    // The reference implementation is untouched and still enforces both tiers.
    $thinPair=[quote('https://basalam.com/p/222',69000),quote('https://basalam.com/p/223',70000)];
    $decision=molavi_domain_market_agreement_classify($thinPair,1500000,24,null,null);
    check_sanity($decision['outcome']==='disagree'&&$decision['reason_code']==='spread_too_thin',
        'market-agreement still disagrees spread_too_thin on a thin median pick');
    $rejectPair=[quote('https://basalam.com/p/333',60000),quote('https://basalam.com/p/334',61000)];
    $decision=molavi_domain_market_agreement_classify($rejectPair,1500000,24,null,null);
    check_sanity($decision['outcome']==='disagree'&&$decision['reason_code']==='spread_too_thin',
        'market-agreement still disagrees on a below-cost median pick');
}

// ---- market_price_api: suggestion, candidates, drop log. ----
function api_result(): array { return molavi_domain_market_price_api('GET',['offer_id'=>'offer-tea'],'wp:7'); }
seed_quotes([$ok,$thin,$reject],$now,$lookupKey,$identity);
seed_source(null,null,$now,$lookupKey,$identity);
$result=api_result();
check_sanity($result['suggested_price']['url']===$ok['url'],'API: the ok quote is suggested when ok + thin + reject are present');
check_sanity(array_column($result['candidates'],'url')===[$thin['url'],$ok['url']]||array_column($result['candidates'],'url')===[$ok['url'],$thin['url']],
    'API: the reject quote is dropped from the candidate list');
$thinListed=array_values(array_filter($result['candidates'],static fn($quote)=>$quote['url']===$thin['url']));
check_sanity(count($thinListed)===1&&$thinListed[0]['sanity_flag']==='spread_too_thin'
    &&abs((float)$thinListed[0]['implied_spread']-0.0942)<0.001,
    'API: the thin quote stays listed, flagged spread_too_thin with the implied %');
check_sanity(($result['sanity_dropped']??null)===1,'API: the drop is logged (sanity_dropped = 1)');
seed_quotes([$thin],$now,$lookupKey,$identity);
$result=api_result();
check_sanity(count($result['candidates'])===1&&$result['candidates'][0]['sanity_flag']==='spread_too_thin',
    'API: a lone thin quote stays listed and flagged');
check_sanity($result['suggested_price']['url']===$thin['url'],
    'API: with no trustworthy quote the suggestion falls back to the flagged thin quote so the owner can still pick manually');
seed_quotes([$ok,$wide],$now,$lookupKey,$identity);
$result=api_result();
check_sanity(count($result['candidates'])===2&&!isset($result['candidates'][0]['sanity_flag'])
    &&!isset($result['candidates'][1]['sanity_flag'])&&($result['sanity_dropped']??null)===0,
    'API: ok + wide quotes are unflagged and nothing is dropped (wide side unchanged)');
seed_quotes([$ok,$reject],$now,$lookupKey,$identity);
seed_source($reject['url'],$reject,$now,$lookupKey,$identity);
$result=api_result();
check_sanity($result['suggested_price']['url']===$ok['url'],
    'API: an owner-selected reject-tier source is never suggested');
check_sanity(in_array($reject['url'],array_column($result['candidates'],'url'),true)
    &&array_values(array_filter($result['candidates'],static fn($quote)=>$quote['url']===$reject['url']))[0]['sanity_flag']==='regular_at_or_below_cost',
    'API: the selected reject-tier source stays listed, flagged, so the owner choice stays visible');
seed_source(null,null,$now,$lookupKey,$identity);

// ---- G4 (shadow): the live-pick set. ----
function g4_run(array $settings,array $tables,int $now): array {
    return molavi_domain_autopilot_rule_market($settings,$tables,$now,(float)strtotime('today UTC'),false,true,'test',false);
}
function g4_clean(): void {
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,"DELETE FROM autopilot_log WHERE gate='market'"));
}
function g4_pick(): ?array {
    $row=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,
        "SELECT inputs_json FROM autopilot_log WHERE gate='market' AND action='shadow' AND result='would_pick' ORDER BY id DESC LIMIT 1"));
    return $row?molavi_domain_decode($row['inputs_json']):null;
}
function g4_hold(): ?string {
    $row=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,
        "SELECT reason FROM autopilot_holds WHERE gate='market' AND product_id='tea-1'"));
    return $row['reason']??null;
}
$settings=['quote_band'=>0.05,'reference_margin'=>0.05,'cap_per_gate_per_day'=>20];
seed_quotes([$ok,$thin,$reject],$now,$lookupKey,$identity);
g4_clean();
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
$pick=g4_pick();
check_sanity($counts['shadow']===1&&$pick!==null&&$pick['url']===$ok['url'],
    'G4: the ok quote is picked when ok + thin + reject are present');
check_sanity(($pick['quote_count']??null)===2,'G4: the thin quote still counts in the spread (quote_count = 2)');
check_sanity(($pick['sanity_dropped']??null)===1&&($pick['sanity_thin']??null)===1,
    'G4: the drop is logged on the decision row (sanity_dropped = 1, sanity_thin = 1)');
seed_quotes([$thin],$now,$lookupKey,$identity);
g4_clean();
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check_sanity($counts['shadow']===0&&$counts['holds']===1&&g4_pick()===null
    &&str_contains((string)g4_hold(),'۱۲٪'),'G4: a lone thin quote is never auto-picked; the hold names the 12% floor');
seed_quotes([$reject],$now,$lookupKey,$identity);
g4_clean();
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check_sanity($counts['shadow']===0&&$counts['holds']===1&&g4_pick()===null
    &&str_contains((string)g4_hold(),'تأمین‌کننده یا پایین‌تر'),'G4: a lone below-cost quote is dropped; the hold names the cause');
seed_quotes([$ok,$wide],$now,$lookupKey,$identity);
g4_clean();
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
check_sanity($counts['shadow']===0&&$counts['holds']===1&&g4_pick()===null
    &&str_contains((string)g4_hold(),'اختلاف دارند'),'G4: ok + wide still disagrees on spread, exactly as today');
seed_quotes([$ok,$thin],$now,$lookupKey,$identity);
seed_source($thin['url'],$thin,$now,$lookupKey,$identity);
g4_clean();
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
$pick=g4_pick();
check_sanity($counts['shadow']===1&&$pick!==null&&$pick['url']===$thin['url'],
    'G4: an owner-pinned thin quote still wins the pick (explicit owner choice)');
seed_quotes([$ok,$ok2,$reject],$now,$lookupKey,$identity);
seed_source($reject['url'],$reject,$now,$lookupKey,$identity);
g4_clean();
$counts=g4_run($settings,$GLOBALS['sheets'],$now);
$pick=g4_pick();
check_sanity($counts['shadow']===1&&$pick!==null&&$pick['url']===$ok2['url'],
    'G4: an owner-pinned below-cost quote never wins the pick; an ok quote is picked instead');
seed_source(null,null,$now,$lookupKey,$identity);
echo "ALL BASALAM SANITY CHECKS PASSED\n";
