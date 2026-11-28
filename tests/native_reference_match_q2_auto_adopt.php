<?php
/** Q2 auto-adopt harness: catalog moves over 5% auto-adopt (owner decision
 * 2026-11-27), except flagged anomalies stay owner-held.
 * Run: php tests/native_reference_match_q2_auto_adopt.php
 * Negative control: copy this file into an unmodified wave2 tree
 * (fix/persistent-matching-wave2 @ 878c0d1) and run
 * Q2_NEGATIVE=1 php tests/native_reference_match_q2_auto_adopt.php
 * — the adopted case must report the OLD behaviour (owner-held,
 * outside_margin, digest action accept) instead of passing.
 *
 * Proves, with no browser and no provider calls:
 *   (a) >5% clean on-sale move auto-adopts: match stays active, stored
 *       prices + snapshot + verified_at updated, event price_changed /
 *       outside_margin_auto_adopted, sheet approved_auto_adopted;
 *   (b) <=5% still ledger-only (price_updated / within_margin, no
 *       digest line, no card alert);
 *   (c) anomalies still needs_review with prices preserved: sale>regular
 *       per the parser flag, sale>=regular per a stored regular price,
 *       and a missing new price;
 *   (d) removed entry still needs_review (unchanged);
 *   (e) the adopted event surfaces in the match digest (action 'none',
 *       why names the auto-adoption) and in the activity feed's
 *       selection (same kind filter as molavi_domain_dashboard_activity);
 *   (f) a second reconcile is idempotent: no new events.
 *
 * Fixture rows are REAL parser-shaped v2 rows: an on-sale row carries a
 * regular price strictly above the supply price; a single-price row
 * carries no regular price at all. A sale exactly equal to regular
 * leaves no trace in a parser-built row (the parser treats it as "no
 * sale"), so the flag + numeric checks below are the whole detectable
 * surface for sale>=regular.
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-match-q2-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['q2_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['q2_options'][$key] = $value; }
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['q2_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields($name, $row, $headers, $values) {
    foreach ($values as $field => $value) $GLOBALS['q2_tables'][$name][$row - 1][array_search($field, $headers, true)] = $value;
}
function molavi_domain_sheet_cells($name, $updates) {
    foreach ($updates as $update) {
        if (!preg_match('/^([A-Z]+)(\\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range ' . $update['range']);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $GLOBALS['q2_tables'][$name][(int) $m[2] - 1][$col - 1] = $update['values'][0][0];
    }
}
function molavi_domain_sheet_ensure($name, $headers) {
    if (empty($GLOBALS['q2_tables'][$name])) $GLOBALS['q2_tables'][$name] = [$headers];
}
function molavi_domain_sheet_append($name, $rows) {
    foreach ($rows as $row) $GLOBALS['q2_tables'][$name][] = $row;
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
    'domain-reference-workflow', 'domain-reference-backfill', 'domain-autopilot'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';
$matchesFile = __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-domain-reference-matches.php';
if (file_exists($matchesFile)) require $matchesFile;

$root = molavi_state_root();
foreach (['pipeline', 'reference'] as $store) {
    $db = new SQLite3($root . '/' . $store . '.sqlite3');
    $db->close();
}
$negative = (bool) getenv('Q2_NEGATIVE');
$count = 0;
function check($actual, $expected, $label) {
    global $count;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected))
        throw new RuntimeException($label . ' ' . molavi_domain_json(['actual' => $actual, 'expected' => $expected]));
    $count++;
}
function check_true($actual, $label) { check($actual, true, $label); }
/* New-behaviour check: in negative mode the OLD behaviour must hold instead. */
function check_q2($condition, $label, $oldCondition, $oldLabel) {
    global $negative;
    if ($negative) check_true($oldCondition, 'NEGATIVE ' . $oldLabel . ' (old behaviour on base)');
    else check_true($condition, $label);
}
function match_row($product, $source = 'catalog') { return molavi_domain_reference_match_get($product, $source); }
function match_events($product = null) {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db,
        'SELECT * FROM reference_match_events' . ($product ? ' WHERE product_id=?' : '') . ' ORDER BY rowid', $product ? [$product] : []));
}
function sheet_product($id) {
    foreach (molavi_domain_records($GLOBALS['q2_tables']['Products']) as $record)
        if ((string) ($record['Product ID'] ?? '') === $id) return $record;
    return null;
}

