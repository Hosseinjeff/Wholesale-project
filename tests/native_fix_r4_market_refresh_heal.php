<?php
/** FIX-R4: market refresh heals missing kept market rows (2026-11-27).
 *
 * The gap: the Q4 market-first leg of auto-verify needs the KEPT market
 * row (reference_product_matches, source 'market', status 'active',
 * identity proof + URL agreeing with the picked listing) — but nothing
 * ever creates one except the pick itself (choose_source's secondary
 * write). Picks made before persistent matching landed (wave 2) have
 * match_basis NULL and no kept row at all — live: 3 picked sources
 * (coffee 23,750, 64,610, cappuccino 55,076), 0 kept market rows — and
 * a pick whose secondary write failed leaves the same gap silently, so
 * the market-first leg could never fire for them. The fix: the daily
 * market refresh, which re-verifies every picked source, (re)creates
 * the missing kept row from the freshly verified quote.
 *
 * Cases (FIXED mode; all sale 80000, sheet consumer 0, pack 1 unless
 * noted; market regular 90000 unless noted):
 *   (a) r4-legacy: legacy source, match_basis NULL -> refresh creates
 *       the kept row (basis 'backfill', status active, URL + signature
 *       agree), stamps the source row match_basis 'backfill' /
 *       matched_by 'system:market-refresh', and the Q4 market-first leg
 *       then fires (eligible, leg market, consumer_price 90000). THE
 *       bug case.
 *   (b) r4-legacy-owner: legacy source with match_basis 'owner_pick'
 *       -> kept row keeps basis 'owner_pick'.
 *   (c) r4-hasrow: kept row already exists -> refresh leaves it
 *       entirely alone (checked_at / price / basis unchanged, no new
 *       match event).
 *   (d) r4-fail: refresh check fails (listing unavailable) -> NO kept
 *       row; the source is flagged needs_review as before.
 *   (e) r4-offeronly: offer-only source (no product) -> no kept row,
 *       refresh still succeeds.
 *   (f) r4-retired: kept row retired -> refresh does NOT resurrect it;
 *       the source row itself is still re-verified.
 *   (g) r4-needsreview: kept row needs_review, same URL -> untouched,
 *       still needs_review; the Q4 leg still does not fire (only a new
 *       decision clears needs_review) and auto-verify stays ineligible.
 *   (h) the 5 disagree shapes (pure classify): two quotes 13.5% apart
 *       -> disagree/quotes_disagree; one quote, no reference ->
 *       disagree/single_quote_no_reference; one quote + reference
 *       within 5% -> agree; two quotes within band -> agree. Plus
 *       Basalam sanity: a quote at/below the sale price is rejected,
 *       a thin quote (<12% implied spread) is thin.
 *   (i) live shape: the three live picked sources (coffee 23,750
 *       basalam.com/p/19370554; 64,610 /17409711; cappuccino 55,076
 *       /17409587), all match_basis NULL -> after refresh each has a
 *       kept row (basis 'backfill').
 *   (j) fresh-pick regression: choose_source with explicit basis
 *       'owner_pick' still creates the kept row itself (new picks were
 *       never broken; the heal only covers legacy/failed writes).
 *
 * Negative control: on pristine 815d7e6 (no heal function) the same
 * file reports the old behaviour — refresh re-verifies but creates NO
 * kept row, the source stays match_basis NULL, and the Q4 leg cannot
 * fire (auto-verify ineligible, reason no_reference_match) — while the
 * classify cases and the fresh-pick regression behave exactly as on
 * the fixed tree. Run: php tests/native_fix_r4_market_refresh_heal.php
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-r4-market-heal-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['wiring_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['wiring_options'][$key] = $value; }
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
    'domain-market-basalam', 'domain-market-prices', 'domain-market-sources', 'domain-market-agreement',
    'domain-supplier-auto-verify'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';

$root = molavi_state_root();
foreach (['pipeline', 'reference'] as $store) {
    $db = new SQLite3($root . '/' . $store . '.sqlite3');
    $db->close();
}
$count = 0;
$fail = 0;
function check($actual, $expected, $label) {
    global $count, $fail;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected)) {
        echo 'FAIL ' . $label . ' actual=' . molavi_domain_json($actual) . ' expected=' . molavi_domain_json($expected) . "\n";
        $fail++;
        return;
    }
    $count++;
}
function check_true($actual, $label) { check($actual, true, $label); }
function cleanup() {
    global $root;
    foreach (glob($root . '/*') as $file) unlink($file);
    @rmdir($root);
}

$now = molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$nowInt = (int) $now;

/* --- Fixtures: Products + Offers (sale 80000, consumer 0 = missing
 * regular price, pack 1 unless the name carries a count). --- */
