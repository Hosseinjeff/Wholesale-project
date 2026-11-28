<?php
/** Q3 auto-retire + Q7 propose-after-retirement harness.
 *
 * Proves, against the fixed tree:
 *   Q3 (a) entry missing <30 days -> needs_review, not retired;
 *       (b) entry missing >=30 days -> retired, old price preserved in a
 *           retired/auto_retired event, sheet reference fields cleared,
 *           product row and prices untouched, digest lists the retirement;
 *       (c) the first-missing timestamp anchors the clock (29 days ago
 *           waits, exactly 30 days retires, 31 days retires);
 *       (c2) a pre-existing reference_missing hold with no clock yet gets
 *           stamped on the first refresh after deploy, and the 30 days run
 *           from that stamp; an entry that returns clears the clock and
 *           the row stays owner-held;
 *   Q7 (d) a retired match does not block a backfill candidate proposal;
 *       (e) the backfill never auto-applies for a retired-match product
 *           (candidate only; apply() refuses a forged matched outcome);
 *       (g) an owner pick after retirement writes a new match
 *           (replaced / owner_pick_after_retired);
 *       (h) system writers still fail match_retired on a retired row;
 *   (f) the manual owner retire path still works (owner-only, note
 *       required, retired / owner_retired event, no duplicate event,
 *       owner_retired is NOT digest-new).
 *
 * Negative control: on the unmodified wave2 base (878c0d1) the same file
 * reports the new behaviour absent — a 31-day-missing row stays
 * needs_review and a retired product scans as skipped/match_retired.
 * Run: php tests/native_reference_match_retire_propose.php
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-match-retire-propose-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['q3_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['q3_options'][$key] = $value; }
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['q3_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields($name, $row, $headers, $values) {
    foreach ($values as $field => $value) $GLOBALS['q3_tables'][$name][$row - 1][array_search($field, $headers, true)] = $value;
}
function molavi_domain_sheet_cells($name, $updates) {
    foreach ($updates as $update) {
        if (!preg_match('/^([A-Z]+)(\\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range ' . $update['range']);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $GLOBALS['q3_tables'][$name][(int) $m[2] - 1][$col - 1] = $update['values'][0][0];
    }
}
function molavi_domain_sheet_ensure($name, $headers) {
    if (empty($GLOBALS['q3_tables'][$name])) $GLOBALS['q3_tables'][$name] = [$headers];
}
function molavi_domain_sheet_append($name, $rows) {
    foreach ($rows as $row) $GLOBALS['q3_tables'][$name][] = $row;
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
    'domain-reference-workflow', 'domain-reference-backfill', 'domain-autopilot'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';

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
    foreach (molavi_domain_records($GLOBALS['q3_tables']['Products']) as $record)
        if ((string) ($record['Product ID'] ?? '') === $id) return $record;
    return null;
}
function cleanup() {
    foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
    rmdir(MOLAVI_STATE_ROOT);
}

/* --- Fixtures: 26-column catalog rows (v2 schema) --- */
function catalog_row26($name, $code, $consumer, $unit, $package, $brand, $category, $variant = '', $volume = '', $weight = '', $count = 1) {
    return [$name, $name, 'key-' . $code, $package, $unit, $consumer, $count, '', $volume, $weight, 0,
        $variant, $brand, $category, '', 1, $code, 'TOMAN', 'rezvanpakhsh_woocommerce_store_api',
        'https://rezvanpakhsh.com/wp-json/wc/store/v1/products', '2026-11-26T00:00:00+00:00',
        $package, $unit, $package, $unit, ''];
}
$oldRows = [
    catalog_row26('شکر سفید دولیتل', 'REF-OLD', 50000, 48000, 50000, 'دولیتل', 'شکر'),
    catalog_row26('عدس سبز', 'REF-KEEP', 120000, 115000, 120000, '', 'حبوبات', '', '900 گرم'),
];
$newRows = [
    catalog_row26('روغن آفتابگردان اویلا', 'REF-NEW', 250000, 240000, 250000, 'اویلا', 'روغن', '', '1 لیتر'),
    catalog_row26('عدس سبز', 'REF-KEEP', 120000, 115000, 120000, '', 'حبوبات', '', '900 گرم'),
];
$newSummary = ['added_count' => 1, 'removed_count' => 1, 'updated_count' => 0,
    'added' => [['source_product_id' => '70003', 'product_code' => 'REF-NEW']],
    'removed' => [['source_product_id' => '70001', 'product_code' => 'REF-OLD']],
    'changed' => [],
    'reconcile_reference_ids' => ['REF-OLD'],
    'metadata_upgrade' => false, 'order_changed' => false, 'has_changes' => true];