/* --- Fixtures: parser-shaped 26-column catalog rows --- */
function q2_row($name, $code, $consumer, $unit, $package, $brand, $category, $variant = '', $volume = '', $regularPack = '', $regularUnit = '', $anomaly = '') {
    return [$name, $name, 'key-' . $code, $package, $unit, $consumer, 1, '', $volume, '', 0,
        $variant, $brand, $category, '', 1, $code, 'TOMAN', 'rezvanpakhsh_woocommerce_store_api',
        'https://rezvanpakhsh.com/wp-json/wc/store/v1/products', '2026-11-26T00:00:00+00:00',
        $package, $unit, $regularPack, $regularUnit, $anomaly];
}
$oldRows = [
    q2_row('چای سیاه احمد', 'REF-A', 100000, 95000, 100000, 'احمد', 'چای', '', '500 گرم'),
    q2_row('قهوه فوری نسکافه', 'REF-B', 100000, 98000, 100000, 'نسکافه', 'قهوه', '', '200 گرم', 110000, 107000),
    q2_row('شکر سفید دولیتل', 'REF-C', 52000, 50000, 52000, 'دولیتل', 'شکر'),
    q2_row('روغن آفتابگردان اویلا', 'REF-D', 62000, 60000, 62000, 'اویلا', 'روغن', '', '1 لیتر'),
    q2_row('برنج هاشمی گیلان', 'REF-E', 82000, 80000, 82000, '', 'برنج', '', '5 کیلوگرم'),
    q2_row('عدس سبز', 'REF-F', 42000, 40000, 42000, '', 'حبوبات', '', '900 گرم'),
    q2_row('نخود', 'REF-G', 50000, 50000, 50000, '', 'حبوبات', '', '900 گرم'),
];
$oldSourceIds = ['70001', '70002', '70003', '70004', '70005', '70006', '70007'];
$newRows = [
    // (a) clean on-sale move: regular strictly above supply -> auto-adopt.
    q2_row('چای سیاه احمد', 'REF-A', 118000, 115000, 118000, 'احمد', 'چای', '', '500 گرم', 130000, 126000),
    // (b) within margin, on sale.
    q2_row('قهوه فوری نسکافه', 'REF-B', 102000, 100000, 102000, 'نسکافه', 'قهوه', '', '200 گرم', 112000, 109000),
    // (c1) sale>regular per the parser flag (real parser shape: no regular stored).
    q2_row('شکر سفید دولیتل', 'REF-C', 60000, 58000, 60000, 'دولیتل', 'شکر', '', '', '', '', 'sale_not_below_regular'),
    // (c2) sale>=regular per a stored regular price (regular == supply).
    q2_row('روغن آفتابگردان اویلا', 'REF-D', 72000, 70000, 72000, 'اویلا', 'روغن', '', '1 لیتر', 72000, 70000),
    // (c3) new price missing: unit column blank.
    q2_row('برنج هاشمی گیلان', 'REF-E', 90000, '', 90000, '', 'برنج', '', '5 کیلوگرم'),
    // (d) REF-F removed: no new row.
    q2_row('نخود', 'REF-G', 50000, 50000, 50000, '', 'حبوبات', '', '900 گرم'),
];
$newSourceIds = ['70001', '70002', '70003', '70004', '70005', '70007'];
$newSummary = ['added_count' => 0, 'removed_count' => 1, 'updated_count' => 5,
    'added' => [], 'removed' => [['source_product_id' => '70006', 'product_code' => 'REF-F']],
    'changed' => [
        ['source_product_id' => '70001', 'old_product_code' => 'REF-A', 'product_code' => 'REF-A', 'nodes' => ['prices'], 'price_changed' => true],
        ['source_product_id' => '70002', 'old_product_code' => 'REF-B', 'product_code' => 'REF-B', 'nodes' => ['prices'], 'price_changed' => true],
        ['source_product_id' => '70003', 'old_product_code' => 'REF-C', 'product_code' => 'REF-C', 'nodes' => ['prices'], 'price_changed' => true],
        ['source_product_id' => '70004', 'old_product_code' => 'REF-D', 'product_code' => 'REF-D', 'nodes' => ['prices'], 'price_changed' => true],
        ['source_product_id' => '70005', 'old_product_code' => 'REF-E', 'product_code' => 'REF-E', 'nodes' => ['prices'], 'price_changed' => true],
    ],
    'reconcile_reference_ids' => ['REF-A', 'REF-B', 'REF-C', 'REF-D', 'REF-E', 'REF-F'],
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

function product_record($id, $name, $brand, $category, $variant, $volume, $consumer, $offer, $ref, $snapshotId) {
    return ['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => $consumer, 'Sale Price' => 80000, 'Status' => 'imported', 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Reference Match ID' => $ref, 'Reference Snapshot ID' => $snapshotId,
        'Reference Approval State' => 'approved_within_margin', 'Reference Decision ID' => '', 'Reference Price Role' => 'unit',
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
    product_record('p_adopt', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-a', 'REF-A', $oldId),
    product_record('p_within', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'offer-b', 'REF-B', $oldId),
    product_record('p_anomaly_flag', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 52000, 'offer-c', 'REF-C', $oldId),
    product_record('p_anomaly_num', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 62000, 'offer-d', 'REF-D', $oldId),
    product_record('p_noprice', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 82000, 'offer-e', 'REF-E', $oldId),
    product_record('p_removed', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 42000, 'offer-f', 'REF-F', $oldId),
    product_record('p_same', 'نخود', '', 'حبوبات', '', '900 گرم', 50000, 'offer-g', 'REF-G', $oldId),
];
$offers = [
    offer_record('offer-a', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'p_adopt'),
    offer_record('offer-b', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'p_within'),
    offer_record('offer-c', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 52000, 'p_anomaly_flag'),
    offer_record('offer-d', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 62000, 'p_anomaly_num'),
    offer_record('offer-e', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 82000, 'p_noprice'),
    offer_record('offer-f', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 42000, 'p_removed'),
    offer_record('offer-g', 'نخود', '', 'حبوبات', '', '900 گرم', 50000, 'p_same'),
];
$GLOBALS['q2_tables'] = [
    'Products' => molavi_domain_rows_from_records(molavi_domain_product_headers(), $products),
    'Offers' => molavi_domain_rows_from_records(molavi_domain_offer_headers(), $offers),
    'Shop' => [],
];

function seed_match($product, $offer, $ref, $sourceId, $package, $unit, $consumer, $oldId) {
    molavi_domain_reference_match_record([
        'product_id' => $product, 'source' => 'catalog', 'offer_id' => $offer, 'signature' => 'sig-' . $product,
        'reference_id' => $ref, 'price_role' => 'unit', 'snapshot_id' => $oldId, 'decision_id' => null,
        'match_basis' => 'owner_pick', 'catalog_package_price' => $package, 'catalog_unit_price' => $unit,
        'catalog_consumer_price' => $consumer, 'source_product_id' => $sourceId,
        'sheet_consumer_price' => $consumer, 'status' => 'active', 'title' => $product], 'wp:7');
}
seed_match('p_adopt', 'offer-a', 'REF-A', '70001', 100000, 95000, 100000, $oldId);
seed_match('p_within', 'offer-b', 'REF-B', '70002', 100000, 98000, 100000, $oldId);
seed_match('p_anomaly_flag', 'offer-c', 'REF-C', '70003', 52000, 50000, 52000, $oldId);
seed_match('p_anomaly_num', 'offer-d', 'REF-D', '70004', 62000, 60000, 62000, $oldId);
seed_match('p_noprice', 'offer-e', 'REF-E', '70005', 82000, 80000, 82000, $oldId);
seed_match('p_removed', 'offer-f', 'REF-F', '70006', 42000, 40000, 42000, $oldId);
seed_match('p_same', 'offer-g', 'REF-G', '70007', 50000, 50000, 50000, $oldId);

$pricesBefore = [];
foreach (['p_adopt', 'p_within', 'p_anomaly_flag', 'p_anomaly_num', 'p_noprice', 'p_removed', 'p_same'] as $pid)
    $pricesBefore[$pid] = [match_row($pid)['catalog_unit_price'], match_row($pid)['catalog_consumer_price']];
$eventsBefore = count(match_events());

/* --- Direct reconcile --- */
$clock = '2026-11-27T00:00:00.000000+00:00';
$result = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, $clock);
check($result['checked'], 7, 'reconcile: seven matches examined');
check($result['retained'], 1, 'reconcile: one within-margin retained');
check($result['unchanged'], 1, 'reconcile: one unchanged');
check_q2($result['needs_review'] === 4, 'reconcile: four need owner review (2 anomalies + noprice + removed)',
    $result['needs_review'] === 5, 'reconcile: five need owner review on base (adopt held too)');
check($result['removed'], 1, 'reconcile: one removed');
check($result['price_unavailable'], 1, 'reconcile: one price unavailable');
check_q2($result['outside_margin'] === 2, 'reconcile: two outside-margin outcomes (the anomalies)',
    $result['outside_margin'] === 3, 'reconcile: three outside-margin outcomes on base (adopt held too)');
check_q2(($result['auto_adopted'] ?? 0) === 1, 'reconcile: one auto-adopted',
    ($result['auto_adopted'] ?? 0) === 0, 'reconcile: none auto-adopted on base');
$newEvents = array_slice(match_events(), $eventsBefore);
check(count($newEvents), 6, 'reconcile: six events (within + adopt + 2 anomalies + noprice + removed)');
$byProduct = [];
foreach ($newEvents as $event) $byProduct[$event['product_id']][] = $event;

/* (a) >5% clean: auto-adopts, match stays active, event recorded. */
$row = match_row('p_adopt');
check_q2($row['status'] === 'active', 'adopt: stays active',
    $row['status'] === 'needs_review', 'adopt: needs_review on base');
check_q2([$row['catalog_unit_price'], $row['catalog_consumer_price'], $row['catalog_package_price']] === [115000, 118000, 118000],
    'adopt: stored prices updated to the new catalog prices',
    $row['catalog_unit_price'] === 95000, 'adopt: stored price NOT overwritten on base');
check_q2($row['snapshot_id'] === $newId, 'adopt: snapshot id advanced',
    $row['snapshot_id'] === $oldId, 'adopt: snapshot id kept on base');
check_q2($row['verified_at'] !== null && $row['verified_at'] >= $row['checked_at'], 'adopt: verified_at refreshed',
    $row['verified_at'] === null, 'adopt: verified_at untouched on base');
check(count($byProduct['p_adopt']), 1, 'adopt: one event');
check_q2([$byProduct['p_adopt'][0]['kind'], $byProduct['p_adopt'][0]['code']] === ['price_changed', 'outside_margin_auto_adopted'],
    'adopt: price_changed / outside_margin_auto_adopted',
    [$byProduct['p_adopt'][0]['kind'], $byProduct['p_adopt'][0]['code']] === ['price_changed', 'outside_margin'],
    'adopt: price_changed / outside_margin on base');
check([$byProduct['p_adopt'][0]['old_price'], $byProduct['p_adopt'][0]['new_price'], $byProduct['p_adopt'][0]['change_ratio']],
    [95000, 115000, 0.2105], 'adopt: event old/new/ratio');
$sheet = sheet_product('p_adopt');
check_q2([$sheet['Reference Approval State'], $sheet['Reference Price Change Percent'], $sheet['Reference Review Reason']] === ['approved_auto_adopted', 21.0526, ''],
    'adopt: sheet approved_auto_adopted with the change percent and a cleared reason',
    [$sheet['Status'], $sheet['Reference Approval State']] === ['needs_review', 'outside_margin'],
    'adopt: sheet held for review on base');
check_q2($sheet['Reference Snapshot ID'] === $newId, 'adopt: sheet snapshot advanced',
    $sheet['Reference Snapshot ID'] === $oldId, 'adopt: sheet snapshot kept on base');

/* (b) <=5%: ledger-only, unchanged behaviour in both modes. */
$row = match_row('p_within');
check($row['status'], 'active', 'within: stays active');
check([$row['catalog_unit_price'], $row['catalog_consumer_price']], [100000, 102000], 'within: stored prices updated');
check(count($byProduct['p_within']), 1, 'within: one event');
check([$byProduct['p_within'][0]['kind'], $byProduct['p_within'][0]['code']], ['price_updated', 'within_margin'], 'within: price_updated / within_margin');
check(sheet_product('p_within')['Reference Approval State'], 'approved_within_margin', 'within: sheet approval within margin');

/* (c1) anomaly by parser flag: needs_review, prices preserved. */
$row = match_row('p_anomaly_flag');
check($row['status'], 'needs_review', 'anomaly-flag: needs_review');
check($row['catalog_unit_price'], 50000, 'anomaly-flag: stored price preserved');
check($row['snapshot_id'], $oldId, 'anomaly-flag: snapshot id kept');
check_q2([$byProduct['p_anomaly_flag'][0]['kind'], $byProduct['p_anomaly_flag'][0]['code']] === ['price_changed', 'sale_not_below_regular'],
    'anomaly-flag: price_changed / sale_not_below_regular',
    [$byProduct['p_anomaly_flag'][0]['kind'], $byProduct['p_anomaly_flag'][0]['code']] === ['price_changed', 'outside_margin'],
    'anomaly-flag: price_changed / outside_margin on base');
check_q2(sheet_product('p_anomaly_flag')['Reference Review Reason'] === 'قیمت فروش مرجع برابر یا بیشتر از قیمت عادی است؛ قیمت تازه را بررسی کنید.',
    'anomaly-flag: sheet reason names the anomaly',
    sheet_product('p_anomaly_flag')['Reference Review Reason'] === 'قیمت مرجع بیش از ۵٪ تغییر کرده؛ اطلاعات پیام اصلی را بررسی و به‌روزرسانی کنید.',
    'anomaly-flag: sheet reason is the outside-margin string on base');
check(sheet_product('p_anomaly_flag')['Reference Approval State'], 'outside_margin', 'anomaly-flag: sheet approval stays outside_margin');

/* (c2) anomaly by stored regular price: needs_review, prices preserved. */
$row = match_row('p_anomaly_num');
check($row['status'], 'needs_review', 'anomaly-num: needs_review');
check($row['catalog_unit_price'], 60000, 'anomaly-num: stored price preserved');
check_q2([$byProduct['p_anomaly_num'][0]['kind'], $byProduct['p_anomaly_num'][0]['code']] === ['price_changed', 'sale_not_below_regular'],
    'anomaly-num: price_changed / sale_not_below_regular',
    [$byProduct['p_anomaly_num'][0]['kind'], $byProduct['p_anomaly_num'][0]['code']] === ['price_changed', 'outside_margin'],
    'anomaly-num: price_changed / outside_margin on base');
check(sheet_product('p_anomaly_num')['Reference Approval State'], 'outside_margin', 'anomaly-num: sheet approval stays outside_margin');

/* (c3) new price missing: needs_review + price_unavailable. */
$row = match_row('p_noprice');
check($row['status'], 'needs_review', 'noprice: needs_review');
check($row['catalog_unit_price'], 80000, 'noprice: stored price preserved');
check([$byProduct['p_noprice'][0]['kind'], $byProduct['p_noprice'][0]['code']],
    ['price_changed', 'reference_price_unavailable'], 'noprice: price_changed / reference_price_unavailable');

/* (d) removed: needs_review, row + prices kept (unchanged). */
$row = match_row('p_removed');
check($row['status'], 'needs_review', 'removed: needs_review');
check($row['reference_id'], 'REF-F', 'removed: reference id kept');
check($row['catalog_unit_price'], 40000, 'removed: stored price kept');
check([$byProduct['p_removed'][0]['kind'], $byProduct['p_removed'][0]['code'], $byProduct['p_removed'][0]['new_price']],
    ['reference_removed', 'reference_missing', null], 'removed: reference_removed / reference_missing');

/* Nothing changed: timestamps refreshed, no event. */
$row = match_row('p_same');
check($row['status'], 'active', 'same: stays active');
check($row['snapshot_id'], $newId, 'same: snapshot id advanced');
check(isset($byProduct['p_same']), false, 'same: no event');

/* INVARIANT: every row whose stored price differs from before has an event. */
foreach ($pricesBefore as $pid => $before) {
    $after = [match_row($pid)['catalog_unit_price'], match_row($pid)['catalog_consumer_price']];
    if ($after !== $before) check_true(!empty($byProduct[$pid]), 'invariant: price change on ' . $pid . ' has an event');
}

/* --- (e) digest + feed surface the adopted event --- */
$since = (float) molavi_domain_timestamp($clock) - 3600;
$digest = molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_reference_match_digest_since($db, $since, 10));
$digestById = array_column($digest['entries'], null, 'product_id');
check_q2(isset($digestById['p_adopt']) && ($digestById['p_adopt']['action'] ?? '') === 'none'
        && ($digestById['p_adopt']['code'] ?? '') === 'outside_margin_auto_adopted'
        && str_contains((string) ($digestById['p_adopt']['why'] ?? ''), 'خودکار'),
    'digest: adopted entry listed with action none and an auto-adopted note',
    isset($digestById['p_adopt']) && ($digestById['p_adopt']['action'] ?? '') === 'accept'
        && ($digestById['p_adopt']['code'] ?? '') === 'outside_margin',
    'digest: adopted entry listed as a held accept on base');
check_q2(isset($digestById['p_adopt']) && ($digestById['p_adopt']['old_price'] ?? null) === 95000
        && ($digestById['p_adopt']['new_price'] ?? null) === 115000,
    'digest: adopted entry carries old->new 95000->115000',
    isset($digestById['p_adopt']) && ($digestById['p_adopt']['old_price'] ?? null) === 95000
        && ($digestById['p_adopt']['new_price'] ?? null) === 115000,
    'digest: held entry carries old->new 95000->115000 on base');
check_q2(isset($digestById['p_anomaly_flag']) && ($digestById['p_anomaly_flag']['action'] ?? '') === 'accept'
    && str_contains((string) ($digestById['p_anomaly_flag']['why'] ?? ''), 'برابر یا بیشتر'),
    'digest: anomaly entry listed as accept with the sale/regular note',
    isset($digestById['p_anomaly_flag']) && ($digestById['p_anomaly_flag']['action'] ?? '') === 'accept'
        && !str_contains((string) ($digestById['p_anomaly_flag']['why'] ?? ''), 'برابر یا بیشتر'),
    'digest: anomaly entry listed as accept without the note on base');
check_true(isset($digestById['p_removed']) && ($digestById['p_removed']['action'] ?? '') === 'replace',
    'digest: removed entry listed as replace');
check_true(!isset($digestById['p_within']), 'digest: within-margin move is ledger-only, no digest line');
check_true(!isset($digestById['p_same']), 'digest: unchanged product has no digest line');
/* Feed: the exact selection from molavi_domain_dashboard_activity(). */
$feed = molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db,
    "SELECT id,product_id,offer_id,title,kind,old_price,new_price AS regular_price,code,occurred_at FROM reference_match_events
     WHERE kind IN ('price_changed','reference_removed','identity_changed','replaced','retired') AND occurred_at>=? ORDER BY occurred_at DESC,rowid DESC LIMIT 30",
    [(int) molavi_domain_timestamp($clock) - 1209600]));