function product_record($id, $name, $brand, $category, $variant, $volume, $offer, $extra = []) {
    return array_merge(['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => 0, 'Sale Price' => 80000, 'Status' => 'imported', 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Channel' => 'testchannel', 'Message Timestamp' => '2026-11-20T08:00:00+00:00',
        'Source Timestamp Basis' => '', 'Reference Match ID' => '', 'Reference Snapshot ID' => '',
        'Reference Approval State' => '', 'Reference Decision ID' => '', 'Reference Price Role' => '',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''], $extra);
}
function offer_record($id, $name, $brand, $category, $variant, $volume, $productId) {
    return ['Offer ID' => $id, 'Product ID' => $productId, 'Product Name' => $name, 'Brand' => $brand,
        'Category' => $category, 'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1,
        'Packaging' => '', 'Sale Price' => 80000, 'Consumer Price' => 0, 'Price' => 80000, 'Actual Price' => '',
        'Status' => 'imported', 'Review Reason' => '', 'Channel Username' => 'testchannel', 'Channel' => 'testchannel',
        'Message ID' => 'msg-' . $id, 'Batch ID' => 'batch-1', 'Message Timestamp' => '2026-11-20T00:00:00+00:00',
        'Import Timestamp' => '2026-11-20T00:00:00+00:00', 'Original Message' => 'test',
        'Source Post URL' => '', 'Reference Snapshot ID' => '', 'Reference Match State' => '', 'Reference Match ID' => '',
        'Reference Price Role Hint' => '', 'Reference Decision ID' => '', 'Owner Decision ID' => '', 'Derived From Offer ID' => ''];
}
$products = [
    product_record('r4-legacy', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 'offer-r4-legacy'),
    product_record('r4-legacy-owner', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 'offer-r4-legacy-owner'),
    product_record('r4-hasrow', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 'offer-r4-hasrow'),
    product_record('r4-fail', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 'offer-r4-fail'),
    product_record('r4-retired', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 'offer-r4-retired'),
    product_record('r4-needsreview', 'چای سبز لیپتون', 'لیپتون', 'چای', '', '100 گرم', 'offer-r4-needsreview'),
    product_record('r4-live-coffee', 'قهوه بلک تیمز ۴۰ عددی', 'تیمز', 'قهوه', '', '', 'offer-r4-live-coffee'),
    product_record('r4-live-64610', 'کالای نمونه سوم', '', 'خواربار', '', '', 'offer-r4-live-64610'),
    product_record('r4-live-cappuccino', 'کاپوچینو ۲۰ عددی تیمز', 'تیمز', 'قهوه', '', '', 'offer-r4-live-cappuccino'),
    product_record('r4-freshpick', 'خرما مضافتی بم', '', 'خرما', '', '900 گرم', 'offer-r4-freshpick'),
];
$offers = [
    offer_record('offer-r4-legacy', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 'r4-legacy'),
    offer_record('offer-r4-legacy-owner', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 'r4-legacy-owner'),
    offer_record('offer-r4-hasrow', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 'r4-hasrow'),
    offer_record('offer-r4-fail', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 'r4-fail'),
    offer_record('offer-r4-retired', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 'r4-retired'),
    offer_record('offer-r4-needsreview', 'چای سبز لیپتون', 'لیپتون', 'چای', '', '100 گرم', 'r4-needsreview'),
    offer_record('offer-r4-live-coffee', 'قهوه بلک تیمز ۴۰ عددی', 'تیمز', 'قهوه', '', '', 'r4-live-coffee'),
    offer_record('offer-r4-live-64610', 'کالای نمونه سوم', '', 'خواربار', '', '', 'r4-live-64610'),
    offer_record('offer-r4-live-cappuccino', 'کاپوچینو ۲۰ عددی تیمز', 'تیمز', 'قهوه', '', '', 'r4-live-cappuccino'),
    offer_record('offer-r4-freshpick', 'خرما مضافتی بم', '', 'خرما', '', '900 گرم', 'r4-freshpick'),
];
$GLOBALS['wiring_tables'] = [
    'Products' => molavi_domain_rows_from_records(molavi_domain_product_headers(), $products),
    'Offers' => molavi_domain_rows_from_records(molavi_domain_offer_headers(), $offers),
    'Shop' => [],
];
$sheetRecords = molavi_domain_records($GLOBALS['wiring_tables']['Products']);
$revision = [];
foreach ($sheetRecords as $r) $revision[(string)$r['Product ID']] = molavi_domain_source_revision($r);
$recordById = static fn($id) => array_values(array_filter(molavi_domain_records($GLOBALS['wiring_tables']['Products']),
    static fn($r) => $r['Product ID'] === $id))[0];
