<?php
/** Product step-status trace (owner request 2026-11-25): stub harness.
 * Run: php tests/native_product_step_trace.php
 *
 * Fixtures mirror the real live values (2026-11-25 14:01 probe):
 *  - tuna خوش خوراک (p_bae3dc55fca5c1df9d6383f0, woo 100): sale 190,000,
 *    shelf 290,000, pack 24, owner-confirmed, live admitted, verdict ok,
 *    closeness 0.8593 (arm regular_expiry), published.
 *  - cappuccino (woo 105): sale 800,000, regular 1,101,520 (market
 *    55,076/unit), quotes disagree 23.6%, shadow would_admit flagged,
 *    admission hold (Arm 10), published but blocked for fresh admission.
 *  - coffee بلک تیمز ۴۰ عددی (woo 110, draft): sale 900,000, regular
 *    950,000 (spread 5.26%, thin), quotes disagree 74.7%, Arm 7 hold,
 *    no gA log row, no verdict, never published.
 * Product ids for cappuccino/coffee are fixture ids (the live ids are not
 * recorded); every asserted price, spread, date and verdict is the real one.
 */
declare(strict_types=1);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-step-trace-test-'.bin2hex(random_bytes(5)));
define('ABSPATH', MOLAVI_STATE_ROOT.'/wp/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
function add_action(...$args) {}
function add_filter(...$args) {}
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function wp_parse_url($url, $component = -1) { return $component === -1 ? parse_url($url) : parse_url($url, $component); }
function wp_http_validate_url($url) { return $url; }
function wp_safe_remote_request($url, $args = []) { return $GLOBALS['pages'][$url] ?? ['status'=>404, 'body'=>'']; }
function is_wp_error($value) { return false; }
function home_url($path = '') { return 'https://staging.molavisale.ir/'.$path; }
function get_current_user_id() { return 7; }
function get_post_meta($id, $key, $single = false) { return ''; }
function get_edit_post_link($id, $context = 'display') { return ''; }
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }
function molavi_domain_ai_review_cases(...$args) { return []; }
function molavi_domain_http($method, $url, $args = []) { throw new RuntimeException('unexpected live fetch: '.$url); }
// No reference match in any fixture: the reference price is 0.
function molavi_domain_reference_active(): ?array { return ['rows'=>[]]; }
function molavi_domain_matching_reference(array $row): array { return ['reference_id'=>'', 'consumer_price'=>0]; }
// Pricing band, mirroring includes/pricing.php defaults (min_margin 0.12,
// min_profit 5000): discountable only when the spread leaves room for the
// minimum profit above the supplier price.
function molavi_price_settings(): array {
    return ['min_margin'=>0.12, 'min_profit'=>5000, 'spread_low'=>0.10, 'spread_high'=>0.60,
        'profit_share_low'=>0.70, 'profit_share_high'=>0.35, 'opening_share_low'=>0.20, 'opening_share_high'=>0.25];
}
function molavi_band(int $cost, int $shelf): array {
    $blank = ['floor'=>0, 'ceiling'=>0, 'max'=>0.0, 'discountable'=>false];
    if ($cost <= 0 || $shelf <= 0 || $shelf <= $cost) return $blank;
    $profit = max($cost * 0.12, 5000.0);
    if ($cost + $profit >= $shelf) return $blank;
    return ['floor'=>0, 'ceiling'=>0, 'max'=>0.0, 'discountable'=>true];
}
// Woo catalog fixture: tuna + cappuccino published, coffee a draft.
// molavi_domain_published_review_catalog() calls this with (true) for the
// published-only list; the trace builder calls it with (false, true) for
// the full registered catalog (drafts included).
function molavi_catalog_identity_snapshot(bool $publishedOnly = false, bool $includeRegistered = false): array {
    $rows = [
        ['source_record_id'=>'p_bae3dc55fca5c1df9d6383f0', 'published'=>true, 'woo_product_id'=>100, 'purchasable'=>true],
        ['source_record_id'=>'p_cappuccino', 'published'=>true, 'woo_product_id'=>105, 'purchasable'=>true],
        ['source_record_id'=>'p_coffee_black', 'published'=>false, 'woo_product_id'=>110, 'purchasable'=>false],
    ];
    return $publishedOnly ? array_values(array_filter($rows, static fn($r) => $r['published'])) : $rows;
}
function wc_get_products($args = []) { return [100, 105, 110]; }
$root = __DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-publisher', 'domain-products',
          'domain-sale-offers', 'domain-market-basalam', 'domain-market-prices',
          'domain-market-sources', 'domain-autopilot', 'domain-product-step-trace',
          'domain-ai-published-reviews', 'domain-source-projection',
          'domain-reference-parser', 'domain-orders'] as $module) {
    require $root.'molavi-'.$module.'.php';
}
function check($condition, string $label): void { if (!$condition) throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label."\n"; }
function sheet_row(array $values): array {
    static $headers = null;
    $headers ??= molavi_domain_product_headers();
    $row = array_fill(0, count($headers), '');
    foreach ($values as $key => $value) $row[array_search($key, $headers, true)] = $value;
    return $row;
}
function offer_row(array $values): array {
    static $headers = null;
    $headers ??= molavi_domain_offer_headers();
    $row = array_fill(0, count($headers), '');
    foreach ($values as $key => $value) $row[array_search($key, $headers, true)] = $value;
    return $row;
}
function db_exec(string $store, string $sql, array $params = []): void {
    molavi_domain_tx($store, static fn(SQLite3 $db) => molavi_domain_sql($db, $sql, $params));
}
function trace_step(array $trace, string $key): array {
    foreach ($trace['steps'] as $step) if (($step['key'] ?? '') === $key) return $step;
    throw new RuntimeException('missing step '.$key.' for '.$trace['product_id']);
}
function step_fact(array $step, string $label) {
    foreach ($step['facts'] as $fact) if (($fact[0] ?? '') === $label) return $fact[1];
    return null;
}
function step_fact_kind(array $step, string $label): ?string {
    foreach ($step['facts'] as $fact) if (($fact[0] ?? '') === $label) return $fact[2] ?? null;
    return null;
}
// Content hash of every table the trace builder reads, so a write is caught.
function db_snapshot(): string {
    $hash = [];
    foreach (['pipeline' => ['supplier_price_evidence', 'supplier_link_checks', 'market_agreement_checks',
                'autopilot_log', 'autopilot_holds'],
             'reference' => ['market_price_lookups', 'market_product_sources']] as $store => $tables) {
        foreach ($tables as $table) {
            $rows = molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_rows($db, 'SELECT * FROM '.$table));
            $hash[$store.':'.$table] = molavi_domain_hash($rows);
        }
    }
    return molavi_domain_hash($hash);
}

