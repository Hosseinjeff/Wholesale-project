<?php
/** Refresh-reconcile harness: 5% / >5% / removed / identity drift / relink /
 * nothing-changed / retire / owner-accept, the price-change invariant, and
 * the complete() hook (reconcile-first ordering). Also the negative
 * control: on a tree without the reconcile function the same file reports
 * the new behaviour absent.
 *
 * Q2 (2026-11-27): a clean >5% move auto-adopts (see
 * tests/native_reference_match_q2_auto_adopt.php for the adopted path).
 * This file's >5% product (p_outside) stays owner-held because its
 * fixture row stores a regular price equal to the sale price — the
 * sale>=regular anomaly — so it now asserts the anomaly hold (code
 * sale_not_below_regular, anomaly reason) instead of outside_margin.
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-match-reconcile-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['reconcile_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['reconcile_options'][$key] = $value; }
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['reconcile_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields($name, $row, $headers, $values) {
    foreach ($values as $field => $value) $GLOBALS['reconcile_tables'][$name][$row - 1][array_search($field, $headers, true)] = $value;
}
function molavi_domain_sheet_cells($name, $updates) {
    foreach ($updates as $update) {
        if (!preg_match('/^([A-Z]+)(\\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range ' . $update['range']);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $GLOBALS['reconcile_tables'][$name][(int) $m[2] - 1][$col - 1] = $update['values'][0][0];
    }
}
function molavi_domain_sheet_ensure($name, $headers) {
    if (empty($GLOBALS['reconcile_tables'][$name])) $GLOBALS['reconcile_tables'][$name] = [$headers];
}
function molavi_domain_sheet_append($name, $rows) {
    foreach ($rows as $row) $GLOBALS['reconcile_tables'][$name][] = $row;
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
    'domain-reference-workflow', 'domain-reference-backfill'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';
/* The files under test: present on the work branch, absent/older on the base (negative control). */
$matchesFile = __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-domain-reference-matches.php';
if (file_exists($matchesFile)) require $matchesFile;

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
function match_row($product, $source = 'catalog') { return molavi_domain_reference_match_get($product, $source); }
function match_events($product = null) {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db,
        'SELECT * FROM reference_match_events' . ($product ? ' WHERE product_id=?' : '') . ' ORDER BY rowid', $product ? [$product] : []));
}
function sheet_product($id) {
    foreach (molavi_domain_records($GLOBALS['reconcile_tables']['Products']) as $record)
        if ((string) ($record['Product ID'] ?? '') === $id) return $record;
    return null;
}

/* --- NEGATIVE CONTROL: the base has no reconcile and no retire action --- */
if (!function_exists('molavi_domain_reference_match_reconcile')) {
    check(function_exists('molavi_domain_reference_match_reconcile'), false, 'negative: no reconcile on the base');
    check(function_exists('molavi_domain_reference_match_retire_api'), false, 'negative: no retire handler on the base');
    try {
        molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_x', 'source' => 'catalog', 'note' => 'n'], 'wp:7');
        $code = 'no_error';
    } catch (MolaviWorkflowError $e) { $code = $e->getMessage(); }
    check($code, 'invalid_body', 'negative: retire action rejected on the base');
    foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
    rmdir(MOLAVI_STATE_ROOT);
    echo 'NEGATIVE native reference match reconcile ' . $count . " cases (new behaviour absent on the base)\n";
    exit(0);
}

