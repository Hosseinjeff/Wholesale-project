<?php
/** Reference auto-match backfill — looser matching harness.
 *
 * Proves the tiered rule: tier 1 (>= 0.92, price within 5%) auto-applies as
 * before; tier 2 (no tier-1 candidate, exactly one candidate >= 0.80, price
 * within 5%, no attribute conflict) auto-applies; a product with no sheet
 * Consumer Price auto-applies only on a near-exact unique name (>= 0.98)
 * and is otherwise a candidate for the owner; ambiguity, a price outside
 * the 5% band, an attribute conflict, and no catalog twin never match.
 * Boundary cases: 0.80 exactly matches (inclusive floor), 0.7619 does not;
 * 0.9091 is tier 2 (below the 0.92 tier-1 bar); 0.963 with no sheet price
 * is a candidate, not an auto-match; 1.0 with no sheet price auto-applies.
 * Fixture similarities are the real matcher outputs for these name pairs.
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-reference-backfill-looser-' . bin2hex(random_bytes(6)));
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
        if (!preg_match('/^([A-Z]+)(\\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range ' . $update['range']);
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
    catalog_row('نودل تخت شف هو', 'REF-T1', 150000, 145000, 150000, 'شف هو', 'نودل'),
    catalog_row('قهوه نسکافه', 'REF-T2A', 100000, 98000, 100000, 'نسکافه', 'قهوه', '', '200 گرم'),
    catalog_row('روغن آفتابگردان اویلا 1 لیتری', 'REF-T2B', 250000, 240000, 250000, 'اویلا', 'روغن', '', '1 لیتر'),
    catalog_row('کلوچه خرما', 'REF-T2C', 50000, 48000, 50000, '', 'کلوچه'),
    catalog_row('ماست کاله', 'REF-LOW', 90000, 85000, 90000, 'کاله', 'لبنیات', '', '500 گرم'),
    catalog_row('شکر دولیتل', 'REF-BND', 50000, 48000, 50000, 'دولیتل', 'شکر'),
    catalog_row('برنج هاشمی گیلان', 'REF-EX', 300000, 290000, 300000, '', 'برنج', '', '5 کیلوگرم'),
    catalog_row('چای سیاه احمدی', 'REF-NEAR', 100000, 95000, 100000, 'احمد', 'چای'),
    catalog_row('تن ماهی محفل', 'REF-AMB1', 180000, 175000, 180000, 'محفل', 'کنسرو'),
    catalog_row('تن ماهی محفل', 'REF-AMB2', 190000, 185000, 190000, 'محفل', 'کنسرو'),
    catalog_row('آبمیوه سانی', 'REF-OUT', 100000, 95000, 100000, 'سانی نس', 'آبمیوه'),
    catalog_row('سس هزار جزیره', 'REF-CONF', 120000, 115000, 120000, 'روژین', 'سس', 'ملایم'),
];
/* Seed the snapshot as checked an hour ago: the reference deadline needs
 * last_checked_at <= now (it carries microseconds, so "just now" can fall
 * a fraction of a second in the future and read as not yet valid). */