function offer_for(string $id, array $sheetRecords, int $nowInt): array {
    foreach (molavi_domain_freshness($sheetRecords, (float)$nowInt, false) as $offer)
        if ($offer['product_id'] === $id) return $offer;
    return [];
}
function db_row(string $store, string $sql, array $params = []): ?array {
    return molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_row($db, $sql, $params));
}
function db_count(string $store, string $sql, array $params = []): int {
    return (int)(db_row($store, $sql, $params)['n'] ?? 0);
}

/* --- Seed picked market sources (legacy shape: match_basis NULL) --- */
function seed_market_source(string $pid, string $offerId, int $regular, string $url, ?string $basis, int $verifiedAt, string $target = ''): void {
    global $nowInt, $recordById;
    $record = $pid !== '' ? $recordById($pid) : null;
    $identity = $record ? molavi_domain_market_identity($record) : ['terms' => ['نمونه'], 'sizes' => []];
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($pid, $offerId, $regular, $url, $basis, $verifiedAt, $identity, $record, $nowInt, $target): void {
        molavi_domain_sql($db, 'INSERT INTO market_product_sources (target,product_id,offer_id,title,identity_json,url,quote_json,regular_price,next_at,checked_at,verified_at,changed_at,match_basis,match_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$target !== '' ? $target : 'product:' . $pid, $pid, $offerId, $record ? (string)($record['Product Name'] ?? '') : 'نمونه',
                molavi_domain_json($identity), $url, null, $regular, $nowInt - 60, $verifiedAt, $verifiedAt, $nowInt, $basis, 'active']);
    });
}
function seed_market_match(string $pid, string $offerId, string $url, int $regular, string $status = 'active', string $basis = 'owner_pick'): void {
    global $nowInt, $recordById;
    $record = $recordById($pid);
    $identity = molavi_domain_market_identity($record);
    $signature = molavi_domain_hash($identity);
    $listing = 'basalam-' . basename($url);
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($pid, $offerId, $url, $regular, $status, $basis, $signature, $listing, $identity, $nowInt): void {
        $cols = ['product_id', 'source', 'offer_id', 'signature', 'reference_id', 'source_product_id', 'snapshot_id', 'price_role',
            'catalog_package_price', 'catalog_unit_price', 'catalog_consumer_price', 'catalog_supplier_price', 'sheet_consumer_price',
            'score', 'name_similarity', 'match_basis', 'decision_id', 'listing_id', 'url', 'provider', 'merchant', 'quote_json',
            'regular_price', 'pack_count', 'identity_json', 'actor_id', 'status', 'created_at', 'checked_at', 'verified_at', 'next_at'];
        $vals = [$pid, 'market', $offerId, $signature, $listing, '', '', 'unit', null, null, null, null, null,
            null, null, $basis, null, $listing, $url, 'basalam', 'فروشگاه نمونه', null,
            $regular, 1, molavi_domain_json($identity), 'wp:7', $status,
            $nowInt - 3600, $nowInt - 3600, null, $nowInt - 3600 + 14 * 86400];
        molavi_domain_sql($db, 'INSERT INTO reference_product_matches (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    });
}
seed_market_source('r4-legacy', 'offer-r4-legacy', 90000, 'https://basalam.com/p/9101', null, $nowInt - 3600);
seed_market_source('r4-legacy-owner', 'offer-r4-legacy-owner', 90000, 'https://basalam.com/p/9102', 'owner_pick', $nowInt - 3600);
seed_market_source('r4-hasrow', 'offer-r4-hasrow', 90000, 'https://basalam.com/p/9103', 'owner_pick', $nowInt - 3600);
seed_market_match('r4-hasrow', 'offer-r4-hasrow', 'https://basalam.com/p/9103', 90000);
seed_market_source('r4-fail', 'offer-r4-fail', 90000, 'https://basalam.com/p/9104', null, $nowInt - 3600);
seed_market_source('', 'offer-r4-offeronly', 90000, 'https://basalam.com/p/9105', null, $nowInt - 3600, 'offer:offer-r4-offeronly');
seed_market_source('r4-retired', 'offer-r4-retired', 90000, 'https://basalam.com/p/9106', null, $nowInt - 3600);
seed_market_match('r4-retired', 'offer-r4-retired', 'https://basalam.com/p/9106', 90000, 'retired');
seed_market_source('r4-needsreview', 'offer-r4-needsreview', 90000, 'https://basalam.com/p/9107', 'owner_pick', $nowInt - 3600);
seed_market_match('r4-needsreview', 'offer-r4-needsreview', 'https://basalam.com/p/9107', 90000, 'needs_review');
seed_market_source('r4-live-coffee', 'offer-r4-live-coffee', 23750, 'https://basalam.com/p/19370554', null, $nowInt - 3600);
seed_market_source('r4-live-64610', 'offer-r4-live-64610', 64610, 'https://basalam.com/p/17409711', null, $nowInt - 3600);
seed_market_source('r4-live-cappuccino', 'offer-r4-live-cappuccino', 55076, 'https://basalam.com/p/17409587', null, $nowInt - 3600);

/* --- Seed link checks 'unchanged' for the auto-verify gate --- */
function seed_link(string $id, int $checkedAt, string $sale, string $revision): void {
    global $nowInt;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $checkedAt, $sale, $revision, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, 'offer-' . $id, 'Item ' . $id, 'https://t.me/testchannel/' . $id, $revision, $sale,
                'Item ' . $id . ' ' . $sale, 'unchanged', 'price_unchanged', $sale, null, 'railway', $checkedAt, $nowInt + 86400, null,
                null, null, $nowInt - 7200]);
    });
}
seed_link('r4-legacy', $nowInt - 9000, '80000', $revision['r4-legacy']);
seed_link('r4-needsreview', $nowInt - 9000, '80000', $revision['r4-needsreview']);

