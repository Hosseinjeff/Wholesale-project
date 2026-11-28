<?php
/** Reference auto-match backfill harness: decision fixtures (a)-(f) + API dry-run/write/idempotency. */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-reference-backfill-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['backfill_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['backfill_options'][$key] = $value; }

/* Sheets are stubbed in-memory (domain-connectors.php is NOT required, so no HTTP happens). */
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['backfill_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields($name, $row, $headers, $values) {
    foreach ($values as $field => $value) $GLOBALS['backfill_tables'][$name][$row - 1][array_search($field, $headers, true)] = $value;
}
function molavi_domain_sheet_cells($name, $updates) {
    foreach ($updates as $update) {
        if (!preg_match('/^([A-Z]+)(\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range ' . $update['range']);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $GLOBALS['backfill_tables'][$name][(int) $m[2] - 1][$col - 1] = $update['values'][0][0];
    }
}
function molavi_domain_sheet_ensure($name, $headers) {
    if (empty($GLOBALS['backfill_tables'][$name])) $GLOBALS['backfill_tables'][$name] = [$headers];
}
function molavi_domain_sheet_append($name, $rows) {
    foreach ($rows as $row) $GLOBALS['backfill_tables'][$name][] = $row;
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
    'domain-reference-store', 'domain-reference-matching', 'domain-products', 'domain-publisher',
    'domain-product-derivation', 'domain-sheet-conflicts', 'domain-jobs', 'domain-source-projection',
    'domain-sale-offers', 'domain-orders', 'domain-reference-reviews', 'domain-reference-scheduler',
    'domain-reference-backfill','domain-reference-matches'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';

$root = molavi_state_root();
foreach (['pipeline', 'reference'] as $store) {
    $db = new SQLite3($root . '/' . $store . '.sqlite3');
    $db->close();
}
$count = 0;
function check($actual, $expected, $label) {
    global $count;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected))
        throw new RuntimeException($label . ' ' . molavi_domain_json(['actual' => $actual, 'expected' => $expected]));
    $count++;
}
function check_true($actual, $label) { check($actual, true, $label); }

/* --- Fixtures: 21-column catalog rows (reference headers order) --- */
function catalog_row($name, $code, $consumer, $unit, $package, $brand, $category, $variant = '', $volume = '', $weight = '', $count = 1) {
    return [$name, $name, 'key-' . $code, $package, $unit, $consumer, $count, '', $volume, $weight, 0,
        $variant, $brand, $category, '', 1, $code, 'TOMAN', 'rezvanpakhsh_woocommerce_store_api',
        'https://rezvanpakhsh.com/wp-json/wc/store/v1/products', '2026-11-26T00:00:00+00:00'];
}
$catalog = [
    catalog_row('چای سیاه احمد', 'REF-001', 100000, 95000, 100000, 'احمد', 'چای', '', '500 گرم'),
    catalog_row('قهوه فوری نسکافه', 'REF-002', 100000, 98000, 100000, 'نسکافه', 'قهوه', '', '200 گرم'),
    catalog_row('شکر سفید دولیتل', 'REF-003A', 50000, 48000, 50000, 'دولیتل', 'شکر'),
    catalog_row('شکر سفید دولیتل', 'REF-003B', 51000, 49000, 51000, 'دولیتل', 'شکر'),
    catalog_row('روغن آفتابگردان اویلا', 'REF-004', 250000, 240000, 250000, 'اویلا', 'روغن', '', '1 لیتر'),
    catalog_row('برنج هاشمی گیلان', 'REF-006', 300000, 290000, 300000, '', 'برنج', '', '5 کیلوگرم'),
    catalog_row('ماست موسیر کاله', 'REF-007', 90000, 85000, 90000, 'کاله', 'لبنیات', 'ساده', '500 گرم'),
];
/* Seed the snapshot as checked an hour ago: the reference deadline needs
 * last_checked_at <= now (it carries microseconds, so "just now" can fall
 * a fraction of a second in the future and read as not yet valid). */
$nowIso = (new DateTimeImmutable('-3600 seconds', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
$snapshotId = hash('sha256', molavi_domain_json(['rows' => $catalog, 'extracted_at' => $nowIso]));
molavi_domain_tx('reference', static function (SQLite3 $db) use ($catalog, $nowIso, $snapshotId): void {
    $payload = molavi_domain_json(['status' => 'staged', 'extracted_at' => $nowIso,
        'headers' => molavi_domain_reference_headers(), 'rows' => $catalog]);
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)', [$snapshotId, $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active VALUES (1,?)', [$snapshotId]);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check VALUES (1,?,?)', [$snapshotId, $nowIso]);
});

/* --- Fixtures: Products + Offers --- */
function product_record($id, $name, $brand, $category, $variant, $volume, $consumer, $offer, $status = 'imported', $extra = []) {
    return array_merge(['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => $consumer, 'Sale Price' => 80000, 'Status' => $status, 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Reference Match ID' => '', 'Reference Snapshot ID' => '',
        'Reference Approval State' => '', 'Reference Decision ID' => '', 'Reference Price Role' => '',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''], $extra);
}
function offer_record($id, $name, $brand, $category, $variant, $volume, $consumer, $productId) {
    return ['Offer ID' => $id, 'Product ID' => $productId, 'Product Name' => $name, 'Brand' => $brand,
        'Category' => $category, 'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1,
        'Packaging' => '', 'Sale Price' => 80000, 'Consumer Price' => $consumer, 'Price' => 80000, 'Actual Price' => '',
        'Status' => 'imported', 'Review Reason' => '', 'Channel Username' => 'testchannel', 'Channel' => 'testchannel',
        'Message ID' => 'msg-' . $id, 'Batch ID' => 'batch-1', 'Message Timestamp' => '2026-11-01T00:00:00+00:00',
        'Source Timestamp Basis' => '', 'Import Timestamp' => '2026-11-20T00:00:00+00:00', 'Original Message' => 'test',
        'Source Post URL' => '', 'Reference Snapshot ID' => '', 'Reference Match State' => '', 'Reference Match ID' => '',
        'Reference Price Role Hint' => '', 'Reference Decision ID' => '', 'Owner Decision ID' => '', 'Derived From Offer ID' => ''];
}
$products = [
    product_record('p_match_exact', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-1'),
    product_record('p_match_margin', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 102000, 'offer-2'),
    product_record('p_multiple', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-3'),
    product_record('p_price_out', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 200000, 'offer-4'),
    product_record('p_no_candidate', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 800000, 'offer-5'),
    product_record('p_no_price', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', '', 'offer-6'),
    product_record('p_conflict', 'ماست موسیر کاله', 'کاله', 'لبنیات', 'موسیر', '500 گرم', 90000, 'offer-7'),
    product_record('p_already', 'تن ماهی محفل', 'محفل', 'کنسرو', '', '', 150000, 'offer-8', 'imported',
        ['Reference Match ID' => 'REF-000', 'Reference Snapshot ID' => $snapshotId, 'Reference Approval State' => 'approved_within_margin']),
];
$offers = [
    offer_record('offer-1', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'p_match_exact'),
    offer_record('offer-2', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 102000, 'p_match_margin'),
    offer_record('offer-3', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_multiple'),
    offer_record('offer-4', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 200000, 'p_price_out'),
    offer_record('offer-5', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 800000, 'p_no_candidate'),
    offer_record('offer-6', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', '', 'p_no_price'),
    offer_record('offer-7', 'ماست موسیر کاله', 'کاله', 'لبنیات', 'موسیر', '500 گرم', 90000, 'p_conflict'),
    offer_record('offer-8', 'تن ماهی محفل', 'محفل', 'کنسرو', '', '', 150000, 'p_already'),
];
$GLOBALS['backfill_tables'] = [
    'Products' => molavi_domain_rows_from_records(molavi_domain_product_headers(), $products),
    'Offers' => molavi_domain_rows_from_records(molavi_domain_offer_headers(), $offers),
    'Shop' => [],
];
$byId = static fn($id) => array_values(array_filter(molavi_domain_records($GLOBALS['backfill_tables']['Products']),
    static fn($r) => $r['Product ID'] === $id))[0];
$offerById = static fn($id) => array_values(array_filter(molavi_domain_records($GLOBALS['backfill_tables']['Offers']),
    static fn($r) => $r['Offer ID'] === $id))[0];
$decideFor = static function (array $record, array $offer) use ($catalog): array {
    $attrs = molavi_domain_offer_attributes($offer);
    $attrs['supplier_price'] = molavi_domain_py_value($offer['Price'] ?? null, molavi_domain_py_value($offer['Sale Price'] ?? null));
    return molavi_domain_reference_backfill_decide($record, molavi_domain_reference_candidates($attrs, $catalog, 20));
};

/* --- (a)-(f) pure decision fixtures, through the real candidate matcher --- */
$d = $decideFor($byId('p_match_exact'), $offerById('offer-1'));
check_true($d['matched'], '(a) single high-confidence candidate matches');
check($d['reference_id'], 'REF-001', '(a) reference id');
check($d['price_ratio'], 0.0, '(a) exact price agreement, ratio 0');
check_true($d['name_similarity'] >= 0.92, '(a) name similarity >= 0.92');
$d = $decideFor($byId('p_match_margin'), $offerById('offer-2'));
check_true($d['matched'], '(a2) single candidate within 5% matches');
check($d['reference_id'], 'REF-002', '(a2) reference id');
check($d['price_ratio'], 0.0196, '(a2) price ratio ~2%');
$d = $decideFor($byId('p_multiple'), $offerById('offer-3'));
check($d, ['matched' => false, 'reason' => 'multiple_high_candidates', 'candidate_count' => 2], '(b) multiple high candidates left for the owner');
$d = $decideFor($byId('p_price_out'), $offerById('offer-4'));
check($d['matched'], false, '(c) price outside 5% not matched');
check($d['reason'], 'price_outside_margin', '(c) reason');
$d = $decideFor($byId('p_no_candidate'), $offerById('offer-5'));
check($d, ['matched' => false, 'reason' => 'no_candidate'], '(d) no candidate not matched');
$d = $decideFor($byId('p_no_price'), $offerById('offer-6'));
check_true($d['matched'], '(e) no sheet Consumer Price but an exact unique name matches as exact_name');
check($d['tier'], 'exact_name', '(e) tier is exact_name');
check($d['reference_id'], 'REF-006', '(e) reference id');
$d = $decideFor($byId('p_conflict'), $offerById('offer-7'));
check($d['matched'], false, '(f) attribute conflict not matched');
check($d['reason'], 'attribute_conflict', '(f) reason');
check($d['conflict_key'], 'variant', '(f) conflicting attribute is variant');

/* --- API: explicit dry-run writes nothing --- */
$beforeTables = molavi_domain_json($GLOBALS['backfill_tables']);
$dry = molavi_domain_reference_api(['action' => 'backfill_reference_matches', 'dry_run' => true], 'wp:7');
check($dry['dry_run'], true, 'dry-run flag echoes true');
check($dry['snapshot_id'], $snapshotId, 'dry-run reports the active snapshot');
check($dry['scanned'], 7, 'dry-run scans the 7 undecided products');
check($dry['matched'], 3, 'dry-run matches exactly the three unambiguous products (two price-agreed, one exact name without a sheet price)');
check($dry['candidates'], 0, 'dry-run surfaces no owner candidates in this fixture set');
check(array_column($dry['matches'], 'product_id'), ['p_match_exact', 'p_match_margin', 'p_no_price'], 'dry-run match list');
check($dry['matches'][0]['reference_id'], 'REF-001', 'dry-run match reference');
check($dry['matches'][0]['price_ratio'], 0.0, 'dry-run match ratio');
check($dry['skipped'], ['multiple_high_candidates' => 1, 'price_outside_margin' => 1, 'no_candidate' => 1,
    'attribute_conflict' => 1, 'already_matched' => 1], 'dry-run skip reasons');
check(molavi_domain_json($GLOBALS['backfill_tables']), $beforeTables, 'dry-run leaves the sheets untouched');
check(molavi_domain_read('reference', static fn(SQLite3 $db) => count(molavi_domain_rows($db, 'SELECT * FROM reference_item_decisions'))), 0, 'dry-run records no decisions');

/* --- API: dry-run is the default when dry_run is omitted --- */
$default = molavi_domain_reference_api(['action' => 'backfill_reference_matches'], 'wp:7');
check($default['dry_run'], true, 'omitted dry_run defaults to dry-run');
check(molavi_domain_json($GLOBALS['backfill_tables']), $beforeTables, 'default dry-run leaves the sheets untouched');

/* --- API: write run --- */
$write = molavi_domain_reference_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($write['dry_run'], false, 'write run echoes dry_run false');
check($write['matched'], 3, 'write run matches three');
check($write['written'], 3, 'write run writes three');
check($write['failures'], [], 'write run has no failures');
check_true(is_string($write['publish_job'] ?? null) && ($write['publish_job'] ?? '') !== '', 'write run queues a publish job for the projection change');
check_true(isset($write['product_status_snapshot']['snapshot_id']), 'write run captures a product status snapshot');
$exact = $byId('p_match_exact');
check($exact['Reference Match ID'], 'REF-001', 'write: match id on the card');
check($exact['Reference Snapshot ID'], $snapshotId, 'write: active snapshot id on the card');
check($exact['Reference Approval State'], 'approved_within_margin', 'write: approval state is approved_within_margin');
check($exact['Reference Price Role'], 'unit', 'write: price role unit (consumer unit price basis)');
check((float) $exact['Reference Price Change Percent'], 0.0, 'write: change percent 0 for exact agreement');
check_true($exact['Reference Price Checked At'] !== '', 'write: checked-at stamped');
check_true($exact['Reference Decision ID'] !== '', 'write: decision id on the card');
check($exact['Reference Review Reason'], '', 'write: review reason cleared');
$margin = $byId('p_match_margin');
check($margin['Reference Match ID'], 'REF-002', 'write: margin match id on the card');
check($margin['Reference Approval State'], 'approved_within_margin', 'write: margin approval state');
check((float) $margin['Reference Price Change Percent'], 1.96, 'write: change percent 1.96 for the 2% case (ratio rounded to 4dp first)');
$decisions = molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db, 'SELECT * FROM reference_item_decisions ORDER BY sequence'));
check(count($decisions), 3, 'write: three decision rows recorded');
check(array_column($decisions, 'choice'), ['match', 'match', 'match'], 'write: decisions are matches');
check(array_column($decisions, 'price_role'), ['unit', 'unit', 'unit'], 'write: decisions carry price role unit');
check(array_column($decisions, 'reference_id'), ['REF-001', 'REF-002', 'REF-006'], 'write: decisions carry the reference ids');
check_true(str_contains($decisions[0]['note'], 'Auto-match backfill'), 'write: decision note marks the automated backfill');
check_true(str_contains($decisions[0]['note'], 'REF-001'), 'write: decision note carries the reference id');
check($decisions[0]['decision_id'], $exact['Reference Decision ID'], 'write: card decision id is the recorded decision');
check($byId('p_no_price')['Consumer Price'], '', 'write: no-price product never gains a price from the catalog');
$noPrice = $byId('p_no_price');
check($noPrice['Reference Match ID'], 'REF-006', 'write: exact-name no-price product gains the match');
check($noPrice['Reference Approval State'], 'approved_compatible', 'write: exact-name approval is approved_compatible');
check($noPrice['Reference Price Change Percent'], '', 'write: exact-name change percent is empty');
check($byId('p_multiple')['Reference Match ID'], '', 'write: multiple-candidate product gains no match');
check($byId('p_price_out')['Reference Match ID'], '', 'write: outside-margin product gains no match');
check($byId('p_conflict')['Reference Match ID'], '', 'write: conflict product gains no match');

/* --- Downstream consequence: the exact-agreement match makes the regular price current. --- */
$evidenceExact = molavi_domain_regular_price_evidence($byId('p_match_exact'), $offerById('offer-1'), null, time());
check($evidenceExact['state'], 'current', 'evidence: exact-agreement match makes the regular price current via the reference deadline');
check($evidenceExact['amount'], 100000, 'evidence: current amount is the sheet consumer price');
/* Known limit, asserted honestly: the reference deadline needs the catalog price to EQUAL the
 * sheet price, so a within-5% but unequal match is recorded yet stays unverified until the
 * deadline is relaxed to the margin. The backfill does not paper over this. */
$evidenceMargin = molavi_domain_regular_price_evidence($byId('p_match_margin'), $offerById('offer-2'), null, time());
check($evidenceMargin['state'], 'unverified', 'evidence: within-5% but unequal match stays unverified under the exact-equality deadline');

/* Known limit, asserted honestly: the exact-name match records the reference but the product has
 * no sheet Consumer Price, so the evidence is still missing until the regular-price sync writes
 * the catalog price to the sheet on the next tick (catalogue wins by default). The backfill does
 * not write prices; the sync is the mechanism that completes it. */
$evidenceNoPrice = molavi_domain_regular_price_evidence($byId('p_no_price'), $offerById('offer-6'), null, time());
check($evidenceNoPrice['state'], 'missing', 'evidence: exact-name match without a sheet price stays missing until the sync writes the catalog price');

/* --- Idempotency: a second write run matches nothing new --- */
$again = molavi_domain_reference_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($again['matched'], 0, 'second run matches nothing');
check($again['written'], 0, 'second run writes nothing');
check($again['skipped']['already_matched'] ?? 0, 4, 'second run reports the four matched products as already matched');
check(molavi_domain_read('reference', static fn(SQLite3 $db) => count(molavi_domain_rows($db, 'SELECT * FROM reference_item_decisions'))), 3, 'second run records no further decisions');

foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native reference backfill ' . $count . " cases\n";