$now = time();
$day = 86400;
$iso = static fn(int $t): string => gmdate('Y-m-d\\TH:i:s+00:00', $t);

// --- Fixture sheet records (real prices, spreads, verdicts) ---
$products = [
    ['Product ID'=>'p_bae3dc55fca5c1df9d6383f0', 'Product Name'=>'تن ماهی خوش خوراک، ۲۴ عددی',
     'Sale Price'=>'190000', 'Consumer Price'=>'290000', 'Currency'=>'تومان', 'Package Count'=>'24',
     'Packaging'=>'کارتن ۲۴ عددی', 'Channel Username'=>'bonakdar_javan',
     'Latest Offer ID'=>'owner-confirm:df397634', 'Source Timestamp Basis'=>'supplier_channel_update',
     'Message Timestamp'=>$iso($now - $day - 3600), 'Source Post URL'=>'https://t.me/bonakdar_javan/100',
     'Status'=>'imported', 'Stock Status'=>'', 'Reference Match ID'=>'', 'Reference Approval State'=>'',
     'Image File ID'=>'img-tuna'],
    ['Product ID'=>'p_cappuccino', 'Product Name'=>'کاپوچینو ۲۰ عددی تیمز',
     'Sale Price'=>'800000', 'Consumer Price'=>'1101520', 'Currency'=>'تومان', 'Package Count'=>'20',
     'Packaging'=>'بسته ۲۰ عددی', 'Channel Username'=>'top_shop_rahimi',
     'Latest Offer ID'=>'owner-confirm:cappuccino-1', 'Source Timestamp Basis'=>'supplier_channel_update',
     'Message Timestamp'=>$iso($now - $day), 'Source Post URL'=>'https://t.me/top_shop_rahimi/1343',
     'Status'=>'imported', 'Stock Status'=>'', 'Reference Match ID'=>'', 'Reference Approval State'=>'',
     'Image File ID'=>'img-cappuccino'],
    ['Product ID'=>'p_coffee_black', 'Product Name'=>'قهوه بلک تیمز ۴۰ عددی',
     'Sale Price'=>'900000', 'Consumer Price'=>'950000', 'Currency'=>'تومان', 'Package Count'=>'40',
     'Packaging'=>'کارتن ۴۰ عددی', 'Channel Username'=>'top_shop_rahimi',
     'Latest Offer ID'=>'offer-coffee-1', 'Source Timestamp Basis'=>'supplier_channel_update',
     'Message Timestamp'=>$iso($now - 12 * 3600), 'Source Post URL'=>'https://t.me/top_shop_rahimi/1400',
     'Status'=>'imported', 'Stock Status'=>'', 'Reference Match ID'=>'', 'Reference Approval State'=>'',
     'Image File ID'=>'img-coffee'],
];
$offers = [
    ['Offer ID'=>'owner-confirm:df397634', 'Product ID'=>'p_bae3dc55fca5c1df9d6383f0',
     'Sale Price'=>'190000', 'Consumer Price'=>'290000', 'Package Count'=>'24',
     'Owner Decision ID'=>'df397634', 'Owner Decision At'=>$iso($now - $day),
     'Source Timestamp Basis'=>'supplier_channel_update', 'Message Timestamp'=>$iso($now - $day - 3600)],
    ['Offer ID'=>'owner-confirm:cappuccino-1', 'Product ID'=>'p_cappuccino',
     'Sale Price'=>'800000', 'Consumer Price'=>'1101520', 'Package Count'=>'20',
     'Owner Decision ID'=>'cappuccino-1', 'Owner Decision At'=>$iso($now - $day),
     'Source Timestamp Basis'=>'supplier_channel_update', 'Message Timestamp'=>$iso($now - $day)],
    ['Offer ID'=>'offer-coffee-1', 'Product ID'=>'p_coffee_black',
     'Sale Price'=>'900000', 'Consumer Price'=>'950000', 'Package Count'=>'40',
     'Owner Decision ID'=>'', 'Owner Decision At'=>'',
     'Source Timestamp Basis'=>'supplier_channel_update', 'Message Timestamp'=>$iso($now - 12 * 3600)],
];
$GLOBALS['sheets'] = ['Products'=>[molavi_domain_product_headers()], 'Offers'=>[molavi_domain_offer_headers()]];
foreach ($products as $p) $GLOBALS['sheets']['Products'][] = sheet_row($p);
foreach ($offers as $o) $GLOBALS['sheets']['Offers'][] = offer_row($o);
foreach (['reference', 'pipeline'] as $store) { $db = new SQLite3(molavi_state_root().'/'.$store.'.sqlite3'); $db->close(); }
// Autopilot tables are created by the ensure function, not the schema file.
molavi_domain_autopilot_ensure_tables();