/* --- Refresh stub: the listing re-verifies at the same price, except
 * r4-fail's listing, which is gone. --- */
$r4Prices = [
    'https://basalam.com/p/9101' => 90000, 'https://basalam.com/p/9102' => 90000,
    'https://basalam.com/p/9103' => 90000, 'https://basalam.com/p/9105' => 90000,
    'https://basalam.com/p/9106' => 90000, 'https://basalam.com/p/9107' => 90000,
    'https://basalam.com/p/19370554' => 23750, 'https://basalam.com/p/17409711' => 64610,
    'https://basalam.com/p/17409587' => 55076,
];
$r4Check = static function (string $url, array $identity) use ($r4Prices): ?array {
    if (!isset($r4Prices[$url])) return null;
    return ['url' => $url, 'regular_unit_price' => $r4Prices[$url], 'unit_price' => $r4Prices[$url],
        'pack_count' => 1, 'provider' => 'basalam', 'merchant' => 'basalam.com',
        'listing_id' => (int) basename($url), 'title' => 'نمونه'];
};

/* --- Snapshot the pre-refresh kept row for r4-hasrow (untouched check) --- */
$hasrowBefore = molavi_domain_reference_match_get('r4-hasrow', 'market');
$hasrowEventsBefore = db_count('reference', "SELECT COUNT(*) n FROM reference_match_events WHERE product_id=? AND source='market'", ['r4-hasrow']);