/* --- Fixtures: 26-column catalog rows (v2 schema) --- */
function catalog_row26($name, $code, $consumer, $unit, $package, $brand, $category, $variant = '', $volume = '', $weight = '', $count = 1) {
    return [$name, $name, 'key-' . $code, $package, $unit, $consumer, $count, '', $volume, $weight, 0,
        $variant, $brand, $category, '', 1, $code, 'TOMAN', 'rezvanpakhsh_woocommerce_store_api',
        'https://rezvanpakhsh.com/wp-json/wc/store/v1/products', '2026-11-26T00:00:00+00:00',
        $package, $unit, $package, $unit, ''];
}
$oldRows = [
    catalog_row26('چای سیاه احمد', 'REF-A', 100000, 95000, 100000, 'احمد', 'چای', '', '500 گرم'),
    catalog_row26('قهوه فوری نسکافه', 'REF-B', 100000, 98000, 100000, 'نسکافه', 'قهوه', '', '200 گرم'),
    catalog_row26('شکر سفید دولیتل', 'REF-C', 50000, 48000, 50000, 'دولیتل', 'شکر'),
    catalog_row26('روغن آفتابگردان اویلا', 'REF-D', 250000, 240000, 250000, 'اویلا', 'روغن', '', '1 لیتر'),
    catalog_row26('برنج هاشمی گیلان', 'REF-E', 300000, 290000, 300000, '', 'برنج', '', '5 کیلوگرم'),
    catalog_row26('شکلات تلخ آیدین', 'REF-G', 60000, 58000, 60000, 'آیدین', 'شکلات'),
    catalog_row26('شکلات شیری آیدین', 'REF-H', 70000, 68000, 70000, 'آیدین', 'شکلات'),
    catalog_row26('عدس سبز', 'REF-I', 120000, 115000, 120000, '', 'حبوبات', '', '900 گرم'),
    catalog_row26('نخود', 'REF-J', 50000, 50000, 50000, '', 'حبوبات', '', '900 گرم'),
    catalog_row26('لوبیا قرمز', 'REF-K', 40000, 40000, 40000, '', 'حبوبات', '', '900 گرم'),
];
$oldSourceIds = ['70001', '70002', '70003', '70004', '70005', '70006', '70007', '70008', '70009', '70010'];
$newRows = [
    catalog_row26('چای سیاه احمد', 'REF-A', 103000, 97500, 103000, 'احمد', 'چای', '', '500 گرم'),
    catalog_row26('قهوه فوری نسکافه', 'REF-B', 118000, 115000, 118000, 'نسکافه', 'قهوه', '', '200 گرم'),
    catalog_row26('روغن زیتون اویلا', 'REF-D', 250000, 240000, 250000, 'اویلا', 'روغن', '', '1 لیتر'),
    catalog_row26('برنج هاشمی گیلان', 'REF-E', 300000, 290000, 300000, '', 'برنج', '', '5 کیلوگرم'),
    catalog_row26('شکلات تلخ آیدین', 'REF-G2', 61000, 59000, 61000, 'آیدین', 'شکلات'),
    catalog_row26('شکلات شیری آیدین', 'REF-H2', 82000, 80000, 82000, 'آیدین', 'شکلات'),
    catalog_row26('عدس سبز', 'REF-I', 120000, 115000, 120000, '', 'حبوبات', '', '900 گرم'),
    catalog_row26('نخود', 'REF-J', 60000, 60000, 60000, '', 'حبوبات', '', '900 گرم'),
    catalog_row26('لوبیا قرمز', 'REF-K', 40000, 40000, 40000, '', 'حبوبات', '', '900 گرم'),
];
$newSourceIds = ['70001', '70002', '70004', '70005', '70006', '70007', '70008', '70009', '70010'];
$newSummary = ['added_count' => 0, 'removed_count' => 1, 'updated_count' => 6,
    'added' => [], 'removed' => [['source_product_id' => '70003', 'product_code' => 'REF-C']],
    'changed' => [
        ['source_product_id' => '70001', 'old_product_code' => 'REF-A', 'product_code' => 'REF-A', 'nodes' => ['prices'], 'price_changed' => true],
        ['source_product_id' => '70002', 'old_product_code' => 'REF-B', 'product_code' => 'REF-B', 'nodes' => ['prices'], 'price_changed' => true],
        ['source_product_id' => '70004', 'old_product_code' => 'REF-D', 'product_code' => 'REF-D', 'nodes' => ['name'], 'price_changed' => false],
        ['source_product_id' => '70006', 'old_product_code' => 'REF-G', 'product_code' => 'REF-G2', 'nodes' => ['sku', 'prices'], 'price_changed' => true],
        ['source_product_id' => '70007', 'old_product_code' => 'REF-H', 'product_code' => 'REF-H2', 'nodes' => ['sku', 'prices'], 'price_changed' => true],
        ['source_product_id' => '70009', 'old_product_code' => 'REF-J', 'product_code' => 'REF-J', 'nodes' => ['prices'], 'price_changed' => true],
    ],
    'reconcile_reference_ids' => ['REF-A', 'REF-B', 'REF-C', 'REF-G', 'REF-G2', 'REF-H', 'REF-H2', 'REF-J'],
    'metadata_upgrade' => false, 'order_changed' => false, 'has_changes' => true];