// Revision each evidence row must match (computed the same way live does).
$records = molavi_domain_records($GLOBALS['sheets']['Products']);
$revisions = [];
foreach ($records as $record) $revisions[$record['Product ID']] = molavi_domain_source_revision($record);

// --- Seed pipeline tables ---
db_exec('pipeline', 'INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)',
    ['p_bae3dc55fca5c1df9d6383f0', $revisions['p_bae3dc55fca5c1df9d6383f0'], $iso($now - $day), 'manual', 'wp:4']);
db_exec('pipeline', 'INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)',
    ['p_cappuccino', $revisions['p_cappuccino'], $iso($now - $day + 1800), 'manual', 'wp:4']);
db_exec('pipeline', 'INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)',
    ['p_coffee_black', $revisions['p_coffee_black'], $iso($now - 12 * 3600), 'source', null]);
db_exec('pipeline', 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,route,checked_at,next_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['p_bae3dc55fca5c1df9d6383f0', 'owner-confirm:df397634', 'تن ماهی خوش خوراک، ۲۴ عددی', 'https://t.me/bonakdar_javan/100',
     $revisions['p_bae3dc55fca5c1df9d6383f0'], '190000', '', 'unchanged', 'identical', '190000', 'railway', $now - 1800, $now + $day, $now - $day]);
db_exec('pipeline', 'INSERT INTO market_agreement_checks (product_id,offer_id,title,source_revision,stored_sale_price,outcome,reason_code,quote_url,quote_price,implied_spread,checked_at,next_at,verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['p_bae3dc55fca5c1df9d6383f0', 'owner-confirm:df397634', 'تن ماهی خوش خوراک، ۲۴ عددی', $revisions['p_bae3dc55fca5c1df9d6383f0'],
     '190000', 'agree', 'agreement', 'https://basalam.com/tuna', 290000, 0.345, $now - 1800, $now + $day, $iso($now - 1800), $now - $day]);
db_exec('pipeline', 'INSERT INTO market_agreement_checks (product_id,offer_id,title,source_revision,stored_sale_price,outcome,reason_code,checked_at,next_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
    ['p_cappuccino', 'owner-confirm:cappuccino-1', 'کاپوچینو ۲۰ عددی تیمز', $revisions['p_cappuccino'],
     '800000', 'disagree', 'quotes_disagree', $now - 3600, $now + $day, $now - $day]);
db_exec('pipeline', 'INSERT INTO market_agreement_checks (product_id,offer_id,title,source_revision,stored_sale_price,outcome,reason_code,checked_at,next_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
    ['p_coffee_black', 'offer-coffee-1', 'قهوه بلک تیمز ۴۰ عددی', $revisions['p_coffee_black'],
     '900000', 'disagree', 'quotes_disagree', $now - 3600, $now + $day, $now - $day]);
// gA log rows: tuna live admitted (verdict ok, closeness 0.8593, arm
// regular_expiry); cappuccino shadow would_admit flagged (closeness
// -3.7207, arm quote_spread). Coffee has no gA row — Arm 7 blocks it.
$tunaInputs = ['spread'=>0.3448, 'supplier_price'=>190000, 'regular_price'=>290000, 'closeness'=>0.8593,
    'closeness_arm'=>'regular_expiry', 'closeness_arms'=>['spread'=>1.873, 'supplier_age'=>0.9286, 'regular_expiry'=>0.8593],
    'risk_tier'=>'green'];
