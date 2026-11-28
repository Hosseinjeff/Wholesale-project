<?php
/** FIX-R3 round 2: two sudden hold jumps on staging (measured 06:56–06:58
 * 2026-11-27, holds 132 -> 145).
 *
 * (A) Publish holds 2 -> 14, all "price-spread review" in 8 wording
 * variants of the same reason. The wordings exist NOWHERE in the repo:
 * they are Gemini verdict_reason strings, written verbatim as hold
 * reasons by molavi_domain_autopilot_run_verdicts() when the model flags
 * a publish-gate log row. 10 of the 14 are named in the round-2 ENUM-A
 * raw output (/tmp/probe_r2_A_3.txt admission_list): آب میوه سلمان,
 * 7x آبمیوه تتراپک سون ۲۲۰ میلی‌لیتر (آلبالو، آناناس، انار، انبه،
 * سیب موز، هلو، پرتقال), آبمیوه شیشه‌ای موکوکوکو طعم انبه, کیکس مکس;
 * 4 more publish-flagged products are not named in any round-2 raw
 * output. The procedure fault: a publish log row carried ONLY the two
 * bare prices (supplier_price, regular_price), while the verdict prompt
 * told the model to flag "a spread wider than the claimed basis" — with
 * no basis claimed anywhere in the prompt, and NO upper spread limit
 * anywhere in the deterministic rules (in the deployed tree molavi_band
 * calls any positive spread discountable; the thin_spread blocker only
 * fires when there is no spread at all — and Arm 7 + the sale-offer gate
 * already passed these products to publish). The model invented an upper
 * limit and flagged 14 lawful wide spreads.
 * The fix: the publish rule now logs the same spread basis the admission
 * rule's §10.4 enrichment carries (spread, min_margin,
 * spread_discountable), and the prompt states there is no upper limit.
 * Existing flags are NOT cleared by code (hard limit: never resolve
 * holds); they expire 7 days after the flag row, and the next day's
 * publish retry is judged with the basis in hand.
 *
 * (B) Admission "image broken" holds 2 -> 14. The dead list
 * (molavi_dead_image_files) is append-only and permanent, and a file is
 * marked dead on a SINGLE 404 from the reference server — but the
 * server's /media/file endpoint (app.py media_file) returns 404 for
 * EVERY failure: a missing file, a Telegram getFile timeout, a rate
 * limit, a restart, a missing bot token. One transient 404 during a
 * heavy publish night permanently blocks the product at admission Arm 8
 * with no path forward. The fix is two-strike: a first 404 only suspects
 * the file (re-fetched on the next publish run; admission holds it with
 * the distinct reason 'تصویر کالا در حال بررسی است.' — still no release
 * without a picture, the no-picture rule stands); a 404 at least an hour
 * after the first sighting confirms death. A genuinely missing file
 * still ends up dead, one run later; a 200 clears the suspect.
 *
 * Harness: pure-function checks, no model call, no HTTP, no tick.
 * The harness CANNOT prove the model will stop flagging wide spreads —
 * it proves the model now receives the claimed basis, and the
 * dead-marking now needs confirmation. Negative control: on the
 * unmodified 815d7e6 base the same file fails the new-behaviour checks
 * (no publish_spread_inputs helper, no suspect helpers, prompt without
 * the no-upper-limit sentence). Run: php tests/native_r3_hold_jumps.php
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-r3-hold-jumps-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['wiring_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['wiring_options'][$key] = $value; }
function add_option($key, $value, $deprecated = '', $autoload = false) { $GLOBALS['wiring_options'][$key] = $value; }
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['wiring_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields($name, $row, $headers, $values) {
    foreach ($values as $field => $value) $GLOBALS['wiring_tables'][$name][$row - 1][array_search($field, $headers, true)] = $value;
}
function molavi_domain_sheet_cells($name, $updates) {
    foreach ($updates as $update) {
        if (!preg_match('/^([A-Z]+)(\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range ' . $update['range']);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $GLOBALS['wiring_tables'][$name][(int) $m[2] - 1][$col - 1] = $update['values'][0][0];
    }
}
function molavi_domain_sheet_ensure($name, $headers) {
    if (empty($GLOBALS['wiring_tables'][$name])) $GLOBALS['wiring_tables'][$name] = [$headers];
}
function molavi_domain_sheet_append($name, $rows) {
    foreach ($rows as $row) $GLOBALS['wiring_tables'][$name][] = $row;
}
function molavi_domain_sheet_column($number) {
    $out = '';
    while ($number > 0) {
        $number--;
        $out = chr(65 + $number % 26) . $out;
        $number = intdiv($number, 26);
    }
    return $out;
}

foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-gateway', 'domain-reference-parser',
    'domain-reference-store', 'domain-reference-matching', 'domain-reference-matches', 'domain-products', 'domain-publisher',
    'domain-product-derivation', 'domain-sheet-conflicts', 'domain-jobs', 'domain-source-projection',
    'domain-sale-offers', 'domain-orders', 'domain-reference-reviews', 'domain-reference-scheduler',
    'domain-reference-backfill', 'domain-supplier-link-checks', 'domain-supplier-prices',
    'domain-market-basalam', 'domain-market-prices', 'domain-market-sources',
    'domain-supplier-auto-verify', 'domain-autopilot'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';
require __DIR__ . '/../shop-wp/molavi-shop/includes/pricing.php';
require __DIR__ . '/../shop-wp/molavi-shop/includes/sync.php';

$pass = 0; $fail = 0;
function r3_check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// ---------------------------------------------------------------- (A) publish spread inputs
$hasHelper = function_exists('molavi_domain_autopilot_publish_spread_inputs');
r3_check('a-helper-exists', $hasHelper, 'publish_spread_inputs helper missing (base behaviour)');
if ($hasHelper) {
    // Wide-but-discountable: supplier 15000, regular 40000 -> spread 0.625.
    $wide = molavi_domain_autopilot_publish_spread_inputs(15000, 40000);
    r3_check('a-wide-spread', abs(($wide['spread'] ?? -1) - 0.625) < 0.0001, 'spread=' . var_export($wide['spread'] ?? null, true));
    r3_check('a-wide-min-margin', abs(($wide['min_margin'] ?? -1) - 0.12) < 0.0001, 'min_margin=' . var_export($wide['min_margin'] ?? null, true));
    r3_check('a-wide-discountable-yes', ($wide['spread_discountable'] ?? '') === 'yes', 'spread_discountable=' . var_export($wide['spread_discountable'] ?? null, true));
    // Thin spread: supplier 90000, regular 100000 -> spread 0.1. In the
    // deployed tree ANY positive spread is discountable (floor = cost),
    // so this is 'yes': the thin_spread blocker only fires when there is
    // no spread at all. A model flagging 0.1 as "too thin" would be
    // inventing a floor the gate does not have.
    $thin = molavi_domain_autopilot_publish_spread_inputs(90000, 100000);
    r3_check('a-thin-spread', abs(($thin['spread'] ?? -1) - 0.1) < 0.0001, 'spread=' . var_export($thin['spread'] ?? null, true));
    r3_check('a-thin-discountable-yes', ($thin['spread_discountable'] ?? '') === 'yes', 'spread_discountable=' . var_export($thin['spread_discountable'] ?? null, true));
    // No spread: equal prices -> spread 0.0, no discountable key.
    $none = molavi_domain_autopilot_publish_spread_inputs(100000, 100000);
    r3_check('a-none-spread', ($none['spread'] ?? -1) === 0.0 && !isset($none['spread_discountable']), 'inputs=' . json_encode($none));
    // Zero cost -> spread 0.0, no discountable key.
    $zero = molavi_domain_autopilot_publish_spread_inputs(0, 40000);
    r3_check('a-zero-cost', ($zero['spread'] ?? -1) === 0.0 && !isset($zero['spread_discountable']), 'inputs=' . json_encode($zero));
} else {
    foreach (['a-wide-spread', 'a-wide-min-margin', 'a-wide-discountable-yes', 'a-thin-spread', 'a-thin-discountable-yes', 'a-none-spread', 'a-zero-cost'] as $n) r3_check($n, false, 'helper missing');
}

// Real band (deployed tree): any positive spread is discountable, no
// spread is not (the rule the verdict must judge against — no upper
// limit exists).
$bandWide = molavi_band(15000, 40000);
r3_check('a-band-wide-discountable', !empty($bandWide['discountable']), 'band=' . json_encode($bandWide));
$bandThin = molavi_band(90000, 100000);
r3_check('a-band-positive-spread-discountable', !empty($bandThin['discountable']), 'band=' . json_encode($bandThin));
$bandNone = molavi_band(100000, 100000);
r3_check('a-band-no-spread-not-discountable', empty($bandNone['discountable']), 'band=' . json_encode($bandNone));

// ---------------------------------------------------------------- verdict prompt
$hasPrompt = function_exists('molavi_domain_autopilot_verdict_prompt');
r3_check('a-prompt-exists', $hasPrompt, 'verdict_prompt missing');
if ($hasPrompt) {
    // Enriched publish row (what the rule logs after the fix).
    $row = ['gate' => 'publish', 'rule_id' => 'g5', 'action' => 'live', 'result' => 'queued',
        'confidence' => 1.0,
        'inputs_json' => json_encode(['product_id' => 'p_x', 'offer_id' => 'o_x',
            'supplier_price' => 15000, 'regular_price' => 40000,
            'spread' => 0.625, 'min_margin' => 0.12, 'spread_discountable' => 'yes'])];
    $prompt = molavi_domain_autopilot_verdict_prompt($row);
    r3_check('a-prompt-has-spread', str_contains($prompt, 'spread: 0.625'), 'prompt lacks spread line');
    r3_check('a-prompt-has-min-margin', str_contains($prompt, 'min_margin: 0.12'), 'prompt lacks min_margin line');
    r3_check('a-prompt-has-discountable', str_contains($prompt, 'spread_discountable: yes'), 'prompt lacks spread_discountable line');
    r3_check('a-prompt-no-upper-limit', str_contains($prompt, 'no upper limit on the spread'), 'prompt lacks the no-upper-limit sentence');
    r3_check('a-prompt-no-invented-basis', !str_contains($prompt, 'a spread wider than the claimed basis'), 'old "wider than the claimed basis" wording still present');
    // Old-style row (two bare prices, pre-fix): documents the gap — the
    // prompt carries no basis at all. Input lines start at a line break,
    // so match "\nspread:" / "\nmin_margin:" (the prompt sentence itself
    // contains "on the spread:" mid-line). This passes on both trees.
    $oldRow = ['gate' => 'publish', 'rule_id' => 'g5', 'action' => 'live', 'result' => 'queued',
        'confidence' => 1.0,
        'inputs_json' => json_encode(['product_id' => 'p_x', 'offer_id' => 'o_x',
            'supplier_price' => 15000, 'regular_price' => 40000])];
    $oldPrompt = molavi_domain_autopilot_verdict_prompt($oldRow);
    r3_check('a-old-row-has-no-basis', !str_contains($oldPrompt, "\nspread:") && !str_contains($oldPrompt, "\nmin_margin:"), 'old row unexpectedly carries a basis');
    // The sentence is in the shared prompt, so admission rows get it too.
    $admRow = ['gate' => 'admission', 'rule_id' => 'gA', 'action' => 'live', 'result' => 'admitted',
        'confidence' => 0.5,
        'inputs_json' => json_encode(['product_id' => 'p_y', 'supplier_price' => 15000, 'regular_price' => 40000, 'spread' => 0.625, 'min_margin' => 0.12])];
    r3_check('a-prompt-admission-sentence', str_contains(molavi_domain_autopilot_verdict_prompt($admRow), 'no upper limit on the spread'), 'shared prompt lacks the sentence on admission rows');
} else {
    foreach (['a-prompt-has-spread', 'a-prompt-has-min-margin', 'a-prompt-has-discountable', 'a-prompt-no-upper-limit', 'a-prompt-no-invented-basis', 'a-old-row-has-no-basis', 'a-prompt-admission-sentence'] as $n) r3_check($n, false, 'prompt missing');
}

// ---------------------------------------------------------------- (B) 404 two-strike
$hasOutcome = function_exists('molavi_image_404_outcome');
r3_check('b-outcome-exists', $hasOutcome, 'image_404_outcome helper missing (base behaviour)');
if ($hasOutcome) {
    $file = 'Abcdefgh12345678';
    r3_check('b-first-404-suspect', molavi_image_404_outcome($file, 1700000000, []) === 'suspect');
    r3_check('b-second-404-30min-suspect', molavi_image_404_outcome($file, 1700000000 + 1800, [$file => 1700000000]) === 'suspect');
    r3_check('b-second-404-59min-suspect', molavi_image_404_outcome($file, 1700000000 + 3599, [$file => 1700000000]) === 'suspect');
    r3_check('b-second-404-60min-dead', molavi_image_404_outcome($file, 1700000000 + 3600, [$file => 1700000000]) === 'dead');
    r3_check('b-second-404-next-day-dead', molavi_image_404_outcome($file, 1700000000 + 90000, [$file => 1700000000]) === 'dead');
    r3_check('b-invalid-file-suspect', molavi_image_404_outcome('bad!', 1700000000, []) === 'suspect');
    r3_check('b-invalid-file-never-dead', molavi_image_404_outcome('bad!', 1700000000 + 99999, ['bad!' => 1700000000]) === 'suspect');
} else {
    foreach (['b-first-404-suspect', 'b-second-404-30min-suspect', 'b-second-404-59min-suspect', 'b-second-404-60min-dead', 'b-second-404-next-day-dead', 'b-invalid-file-suspect', 'b-invalid-file-never-dead'] as $n) r3_check($n, false, 'outcome helper missing');
}

// Suspect option round-trip (sync.php helpers).
$hasSuspects = function_exists('molavi_suspect_image_files') && function_exists('molavi_note_image_404_suspect') && function_exists('molavi_clear_image_404_suspect');
r3_check('b-suspect-helpers-exist', $hasSuspects, 'suspect helpers missing (base behaviour)');
if ($hasSuspects) {
    $GLOBALS['wiring_options'] = [];
    $file = 'Xyz12345_abcdef';
    molavi_note_image_404_suspect($file, 1700000000);
    $suspects = molavi_suspect_image_files();
    r3_check('b-suspect-recorded', ($suspects[$file] ?? null) === 1700000000, 'suspects=' . json_encode($suspects));
    molavi_note_image_404_suspect($file, 1700005000);
    $suspects = molavi_suspect_image_files();
    r3_check('b-suspect-first-sighting-stands', ($suspects[$file] ?? null) === 1700000000, 'suspects=' . json_encode($suspects));
    molavi_note_image_404_suspect('bad!', 1700000000);
    r3_check('b-suspect-invalid-ignored', !isset(molavi_suspect_image_files()['bad!']));
    molavi_clear_image_404_suspect($file);
    r3_check('b-suspect-cleared', !isset(molavi_suspect_image_files()[$file]));
    // Marking dead clears the suspect: a confirmed file leaves no stale suspect.
    molavi_note_image_404_suspect($file, 1700000000);
    molavi_mark_image_dead($file);
    r3_check('b-dead-listed', isset(molavi_dead_image_files()[$file]));
    r3_check('b-dead-clears-suspect', !isset(molavi_suspect_image_files()[$file]));
    // Re-marking dead is a no-op and keeps the list valid.
    molavi_mark_image_dead($file);
    r3_check('b-dead-idempotent', isset(molavi_dead_image_files()[$file]) && count(molavi_dead_image_files()) === 1);
} else {
    foreach (['b-suspect-recorded', 'b-suspect-first-sighting-stands', 'b-suspect-invalid-ignored', 'b-suspect-cleared', 'b-dead-listed', 'b-dead-clears-suspect', 'b-dead-idempotent'] as $n) r3_check($n, false, 'suspect helpers missing');
}

echo "\n$pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