$nowIso = '2026-11-27T00:00:00.000000+00:00';
$T0 = (int) molavi_domain_timestamp('2026-11-27T00:00:00+00:00');
$day = 86400;
function snapshot_payload($rows, $sourceIds, $summary, $nowIso) {
    return ['status' => 'staged', 'extracted_at' => $nowIso, 'headers' => molavi_domain_reference_headers(),
        'rows' => $rows, 'source_product_ids' => $sourceIds, 'change_summary' => $summary];
}
$oldPayload = snapshot_payload($oldRows, ['70001', '70002'], [], $nowIso);
$newPayload = snapshot_payload($newRows, ['70003', '70002'], $newSummary, $nowIso);
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
    product_record('p_old', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-old', 'REF-OLD', $oldId),
    product_record('p_young', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-young', 'REF-OLD', $oldId),
    product_record('p_29', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-29', 'REF-OLD', $oldId, 'needs_review', 'reference_missing'),
    product_record('p_30', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-30', 'REF-OLD', $oldId, 'needs_review', 'reference_missing'),
    product_record('p_31', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-31', 'REF-OLD', $oldId, 'needs_review', 'reference_missing'),
    product_record('p_preclock', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-pre', 'REF-OLD', $oldId, 'needs_review', 'reference_missing'),
    product_record('p_returned', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'offer-ret', 'REF-KEEP', $oldId, 'needs_review', 'outside_margin'),
    product_record('p_active_bf', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'offer-abf', 'REF-KEEP', $oldId),
    product_record('p_needs_bf', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'offer-nbf', 'REF-KEEP', $oldId, 'needs_review', 'outside_margin'),
    product_record('p_retired_bf', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'offer-rt', '', '', 'imported', ''),
    product_record('p_stale_bf', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'offer-sbf', 'REF-KEEP', $oldId),
    product_record('p_manual', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'offer-man', 'REF-KEEP', $oldId),
];
$offers = [
    offer_record('offer-old', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_old'),
    offer_record('offer-young', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_young'),
    offer_record('offer-29', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_29'),
    offer_record('offer-30', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_30'),
    offer_record('offer-31', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_31'),
    offer_record('offer-pre', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_preclock'),
    offer_record('offer-ret', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'p_returned'),
    offer_record('offer-abf', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'p_active_bf'),
    offer_record('offer-nbf', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'p_needs_bf'),
    offer_record('offer-rt', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'p_retired_bf'),
    offer_record('offer-sbf', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'p_stale_bf'),
    offer_record('offer-man', 'عدس سبز', '', 'حبوبات', '', '900 گرم', 120000, 'p_manual'),
];
$GLOBALS['q3_tables'] = [
    'Products' => molavi_domain_rows_from_records(molavi_domain_product_headers(), $products),
    'Offers' => molavi_domain_rows_from_records(molavi_domain_offer_headers(), $offers),
    'Shop' => [],
];

function seed_match($product, $offer, $ref, $sourceId, $package, $unit, $consumer, $oldId, $status = 'active') {
    molavi_domain_reference_match_record([
        'product_id' => $product, 'source' => 'catalog', 'offer_id' => $offer, 'signature' => 'sig-' . $product,
        'reference_id' => $ref, 'price_role' => 'unit', 'snapshot_id' => $oldId, 'decision_id' => null,
        'match_basis' => 'owner_pick', 'catalog_package_price' => $package, 'catalog_unit_price' => $unit,
        'catalog_consumer_price' => $consumer, 'source_product_id' => $sourceId,
        'sheet_consumer_price' => $consumer, 'status' => $status, 'title' => $product], 'wp:7');
}
seed_match('p_old', 'offer-old', 'REF-OLD', '70001', 50000, 48000, 50000, $oldId);
seed_match('p_young', 'offer-young', 'REF-OLD', '70001', 50000, 48000, 50000, $oldId);
seed_match('p_29', 'offer-29', 'REF-OLD', '70001', 50000, 48000, 50000, $oldId, 'needs_review');
seed_match('p_30', 'offer-30', 'REF-OLD', '70001', 50000, 48000, 50000, $oldId, 'needs_review');
seed_match('p_31', 'offer-31', 'REF-OLD', '70001', 50000, 48000, 50000, $oldId, 'needs_review');
seed_match('p_preclock', 'offer-pre', 'REF-OLD', '70001', 50000, 48000, 50000, $oldId, 'needs_review');
seed_match('p_returned', 'offer-ret', 'REF-KEEP', '70002', 120000, 115000, 120000, $oldId, 'needs_review');
seed_match('p_active_bf', 'offer-abf', 'REF-KEEP', '70002', 120000, 115000, 120000, $oldId);
seed_match('p_needs_bf', 'offer-nbf', 'REF-KEEP', '70002', 120000, 115000, 120000, $oldId, 'needs_review');
seed_match('p_retired_bf', 'offer-rt', 'REF-OLD', '70001', 50000, 48000, 50000, $oldId, 'retired');
seed_match('p_stale_bf', 'offer-sbf', 'REF-KEEP', '70002', 120000, 115000, 120000, $oldId, 'retired');
seed_match('p_manual', 'offer-man', 'REF-KEEP', '70002', 120000, 115000, 120000, $oldId);

/* --- NEGATIVE CONTROL: the wave2 base has no removal clock --- */
if (!defined('MOLAVI_REFERENCE_MATCH_REMOVED_AUTO_RETIRE_SECONDS')) {
    check(defined('MOLAVI_REFERENCE_MATCH_REMOVED_AUTO_RETIRE_SECONDS'), false, 'negative: no auto-retire window on the base');
    $late = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, '2026-12-28T00:00:00.000000+00:00');
    check(match_row('p_old')['status'], 'needs_review', 'negative: 31-day-missing row stays needs_review on the base');
    check(array_values(array_filter(match_events('p_old'), static fn($e) => $e['kind'] === 'retired')), [], 'negative: no retired event on the base');
    $scan = molavi_domain_reference_backfill_scan($GLOBALS['q3_tables'], $newSnap);
    $byProduct = [];
    foreach ($scan['outcomes'] as $outcome) $byProduct[$outcome['product_id']] = $outcome;
    check($byProduct['p_retired_bf']['status'], 'skipped', 'negative: retired product scans as skipped on the base');
    check($byProduct['p_retired_bf']['reason'], 'match_retired', 'negative: retired product reason is match_retired on the base');
    cleanup();
    echo 'NEGATIVE native reference match retire/propose ' . $count . " cases (new behaviour absent on the base)\n";
    exit(0);
}
check(MOLAVI_REFERENCE_MATCH_REMOVED_AUTO_RETIRE_SECONDS, 2592000, 'q3: auto-retire window is 30 days');

/* Seed the first-missing stamps (direct SQL: the writer takes no removed_at). */
function set_removed_at($product, $removedAt) {
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($product, $removedAt): void {
        molavi_domain_sql($db, 'UPDATE reference_product_matches SET removed_at=? WHERE product_id=? AND source=?',
            [$removedAt, $product, 'catalog']);
    });
}
set_removed_at('p_29', $T0 - 29 * $day);
set_removed_at('p_30', $T0 - 30 * $day);
set_removed_at('p_31', $T0 - 31 * $day);
set_removed_at('p_returned', $T0 - 5 * $day);

/* --- Run 1 at T0: first observations, anchor boundary, pre-clock stamp, return --- */
$eventsBefore = count(match_events());
$r1 = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, '2026-11-27T00:00:00.000000+00:00');
check($r1['checked'], 9, 'run1: nine matches examined (ten non-retired minus the skipped needs_review)');
check($r1['unchanged'], 2, 'run1: two unchanged (active REF-KEEP rows)');
check($r1['needs_review'], 2, 'run1: two newly held (first observations of the removal)');
check($r1['removed'], 2, 'run1: two first-observation removals');
check($r1['auto_retired'], 2, 'run1: two auto-retired (30d and 31d clocks)');
check($r1['removed_waiting'], 2, 'run1: two waiting (29d clock + pre-clock stamp)');
check($r1['removed_returned'], 1, 'run1: one returned entry clears its clock');
check($r1['skipped_needs_review'], 1, 'run1: one owner-held row skipped');
check(count(match_events()) - $eventsBefore, 4, 'run1: four events (two reference_removed + two retired)');
$newEvents = array_slice(match_events(), $eventsBefore);
$byProduct = [];
foreach ($newEvents as $event) $byProduct[$event['product_id']][] = $event;

/* (a) first observation: needs_review, clock stamped at T0, event recorded. */
$row = match_row('p_young');
check($row['status'], 'needs_review', 'a: young removal stays needs_review');
check($row['removed_at'], $T0, 'a: clock stamped at first observation (T0)');
check($row['catalog_unit_price'], 48000, 'a: stored price kept');
check([$byProduct['p_young'][0]['kind'], $byProduct['p_young'][0]['code']], ['reference_removed', 'reference_missing'], 'a: reference_removed / reference_missing event');
check(sheet_product('p_young')['Reference Approval State'], 'reference_missing', 'a: sheet still says reference_missing');

/* (c) the stamp anchors the clock: 29d waits, exactly 30d retires, 31d retires. */
check(match_row('p_29')['status'], 'needs_review', 'c: 29 days missing waits');
check(isset($byProduct['p_29']), false, 'c: 29d writes no event');
check(match_row('p_30')['status'], 'retired', 'c: exactly 30 days missing retires');
check(match_row('p_31')['status'], 'retired', 'c: 31 days missing retires');
check([$byProduct['p_30'][0]['kind'], $byProduct['p_30'][0]['code'], $byProduct['p_30'][0]['old_price'], $byProduct['p_30'][0]['new_price']],
    ['retired', 'auto_retired', 48000, null], 'c: retired / auto_retired event preserves the old price');
check(match_row('p_30')['catalog_unit_price'], 48000, 'c: retired row keeps its stored price');
check(match_row('p_30')['reference_id'], 'REF-OLD', 'c: retired row keeps its old reference id');
check([sheet_product('p_30')['Reference Approval State'], sheet_product('p_30')['Reference Match ID']], ['', ''], 'c: auto-retire clears the sheet reference fields');
check(sheet_product('p_30')['Product Name'], 'شکر سفید دولیتل', 'c: product row itself untouched by retiring');
check(sheet_product('p_30')['Consumer Price'], 50000, 'c: product price untouched by retiring');

/* (c2) pre-existing hold with no clock: stamped on the first refresh, no event. */
$row = match_row('p_preclock');
check($row['status'], 'needs_review', 'c2: pre-clock hold stays needs_review');
check($row['removed_at'], $T0, 'c2: first post-deploy refresh stamps the clock (T0, not deploy time)');
check(isset($byProduct['p_preclock']), false, 'c2: stamping writes no event');

/* Returned entry: clock cleared, row stays owner-held, no event. */
$row = match_row('p_returned');
check($row['status'], 'needs_review', 'returned: stays needs_review for the owner');
check($row['removed_at'], null, 'returned: clock cleared');
check(isset($byProduct['p_returned']), false, 'returned: no event');

/* --- Run 2 at T0+10d: waiting rows wait; the 29d clock crosses 30d and retires --- */
$eventsBefore2 = count(match_events());
$r2 = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, '2026-12-07T00:00:00.000000+00:00');
check($r2['checked'], 4, 'run2: four rows re-examined (two waiting + pre-clock + the 29d clock)');
check($r2['auto_retired'], 1, 'run2: one auto-retired (29d clock is now 39d)');
check($r2['removed_waiting'], 3, 'run2: three still waiting');
check($r2['skipped_needs_review'], 2, 'run2: two owner-held rows skipped');
check(count(match_events()) - $eventsBefore2, 1, 'run2: exactly one new event');
check(match_row('p_29')['status'], 'retired', 'run2: 29d clock retires once past 30 days');
check(match_row('p_young')['status'], 'needs_review', 'run2: 10d missing still waits');
check(match_row('p_young')['removed_at'], $T0, 'run2: waiting does not move the stamp');

/* --- Run 3 at T0+31d: the T0 clocks retire --- */
$eventsBefore3 = count(match_events());
$r3 = molavi_domain_reference_match_reconcile($newSnap, $oldSnap, '2026-12-28T00:00:00.000000+00:00');
check($r3['checked'], 3, 'run3: three rows re-examined');
check($r3['auto_retired'], 3, 'run3: three auto-retired (old, young, pre-clock clocks all 31d)');
check(count(match_events()) - $eventsBefore3, 3, 'run3: three new retired events');
check(match_row('p_old')['status'], 'retired', 'b: 31d missing retires');
check(match_row('p_young')['status'], 'retired', 'b: young clock retires at 31d');
check(match_row('p_preclock')['status'], 'retired', 'b: pre-clock stamp retires 31d after the stamp');
$retiredEvents = array_values(array_filter(match_events('p_young'), static fn($e) => $e['kind'] === 'retired'));
check(count($retiredEvents), 1, 'b: exactly one retired event for the young row');
check([$retiredEvents[0]['code'], $retiredEvents[0]['old_price'], $retiredEvents[0]['new_price']], ['auto_retired', 48000, null], 'b: retired event preserves the old price');

/* --- (f) manual owner retire still works --- */
try {
    molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_manual', 'source' => 'catalog', 'note' => 'x'], 'wp:99');
    $retireCode = 'no_error';
} catch (MolaviWorkflowError $e) { $retireCode = $e->getMessage(); }
check($retireCode, 'owner_required', 'f: manual retire rejects non-owner');
try {
    molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_manual', 'source' => 'catalog', 'note' => '   '], 'wp:7');
    $retireCode = 'no_error';
} catch (MolaviWorkflowError $e) { $retireCode = $e->getMessage(); }
check($retireCode, 'review_note_required', 'f: manual retire still requires a note');
$eventsBeforeRetire = count(match_events());
$retired = molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_manual', 'source' => 'catalog', 'note' => 'Owner retired this match.'], 'wp:7');
check($retired['status'], 'retired', 'f: manual retire retires the row');
check(match_row('p_manual')['status'], 'retired', 'f: manual retire status retired');
check([sheet_product('p_manual')['Reference Approval State'], sheet_product('p_manual')['Reference Match ID']], ['', ''], 'f: manual retire clears the sheet fields');
$retireEvents = array_slice(match_events(), $eventsBeforeRetire);
check(count($retireEvents), 1, 'f: manual retire writes one event');
check([$retireEvents[0]['kind'], $retireEvents[0]['code'], $retireEvents[0]['old_reference_id'], $retireEvents[0]['old_price']],
    ['retired', 'owner_retired', 'REF-KEEP', 120000], 'f: retired / owner_retired event preserves the old price (catalog consumer price, as the retire API records it)');
$again = molavi_domain_reference_api(['action' => 'retire_reference_match', 'product_id' => 'p_manual', 'source' => 'catalog', 'note' => 'Again.'], 'wp:7');
check($again['already_retired'], true, 'f: second manual retire is a no-op success');
check(count(match_events()), $eventsBeforeRetire + 1, 'f: no duplicate event');

/* --- (b) digest: auto-retires are listed, owner-retires are not --- */
$digest = molavi_domain_read('reference', static fn(SQLite3 $db) =>
    molavi_domain_reference_match_digest_since($db, (float) ($T0 - $day), 10));
$digestByProduct = [];
foreach ($digest['entries'] as $entry) $digestByProduct[$entry['product_id']] = $entry;
check($digest['total_new'], 6, 'b-digest: six auto-retired products are digest-new');
foreach (['p_old', 'p_young', 'p_29', 'p_30', 'p_31', 'p_preclock'] as $pid) {
    check($digestByProduct[$pid]['kind'] ?? null, 'retired', 'b-digest: ' . $pid . ' listed as retired');
    check($digestByProduct[$pid]['code'] ?? null, 'auto_retired', 'b-digest: ' . $pid . ' code auto_retired');
    check($digestByProduct[$pid]['action'] ?? null, 'replace', 'b-digest: ' . $pid . ' action is replace');
    check($digestByProduct[$pid]['old_price'] ?? null, 48000, 'b-digest: ' . $pid . ' old price preserved');
}
check(isset($digestByProduct['p_manual']), false, 'b-digest: owner-retired product is NOT digest-new');
check_true(str_contains($digestByProduct['p_young']['why'] ?? '', 'بازنشسته'), 'b-digest: why line names the auto-retirement');

/* --- (d) Q7: retired match does not block a backfill candidate --- */
$scan = molavi_domain_reference_backfill_scan($GLOBALS['q3_tables'], $newSnap);
$byProduct = [];
foreach ($scan['outcomes'] as $outcome) $byProduct[$outcome['product_id']] = $outcome;
check($scan['scanned'], 9, 'd: nine products scanned (six auto-retired no-candidate + three retired candidate products)');
check($byProduct['p_retired_bf']['status'], 'candidate', 'd: retired product surfaces a candidate, not a skip');
check($byProduct['p_retired_bf']['reason'], 'retired_match_candidate_only', 'd: candidate reason names the retired match');
check($byProduct['p_retired_bf']['reference_id'], 'REF-NEW', 'd: candidate is the live REF-NEW entry');
check($byProduct['p_stale_bf']['status'], 'candidate', 'd: stale-sheet retired product also surfaces a candidate');
check($byProduct['p_stale_bf']['reason'], 'retired_match_candidate_only', 'd: stale-sheet candidate reason');
check($byProduct['p_stale_bf']['reference_id'], 'REF-KEEP', 'd: stale-sheet candidate is the live REF-KEEP entry');
check($byProduct['p_manual']['status'], 'candidate', 'd: owner-retired product also surfaces a candidate');
check($byProduct['p_manual']['reason'], 'retired_match_candidate_only', 'd: owner-retired candidate reason');
check($byProduct['p_manual']['reference_id'], 'REF-KEEP', 'd: owner-retired candidate is the live REF-KEEP entry');
check($byProduct['p_active_bf']['reason'], 'already_matched', 'd: active kept row still skips');
check($byProduct['p_needs_bf']['reason'], 'match_needs_review', 'd: needs_review kept row still skips');
$matched = array_values(array_filter($scan['outcomes'], static fn($o) => $o['status'] === 'matched'));
check($matched, [], 'd: no matched outcome anywhere in the scan');

/* --- (e) Q7: the backfill never auto-applies for a retired-match product --- */
$applied = molavi_domain_reference_backfill_apply($scan, 'wp:7');
$written = array_values(array_filter($applied, static fn($o) => !empty($o['written'])));
check($written, [], 'e: apply writes nothing for the candidate-only scan');
check(match_row('p_retired_bf')['status'], 'retired', 'e: kept row still retired after apply');
check(molavi_domain_reference_latest(molavi_domain_reference_case_id('offer-rt', $newId)), null, 'e: no decision row written for the retired product');
$fake = ['product_id' => 'p_retired_bf', 'product_name' => 'روغن آفتابگردان اویلا', 'row' => 11, 'snapshot_id' => $newId,
    'status' => 'matched', 'reason' => 'unique_price_agreed_candidate', 'offer_id' => 'offer-rt',
    'case_id' => molavi_domain_reference_case_id('offer-rt', $newId), 'signature' => str_repeat('a', 64),
    'reference_id' => 'REF-NEW', 'reference_name' => 'روغن آفتابگردان اویلا', 'score' => 1.0, 'name_similarity' => 1.0,
    'tier' => 'high', 'sheet_price' => 250000, 'catalog_price' => 250000, 'price_ratio' => 0.0];
$guarded = molavi_domain_reference_backfill_apply(['snapshot_id' => $newId, 'scanned' => 1, 'outcomes' => [$fake]], 'wp:7');
check($guarded[0]['written'], false, 'e: forged matched outcome for a retired product is refused');
check($guarded[0]['error'], 'match_retired', 'e: refusal reason is match_retired');
check(match_row('p_retired_bf')['status'], 'retired', 'e: kept row still retired after the refused apply');
check(molavi_domain_reference_latest(molavi_domain_reference_case_id('offer-rt', $newId)), null, 'e: refused apply writes no decision either');

/* --- (g) Q7: an owner pick after retirement writes a new match --- */
$pick = molavi_domain_reference_match_from_snapshot([
    'product_id' => 'p_retired_bf', 'source' => 'catalog', 'offer_id' => 'offer-rt', 'signature' => 'sig-pick',
    'reference_id' => 'REF-NEW', 'price_role' => 'unit', 'snapshot_id' => $newId,
    'decision_id' => 'dec-owner-pick-1', 'match_basis' => 'owner_pick',
    'score' => 1.0, 'name_similarity' => 1.0,
    'sheet_consumer_price' => 250000, 'title' => 'روغن آفتابگردان اویلا'], $newSnap);
check($pick !== null, true, 'g: replacement match builds from the live snapshot');
$picked = molavi_domain_reference_match_record($pick, 'wp:7');
check($picked['status'], 'active', 'g: owner pick revives the match as active');
check($picked['reference_id'], 'REF-NEW', 'g: owner pick stores the new reference');
$pickEvents = array_values(array_filter(match_events('p_retired_bf'),
    static fn($e) => $e['kind'] === 'replaced' && $e['code'] === 'owner_pick_after_retired'));
check(count($pickEvents), 1, 'g: one replaced / owner_pick_after_retired event');
check([$pickEvents[0]['old_reference_id'], $pickEvents[0]['new_reference_id']], ['REF-OLD', 'REF-NEW'], 'g: event carries old and new reference');

/* --- (h) system writers still fail match_retired on a retired row --- */
foreach ([
    ['basis' => 'backfill', 'decision_id' => 'dec-system-1', 'label' => 'backfill with a decision'],
    ['basis' => 'owner_pick', 'decision_id' => null, 'label' => 'owner basis without a decision'],
    ['basis' => 'auto_exact', 'decision_id' => null, 'label' => 'extraction exact'],
] as $attempt) {
    try {
        molavi_domain_reference_match_record([
            'product_id' => 'p_old', 'source' => 'catalog', 'offer_id' => 'offer-old', 'signature' => 'sig-p-old',
            'reference_id' => 'REF-NEW', 'price_role' => 'unit', 'snapshot_id' => $newId,
            'decision_id' => $attempt['decision_id'], 'match_basis' => $attempt['basis'],
            'sheet_consumer_price' => 50000, 'title' => 'شکر سفید دولیتل'], 'wp:7');
        $code = 'no_error';
    } catch (MolaviWorkflowError $e) { $code = $e->getMessage(); }
    check($code, 'match_retired', 'h: ' . $attempt['label'] . ' still fails match_retired');
    check(match_row('p_old')['status'], 'retired', 'h: retired row untouched by the refused write');
}

cleanup();
echo 'PASS native reference match retire/propose ' . $count . " cases\n";