$nowIso = (new DateTimeImmutable('-3600 seconds', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.uP');
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
    product_record('p_tier1', 'نودل تخت شف هو', 'شف هو', 'نودل', '', '', 150000, 'offer-t1'),
    product_record('p_tier2_low', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'offer-t2a'),
    product_record('p_tier2_mid', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'offer-t2b'),
    product_record('p_tier2_high', 'کلوچه خرمایی', '', 'کلوچه', '', '', 50000, 'offer-t2c'),
    product_record('p_below', 'ماست موسیر کاله', 'کاله', 'لبنیات', '', '500 گرم', 90000, 'offer-low'),
    product_record('p_boundary', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-bnd'),
    product_record('p_exact_name', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', '', 'offer-ex'),
    product_record('p_near_candidate', 'چای سیاه احمد', 'احمد', 'چای', '', '', '', 'offer-near'),
    product_record('p_ambiguous', 'تن ماهی محفل', 'محفل', 'کنسرو', '', '', 180000, 'offer-amb'),
    product_record('p_price_out', 'آبمیوه سانی نس', 'سانی نس', 'آبمیوه', '', '', 200000, 'offer-out'),
    product_record('p_conflict', 'سس هزار جزیره روژین', 'روژین', 'سس', 'تند', '', 120000, 'offer-conf'),
    product_record('p_no_twin', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 800000, 'offer-notwin'),
    product_record('p_already', 'تن ماهی محفل', 'محفل', 'کنسرو', '', '', 150000, 'offer-alr', 'imported',
        ['Reference Match ID' => 'REF-000', 'Reference Snapshot ID' => $snapshotId, 'Reference Approval State' => 'approved_within_margin']),
];
$offers = [
    offer_record('offer-t1', 'نودل تخت شف هو', 'شف هو', 'نودل', '', '', 150000, 'p_tier1'),
    offer_record('offer-t2a', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'p_tier2_low'),
    offer_record('offer-t2b', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'p_tier2_mid'),
    offer_record('offer-t2c', 'کلوچه خرمایی', '', 'کلوچه', '', '', 50000, 'p_tier2_high'),
    offer_record('offer-low', 'ماست موسیر کاله', 'کاله', 'لبنیات', '', '500 گرم', 90000, 'p_below'),
    offer_record('offer-bnd', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'p_boundary'),
    offer_record('offer-ex', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', '', 'p_exact_name'),
    offer_record('offer-near', 'چای سیاه احمد', 'احمد', 'چای', '', '', '', 'p_near_candidate'),
    offer_record('offer-amb', 'تن ماهی محفل', 'محفل', 'کنسرو', '', '', 180000, 'p_ambiguous'),
    offer_record('offer-out', 'آبمیوه سانی نس', 'سانی نس', 'آبمیوه', '', '', 200000, 'p_price_out'),
    offer_record('offer-conf', 'سس هزار جزیره روژین', 'روژین', 'سس', 'تند', '', 120000, 'p_conflict'),
    offer_record('offer-notwin', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 800000, 'p_no_twin'),
    offer_record('offer-alr', 'تن ماهی محفل', 'محفل', 'کنسرو', '', '', 150000, 'p_already'),
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

/* --- Pure decision fixtures, through the real candidate matcher --- */
/* Tier 1 is unchanged: an exact name with an agreeing price auto-applies. */
$d = $decideFor($byId('p_tier1'), $offerById('offer-t1'));
check_true($d['matched'], 'tier1: exact name with agreeing price matches');
check($d['tier'], 'high', 'tier1: tier is high');
check($d['reference_id'], 'REF-T1', 'tier1: reference id');
check($d['name_similarity'], 1.0, 'tier1: name similarity 1.0');
/* Tier 2, just above the 0.80 floor (0.8148): auto-applies on price agreement. */
$d = $decideFor($byId('p_tier2_low'), $offerById('offer-t2a'));
check_true($d['matched'], 'tier2-low: 0.8148 name with agreeing price matches');
check($d['tier'], 'lower_similarity', 'tier2-low: tier is lower_similarity');
check($d['reference_id'], 'REF-T2A', 'tier2-low: reference id');
check($d['name_similarity'], 0.8148, 'tier2-low: name similarity 0.8148');
/* Tier 2, mid-band (0.8571). */
$d = $decideFor($byId('p_tier2_mid'), $offerById('offer-t2b'));
check_true($d['matched'], 'tier2-mid: 0.8571 name with agreeing price matches');
check($d['tier'], 'lower_similarity', 'tier2-mid: tier is lower_similarity');
check($d['reference_id'], 'REF-T2B', 'tier2-mid: reference id');
check($d['name_similarity'], 0.8571, 'tier2-mid: name similarity 0.8571');
/* Tier 2, just below the 0.92 tier-1 bar (0.9091): matches, but as tier 2, not tier 1. */
$d = $decideFor($byId('p_tier2_high'), $offerById('offer-t2c'));
check_true($d['matched'], 'tier2-high: 0.9091 name with agreeing price matches');
check($d['tier'], 'lower_similarity', 'tier2-high: 0.9091 is tier 2, not tier 1');
check($d['reference_id'], 'REF-T2C', 'tier2-high: reference id');
check($d['name_similarity'], 0.9091, 'tier2-high: name similarity 0.9091');
/* Just below the 0.80 floor (0.75): does not match. */
$d = $decideFor($byId('p_below'), $offerById('offer-low'));
check($d, ['matched' => false, 'reason' => 'no_candidate'], 'below-floor: 0.75 name does not match');
/* Exactly on the 0.80 floor: matches (the floor is inclusive). */
$d = $decideFor($byId('p_boundary'), $offerById('offer-bnd'));
check_true($d['matched'], 'boundary: 0.80 exactly matches (inclusive floor)');
check($d['tier'], 'lower_similarity', 'boundary: tier is lower_similarity');
check($d['reference_id'], 'REF-BND', 'boundary: reference id');
check($d['name_similarity'], 0.8, 'boundary: name similarity 0.8');
/* No sheet price, exact unique name (1.0): auto-applies as exact_name. */
$d = $decideFor($byId('p_exact_name'), $offerById('offer-ex'));
check_true($d['matched'], 'exact-name: 1.0 name with no sheet price matches');
check($d['tier'], 'exact_name', 'exact-name: tier is exact_name');
check($d['reference_id'], 'REF-EX', 'exact-name: reference id');
check($d['catalog_price'], 300000.0, 'exact-name: catalog price recorded');
/* No sheet price, near-exact unique name (0.963, below 0.98): a candidate for the owner, NOT an auto-match. */
$d = $decideFor($byId('p_near_candidate'), $offerById('offer-near'));
check($d['matched'], false, 'near-candidate: 0.963 name with no sheet price does not auto-apply');
check_true($d['candidate'] ?? false, 'near-candidate: it is a candidate for the owner');
check($d['reason'], 'unique_name_candidate_no_sheet_price', 'near-candidate: reason');
check($d['tier'], 'name_candidate', 'near-candidate: tier is name_candidate');
check($d['reference_id'], 'REF-NEAR', 'near-candidate: reference id');
check($d['name_similarity'], 0.963, 'near-candidate: name similarity 0.963');
check($d['catalog_price'], 100000.0, 'near-candidate: catalog price shown to the owner');
/* Ambiguity: two catalog rows with the same name at 1.0 — nothing matches. */
$d = $decideFor($byId('p_ambiguous'), $offerById('offer-amb'));
check($d, ['matched' => false, 'reason' => 'multiple_high_candidates', 'candidate_count' => 2], 'ambiguous: two high candidates left for the owner');
/* Tier-2 name (0.88) but the catalog price is 50% off the sheet price: does not match. */
$d = $decideFor($byId('p_price_out'), $offerById('offer-out'));
check($d['matched'], false, 'price-out: tier-2 name with a disagreeing price does not match');
check($d['reason'], 'price_outside_margin', 'price-out: reason');
check($d['price_ratio'], 0.5, 'price-out: ratio 0.5');
/* Tier-2 name (0.8125) but a provided attribute conflicts: does not match. */
$d = $decideFor($byId('p_conflict'), $offerById('offer-conf'));
check($d['matched'], false, 'conflict: tier-2 name with an attribute conflict does not match');
check($d['reason'], 'attribute_conflict', 'conflict: reason');
check($d['conflict_key'], 'variant', 'conflict: conflicting attribute is variant');
/* No catalog twin at all: nothing is invented. */
$d = $decideFor($byId('p_no_twin'), $offerById('offer-notwin'));
check($d, ['matched' => false, 'reason' => 'no_candidate'], 'no-twin: no catalog twin does not match');

/* --- API: explicit dry-run writes nothing --- */
$beforeTables = molavi_domain_json($GLOBALS['backfill_tables']);
$dry = molavi_domain_reference_api(['action' => 'backfill_reference_matches', 'dry_run' => true], 'wp:7');
check($dry['dry_run'], true, 'dry-run flag echoes true');
check($dry['snapshot_id'], $snapshotId, 'dry-run reports the active snapshot');
check($dry['scanned'], 12, 'dry-run scans the 12 undecided products');
check($dry['matched'], 6, 'dry-run matches six');
check($dry['candidates'], 1, 'dry-run surfaces one owner candidate');
check(array_column($dry['matches'], 'product_id'),
    ['p_tier1', 'p_tier2_low', 'p_tier2_mid', 'p_tier2_high', 'p_boundary', 'p_exact_name'], 'dry-run match list');
check(array_column($dry['candidate_list'], 'product_id'), ['p_near_candidate'], 'dry-run candidate list');
check($dry['candidate_list'][0]['reference_id'], 'REF-NEAR', 'dry-run candidate reference');
check($dry['candidate_list'][0]['tier'], 'name_candidate', 'dry-run candidate tier');
check($dry['matches'][5]['tier'], 'exact_name', 'dry-run exact-name match tier');
check($dry['matches'][1]['tier'], 'lower_similarity', 'dry-run tier-2 match tier');
check($dry['skipped'], ['no_candidate' => 2, 'multiple_high_candidates' => 1, 'price_outside_margin' => 1,
    'attribute_conflict' => 1, 'already_matched' => 1], 'dry-run skip reasons');
check(molavi_domain_json($GLOBALS['backfill_tables']), $beforeTables, 'dry-run leaves the sheets untouched');
check(molavi_domain_read('reference', static fn(SQLite3 $db) => count(molavi_domain_rows($db, 'SELECT * FROM reference_item_decisions'))), 0, 'dry-run records no decisions');

/* --- API: dry-run is the default when dry_run is omitted --- */
$default = molavi_domain_reference_api(['action' => 'backfill_reference_matches'], 'wp:7');
check($default['dry_run'], true, 'omitted dry_run defaults to dry-run');
check($default['candidates'], 1, 'default dry-run still surfaces the candidate');
check(molavi_domain_json($GLOBALS['backfill_tables']), $beforeTables, 'default dry-run leaves the sheets untouched');

/* --- API: write run --- */
$write = molavi_domain_reference_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($write['dry_run'], false, 'write run echoes dry_run false');
check($write['matched'], 6, 'write run matches six');
check($write['candidates'], 1, 'write run still reports the candidate');
check($write['written'], 6, 'write run writes six');
check($write['failures'], [], 'write run has no failures');
check_true(is_string($write['publish_job'] ?? null) && ($write['publish_job'] ?? '') !== '', 'write run queues a publish job for the projection change');
check_true(isset($write['product_status_snapshot']['snapshot_id']), 'write run captures a product status snapshot');
$exact = $byId('p_exact_name');
check($exact['Reference Match ID'], 'REF-EX', 'write: exact-name match id on the card');
check($exact['Reference Approval State'], 'approved_compatible', 'write: exact-name approval is approved_compatible');
check($exact['Reference Price Change Percent'], '', 'write: exact-name change percent is empty (no sheet price to compare)');
check_true($exact['Reference Price Checked At'] !== '', 'write: exact-name checked-at stamped');
check_true($exact['Reference Decision ID'] !== '', 'write: exact-name decision id on the card');
check($exact['Consumer Price'], '', 'write: exact-name product gains no sheet price from the catalog (the sync writes it, not the backfill)');
$low = $byId('p_tier2_low');
check($low['Reference Match ID'], 'REF-T2A', 'write: tier-2 match id on the card');
check($low['Reference Approval State'], 'approved_within_margin', 'write: tier-2 approval state is approved_within_margin');
check((float) $low['Reference Price Change Percent'], 0.0, 'write: tier-2 change percent 0 for exact agreement');
$decisions = molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db, 'SELECT * FROM reference_item_decisions ORDER BY sequence'));
check(count($decisions), 6, 'write: six decision rows recorded');
check(array_column($decisions, 'choice'), ['match', 'match', 'match', 'match', 'match', 'match'], 'write: decisions are matches');
check(array_column($decisions, 'reference_id'), ['REF-T1', 'REF-T2A', 'REF-T2B', 'REF-T2C', 'REF-BND', 'REF-EX'], 'write: decisions carry the reference ids');
check_true(str_contains($decisions[1]['note'], 'lower name similarity'), 'write: tier-2 decision note marks the lower similarity');
check_true(str_contains($decisions[5]['note'], 'exact name, no sheet price'), 'write: exact-name decision note marks the tier');
check($decisions[5]['decision_id'], $exact['Reference Decision ID'], 'write: exact-name card decision id is the recorded decision');
check($byId('p_near_candidate')['Reference Match ID'], '', 'write: candidate gains no match until the owner picks it');
check($byId('p_ambiguous')['Reference Match ID'], '', 'write: ambiguous product gains no match');
check($byId('p_price_out')['Reference Match ID'], '', 'write: outside-margin product gains no match');
check($byId('p_conflict')['Reference Match ID'], '', 'write: conflict product gains no match');
check($byId('p_no_twin')['Reference Match ID'], '', 'write: no-twin product gains no match');

/* --- Downstream consequence: the exact-agreement tier-1 match makes the regular price current. --- */
$evidenceTier1 = molavi_domain_regular_price_evidence($byId('p_tier1'), $offerById('offer-t1'), null, time());
check($evidenceTier1['state'], 'current', 'evidence: tier-1 exact-agreement match makes the regular price current via the reference deadline');
check($evidenceTier1['amount'], 150000, 'evidence: current amount is the sheet consumer price');
/* Known limit, asserted honestly: the exact-name match records the reference but the product has
 * no sheet Consumer Price, so the evidence is still missing until the regular-price sync writes
 * the catalog price to the sheet on the next tick (catalogue wins by default). The backfill does
 * not write prices; the sync is the mechanism that completes it. */
$evidenceExact = molavi_domain_regular_price_evidence($byId('p_exact_name'), $offerById('offer-ex'), null, time());
check($evidenceExact['state'], 'missing', 'evidence: exact-name match without a sheet price stays missing until the sync writes the catalog price');

/* --- Idempotency: a second write run matches nothing new, but re-surfaces the candidate --- */
$again = molavi_domain_reference_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($again['matched'], 0, 'second run matches nothing');
check($again['written'], 0, 'second run writes nothing');
check($again['candidates'], 1, 'second run still surfaces the one candidate');
check(array_column($again['candidate_list'], 'product_id'), ['p_near_candidate'], 'second run candidate is the same product');
check($again['skipped']['already_matched'] ?? 0, 7, 'second run reports the six matched products plus the pre-existing one as already matched');
check(molavi_domain_read('reference', static fn(SQLite3 $db) => count(molavi_domain_rows($db, 'SELECT * FROM reference_item_decisions'))), 6, 'second run records no further decisions');

foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native reference backfill looser matching ' . $count . " cases\n";