db_exec('pipeline', 'INSERT INTO autopilot_log (rule_id,gate,product_id,case_id,inputs_json,confidence,action,result,verdict,verdict_reason,created_at,provenance,global_hold,closeness,closeness_arm,risk_tier) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['gA', 'admission', 'p_bae3dc55fca5c1df9d6383f0', '', molavi_domain_json($tunaInputs), 0.8593, 'live', 'admitted', 'ok',
     'قیمت مصرف‌کننده و تأمین‌کننده با قوانین حاشیه سود مطابقت دارد و انتشار محصول تأیید می‌شود.', $now - 3600, 'live', 0, 0.8593, 'regular_expiry', 'green']);
$cappInputs = ['spread'=>0.2737, 'supplier_price'=>800000, 'regular_price'=>1101520, 'closeness'=>-3.7207,
    'closeness_arm'=>'quote_spread', 'closeness_arms'=>['spread'=>1.281, 'supplier_age'=>0.9286, 'regular_expiry'=>0.9, 'quote_spread'=>-3.7207],
    'risk_tier'=>'red', 'quote_list'=>'55076 (basalam, pack 20); 65000 (basalam, pack 20); 70416 (basalam, pack 20)'];
db_exec('pipeline', 'INSERT INTO autopilot_log (rule_id,gate,product_id,case_id,inputs_json,confidence,action,result,verdict,verdict_reason,created_at,provenance,global_hold,closeness,closeness_arm,risk_tier) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['gA', 'admission', 'p_cappuccino', '', molavi_domain_json($cappInputs), 0.0, 'shadow', 'would_admit', 'flag',
     'قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.', $now - 7200, 'live', 0, -3.7207, 'quote_spread', 'red']);
db_exec('pipeline', 'INSERT INTO autopilot_holds (product_id,gate,reason,priority,score,created_at) VALUES (?,?,?,?,?,?)',
    ['p_cappuccino', 'admission', 'کالا در توقف است.', 0.9941, 0.0, $now - 3600]);
db_exec('pipeline', 'INSERT INTO autopilot_holds (product_id,gate,reason,priority,score,created_at) VALUES (?,?,?,?,?,?)',
    ['p_coffee_black', 'admission', 'اختلاف قیمت برای تخفیف کافی نیست.', 0.5, 0.0526, $now - 3600]);

// --- Seed reference tables (market quotes + cappuccino market source) ---
$quote = static fn(int $unit, int $pack): array => ['title'=>'quote', 'url'=>'https://basalam.com/q',
    'merchant'=>'basalam', 'unit_price'=>$unit, 'current_price'=>$unit * $pack, 'pack_count'=>$pack,
    'regular_price'=>$unit * $pack, 'regular_unit_price'=>$unit, 'source_currency'=>'IRR',
    'image_url'=>null, 'verified_at'=>$now];
foreach ($records as $record) {
    $id = $record['Product ID'];
    $identity = molavi_domain_market_identity($record);
    check($identity !== null, 'market identity for '.$id);
    $key = molavi_domain_hash($identity);
    $quotes = match($id) {
        'p_bae3dc55fca5c1df9d6383f0' => [$quote(12083, 24), $quote(12500, 24)],
        'p_cappuccino' => [$quote(55076, 20), $quote(65000, 20), $quote(70416, 20)],
        'p_coffee_black' => [$quote(20000, 40), $quote(25000, 40), $quote(38675, 40)],
    };
    db_exec('reference', 'INSERT INTO market_price_lookups (lookup_key,identity_json,active,priority,state,next_at,checked_at,candidates_json) VALUES (?,?,?,?,?,?,?,?)',
        [$key, molavi_domain_json($identity), 1, 0, 'ready', $now, $now, molavi_domain_json($quotes)]);
}
// Cappuccino regular price is proven by the market source (55,076/unit,
// verified 12h ago — inside the 24h check window).
$cappIdentity = molavi_domain_market_identity($records[1]);
db_exec('reference', 'INSERT INTO market_product_sources (target,product_id,offer_id,title,identity_json,url,regular_price,next_at,checked_at,verified_at,changed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
    ['product:p_cappuccino', 'p_cappuccino', 'owner-confirm:cappuccino-1', 'کاپوچینو ۲۰ عددی تیمز',
     molavi_domain_json($cappIdentity), 'https://basalam.com/cappuccino', 55076, $now + $day, $now - 12 * 3600, $now - 12 * 3600, $now - 12 * 3600]);

$before = db_snapshot();
$traces = molavi_domain_product_step_traces($GLOBALS['sheets']);
$after = db_snapshot();
check($before === $after, 'trace builder writes nothing (DB unchanged)');
check(count($traces) === 3, 'three traces built');