$nowIso = (new DateTimeImmutable('-3600 seconds', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.uP');
function snapshot_payload($rows, $sourceIds, $summary, $nowIso) {
    return ['status' => 'staged', 'extracted_at' => $nowIso, 'headers' => molavi_domain_reference_headers(),
        'rows' => $rows, 'source_product_ids' => $sourceIds, 'change_summary' => $summary];
}
$oldPayload = snapshot_payload($oldRows, $oldSourceIds, [], $nowIso);
$newPayload = snapshot_payload($newRows, $newSourceIds, $newSummary, $nowIso);
$oldId = hash('sha256', molavi_domain_json($oldPayload));
$newId = hash('sha256', molavi_domain_json($newPayload));
$oldPayload['snapshot_id'] = $oldId;
$newPayload['snapshot_id'] = $newId;
molavi_domain_tx('reference', static function (SQLite3 $db) use ($oldPayload, $newPayload, $oldId, $newId, $nowIso): void {
    foreach ([[$oldId, $oldPayload], [$newId, $newPayload]] as [$id, $payload])
        molavi_domain_sql($db, 'INSERT INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)', [$id, molavi_domain_json($payload)]);
    molavi_domain_sql($db, 'INSERT INTO reference_active VALUES (1,?)', [$oldId]);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check VALUES (1,?,?)', [$oldId, $nowIso]);
});
$oldSnap = molavi_domain_reference_snapshot($oldId);
$newSnap = molavi_domain_reference_snapshot($newId);
check($oldSnap['snapshot_id'], $oldId, 'fixture: old snapshot readable');
check(count($newSnap['rows'][0]), 26, 'fixture: v2 rows are 26 columns');

function product_record($id, $name, $brand, $category, $variant, $volume, $consumer, $offer, $ref, $snapshotId, $status = 'imported', $approval = 'approved_within_margin') {
    return ['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => $consumer, 'Sale Price' => 80000, 'Status' => $status, 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Reference Match ID' => $ref, 'Reference Snapshot ID' => $snapshotId,
        'Reference Approval State' => $approval, 'Reference Decision ID' => '', 'Reference Price Role' => 'unit',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''];
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
    product_record('p_within', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-a', 'REF-A', $oldId),
    product_record('p_outside', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'offer-b', 'REF-B', $oldId),
    product_record('p_removed', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-c', 'REF-C', $oldId),
    product_record('p_drift', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'offer-d', 'REF-D', $oldId),
    product_record('p_noprice', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 300000, 'offer-e', 'REF-E', $oldId),
    product_record('p_relink', 'شکلات تلخ آیدین', 'آیدین', 'شکلات', '', '', 60000, 'offer-g', 'REF-G', $oldId),
    product_record('p_relink_fail', 'شکلات شیری آیدین', 'آیدین', 'شکلات', '', '', 70000, 'offer-h', 'REF-H', $oldId),
    product_record('p_same', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'offer-i', 'REF-I', $oldId),
    product_record('p_skip', 'نخود', '', 'حبوبات', '', '900 گرم', 50000, 'offer-j', 'REF-J', $oldId, 'needs_review', 'outside_margin'),
    product_record('p_retire', 'لوبیا قرمز', '', 'حبوبات', '', '900 گرم', 40000, 'offer-k', 'REF-K', $oldId),
];
$offers = [
    offer_record('offer-a', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'p_within'),
    offer_record('offer-b', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'p_outside'),
    offer_record('offer-c', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_removed'),
    offer_record('offer-d', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'p_drift'),
    offer_record('offer-e', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 300000, 'p_noprice'),
    offer_record('offer-g', 'شکلات تلخ آیدین', 'آیدین', 'شکلات', '', '', 60000, 'p_relink'),
    offer_record('offer-h', 'شکلات شیری آیدین', 'آیدین', 'شکلات', '', '', 70000, 'p_relink_fail'),
    offer_record('offer-i', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'p_same'),
    offer_record('offer-j', 'نخود', '', 'حبوبات', '', '900 گرم', 50000, 'p_skip'),
    offer_record('offer-k', 'لوبیا قرمز', '', 'حبوبات', '', '900 گرم', 40000, 'p_retire'),
];
$GLOBALS['reconcile_tables'] = [
    'Products' => molavi_domain_rows_from_records(molavi_domain_product_headers(), $products),
    'Offers' => molavi_domain_rows_from_records(molavi_domain_offer_headers(), $offers),
    'Shop' => [],
];

/* Seed kept matches against the old snapshot (unit role throughout). */
function seed_match($product, $offer, $ref, $sourceId, $package, $unit, $consumer, $oldId, $status = 'active') {
    molavi_domain_reference_match_record([
        'product_id' => $product, 'source' => 'catalog', 'offer_id' => $offer, 'signature' => 'sig-' . $product,
        'reference_id' => $ref, 'price_role' => 'unit', 'snapshot_id' => $oldId, 'decision_id' => null,
        'match_basis' => 'owner_pick', 'catalog_package_price' => $package, 'catalog_unit_price' => $unit,
        'catalog_consumer_price' => $consumer, 'source_product_id' => $sourceId,
        'sheet_consumer_price' => $consumer, 'status' => $status, 'title' => $product], 'wp:7');
}
seed_match('p_within', 'offer-a', 'REF-A', '70001', 100000, 95000, 100000, $oldId);
seed_match('p_outside', 'offer-b', 'REF-B', '70002', 100000, 98000, 100000, $oldId);
seed_match('p_removed', 'offer-c', 'REF-C', '70003', 50000, 48000, 50000, $oldId);
seed_match('p_drift', 'offer-d', 'REF-D', '70004', 250000, 240000, 250000, $oldId);
seed_match('p_noprice', 'offer-e', 'REF-E', '70005', 300000, null, 300000, $oldId);
seed_match('p_relink', 'offer-g', 'REF-G', '70006', 60000, 58000, 60000, $oldId);
seed_match('p_relink_fail', 'offer-h', 'REF-H', '70007', 70000, 68000, 70000, $oldId);
seed_match('p_same', 'offer-i', 'REF-I', '70008', 120000, 115000, 120000, $oldId);
seed_match('p_skip', 'offer-j', 'REF-J', '70009', 50000, 50000, 50000, $oldId, 'needs_review');
seed_match('p_retire', 'offer-k', 'REF-K', '70010', 40000, 40000, 40000, $oldId);
/* v2 from_snapshot works: the writer fills prices from a 26-column row. */
$fromSnap = molavi_domain_reference_match_from_snapshot([
    'product_id' => 'p_probe', 'source' => 'catalog', 'offer_id' => 'offer-a', 'signature' => 'sig',
    'reference_id' => 'REF-A', 'price_role' => 'unit', 'snapshot_id' => $newId,
    'match_basis' => 'owner_pick', 'title' => 'probe'], $newSnap);
check($fromSnap['catalog_unit_price'], 97500, 'v2: from_snapshot reads a 26-column row');
check($fromSnap['source_product_id'], '70001', 'v2: from_snapshot source product id');

$pricesBefore = [];
foreach (['p_within', 'p_outside', 'p_removed', 'p_drift', 'p_noprice', 'p_relink', 'p_relink_fail', 'p_same', 'p_skip', 'p_retire'] as $pid)
    $pricesBefore[$pid] = [match_row($pid)['catalog_unit_price'], match_row($pid)['catalog_consumer_price']];
$eventsBefore = count(match_events());

/* --- Direct reconcile --- */
$clock = '2026-11-27T00:00:00.000000+00:00';
$result = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, $clock);
check($result['checked'], 9, 'reconcile: nine matches examined (ten minus the skipped needs_review)');
check($result['retained'], 1, 'reconcile: one within-margin retained');
check($result['relinked'], 1, 'reconcile: one relink');
check($result['unchanged'], 2, 'reconcile: two unchanged (same + retire)');
check($result['needs_review'], 5, 'reconcile: five need owner review');
check($result['skipped_needs_review'], 1, 'reconcile: pre-existing needs_review skipped');
check($result['already_current'], 0, 'reconcile: none already current');
$newEvents = array_slice(match_events(), $eventsBefore);
check(count($newEvents), 7, 'reconcile: seven events (within + outside + removed + drift + noprice + relink + relink-fail)');
$byProduct = [];
foreach ($newEvents as $event) $byProduct[$event['product_id']][] = $event;

/* <=5%: stays active, stored prices updated, event recorded, sheet updated. */
$row = match_row('p_within');
check($row['status'], 'active', 'within: stays active');
check([$row['catalog_unit_price'], $row['catalog_consumer_price'], $row['catalog_package_price']], [97500, 103000, 103000], 'within: stored prices updated');
check($row['snapshot_id'], $newId, 'within: snapshot id advanced');
check_true($row['verified_at'] !== null && $row['verified_at'] >= $row['checked_at'], 'within: verified_at refreshed');
check(count($byProduct['p_within']), 1, 'within: one event');
check([$byProduct['p_within'][0]['kind'], $byProduct['p_within'][0]['code']], ['price_updated', 'within_margin'], 'within: price_updated / within_margin');
check([$byProduct['p_within'][0]['old_price'], $byProduct['p_within'][0]['new_price'], $byProduct['p_within'][0]['change_ratio']], [95000, 97500, 0.0263], 'within: event old/new/ratio');
$sheet = sheet_product('p_within');
check([$sheet['Reference Snapshot ID'], $sheet['Reference Approval State'], $sheet['Reference Price Change Percent'], $sheet['Reference Review Reason']],
    [$newId, 'approved_within_margin', 2.6316, ''], 'within: sheet mirrors product_changes');

/* >5% with a sale>=regular anomaly: needs_review, stored price NOT
 * overwritten, event with old/new/ratio. The fixture's new REF-B row
 * stores a regular price equal to the sale price (unit 115000), so Q2
 * holds it as an anomaly instead of auto-adopting; the sheet Approval
 * State stays outside_margin (the review case, card path forward and
 * product-state due flags all key off it) while the event code and the
 * reason name the anomaly. */
$row = match_row('p_outside');
check($row['status'], 'needs_review', 'outside: needs_review');
check($row['catalog_unit_price'], 98000, 'outside: stored price NOT overwritten');
check($row['snapshot_id'], $oldId, 'outside: snapshot id kept as the before-evidence');
check([$byProduct['p_outside'][0]['kind'], $byProduct['p_outside'][0]['code']], ['price_changed', 'sale_not_below_regular'], 'outside: price_changed / sale_not_below_regular');
check([$byProduct['p_outside'][0]['old_price'], $byProduct['p_outside'][0]['new_price'], $byProduct['p_outside'][0]['change_ratio']], [98000, 115000, 0.1735], 'outside: event old/new/ratio');
$sheet = sheet_product('p_outside');
check([$sheet['Status'], $sheet['Reference Approval State'], $sheet['Reference Price Change Percent']],
    ['needs_review', 'outside_margin', 17.3469], 'outside: sheet held for review');
check($sheet['Reference Review Reason'], 'قیمت فروش مرجع برابر یا بیشتر از قیمت عادی است؛ قیمت تازه را بررسی کنید.', 'outside: sheet reason names the anomaly');

/* Removed: needs_review, row + prices kept, reference_removed event. */
$row = match_row('p_removed');
check($row['status'], 'needs_review', 'removed: needs_review');
check($row['reference_id'], 'REF-C', 'removed: reference id kept');
check($row['catalog_unit_price'], 48000, 'removed: stored price kept');
check([$byProduct['p_removed'][0]['kind'], $byProduct['p_removed'][0]['code'], $byProduct['p_removed'][0]['new_price']],
    ['reference_removed', 'reference_missing', null], 'removed: reference_removed / reference_missing');
$sheet = sheet_product('p_removed');
check([$sheet['Status'], $sheet['Reference Approval State']], ['needs_review', 'reference_missing'], 'removed: sheet held as missing');

/* Identity drift: name changed, price unchanged -> needs_review + new approval state. */
$row = match_row('p_drift');
check($row['status'], 'needs_review', 'drift: needs_review');
check([$byProduct['p_drift'][0]['kind'], $byProduct['p_drift'][0]['code']], ['identity_changed', 'identity_drift'], 'drift: identity_changed / identity_drift');
$sheet = sheet_product('p_drift');
check($sheet['Reference Approval State'], 'reference_identity_changed', 'drift: new approval state value');
check($sheet['Reference Review Reason'], 'مشخصات مرجع تغییر کرده؛ تطابق را بررسی کنید', 'drift: card copy');

/* Old price unavailable: needs_review + price_unavailable. */
$row = match_row('p_noprice');
check($row['status'], 'needs_review', 'noprice: needs_review');
check([$byProduct['p_noprice'][0]['kind'], $byProduct['p_noprice'][0]['code']], ['price_changed', 'reference_price_unavailable'], 'noprice: price_changed / reference_price_unavailable');
check(sheet_product('p_noprice')['Reference Approval State'], 'price_unavailable', 'noprice: sheet approval state');

/* Relink: all three conditions hold -> new code, stays active. */
$row = match_row('p_relink');
check($row['status'], 'active', 'relink: stays active');
check($row['reference_id'], 'REF-G2', 'relink: reference id updated to the new code');
check($row['source_product_id'], '70006', 'relink: source product id unchanged');
check($row['catalog_unit_price'], 59000, 'relink: stored prices updated');
check([$byProduct['p_relink'][0]['kind'], $byProduct['p_relink'][0]['code'],
    $byProduct['p_relink'][0]['old_reference_id'], $byProduct['p_relink'][0]['new_reference_id']],
    ['replaced', 'code_changed_relinked', 'REF-G', 'REF-G2'], 'relink: replaced / code_changed_relinked');
check(sheet_product('p_relink')['Reference Match ID'], 'REF-G2', 'relink: sheet match id updated');
check(sheet_product('p_relink')['Reference Approval State'], 'approved_within_margin', 'relink: sheet approval within margin');

/* Relink fails on price -> treated as entry-absent (removed), no auto-match to the new code. */
$row = match_row('p_relink_fail');
check($row['status'], 'needs_review', 'relink-fail: needs_review');
check($row['reference_id'], 'REF-H', 'relink-fail: old reference kept, new code NOT auto-matched');
check([$byProduct['p_relink_fail'][0]['kind'], $byProduct['p_relink_fail'][0]['code']], ['reference_removed', 'reference_missing'], 'relink-fail: falls back to removed');

/* Nothing changed: timestamps refreshed, no event, no sheet write. */
$row = match_row('p_same');
check($row['status'], 'active', 'same: stays active');
check($row['snapshot_id'], $newId, 'same: snapshot id advanced');
check_true($row['verified_at'] !== null, 'same: verified_at refreshed');
check(isset($byProduct['p_same']), false, 'same: no event');
check(sheet_product('p_same')['Reference Snapshot ID'], $oldId, 'same: no sheet write');
check(sheet_product('p_same')['Reference Approval State'], 'approved_within_margin', 'same: sheet untouched');

/* Pre-existing needs_review: untouched by reconcile. */
check(match_row('p_skip')['catalog_unit_price'], 50000, 'skip: stored price untouched');
check(isset($byProduct['p_skip']), false, 'skip: no event for an owner-held row');

/* INVARIANT: every row whose stored price differs from before has an event. */
foreach ($pricesBefore as $pid => $before) {
    $after = [match_row($pid)['catalog_unit_price'], match_row($pid)['catalog_consumer_price']];
    if ($after !== $before) check_true(!empty($byProduct[$pid]), 'invariant: price change on ' . $pid . ' has an event');
}

/* Second run is idempotent: no new events, no new changes. The removed
 * row is re-examined (its removal clock runs) but writes no event. */
$eventsBeforeSecond = count(match_events());
$second = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, $clock);
check(count(match_events()), $eventsBeforeSecond, 'idempotent: second reconcile writes no events');
check($second['checked'], 4, 'idempotent: the two change-listed rows plus the two removal-clock rows are re-examined');
check($second['unchanged'], 2, 'idempotent: both re-examined rows are unchanged');
check($second['removed_waiting'], 2, 'idempotent: the two removed rows wait on their clocks (under 30 days)');
check($second['skipped_needs_review'], 4, 'idempotent: four owner-held rows skipped (the clock rows are examined, not skipped)');

/* --- complete() hook: reconcile runs first, inside the publication lock --- */
$new3Rows = $newRows;
$new3Rows[0] = catalog_row26('چای سیاه احمد', 'REF-A', 104000, 99000, 104000, 'احمد', 'چای', '', '500 گرم');
$new3Summary = ['added_count' => 0, 'removed_count' => 0, 'updated_count' => 1, 'added' => [], 'removed' => [],
    'changed' => [['source_product_id' => '70001', 'old_product_code' => 'REF-A', 'product_code' => 'REF-A', 'nodes' => ['prices'], 'price_changed' => true]],
    'reconcile_reference_ids' => ['REF-A'], 'metadata_upgrade' => false, 'order_changed' => false, 'has_changes' => true];
$new3Payload = snapshot_payload($new3Rows, $newSourceIds, $new3Summary, $nowIso);
$new3Id = hash('sha256', molavi_domain_json($new3Payload));
$new3Payload['snapshot_id'] = $new3Id;
molavi_domain_tx('reference', static function (SQLite3 $db) use ($new3Payload, $new3Id): void {
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)', [$new3Id, molavi_domain_json($new3Payload)]);
    molavi_domain_sql($db, 'INSERT OR REPLACE INTO reference_projection_state VALUES (1,?)', [$new3Id]);
});
$new3Snap = molavi_domain_reference_snapshot($new3Id);
$eventsBeforeComplete = count(match_events());
$completed = molavi_domain_reference_complete($new3Snap, $newSnap, true);
check($completed['product_reconciliation_state'], 'complete', 'complete: reconciliation completes');
check($completed['reference_match_reconciliation']['checked'], 6, 'complete: reconcile examined the four stale rows plus the two removal-clock rows');
check($completed['reference_match_reconciliation']['retained'], 1, 'complete: reconcile retained the within-margin move');
check($completed['reference_match_reconciliation']['removed_waiting'], 2, 'complete: the two removed rows still wait on their clocks');
check($completed['reference_match_reconciliation']['unchanged'], 3, 'complete: reconcile left the three unchanged rows alone');
check($completed['product_reference_updates']['checked'], 0, 'complete: product_changes found nothing left (reconcile ran first)');
check(match_row('p_within')['catalog_unit_price'], 99000, 'complete: match row updated by the hooked reconcile');
$completeEvents = array_slice(match_events(), $eventsBeforeComplete);
check(count($completeEvents), 1, 'complete: exactly one new event');
check([$completeEvents[0]['product_id'], $completeEvents[0]['kind']], ['p_within', 'price_updated'], 'complete: the new event is the within-margin update');

/* --- Retire action on POST /reference/snapshots --- */
try {
    molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_retire', 'source' => 'catalog', 'note' => 'x'], 'wp:99');
    $retireCode = 'no_error';
} catch (MolaviWorkflowError $e) { $retireCode = $e->getMessage(); }
check($retireCode, 'owner_required', 'retire: non-owner rejected');
try {
    molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_retire', 'source' => 'catalog', 'note' => '   '], 'wp:7');
    $retireCode = 'no_error';
} catch (MolaviWorkflowError $e) { $retireCode = $e->getMessage(); }
check($retireCode, 'review_note_required', 'retire: note required');
try {
    molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_nope', 'source' => 'catalog', 'note' => 'x'], 'wp:7');
    $retireCode = 'no_error';
} catch (MolaviWorkflowError $e) { $retireCode = $e->getMessage(); }
check($retireCode, 'match_not_found', 'retire: unknown product 404s');
$eventsBeforeRetire = count(match_events());
$retired = molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_retire', 'source' => 'catalog', 'note' => 'Owner retired this match.'], 'wp:7');
check($retired['status'], 'retired', 'retire: row retired');
check(match_row('p_retire')['status'], 'retired', 'retire: match row status retired');
check([sheet_product('p_retire')['Reference Approval State'], sheet_product('p_retire')['Reference Match ID']], ['', ''], 'retire: sheet fields cleared');
$retireEvents = array_slice(match_events(), $eventsBeforeRetire);
check(count($retireEvents), 1, 'retire: one event');
check([$retireEvents[0]['kind'], $retireEvents[0]['code'], $retireEvents[0]['old_reference_id']], ['retired', 'owner_retired', 'REF-K'], 'retire: retired / owner_retired');
$again = molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_retire', 'source' => 'catalog', 'note' => 'Again.'], 'wp:7');
check($again['already_retired'], true, 'retire: second retire is a no-op success');
check(count(match_events()), $eventsBeforeRetire + 1, 'retire: no duplicate event');
try {
    molavi_domain_reference_match_record(['product_id' => 'p_retire', 'source' => 'catalog', 'reference_id' => 'REF-K',
        'match_basis' => 'owner_pick', 'decision_id' => null], 'wp:7');
    $retireCode = 'no_error';
} catch (MolaviWorkflowError $e) { $retireCode = $e->getMessage(); }
check($retireCode, 'match_retired', 'retire: retired row is terminal for the writer');

/* --- Owner accepts the >5% price via the card review flow --- */
molavi_domain_tx('reference', static function (SQLite3 $db) use ($newId, $nowIso): void {
    molavi_domain_sql($db, 'INSERT OR REPLACE INTO reference_active VALUES (1,?)', [$newId]);
    molavi_domain_sql($db, 'INSERT OR REPLACE INTO reference_source_check VALUES (1,?,?)', [$newId, $nowIso]);
});
$acceptCase = molavi_domain_reference_case_id('offer-b', $newId);
$acceptError = null;
try {
    $accept = molavi_domain_reference_reviews_api('POST', ['case_id' => $acceptCase, 'offer_id' => 'offer-b',
        'snapshot_id' => $newId, 'choice' => 'match', 'reference_id' => 'REF-B', 'price_role' => 'unit',
        'note' => 'Owner accepts the new coffee price.', 'expected_decision_id' => null], 'wp:7');
} catch (Throwable $e) { $acceptError = $e->getMessage(); }
check($acceptError, null, 'accept: reviews API completes');
check($accept['choice'], 'match', 'accept: decision recorded as match');
$row = match_row('p_outside');
check($row['status'], 'active', 'accept: match back to active');
check([$row['catalog_unit_price'], $row['catalog_consumer_price']], [115000, 118000], 'accept: stored prices updated to the accepted price');
check($row['snapshot_id'], $newId, 'accept: snapshot id advanced');
check($row['decision_id'], $accept['decision_id'], 'accept: match links the accept decision');
$acceptEvents = array_values(array_filter(match_events('p_outside'), static fn($event) => $event['kind'] === 'reaffirmed'));
check(count($acceptEvents), 1, 'accept: one reaffirmed event');
check([$acceptEvents[0]['old_price'], $acceptEvents[0]['new_price'], $acceptEvents[0]['change_ratio']], [100000, 118000, 0.18], 'accept: reaffirmed event carries old/new/ratio');

foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native reference match reconcile ' . $count . " cases\n";