/* --- Drive the refresh until nothing is due (one source per call) --- */
$runs = 0;
while (molavi_domain_market_refresh_source($nowInt, $r4Check)) {
    if (++$runs > 20) break;
}
check_true($runs >= 10, 'refresh: all 10 due sources processed (one per call)');

/* --- (h) the disagree shapes are pure classify/sanity: same on both
 * trees, so they run before the fixed/base branch. --- */
$vitaminQuotes = [
    ['url' => 'https://basalam.com/p/1', 'regular_unit_price' => 45000, 'unit_price' => 45000, 'pack_count' => 1],
    ['url' => 'https://basalam.com/p/2', 'regular_unit_price' => 52000, 'unit_price' => 52000, 'pack_count' => 1],
];
$vitamin = molavi_domain_market_agreement_classify($vitaminQuotes, 40000.0, 1, null, null);
check($vitamin['outcome'] ?? null, 'disagree', '(h) vitamin-C shape: two quotes 13.5% apart disagree');
check($vitamin['reason_code'] ?? null, 'quotes_disagree', '(h) vitamin-C shape: reason quotes_disagree');
$single = molavi_domain_market_agreement_classify(
    [['url' => 'https://basalam.com/p/3', 'regular_unit_price' => 70000, 'unit_price' => 70000, 'pack_count' => 1]],
    65000.0, 1, null, null);
check($single['outcome'] ?? null, 'disagree', '(h) single-quote shape: one quote, no reference, disagrees');
check($single['reason_code'] ?? null, 'single_quote_no_reference', '(h) single-quote shape: reason single_quote_no_reference');
$singleRef = molavi_domain_market_agreement_classify(
    [['url' => 'https://basalam.com/p/3', 'regular_unit_price' => 70000, 'unit_price' => 70000, 'pack_count' => 1]],
    60000.0, 1, 70000, null);
check($singleRef['outcome'] ?? null, 'agree', '(h) control: single quote + reference within 5% agrees');
check($singleRef['basis'] ?? null, 'rezvan_crosscheck', '(h) control: single-quote agreement basis is rezvan_crosscheck');
$agreeing = molavi_domain_market_agreement_classify([
    ['url' => 'https://basalam.com/p/4', 'regular_unit_price' => 100000, 'unit_price' => 100000, 'pack_count' => 1],
    ['url' => 'https://basalam.com/p/5', 'regular_unit_price' => 102000, 'unit_price' => 102000, 'pack_count' => 1],
], 80000.0, 1, null, null);
check($agreeing['outcome'] ?? null, 'agree', '(h) control: two quotes within the 5% band agree');
$reject = molavi_domain_market_quote_sanity(['regular_unit_price' => 80000], 80000.0, 1);
check($reject['status'] ?? null, 'reject', '(h) sanity: regular at the sale price is rejected');
check($reject['reason_code'] ?? null, 'regular_at_or_below_cost', '(h) sanity: reject reason regular_at_or_below_cost');
$thin = molavi_domain_market_quote_sanity(['regular_unit_price' => 85000], 80000.0, 1);
check($thin['status'] ?? null, 'thin', '(h) sanity: 5.9% implied spread is thin');
check($thin['reason_code'] ?? null, 'spread_too_thin', '(h) sanity: thin reason spread_too_thin');