// --- Tuna: live admitted, verdict ok, published ---
$tuna = $traces['p_bae3dc55fca5c1df9d6383f0'];
check(count($tuna['steps']) === 7, 'tuna has seven steps');
$s = trace_step($tuna, 'supplier_price');
check($s['status'] === 'ok' && $s['status_label'] === 'جاری', 'tuna supplier price current');
check(step_fact($s, 'قیمت ثبت‌شده') === 190000 && step_fact_kind($s, 'قیمت ثبت‌شده') === 'amount', 'tuna sale price 190000');
check(step_fact($s, 'روش تأیید') === 'تأیید مالک', 'tuna verified by owner confirm');
check(step_fact($s, 'بررسی لینک') === 'بدون تغییر', 'tuna link check unchanged');
$s = trace_step($tuna, 'regular_price');
check($s['status'] === 'ok', 'tuna regular price current');
check(step_fact($s, 'قیمت عادی') === 290000, 'tuna regular price 290000');
check(step_fact($s, 'منبع') === 'شیت (قیمت مصرف‌کننده)', 'tuna regular price from sheet');
check(abs((float)step_fact($s, 'اختلاف با قیمت فروش') - 0.3448) < 0.001, 'tuna spread 34.48%');
check(step_fact($s, 'قابلیت تخفیف') === 'دارد', 'tuna discountable');
$s = trace_step($tuna, 'market_agreement');
check($s['status'] === 'ok' && step_fact($s, 'بررسی توافق') === 'همخوان است', 'tuna market agreement agrees');
check(step_fact($s, 'تعداد نقل‌قول') === 2, 'tuna two market quotes');
check(step_fact($s, 'میانهٔ نقل‌قول (بسته)') === 300000, 'tuna median quote pack 300000');
check(abs((float)step_fact($s, 'اختلاف ضمنی') - 0.345) < 0.001, 'tuna implied spread 34.5%');
$s = trace_step($tuna, 'availability');
check($s['status'] === 'ok', 'tuna available (unknown stock passes)');
$s = trace_step($tuna, 'admission');
check($s['status'] === 'ok' && $s['status_label'] === 'پذیرفته شد', 'tuna admission admitted');
check(step_fact($s, 'نتیجه') === 'پذیرفته شد (زنده)', 'tuna live admission');
check(abs((float)step_fact($s, 'نزدیکی به آستانه') - 0.8593) < 0.0001, 'tuna closeness 0.8593');
check(step_fact($s, 'تنگ‌ترین بازو') === 'regular_expiry', 'tuna tightest arm regular_expiry');
check(step_fact($s, 'درجهٔ ریسک') === 'کم‌ریسک', 'tuna risk tier green');
$s = trace_step($tuna, 'verdict');
check($s['status'] === 'ok' && step_fact($s, 'داوری') === 'تأیید شد', 'tuna verdict ok');
$s = trace_step($tuna, 'publication');
check($s['status'] === 'ok' && step_fact($s, 'وضعیت فروشگاه') === 'منتشر شده', 'tuna published');
check(step_fact($s, 'شناسهٔ ووکامرس') === 100, 'tuna woo id 100');

// --- Cappuccino: published but blocked, verdict flagged ---
$capp = $traces['p_cappuccino'];
$s = trace_step($capp, 'supplier_price');
check($s['status'] === 'ok', 'cappuccino supplier price current');
check(step_fact($s, 'قیمت ثبت‌شده') === 800000, 'cappuccino sale price 800000');
check(step_fact($s, 'روش تأیید') === 'تأیید مالک', 'cappuccino verified by owner confirm');
$s = trace_step($capp, 'regular_price');
check($s['status'] === 'ok', 'cappuccino regular price current');
check(step_fact($s, 'قیمت عادی') === 1101520, 'cappuccino regular price 1101520');
check(step_fact($s, 'منبع') === 'بازار (Basalam)', 'cappuccino regular price proven by market');
$s = trace_step($capp, 'market_agreement');
check($s['status'] === 'warn', 'cappuccino market agreement disagrees');
check(step_fact($s, 'تعداد نقل‌قول') === 3, 'cappuccino three market quotes');
check(abs((float)step_fact($s, 'پراکندگی نقل‌قول‌ها') - 0.236) < 0.001, 'cappuccino quote spread 23.6%');
check(step_fact($s, 'نتیجهٔ پراکندگی') === 'خارج از باند مجاز', 'cappuccino quote spread outside the 5% band');
$s = trace_step($capp, 'admission');
check($s['status'] === 'bad' && $s['status_label'] === 'توقف پذیرش بعدی', 'cappuccino admission blocked by hold');
check(step_fact($s, 'نتیجه') === 'همهٔ گیت‌ها سبز — آمادهٔ پذیرش', 'cappuccino shadow evaluation still shown');
check(step_fact($s, 'توقف فعلی') === 'کالا در توقف است.', 'cappuccino current hold shown');
check(abs((float)step_fact($s, 'نزدیکی به آستانه') - -3.7207) < 0.0001, 'cappuccino closeness -3.7207');
check(step_fact($s, 'درجهٔ ریسک') === 'پرخطر', 'cappuccino risk tier red');
$s = trace_step($capp, 'verdict');
check($s['status'] === 'bad' && step_fact($s, 'داوری') === 'پرچم خورد', 'cappuccino verdict flag');
$s = trace_step($capp, 'publication');
check($s['status'] === 'ok' && step_fact($s, 'وضعیت فروشگاه') === 'منتشر شده', 'cappuccino published despite the block');
check(step_fact($s, 'شناسهٔ ووکامرس') === 105, 'cappuccino woo id 105');

