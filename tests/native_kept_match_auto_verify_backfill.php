<?php
/** Kept-match wiring harness (persistent matching, slice 4).
 *
 * Proves, against the fixed tree:
 *   (1) 26-column catalog rows are accepted by matching (candidates,
 *       exact-compatible identity, match-from-snapshot) and 21-column
 *       rows still work;
 *   (2) the auto-verify catalog leg succeeds only for an active trusted
 *       kept match whose current catalog price exactly equals the sheet
 *       price — and fails with the same reason strings for no match,
 *       untrusted/dangling match, signature drift, bad price role,
 *       price mismatch, stale snapshot, expired deadline, and
 *       needs_review/retired rows — via both the latest-offer signature
 *       path and the record-derived fallback;
 *   (3) the FIX-1 link-message leg still works as fallback;
 *   (4) the kept-market-source leg verifies from a fresh picked market
 *       source and preserves the catalog leg's reason when it fails;
 *   (5) the backfill scan order: active -> already_matched,
 *       needs_review -> match_needs_review, retired -> a candidate for
 *       the owner (Q7: reason retired_match_candidate_only, never an
 *       auto-apply; a retired row whose case already has a decision
 *       still skips as reference_decision_exists), decision-without-match
 *       -> reference_decision_exists, with the adoption path reporting
 *       `adopted` on dry-run and creating the row on write-run.
 *
 * Negative control: on the unmodified wave-1 base (deff6ce) the same
 * file reports the new behaviour absent — 26-col rows are rejected as
 * invalid_reference_rows, the scan has no match_* skip reasons, and the
 * old sheet-field catalog leg still fires for a product with no match
 * row. Run: php tests/native_kept_match_auto_verify_backfill.php
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-kept-match-wiring-' . bin2hex(random_bytes(6)));
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
    'domain-market-basalam', 'domain-market-prices', 'domain-market-sources',
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
$isoNow = '2026-11-26T12:00:00+00:00';
$freshIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600);

/* --- 26-column catalog rows (schema v2): [0]-[20] as v1, [21]-[25] new --- */
function catalog_row26($name, $code, $consumer, $unit, $package, $brand, $category, $variant = '', $volume = '', $weight = '', $count = 1) {
    return [$name, $name, 'key-' . $code, $package, $unit, $consumer, $count, '', $volume, $weight, 0,
        $variant, $brand, $category, '', 1, $code, 'TOMAN', 'rezvanpakhsh_woocommerce_store_api',
        'https://rezvanpakhsh.com/wp-json/wc/store/v1/products', '2026-11-26T00:00:00+00:00',
        $package, $unit, $package, $unit, ''];
}
$catalog26 = [
    catalog_row26('چای سیاه احمد', 'REF-001', 100000, 95000, 100000, 'احمد', 'چای', '', '500 گرم'),
    catalog_row26('قهوه فوری نسکافه', 'REF-002', 100000, 98000, 100000, 'نسکافه', 'قهوه', '', '200 گرم'),
    catalog_row26('شکر سفید دولیتل', 'REF-003', 50000, 48000, 50000, 'دولیتل', 'شکر'),
    catalog_row26('روغن آفتابگردان اویلا', 'REF-004', 250000, 240000, 250000, 'اویلا', 'روغن', '', '1 لیتر'),
    catalog_row26('برنج هاشمی گیلان', 'REF-006', 300000, 290000, 300000, '', 'برنج', '', '5 کیلوگرم'),
    catalog_row26('چای سبز لیپتون', 'REF-009', 120000, 115000, 120000, 'لیپتون', 'چای', '', '100 گرم'),
];
$catalog21 = array_map(static fn($row) => array_slice($row, 0, 21), $catalog26);
$sourceIds = ['70001', '70002', '70003', '70004', '70005', '70006'];

/* --- Negative-control probe (pure matching, before any fixtures) --- */
$probeOffer = ['Product Name' => 'چای سیاه احمد', 'Brand' => 'احمد', 'Category' => 'چای',
    'Variation Type' => '', 'Package Count' => 1, 'Packaging' => '', 'Volume' => '500 گرم', 'Weight' => ''];