$isFixed = function_exists('molavi_domain_market_refresh_heal_kept_match');
echo 'MODE: ' . ($isFixed ? 'FIXED (refresh heals missing kept market rows)' : 'BASE 815d7e6 (negative control: no heal)') . "\n";

if (!$isFixed) {
    // --- NEGATIVE CONTROL: refresh re-verifies but creates NO kept row,
    // the source stays match_basis NULL, and the Q4 leg cannot fire. ---
    $keptLegacy = molavi_domain_reference_match_get('r4-legacy', 'market');
    check($keptLegacy, null, 'negative: r4-legacy has NO kept market row after refresh on the base');
    $srcLegacy = db_row('reference', 'SELECT match_basis,matched_by FROM market_product_sources WHERE target=?', ['product:r4-legacy']);
    check($srcLegacy['match_basis'] ?? null, null, 'negative: r4-legacy source match_basis still NULL on the base');
    $chkLegacy = molavi_domain_auto_verify_check($recordById('r4-legacy'), offer_for('r4-legacy', $sheetRecords, $nowInt), $nowInt);
    check($chkLegacy['eligible'], false, 'negative: r4-legacy not auto-verify eligible on the base');
    check($chkLegacy['reason'] ?? null, 'no_reference_match', 'negative: r4-legacy reason is the catalog leg on the base');
    foreach (['r4-live-coffee', 'r4-live-64610', 'r4-live-cappuccino'] as $pid) {
        check(molavi_domain_reference_match_get($pid, 'market'), null, 'negative: ' . $pid . ' has NO kept market row on the base');
    }
    // Fresh picks were never broken: choose_source still writes the row.
    $freshSubject = ['product_id' => 'r4-freshpick', 'offer_id' => 'offer-r4-freshpick',
        'identity' => molavi_domain_market_identity($recordById('r4-freshpick')),
        'target' => 'product:r4-freshpick', 'title' => 'خرما مضافتی بم', 'regular_price' => 0];
    molavi_domain_market_choose_source($freshSubject,
        ['url' => 'https://basalam.com/p/9111', 'regular_unit_price' => 90000, 'unit_price' => 90000,
            'pack_count' => 1, 'provider' => 'basalam', 'merchant' => 'basalam.com', 'listing_id' => 9111],
        null, $nowInt, $nowInt, '', 'owner_pick');
    $keptFresh = molavi_domain_reference_match_get('r4-freshpick', 'market');
    check_true($keptFresh !== null, 'negative: fresh owner pick still creates its kept row on the base');
    check($keptFresh['match_basis'] ?? null, 'owner_pick', 'negative: fresh pick kept row basis owner_pick on the base');
    cleanup();
    echo 'NEGATIVE native FIX-R4 market refresh heal ' . $count . " cases (old behaviour on the base) FAIL $fail\n";
    exit($fail === 0 ? 0 : 1);
}