// --- Coffee: thin spread, Arm 7 hold, draft, no verdict ---
$coffee = $traces['p_coffee_black'];
$s = trace_step($coffee, 'supplier_price');
check($s['status'] === 'ok', 'coffee supplier price current');
check(step_fact($s, 'قیمت ثبت‌شده') === 900000, 'coffee sale price 900000');
check(step_fact($s, 'روش تأیید') === 'مشاهدهٔ پیام منبع', 'coffee verified by source observation');
$s = trace_step($coffee, 'regular_price');
check($s['status'] === 'ok', 'coffee regular price current');
check(step_fact($s, 'قیمت عادی') === 950000, 'coffee regular price 950000');
check(abs((float)step_fact($s, 'اختلاف با قیمت فروش') - 0.0526) < 0.001, 'coffee spread 5.26%');
check(step_fact($s, 'قابلیت تخفیف') === 'ندارد', 'coffee not discountable (thin spread)');
$s = trace_step($coffee, 'market_agreement');
check($s['status'] === 'warn', 'coffee market agreement disagrees');
check(abs((float)step_fact($s, 'پراکندگی نقل‌قول‌ها') - 0.747) < 0.001, 'coffee quote spread 74.7%');
$s = trace_step($coffee, 'admission');
check($s['status'] === 'bad' && $s['status_label'] === 'توقف پذیرش بعدی', 'coffee admission blocked');
check(step_fact($s, 'توقف فعلی') === 'اختلاف قیمت برای تخفیف کافی نیست.', 'coffee Arm 7 hold reason shown');
$s = trace_step($coffee, 'verdict');
check($s['status'] === 'info' && step_fact($s, 'داوری') === 'هنوز داوری ثبت نشده', 'coffee has no verdict yet');
$s = trace_step($coffee, 'publication');
check($s['status'] === 'warn' && step_fact($s, 'وضعیت فروشگاه') === 'پیش‌نویس / پنهان (رکورد ووکامرس هست، منتشر نیست)', 'coffee is a draft');
check(step_fact($s, 'شناسهٔ ووکامرس') === 110, 'coffee woo id 110');
check(step_fact($s, 'مانع انتشار') === 'اختلاف قیمت برای تخفیف کافی نیست', 'coffee blocker is thin spread');

// --- Autopilot summary + history on the card (owner request 2026-11-26) ---
// The decisions tab dissolves into the Products cards: each card's trace
// now carries the autopilot summary (state, tier, verdict, reason) and the
// product's decision history (up to 5 rows, all gates, newest first).
// Default settings in this harness: master off, gA shadow — so the mode
// is 'خاموش' and active is false, while the decision state still shows.
$tunaAuto = $tuna['autopilot'] ?? null;
check(is_array($tunaAuto), 'tuna trace carries an autopilot summary');
check($tunaAuto['state'] === 'admitted' && $tunaAuto['state_label'] === 'پذیرفته شد (زنده)', 'tuna autopilot state admitted live');
check($tunaAuto['tier'] === 'green' && $tunaAuto['tier_label'] === 'کم‌ریسک', 'tuna autopilot tier green');
check($tunaAuto['verdict'] === 'ok' && $tunaAuto['verdict_label'] === 'تأیید شد', 'tuna autopilot verdict ok');
check(str_contains($tunaAuto['verdict_reason'], 'انتشار محصول تأیید می‌شود'), 'tuna verdict reason carried');
check(str_contains($tunaAuto['reason'], 'انقضای قیمت عادی') && str_contains($tunaAuto['reason'], '0.859'), 'tuna reason names the tightest arm (regular_expiry) and margin');
check(abs($tunaAuto['closeness'] - 0.8593) < 0.0001 && $tunaAuto['arm'] === 'regular_expiry', 'tuna closeness/arm in the summary');
check($tunaAuto['hold_reason'] === '', 'tuna has no hold reason');
check($tunaAuto['active'] === false && $tunaAuto['master_on'] === false && $tunaAuto['mode_label'] === 'خاموش', 'tuna mode is off under default settings');
check(count($tunaAuto['history']) === 1, 'tuna history has one row');
$h = $tunaAuto['history'][0];
check($h['action'] === 'live' && $h['result'] === 'admitted' && $h['verdict'] === 'ok', 'tuna history row is the live admission');
check($h['risk_tier'] === 'green' && $h['risk_tier_stored'] === 'green', 'tuna history row tier green');
check(str_contains($h['risk_reason'], 'انقضای قیمت عادی'), 'tuna history row reason names the arm');
check(abs($h['confidence'] - 0.8593) < 0.0001 && (int)$h['reviewed_at'] === 0, 'tuna history row confidence + unreviewed');