$probeAttrs = molavi_domain_offer_attributes($probeOffer);
$probe26 = molavi_domain_reference_candidate_match($probeAttrs, [$catalog26[0]]);
$probe21 = molavi_domain_reference_candidate_match($probeAttrs, [$catalog21[0]]);
$isFixed = ($probe26['reason'] ?? '') !== 'invalid_reference_rows';

/* --- Fixtures: active 26-column snapshot, checked an hour ago --- */
$snapshotId = hash('sha256', molavi_domain_json(['rows' => $catalog26, 'extracted_at' => $freshIso]));
molavi_domain_tx('reference', static function (SQLite3 $db) use ($catalog26, $sourceIds, $freshIso, $snapshotId): void {
    $payload = molavi_domain_json(['status' => 'staged', 'extracted_at' => $freshIso,
        'headers' => molavi_domain_reference_headers(), 'rows' => $catalog26, 'source_product_ids' => $sourceIds]);
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)', [$snapshotId, $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active VALUES (1,?)', [$snapshotId]);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check VALUES (1,?,?)', [$snapshotId, $freshIso]);
});

/* --- Fixtures: Products + Offers --- */
function product_record($id, $name, $brand, $category, $variant, $volume, $consumer, $offer, $extra = []) {
    return array_merge(['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => $consumer, 'Sale Price' => 80000, 'Status' => 'imported', 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Channel' => 'testchannel', 'Message Timestamp' => '2026-11-20T08:00:00+00:00',
        'Source Timestamp Basis' => '', 'Reference Match ID' => '', 'Reference Snapshot ID' => '',
        'Reference Approval State' => '', 'Reference Decision ID' => '', 'Reference Price Role' => '',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''], $extra);
}
function offer_record($id, $name, $brand, $category, $variant, $volume, $consumer, $productId) {
    return ['Offer ID' => $id, 'Product ID' => $productId, 'Product Name' => $name, 'Brand' => $brand,
        'Category' => $category, 'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1,
        'Packaging' => '', 'Sale Price' => 80000, 'Consumer Price' => $consumer, 'Price' => 80000, 'Actual Price' => '',
        'Status' => 'imported', 'Review Reason' => '', 'Channel Username' => 'testchannel', 'Channel' => 'testchannel',
        'Message ID' => 'msg-' . $id, 'Batch ID' => 'batch-1', 'Message Timestamp' => '2026-11-20T08:00:00+00:00',
        'Source Timestamp Basis' => '', 'Import Timestamp' => '2026-11-20T00:00:00+00:00', 'Original Message' => 'test',
        'Source Post URL' => '', 'Reference Snapshot ID' => '', 'Reference Match State' => '', 'Reference Match ID' => '',
        'Reference Price Role Hint' => '', 'Reference Decision ID' => '', 'Owner Decision ID' => '', 'Derived From Offer ID' => ''];
}
$refFields = ['Reference Match ID' => 'REF-001', 'Reference Snapshot ID' => $snapshotId, 'Reference Approval State' => 'owner_approved'];
$products = [
    product_record('av-ok', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-ok', $refFields),
    product_record('av-fallback', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'offer-av-fb',
        ['Reference Match ID' => 'REF-002', 'Reference Snapshot ID' => $snapshotId, 'Reference Approval State' => 'owner_approved']),
    product_record('av-nomatch', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-nm', $refFields),
    product_record('av-untrusted', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-ut', $refFields),
    product_record('av-dangling', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-dg', $refFields),
    product_record('av-drift', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-dr', $refFields),
    product_record('av-role', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-ro', $refFields),
    product_record('av-mismatch', 'چای سبز لیپتون', 'لیپتون', 'چای', '', '100 گرم', 100000, 'offer-av-mm',
        ['Reference Match ID' => 'REF-009', 'Reference Snapshot ID' => $snapshotId, 'Reference Approval State' => 'owner_approved']),
    product_record('av-stale', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-st', $refFields),
    product_record('av-expired', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-ex'),
    product_record('av-needs-review', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-nr', $refFields),
    product_record('av-retired', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-av-rt', $refFields),
    product_record('av-link', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'offer-av-lk'),
    product_record('av-market', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'offer-av-mk'),
    product_record('av-market-stale', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 300000, 'offer-av-ms'),
    product_record('bf-active', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-bf-ac'),
    product_record('bf-needs-review', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'offer-bf-nr'),
    product_record('bf-retired', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'offer-bf-rt'),
    product_record('bf-adopt', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', 'گلد', '200 گرم', 100000, 'offer-bf-ad',
        ['Reference Decision ID' => 'bf-adopt-decision']),
    product_record('bf-plain', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 800000, 'offer-bf-pl'),
];
$offers = [
    offer_record('offer-av-ok', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-ok'),
    // av-fallback deliberately has NO Offers row: the leg must fall back to the Products record.
    offer_record('offer-av-nm', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-nomatch'),
    offer_record('offer-av-ut', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-untrusted'),
    offer_record('offer-av-dg', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-dangling'),
    offer_record('offer-av-dr', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-drift'),
    offer_record('offer-av-ro', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-role'),
    offer_record('offer-av-mm', 'چای سبز لیپتون', 'لیپتون', 'چای', '', '100 گرم', 100000, 'av-mismatch'),
    offer_record('offer-av-st', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-stale'),
    offer_record('offer-av-ex', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-expired'),
    offer_record('offer-av-nr', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-needs-review'),
    offer_record('offer-av-rt', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'av-retired'),
    offer_record('offer-av-lk', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 50000, 'av-link'),
    offer_record('offer-av-mk', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'av-market'),
    offer_record('offer-av-ms', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 300000, 'av-market-stale'),
    offer_record('offer-bf-ac', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'bf-active'),
    offer_record('offer-bf-nr', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 100000, 'bf-needs-review'),
    offer_record('offer-bf-rt', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 250000, 'bf-retired'),
    offer_record('offer-bf-ad', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', 'گلد', '200 گرم', 100000, 'bf-adopt'),
    offer_record('offer-bf-pl', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 800000, 'bf-plain'),
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
$offerById = static fn($id) => array_values(array_filter(molavi_domain_records($GLOBALS['wiring_tables']['Offers']),
    static fn($r) => $r['Offer ID'] === $id))[0];
function offer_for(string $id, array $sheetRecords, int $nowInt): array {
    foreach (molavi_domain_freshness($sheetRecords, (float)$nowInt, false) as $offer)
        if ($offer['product_id'] === $id) return $offer;
    return [];
}

/* --- Seed decisions + kept match rows (direct SQL, checked_at fresh) --- */
function seed_decision(string $offerId, string $refId, string $signature, string $note, string $decisionId): void {
    global $snapshotId;
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($offerId, $refId, $signature, $note, $decisionId, $snapshotId): void {
        molavi_domain_sql($db, 'INSERT INTO reference_item_decisions (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,price_role,actor_id,note,created_at,supersedes_decision_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)',
            [$decisionId, molavi_domain_reference_case_id($offerId, $snapshotId), $offerId, $snapshotId, $signature, 'match', $refId, 'unit', 'wp:7', $note, '2026-11-26T00:00:00+00:00']);
    });
}
function seed_match(string $pid, string $offerId, string $refId, string $signature, $decisionId, string $status = 'active', string $role = 'unit', int $catalogConsumer = 100000): void {
    global $snapshotId, $nowInt;
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($pid, $offerId, $refId, $signature, $decisionId, $status, $role, $catalogConsumer, $snapshotId, $nowInt): void {
        $cols = ['product_id', 'source', 'offer_id', 'signature', 'reference_id', 'source_product_id', 'snapshot_id', 'price_role',
            'catalog_package_price', 'catalog_unit_price', 'catalog_consumer_price', 'catalog_supplier_price', 'sheet_consumer_price',
            'score', 'name_similarity', 'match_basis', 'decision_id', 'listing_id', 'url', 'provider', 'merchant', 'quote_json',
            'regular_price', 'pack_count', 'identity_json', 'actor_id', 'status', 'created_at', 'checked_at', 'verified_at', 'next_at'];
        $vals = [$pid, 'catalog', $offerId, $signature, $refId, '', $snapshotId, $role, null, null, $catalogConsumer, null, $catalogConsumer,
            1.0, 1.0, 'owner_pick', $decisionId, null, null, null, null, null, null, null, null, 'wp:7', $status,
            $nowInt - 3600, $nowInt - 3600, null, $nowInt - 3600 + 14 * 86400];
        molavi_domain_sql($db, 'INSERT INTO reference_product_matches (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    });
}
$sigOfOffer = static fn($offerId) => molavi_domain_reference_signature(molavi_domain_offer_attributes($offerById($offerId)));
$sigOfRecord = static fn($pid) => molavi_domain_reference_signature(molavi_domain_offer_attributes($recordById($pid)));
$sigSugar = $sigOfOffer('offer-av-lk');
$decisions = [];
foreach (['av-ok' => 'offer-av-ok', 'av-fallback' => null, 'av-drift' => 'offer-av-dr', 'av-role' => 'offer-av-ro',
    'av-mismatch' => 'offer-av-mm', 'av-stale' => 'offer-av-st', 'av-expired' => 'offer-av-ex',
    'av-needs-review' => 'offer-av-nr', 'av-retired' => 'offer-av-rt'] as $pid => $offerId) {
    $did = 'wiring-decision-' . $pid;
    $sig = $pid === 'av-fallback' ? $sigOfRecord($pid) : $sigOfOffer($offerId);
    seed_decision($offerId ?? 'offer-av-fb', $pid === 'av-fallback' ? 'REF-002' : ($pid === 'av-mismatch' ? 'REF-009' : 'REF-001'),
        $sig, 'Owner pick (harness).', $did);
    $decisions[$pid] = $did;
}
seed_decision('offer-bf-ad', 'REF-002', $sigOfOffer('offer-bf-ad'), 'Auto-match backfill: single catalog candidate.', 'bf-adopt-decision');
seed_match('av-ok', 'offer-av-ok', 'REF-001', $sigOfOffer('offer-av-ok'), $decisions['av-ok']);
seed_match('av-fallback', 'offer-av-fb', 'REF-002', $sigOfRecord('av-fallback'), $decisions['av-fallback']);
seed_match('av-untrusted', 'offer-av-ut', 'REF-001', $sigOfOffer('offer-av-ut'), null);
seed_match('av-dangling', 'offer-av-dg', 'REF-001', $sigOfOffer('offer-av-dg'), 'no-such-decision');
seed_match('av-drift', 'offer-av-dr', 'REF-001', $sigSugar, $decisions['av-drift']);
seed_match('av-role', 'offer-av-ro', 'REF-001', $sigOfOffer('offer-av-ro'), $decisions['av-role'], 'active', '');
seed_match('av-mismatch', 'offer-av-mm', 'REF-009', $sigOfOffer('offer-av-mm'), $decisions['av-mismatch'], 'active', 'unit', 120000);
seed_match('av-stale', 'offer-av-st', 'REF-001', $sigOfOffer('offer-av-st'), $decisions['av-stale']);
seed_match('av-expired', 'offer-av-ex', 'REF-001', $sigOfOffer('offer-av-ex'), $decisions['av-expired']);
seed_match('av-needs-review', 'offer-av-nr', 'REF-001', $sigOfOffer('offer-av-nr'), $decisions['av-needs-review'], 'needs_review');
seed_match('av-retired', 'offer-av-rt', 'REF-001', $sigOfOffer('offer-av-rt'), $decisions['av-retired'], 'retired');
seed_match('bf-active', 'offer-bf-ac', 'REF-001', $sigOfOffer('offer-bf-ac'), null);
seed_match('bf-needs-review', 'offer-bf-nr', 'REF-002', $sigOfOffer('offer-bf-nr'), null, 'needs_review');
seed_match('bf-retired', 'offer-bf-rt', 'REF-004', $sigOfOffer('offer-bf-rt'), null, 'retired');

/* --- Link checks: 'price_unchanged' isolates the catalog leg (the link
 * leg needs 'identical'); av-link is the one 'identical' case. --- */
function seed_link(string $id, string $reason, int $checkedAt, string $sale, string $revision): void {
    global $nowInt;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $reason, $checkedAt, $sale, $revision, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, 'offer-' . $id, 'Item ' . $id, 'https://t.me/testchannel/' . $id, $revision, $sale,
                'Item ' . $id . ' ' . $sale, 'unchanged', $reason, $sale, null, 'railway', $checkedAt, $nowInt + 86400, null,
                null, null, $nowInt - 7200]);
    });
}
$k = 0;
foreach (['av-ok', 'av-fallback', 'av-nomatch', 'av-untrusted', 'av-dangling', 'av-drift', 'av-role',
    'av-mismatch', 'av-stale', 'av-expired', 'av-needs-review', 'av-retired'] as $pid)
    seed_link($pid, 'price_unchanged', $nowInt - 5000 + ($k++) * 100, '80000', $revision[$pid]);
seed_link('av-link', 'identical', $nowInt - 5000 + ($k++) * 100, '80000', $revision['av-link']);
seed_link('av-market', 'price_unchanged', $nowInt - 5000 + ($k++) * 100, '80000', $revision['av-market']);
seed_link('av-market-stale', 'price_unchanged', $nowInt - 5000 + ($k++) * 100, '80000', $revision['av-market-stale']);

/* --- Kept market sources --- */
function seed_market_source(string $pid, string $offerId, int $regular, int $verifiedAt, string $url): void {
    global $nowInt, $recordById;
    $record = $recordById($pid);
    $identity = molavi_domain_market_identity($record);
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($pid, $offerId, $regular, $verifiedAt, $url, $identity, $record, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO market_product_sources (target,product_id,offer_id,title,identity_json,url,quote_json,regular_price,next_at,checked_at,verified_at,changed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            ['product:' . $pid, $pid, $offerId, (string)($record['Product Name'] ?? ''), molavi_domain_json($identity), $url, null, $regular, $nowInt + 86400, $verifiedAt, $verifiedAt, $nowInt]);
    });
}
seed_market_source('av-market', 'offer-av-mk', 250000, $nowInt - 3600, 'https://basalam.com/p/9');
seed_market_source('av-market-stale', 'offer-av-ms', 300000, $nowInt - 25 * 3600, 'https://basalam.com/p/10');

/* --- NEGATIVE CONTROL: the wave-1 base has none of the new behaviour --- */
if (!$isFixed) {
    check($probe26['reason'], 'invalid_reference_rows', 'negative: 26-col rows rejected on the base');
    check_true(($probe21['reason'] ?? '') !== 'invalid_reference_rows', 'negative: 21-col rows still accepted on the base');
    $scan = molavi_domain_reference_backfill_scan(molavi_domain_sheets(['Products', 'Offers'], true), molavi_domain_reference_active());
    $reasons = array_column($scan['outcomes'], 'reason');
    check_true(!in_array('match_needs_review', $reasons, true) && !in_array('match_retired', $reasons, true),
        'negative: no match_* skip reasons on the base');
    $dry = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => true], 'wp:7');
    check($dry['adopted'], 0, 'negative: 26-col adoption adopts nothing on the base');
    // The old sheet-field catalog leg still fires with no match row.
    $chk = molavi_domain_auto_verify_check($recordById('av-nomatch'), offer_for('av-nomatch', $sheetRecords, $nowInt), $nowInt);
    check_true($chk['eligible'], 'negative: sheet-field catalog leg verifies with no match row on the base');
    check($chk['evidence']['leg'] ?? null, 'catalog', 'negative: old leg reports catalog on the base');
    cleanup();
    echo 'NEGATIVE native kept-match wiring ' . $count . " cases (new behaviour absent on the base)\n";
    exit($fail === 0 ? 0 : 1);
}

/* --- (1) 26-column matching, 21-column still works --- */
$teaAttrs = molavi_domain_offer_attributes($offerById('offer-av-ok'));
$c26 = molavi_domain_reference_candidates($teaAttrs, $catalog26, 10);
check_true(count($c26) >= 1, '26-col: candidates found');
check($c26[0]['reference_id'], 'REF-001', '26-col: top candidate is the tea twin');
check_true($c26[0]['name_similarity'] >= 0.92, '26-col: name similarity >= 0.92');
check((int)$c26[0]['consumer_price'], 100000, '26-col: consumer price from [5] unchanged');
$c21 = molavi_domain_reference_candidates($teaAttrs, $catalog21, 10);
check_true(count($c21) >= 1, '21-col: candidates found');
check($c21[0]['reference_id'], 'REF-001', '21-col: top candidate is the tea twin');
check_true($c21[0]['name_similarity'] >= 0.92, '21-col: name similarity >= 0.92');
check($probe26['state'], 'candidate', '26-col: exact-compatible identity is a candidate');
check($probe26['reason'], 'exact_compatible_identity', '26-col: exact-compatible reason');
check($probe21['state'], 'candidate', '21-col: exact-compatible identity is a candidate');
$bad = molavi_domain_reference_candidate_match($teaAttrs, [array_fill(0, 20, '')]);
check($bad['reason'], 'invalid_reference_rows', 'other widths: 20-col rows still invalid');
$mixed = molavi_domain_reference_candidates($teaAttrs, [$catalog26[1], $catalog21[0]], 10);
check($mixed[0]['reference_id'], 'REF-001', 'mixed 21+26 rows: twin found across shapes');
$snapshot = molavi_domain_reference_active();
$fromSnap = molavi_domain_reference_match_from_snapshot([
    'product_id' => 'av-ok', 'source' => 'catalog', 'offer_id' => 'offer-av-ok',
    'signature' => $sigOfOffer('offer-av-ok'), 'reference_id' => 'REF-001',
    'price_role' => 'unit', 'snapshot_id' => $snapshotId, 'decision_id' => $decisions['av-ok'],
    'match_basis' => 'owner_pick', 'attributes' => $teaAttrs, 'sheet_consumer_price' => 100000,
    'title' => 'چای سیاه احمد'], $snapshot);
check_true($fromSnap !== null, '26-col: match-from-snapshot finds the row');
check([$fromSnap['catalog_package_price'], $fromSnap['catalog_unit_price'], $fromSnap['catalog_consumer_price']],
    [100000, 95000, 100000], '26-col: catalog prices from [3]/[4]/[5]');
check($fromSnap['source_product_id'], '70001', '26-col: source product id from the snapshot');

/* --- (2) auto-verify catalog leg --- */
function av_check(string $pid, array $sheetRecords, int $nowInt): array {
    global $recordById;
    return molavi_domain_auto_verify_check($recordById($pid), offer_for($pid, $sheetRecords, $nowInt), $nowInt);
}
$ev = molavi_domain_auto_verify_eligible($recordById('av-ok'), offer_for('av-ok', $sheetRecords, $nowInt), $nowInt);
check_true($ev !== null, 'catalog leg: active trusted kept match is eligible');
check($ev['leg'], 'catalog', 'catalog leg: leg is catalog');
check($ev['catalog_price'], 100000, 'catalog leg: catalog price 100000');
check($ev['reference_id'], 'REF-001', 'catalog leg: reference id');
check($ev['snapshot_id'], $snapshotId, 'catalog leg: snapshot id');
check($ev['match_id'], 'av-ok:catalog', 'catalog leg: match id in evidence');
check($ev['decision_id'], $decisions['av-ok'], 'catalog leg: decision id in evidence');
$evFb = molavi_domain_auto_verify_eligible($recordById('av-fallback'), offer_for('av-fallback', $sheetRecords, $nowInt), $nowInt);
check_true($evFb !== null, 'catalog leg: record-derived signature fallback is eligible');
check($evFb['leg'], 'catalog', 'catalog leg: fallback leg is catalog');
foreach ([['av-nomatch', 'no_reference_match', 'no match row'],
    ['av-untrusted', 'no_reference_match', 'decision_id NULL (untrusted)'],
    ['av-dangling', 'no_reference_match', 'dangling decision id (untrusted)'],
    ['av-drift', 'no_reference_match', 'signature drift'],
    ['av-role', 'reference_not_approved', 'price role not unit/package'],
    ['av-mismatch', 'catalog_price_mismatch', 'catalog price != sheet price'],
    ['av-expired', 'regular_price_expired', 'unprojected sheet (deadline 0)'],
    ['av-needs-review', 'no_reference_match', 'needs_review row'],
    ['av-retired', 'no_reference_match', 'retired row']] as [$pid, $reason, $label]) {
    $chk = av_check($pid, $sheetRecords, $nowInt);
    check($chk['eligible'], false, 'catalog leg: ' . $label . ' not eligible');
    check($chk['reason'], $reason, 'catalog leg: ' . $label . ' reason');
}

/* --- (3) FIX-1 link-message leg still works as fallback --- */
$evLink = molavi_domain_auto_verify_eligible($recordById('av-link'), offer_for('av-link', $sheetRecords, $nowInt), $nowInt);
check_true($evLink !== null, 'link leg: identical link + offer price is eligible with no match row');
check($evLink['leg'], 'link_message', 'link leg: leg is link_message');
check($evLink['catalog_price'], null, 'link leg: no catalog price');

/* --- (4) kept-market-source leg --- */
$evMarket = molavi_domain_auto_verify_eligible($recordById('av-market'), offer_for('av-market', $sheetRecords, $nowInt), $nowInt);
check_true($evMarket !== null, 'market leg: fresh picked source is eligible');
check($evMarket['leg'], 'market', 'market leg: leg is market');
check($evMarket['market_regular_price'], 250000, 'market leg: pack regular price 250000');
check($evMarket['market_url'], 'https://basalam.com/p/9', 'market leg: source url in evidence');
$chkStale = av_check('av-market-stale', $sheetRecords, $nowInt);
check($chkStale['eligible'], false, 'market leg: stale verified_at not eligible');
check($chkStale['reason'], 'no_reference_match', 'market leg: failure keeps the catalog leg reason');

/* --- one() write path for the catalog and market legs --- */
$oneOk = molavi_domain_auto_verify_one($recordById('av-ok'), offer_for('av-ok', $sheetRecords, $nowInt), $nowInt, $isoNow);
check_true(!empty($oneOk['ok']) && empty($oneOk['idempotent']), 'one(): catalog leg writes the verification');
$histOk = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
    'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY rowid DESC LIMIT 1', ['av-ok']));
check_true(str_contains((string)($histOk['note'] ?? ''), 'تأیید خودکار'), 'one(): catalog note names auto-verify');
check_true(str_contains((string)($histOk['note'] ?? ''), 'snapshot '), 'one(): catalog note names the snapshot');
$oneMarket = molavi_domain_auto_verify_one($recordById('av-market'), offer_for('av-market', $sheetRecords, $nowInt), $nowInt, $isoNow);
check_true(!empty($oneMarket['ok']) && empty($oneMarket['idempotent']), 'one(): market leg writes the verification');
$histMarket = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
    'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY rowid DESC LIMIT 1', ['av-market']));
check_true(str_contains((string)($histMarket['note'] ?? ''), 'بازار'), 'one(): market note names the market source');

/* --- (2f) stale snapshot, then restore the check row --- */
molavi_domain_tx('reference', static function (SQLite3 $db) use ($nowInt): void {
    molavi_domain_sql($db, 'UPDATE reference_source_check SET checked_at=? WHERE singleton=1',
        [gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 25 * 3600)]);
});
$chkStaleSnap = av_check('av-stale', $sheetRecords, $nowInt);
check($chkStaleSnap['eligible'], false, 'catalog leg: stale snapshot not eligible');
check($chkStaleSnap['reason'], 'catalog_stale', 'catalog leg: stale snapshot reason');
molavi_domain_tx('reference', static function (SQLite3 $db) use ($freshIso): void {
    molavi_domain_sql($db, 'UPDATE reference_source_check SET checked_at=? WHERE singleton=1', [$freshIso]);
});

/* --- (5) backfill scan order --- */
$scan = molavi_domain_reference_backfill_scan(molavi_domain_sheets(['Products', 'Offers'], true), molavi_domain_reference_active());
$byProduct = [];
foreach ($scan['outcomes'] as $outcome) $byProduct[$outcome['product_id']] = $outcome;
check($byProduct['bf-active']['reason'], 'already_matched', 'scan: active kept row -> already_matched');
check($byProduct['bf-needs-review']['reason'], 'match_needs_review', 'scan: needs_review kept row -> match_needs_review');
check($byProduct['bf-retired']['status'], 'candidate', 'scan: retired kept row -> candidate, not a skip (Q7)');
check($byProduct['bf-retired']['reason'], 'retired_match_candidate_only', 'scan: retired kept row -> retired_match_candidate_only (Q7)');
check($byProduct['av-retired']['reason'], 'reference_decision_exists', 'scan: retired row with a decision for its case -> reference_decision_exists');
check($byProduct['bf-adopt']['reason'], 'reference_decision_exists', 'scan: decision without match row -> reference_decision_exists');
check($byProduct['bf-plain']['reason'], 'no_candidate', 'scan: no twin still reaches tier logic');
check($byProduct['av-ok']['reason'], 'already_matched', 'scan: auto-verify product with active row -> already_matched');
check($byProduct['av-nomatch']['reason'], 'already_matched', 'scan: sheet-projected match (no kept row) still -> already_matched');
check($byProduct['av-needs-review']['reason'], 'match_needs_review', 'scan: auto-verify needs_review row -> match_needs_review');
check($scan['scanned'], 5, 'scan: 5 products reach tier logic (the retired bf product now reaches it as a candidate)');
$skipped = [];
foreach ($scan['outcomes'] as $outcome)
    if ($outcome['status'] === 'skipped') $skipped[$outcome['reason']] = ($skipped[$outcome['reason']] ?? 0) + 1;
check($skipped, ['already_matched' => 11, 'match_needs_review' => 2,
    'reference_decision_exists' => 2, 'no_candidate' => 1], 'scan: skip reasons (no match_retired: retired rows reach tier logic or the decision skip)');

/* --- adoption: dry-run reports it, write-run creates the row --- */
$beforeTables = molavi_domain_json($GLOBALS['wiring_tables']);
$dry = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => true], 'wp:7');
check($dry['adopted'], 1, 'adopt dry-run: one decision would adopt');
check(molavi_domain_json($GLOBALS['wiring_tables']), $beforeTables, 'adopt dry-run: sheets untouched');
check(molavi_domain_reference_match_get('bf-adopt'), null, 'adopt dry-run: nothing written');
$write = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($write['adopted'], 1, 'adopt write-run: one decision adopted');
$adoptedRow = molavi_domain_reference_match_get('bf-adopt');
check_true($adoptedRow !== null, 'adopt write-run: match row created');
check($adoptedRow['reference_id'], 'REF-002', 'adopt write-run: adopted reference id');
check($adoptedRow['decision_id'], 'bf-adopt-decision', 'adopt write-run: adopted decision linked');
check($adoptedRow['match_basis'], 'backfill', 'adopt write-run: basis backfill from the note');
check($adoptedRow['catalog_consumer_price'], 100000, 'adopt write-run: catalog price from the 26-col snapshot row');
check_true(molavi_domain_reference_match_is_trusted($adoptedRow), 'adopt write-run: adopted row is trusted');
$again = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($again['adopted'], 0, 'adopt: second run adopts nothing');

cleanup();
echo 'PASS native kept-match wiring ' . $count . " cases FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