/* --- (a) THE bug case: legacy source, match_basis NULL --- */
$keptLegacy = molavi_domain_reference_match_get('r4-legacy', 'market');
check_true($keptLegacy !== null, '(a) r4-legacy kept market row created by refresh');
check($keptLegacy['status'] ?? null, 'active', '(a) r4-legacy kept row status active');
check($keptLegacy['match_basis'] ?? null, 'backfill', '(a) r4-legacy kept row basis backfill (reconstructed, not re-picked)');
check($keptLegacy['url'] ?? null, 'https://basalam.com/p/9101', '(a) r4-legacy kept row URL is the picked listing');
check($keptLegacy['signature'] ?? null, molavi_domain_hash(molavi_domain_market_identity($recordById('r4-legacy'))), '(a) r4-legacy kept row signature matches the current identity');
check($keptLegacy['regular_price'] ?? null, 90000, '(a) r4-legacy kept row regular price is the freshly verified 90000');
$srcLegacy = db_row('reference', 'SELECT match_basis,matched_by,matched_at,match_status FROM market_product_sources WHERE target=?', ['product:r4-legacy']);
check($srcLegacy['match_basis'] ?? null, 'backfill', '(a) r4-legacy source match_basis stamped backfill');
check($srcLegacy['matched_by'] ?? null, 'system:market-refresh', '(a) r4-legacy source matched_by system:market-refresh');
check_true((int)($srcLegacy['matched_at'] ?? 0) >= $nowInt, '(a) r4-legacy source matched_at stamped');
check($srcLegacy['match_status'] ?? null, 'active', '(a) r4-legacy source match_status active');
check(db_count('reference', "SELECT COUNT(*) n FROM reference_match_events WHERE product_id=? AND source='market' AND kind='created'", ['r4-legacy']), 1, '(a) r4-legacy kept-row creation recorded as a match event');
// ... and the Q4 market-first leg now fires for it.
$chkLegacy = molavi_domain_auto_verify_check($recordById('r4-legacy'), offer_for('r4-legacy', $sheetRecords, $nowInt), $nowInt);
check($chkLegacy['eligible'], true, '(a) r4-legacy auto-verify eligible after the heal');
check($chkLegacy['evidence']['leg'] ?? null, 'market', '(a) r4-legacy leg is market');
check($chkLegacy['evidence']['market_first'] ?? null, true, '(a) r4-legacy market_first flag');
check($chkLegacy['evidence']['consumer_price'] ?? null, 90000, '(a) r4-legacy certifies the displayed 90000');

/* --- (b) legacy source with a known basis keeps it --- */
$keptOwner = molavi_domain_reference_match_get('r4-legacy-owner', 'market');
check_true($keptOwner !== null, '(b) r4-legacy-owner kept market row created by refresh');
check($keptOwner['match_basis'] ?? null, 'owner_pick', '(b) r4-legacy-owner kept row basis owner_pick preserved');
check(db_row('reference', 'SELECT match_basis FROM market_product_sources WHERE target=?', ['product:r4-legacy-owner'])['match_basis'] ?? null, 'owner_pick', '(b) r4-legacy-owner source match_basis stays owner_pick');

/* --- (c) an existing kept row is left entirely alone --- */
$keptHasrow = molavi_domain_reference_match_get('r4-hasrow', 'market');
check($keptHasrow['checked_at'] ?? null, $hasrowBefore['checked_at'] ?? null, '(c) r4-hasrow kept row checked_at unchanged');
check($keptHasrow['regular_price'] ?? null, $hasrowBefore['regular_price'] ?? null, '(c) r4-hasrow kept row price unchanged');
check($keptHasrow['match_basis'] ?? null, $hasrowBefore['match_basis'] ?? null, '(c) r4-hasrow kept row basis unchanged');
check(db_count('reference', "SELECT COUNT(*) n FROM reference_match_events WHERE product_id=? AND source='market'", ['r4-hasrow']), $hasrowEventsBefore, '(c) r4-hasrow no new match events');

/* --- (d) a failed refresh creates nothing --- */
check(molavi_domain_reference_match_get('r4-fail', 'market'), null, '(d) r4-fail has NO kept market row');
$srcFail = db_row('reference', 'SELECT failure,match_status,match_basis FROM market_product_sources WHERE target=?', ['product:r4-fail']);
check($srcFail['failure'] ?? null, 'listing_unavailable', '(d) r4-fail source flagged listing_unavailable');
check($srcFail['match_status'] ?? null, 'needs_review', '(d) r4-fail source match_status needs_review');
check($srcFail['match_basis'] ?? null, null, '(d) r4-fail source match_basis still NULL');