$cappAuto = $capp['autopilot'] ?? null;
check(is_array($cappAuto), 'cappuccino trace carries an autopilot summary');
check($cappAuto['state'] === 'blocked' && $cappAuto['state_label'] === 'مسدود', 'cappuccino autopilot state blocked (hold wins over would_admit)');
check($cappAuto['tier'] === 'red' && $cappAuto['tier_label'] === 'پرخطر', 'cappuccino autopilot tier red');
check($cappAuto['verdict'] === 'flag' && $cappAuto['verdict_label'] === 'پرچم خورد', 'cappuccino autopilot verdict flag');
check(str_contains($cappAuto['verdict_reason'], 'بالاتر از نقل‌قول‌های بازار'), 'cappuccino verdict reason carried');
check(str_contains($cappAuto['reason'], 'پراکندگی نقل‌قول‌ها') && str_contains($cappAuto['reason'], '-3.721'), 'cappuccino reason names the tightest arm (quote_spread) and margin');
check($cappAuto['hold_reason'] === 'کالا در توقف است.', 'cappuccino hold reason shown');
check(abs($cappAuto['closeness'] - -3.7207) < 0.0001 && $cappAuto['arm'] === 'quote_spread', 'cappuccino closeness/arm in the summary');
check(count($cappAuto['history']) === 1, 'cappuccino history has one row');
$h = $cappAuto['history'][0];
check($h['action'] === 'shadow' && $h['result'] === 'would_admit' && $h['verdict'] === 'flag', 'cappuccino history row is the shadow would_admit');
check($h['risk_tier'] === 'red' && $h['risk_tier_stored'] === 'red', 'cappuccino history row tier red');

$coffeeAuto = $coffee['autopilot'] ?? null;
check(is_array($coffeeAuto), 'coffee trace carries an autopilot summary');
check($coffeeAuto['state'] === 'blocked' && $coffeeAuto['state_label'] === 'مسدود', 'coffee autopilot state blocked (Arm 7 hold)');
check($coffeeAuto['tier'] === 'none' && $coffeeAuto['tier_label'] === 'بدون درجه‌بندی', 'coffee autopilot tier none (no decision row)');
check($coffeeAuto['verdict'] === '' && $coffeeAuto['verdict_label'] === 'ثبت نشده', 'coffee has no verdict');
check($coffeeAuto['reason'] === '' && $coffeeAuto['hold_reason'] === 'اختلاف قیمت برای تخفیف کافی نیست.', 'coffee reason empty, hold reason shown');
check($coffeeAuto['closeness'] === null && $coffeeAuto['arm'] === '', 'coffee has no closeness/arm');
check($coffeeAuto['history'] === [], 'coffee history is empty (no log rows)');

// --- Mode: the summary follows master + gA rule mode ---
// Master on + gA live → active, 'فعال — پذیرش زنده'. Master off → 'خاموش'.
// Master on + gA shadow → 'سایه — فقط ثبت می‌کند'. The builder is pure,
// so rebuilding with different settings re-derives the mode.
$GLOBALS['options']['molavi_autopilot_settings_v1'] = ['master_enabled'=>true, 'rule_modes'=>['gA'=>'live']];
$liveMode = molavi_domain_product_step_traces($GLOBALS['sheets'])['p_bae3dc55fca5c1df9d6383f0']['autopilot'];
check($liveMode['active'] === true && $liveMode['master_on'] === true && $liveMode['rule_mode'] === 'live', 'mode live: master on + gA live is active');
check($liveMode['mode_label'] === 'فعال — پذیرش زنده', 'mode live label');
check($liveMode['state'] === 'admitted', 'mode live does not change the decision state');
$GLOBALS['options']['molavi_autopilot_settings_v1'] = ['master_enabled'=>false, 'rule_modes'=>['gA'=>'live']];
$offMode = molavi_domain_product_step_traces($GLOBALS['sheets'])['p_bae3dc55fca5c1df9d6383f0']['autopilot'];
check($offMode['active'] === false && $offMode['master_on'] === false && $offMode['mode_label'] === 'خاموش', 'mode off: master off is خاموش');
$GLOBALS['options']['molavi_autopilot_settings_v1'] = ['master_enabled'=>true, 'rule_modes'=>['gA'=>'shadow']];
$shadowMode = molavi_domain_product_step_traces($GLOBALS['sheets'])['p_bae3dc55fca5c1df9d6383f0']['autopilot'];
check($shadowMode['active'] === false && $shadowMode['master_on'] === true && $shadowMode['rule_mode'] === 'shadow', 'mode shadow: master on + gA shadow is not active');
check($shadowMode['mode_label'] === 'سایه — فقط ثبت می‌کند', 'mode shadow label');
// Restore the default (no stored settings) for the sections below.
unset($GLOBALS['options']['molavi_autopilot_settings_v1']);

