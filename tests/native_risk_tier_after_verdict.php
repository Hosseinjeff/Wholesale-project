<?php
// Harness: the stale red risk badge on admitted products.
// The tuna's live admission wrote risk_tier='red' at admission (verdict
// pending then); the verdict runner never updates risk_tier when the
// verdict lands ok, so the review function returned the stale stored
// 'red'. The fix derives the tier from the closeness margin once the
// verdict is ok — exactly as the digest does — so the tuna (closeness
// 0.816) renders green. Pending stays red, flag stays red, and a row with
// no closeness data and no stored/receipt tier fails closed to red.
declare(strict_types=1);
define('ABSPATH','/synthetic/');
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-autopilot.php';

$settings=['risk_red_close'=>0.10,'risk_amber_close'=>0.25];
$pass=0;$fail=0;
function check(string $name,$got,$want): void {
    global $pass,$fail;
    if($got===$want){$pass++;echo "PASS $name\n";}
    else{$fail++;echo "FAIL $name: got ".var_export($got,true).", want ".var_export($want,true)."\n";}
}

// 1. The tuna: live admission, verdict ok, stored tier 'red' (admission
// time), closeness 0.816 (green tier). The badge must be green.
check('tuna verdict ok -> green',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.816,
         'closeness_arm'=>'regular_expiry','inputs'=>['closeness'=>0.816]],null,$settings),
    'green');

// 2. Same row, called the way the step-trace summary calls it: only
// ($row, null), no settings — the row carries the closeness column, so the
// derivation still works (default thresholds 0.10 / 0.25).
check('tuna verdict ok, no settings passed -> green',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.816,
         'closeness_arm'=>'regular_expiry'],null),
    'green');

// 3. Still red while the verdict is pending (admission-time behaviour).
check('tuna verdict pending -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'pending','risk_tier'=>'red','closeness'=>0.816],null,$settings),
    'red');

// 4. Red on a flag — live admission (stored 'red').
check('live admission verdict flag -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'flag','risk_tier'=>'red','closeness'=>0.816],null,$settings),
    'red');

// 5. Red on a flag — shadow row whose stored tier is the closeness tier
// (green). A flagged decision must never render green.
check('shadow verdict flag, stored green -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'flag','risk_tier'=>'green','closeness'=>0.816],null,$settings),
    'red');

// 6. Cappuccino: shadow, verdict flag, closeness -3.7207 -> red.
check('cappuccino verdict flag -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'flag','risk_tier'=>'red','closeness'=>-3.7207,
         'closeness_arm'=>'quote_spread'],null,$settings),
    'red');

// 7. Thresholds intact through the derivation (verdict ok, stored 'red').
check('closeness 0.10 -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.10],null,$settings),'red');
check('closeness 0.11 -> amber',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.11],null,$settings),'amber');
check('closeness 0.25 -> amber',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.25],null,$settings),'amber');
check('closeness 0.26 -> green',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.26],null,$settings),'green');
check('closeness 0.0 -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.0],null,$settings),'red');
check('closeness -0.5 -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>-0.5],null,$settings),'red');

// 8. Closeness from inputs_json when the row column is null.
check('closeness via inputs_json -> green',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>null,
         'inputs'=>['closeness'=>0.816]],null,$settings),
    'green');

// 9. Fail closed: verdict ok, no closeness data anywhere, no stored or
// receipt tier -> red.
check('no closeness, no stored tier -> red',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'','closeness'=>null,'inputs'=>[]],null,$settings),
    'red');

// 10. Fail closed, receipt fallback: no closeness, stored '', receipt 'amber'.
check('no closeness, receipt amber -> amber',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'','closeness'=>null,'inputs'=>[]],'amber',$settings),
    'amber');

// 11. No closeness data but a stored tier exists: the stored tier is used.
check('no closeness, stored green -> green',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'green','closeness'=>null,'inputs'=>[]],null,$settings),
    'green');

// 12. Non-admission gates are 'none', untouched.
check('g5 gate -> none',
    molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'publish','verdict'=>'ok','risk_tier'=>'red','closeness'=>0.816],null,$settings),
    'none');

// 13. The review function agrees with the digest's derivation for every
// verdict-ok closeness (digest: verdictPending ? 'red' : decision tier;
// here the verdict is ok, so it is the decision tier).
foreach([0.0,0.05,0.10,0.11,0.20,0.25,0.26,0.50,0.816,1.0] as $c) {
    $got=molavi_domain_autopilot_review_risk_tier(
        ['gate'=>'admission','verdict'=>'ok','risk_tier'=>'red','closeness'=>$c],null,$settings);
    $want=molavi_domain_autopilot_decision_risk_tier($c,$settings);
    check("digest agreement closeness $c -> $want",$got,$want);
}

// 14. The API call site passes closeness + inputs + settings to the review
// function (so the derivation has the data and the live thresholds).
$src=file_get_contents(__DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-autopilot.php');
check('call site passes closeness',
    str_contains($src,"'closeness'=>\$row['closeness']"),true);
check('call site passes inputs',
    str_contains($src,"'inputs'=>\$row['inputs']"),true);
check('call site passes settings',
    str_contains($src,"],null,\$settings);"),true);

// 15. Thresholds and the verdict runner are unchanged (no decision logic
// moved): the constants are 0.10 / 0.25 and the runner still updates only
// verdict + verdict_reason, never risk_tier.
check('red threshold constant 0.10',
    str_contains($src,'const MOLAVI_ADMISSION_BOUNDARY_CLOSE = 0.10;'),true);
check('amber threshold constant 0.25',
    str_contains($src,'const MOLAVI_ADMISSION_AMBER_CLOSE = 0.25;'),true);
check('verdict runner updates verdict + reason only',
    str_contains($src,"UPDATE autopilot_log SET verdict=?, verdict_reason=? WHERE id=?"),true);
check('verdict runner never writes risk_tier',
    !str_contains($src,'SET risk_tier='),true);

echo "----\n$pass PASS, $fail FAIL\n";
exit($fail===0?0:1);