/* --- (e) offer-only sources get no kept row --- */
check(molavi_domain_reference_match_get('', 'market'), null, '(e) offer-only source has no kept market row');
$srcOffer = db_row('reference', 'SELECT product_id,match_basis FROM market_product_sources WHERE target=?', ['offer:offer-r4-offeronly']);
check($srcOffer['product_id'] ?? null, '', '(e) offer-only source still has no product');
check($srcOffer['match_basis'] ?? null, null, '(e) offer-only source match_basis still NULL');

/* --- (f) a retired kept row is NOT resurrected --- */
$keptRetired = molavi_domain_reference_match_get('r4-retired', 'market');
check($keptRetired['status'] ?? null, 'retired', '(f) r4-retired kept row stays retired');
check($keptRetired['match_basis'] ?? null, 'owner_pick', '(f) r4-retired kept row basis untouched (never re-recorded)');
$srcRetired = db_row('reference', 'SELECT verified_at,failure FROM market_product_sources WHERE target=?', ['product:r4-retired']);
check((int)($srcRetired['verified_at'] ?? 0), $nowInt, '(f) r4-retired source itself still re-verified');
check($srcRetired['failure'] ?? null, null, '(f) r4-retired source has no failure');

/* --- (g) a needs_review kept row is NOT cleared by refresh --- */
$keptReview = molavi_domain_reference_match_get('r4-needsreview', 'market');
check($keptReview['status'] ?? null, 'needs_review', '(g) r4-needsreview kept row stays needs_review');
$chkReview = molavi_domain_auto_verify_check($recordById('r4-needsreview'), offer_for('r4-needsreview', $sheetRecords, $nowInt), $nowInt);
check($chkReview['eligible'], false, '(g) r4-needsreview still not auto-verify eligible');
check($chkReview['reason'] ?? null, 'no_reference_match', '(g) r4-needsreview reason stays the catalog leg');

/* --- (i) the three live-shaped legacy sources are healed --- */
foreach (['r4-live-coffee' => 'https://basalam.com/p/19370554', 'r4-live-64610' => 'https://basalam.com/p/17409711',
    'r4-live-cappuccino' => 'https://basalam.com/p/17409587'] as $pid => $url) {
    $kept = molavi_domain_reference_match_get($pid, 'market');
    check_true($kept !== null, '(i) ' . $pid . ' kept market row created by refresh');
    check($kept['status'] ?? null, 'active', '(i) ' . $pid . ' kept row status active');
    check($kept['match_basis'] ?? null, 'backfill', '(i) ' . $pid . ' kept row basis backfill');
    check($kept['url'] ?? null, $url, '(i) ' . $pid . ' kept row URL is the picked listing');
}

/* --- (j) fresh-pick regression: choose_source still writes the row --- */
$freshSubject = ['product_id' => 'r4-freshpick', 'offer_id' => 'offer-r4-freshpick',
    'identity' => molavi_domain_market_identity($recordById('r4-freshpick')),
    'target' => 'product:r4-freshpick', 'title' => 'خرما مضافتی بم', 'regular_price' => 0];
molavi_domain_market_choose_source($freshSubject,
    ['url' => 'https://basalam.com/p/9111', 'regular_unit_price' => 90000, 'unit_price' => 90000,
        'pack_count' => 1, 'provider' => 'basalam', 'merchant' => 'basalam.com', 'listing_id' => 9111],
    null, $nowInt, $nowInt, '', 'owner_pick');
$keptFresh = molavi_domain_reference_match_get('r4-freshpick', 'market');
check_true($keptFresh !== null, '(j) fresh owner pick creates its kept row');
check($keptFresh['match_basis'] ?? null, 'owner_pick', '(j) fresh pick kept row basis owner_pick');
check(db_row('reference', 'SELECT match_basis FROM market_product_sources WHERE target=?', ['product:r4-freshpick'])['match_basis'] ?? null, 'owner_pick', '(j) fresh pick source match_basis owner_pick');

cleanup();
echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