// --- Status API hook: view=current attaches the trace, still no writes ---
$beforeApi = db_snapshot();
$current = molavi_domain_status_api('GET', ['view'=>'current'], null, 'test');
check(($current['ok'] ?? false) === true, 'status API current view ok');
$byId = [];
foreach ($current['products'] as $product) $byId[$product['product_id']] = $product;
check(isset($byId['p_bae3dc55fca5c1df9d6383f0']['step_trace']), 'status API attaches tuna trace');
check(count($byId['p_bae3dc55fca5c1df9d6383f0']['step_trace']['steps']) === 7, 'attached tuna trace has seven steps');
check(isset($byId['p_cappuccino']['step_trace']) && isset($byId['p_coffee_black']['step_trace']), 'status API attaches all three traces');
check(db_snapshot() === $beforeApi, 'status API hook writes nothing new');

// --- Snapshot branch: the dashboard path attaches the trace too ---
// The dashboard loads products through /pipeline/dashboard-bootstrap,
// which dispatches product_status with an empty query, so this branch
// (not view=current) is what the workbench actually renders. Capture a
// fresh snapshot from the fixture sheets first (the API's own daily
// checkpoint does exactly this), then the snapshot view must carry the
// same trace per product — and the attachment itself must write nothing.
$capture = molavi_domain_status_capture($GLOBALS['sheets'], 'test-snapshot');
check(($capture['product_count'] ?? 0) === 3, 'snapshot capture holds three products');
$beforeSnapshot = db_snapshot();
$snapshot = molavi_domain_status_api('GET', [], null, 'test');
check(($snapshot['ok'] ?? false) === true, 'status API snapshot view ok');
check(count($snapshot['products'] ?? []) === 3, 'snapshot view returns three products');
$byIdSnap = [];
foreach ($snapshot['products'] as $product) $byIdSnap[$product['product_id']] = $product;
foreach (['p_bae3dc55fca5c1df9d6383f0', 'p_cappuccino', 'p_coffee_black'] as $id) {
    check(isset($byIdSnap[$id]['step_trace']), 'snapshot view attaches trace for '.$id);
    check(count($byIdSnap[$id]['step_trace']['steps']) === 7, 'snapshot trace has seven steps for '.$id);
}
// The attached trace is the same trace view=current returns: same
// builder, same sheets, same rows — only the payload branch differs.
// (Compared on steps, not the whole trace: generated_at is wall-clock.)
check($byIdSnap['p_bae3dc55fca5c1df9d6383f0']['step_trace']['steps'] === $byId['p_bae3dc55fca5c1df9d6383f0']['step_trace']['steps'], 'snapshot tuna trace matches current-view trace');
check($byIdSnap['p_cappuccino']['step_trace']['steps'] === $byId['p_cappuccino']['step_trace']['steps'], 'snapshot cappuccino trace matches current-view trace');
check($byIdSnap['p_coffee_black']['step_trace']['steps'] === $byId['p_coffee_black']['step_trace']['steps'], 'snapshot coffee trace matches current-view trace');
// The snapshot trace also carries the autopilot summary the card renders.
check($byIdSnap['p_bae3dc55fca5c1df9d6383f0']['step_trace']['autopilot']['state'] === 'admitted', 'snapshot tuna trace carries the autopilot summary (admitted)');
check($byIdSnap['p_cappuccino']['step_trace']['autopilot']['state'] === 'blocked', 'snapshot cappuccino trace carries the autopilot summary (blocked)');
check($byIdSnap['p_coffee_black']['step_trace']['autopilot']['state'] === 'blocked', 'snapshot coffee trace carries the autopilot summary (blocked)');
check(count($byIdSnap['p_bae3dc55fca5c1df9d6383f0']['step_trace']['autopilot']['history']) === 1, 'snapshot tuna trace carries the decision history');
check(db_snapshot() === $beforeSnapshot, 'snapshot view hook writes nothing new');

// --- Live tuna shape: 6 ok + 1 info ---
// Live, the tuna has no market-agreement outcome recorded (the runner
// has verified nothing yet), so its market_agreement step is 'info' —
// correct, not a failure. Reproduce that here by dropping the fixture
// 'agree' row and rebuilding: the other six steps stay ok.
db_exec('pipeline', 'DELETE FROM market_agreement_checks WHERE product_id=?', ['p_bae3dc55fca5c1df9d6383f0']);
$liveTuna = molavi_domain_product_step_traces($GLOBALS['sheets'])['p_bae3dc55fca5c1df9d6383f0'];
$liveStatuses = [];
foreach ($liveTuna['steps'] as $step) $liveStatuses[$step['key']] = $step['status'];
check(count($liveTuna['steps']) === 7, 'live-shape tuna still has seven steps');
check($liveStatuses['market_agreement'] === 'info', 'live-shape tuna market agreement is info (no outcome recorded)');
check(count(array_filter($liveStatuses, static fn($s) => $s === 'ok')) === 6, 'live-shape tuna is six ok + one info');
check(trace_step($liveTuna, 'market_agreement')['status_label'] === 'بررسی نشده', 'live-shape tuna market agreement label is not-reviewed');
echo "ALL STEP-TRACE CHECKS PASSED\n";