$feedById = array_column($feed, null, 'product_id');
check_true(isset($feedById['p_adopt']) && ($feedById['p_adopt']['kind'] ?? '') === 'price_changed',
    'feed: adopted event is in the activity feed selection');
check_q2(($feedById['p_adopt']['code'] ?? '') === 'outside_margin_auto_adopted',
    'feed: adopted event carries the auto-adopted code',
    ($feedById['p_adopt']['code'] ?? '') === 'outside_margin',
    'feed: held event carries outside_margin on base');
check_true(!isset($feedById['p_within']), 'feed: within-margin price_updated is not in the feed');
/* Feed label: the dashboard renders the auto-adopted code distinctly. */
$dashboardJs = (string) file_get_contents(__DIR__ . '/../shop-wp/molavi-shop/includes/owner-dashboard.js');
check_q2(str_contains($dashboardJs, "outside_margin_auto_adopted'?'قیمت مرجع تغییر کرد و خودکار پذیرفته شد'"),
    'feed: dashboard labels the auto-adopted event distinctly',
    !str_contains($dashboardJs, 'outside_margin_auto_adopted'),
    'feed: no auto-adopted label on base');

/* --- (f) second run is idempotent: no new events --- */
$eventsBeforeSecond = count(match_events());
$second = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, $clock);
check(count(match_events()), $eventsBeforeSecond, 'idempotent: second reconcile writes no events');
/* Merged tree (Q2+Q3): the removed row carries a removal clock after the
 * first run, so the second run re-examines it (removed_waiting, no event)
 * instead of skipping it — checked 3, skipped 3. On the wave2 base there
 * is no removal clock, so the removed row stays skipped (checked 1). */
check_q2($second['checked'] === 3, 'idempotent: the two change-listed active rows plus the removal-clock row are re-examined',
    $second['checked'] === 1, 'idempotent: one change-listed active row on base');
check_q2($second['unchanged'] === 2, 'idempotent: both re-examined active rows are unchanged',
    $second['unchanged'] === 1, 'idempotent: the one re-examined row is unchanged on base');
check_q2($second['skipped_needs_review'] === 3, 'idempotent: three owner-held rows skipped',
    $second['skipped_needs_review'] === 5, 'idempotent: five owner-held rows skipped on base');

foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo ($negative ? 'NEGATIVE native reference match Q2 auto-adopt ' : 'PASS native reference match Q2 auto-adopt ') . $count . " cases\n";
