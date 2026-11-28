<?php
/** Kept catalog matches harness: writer rules, owner/backfill/adoption paths, exact-identity hook.
 * Also the negative control: on a tree without molavi-domain-reference-matches.php the same
 * file reports the new behaviour absent (no writer, no tables, no 'adopted' in the backfill result).
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-kept-matches-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['kept_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['kept_options'][$key] = $value; }
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['kept_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields($name, $row, $headers, $values) {
    foreach ($values as $field => $value) $GLOBALS['kept_tables'][$name][$row - 1][array_search($field, $headers, true)] = $value;
}
function molavi_domain_sheet_cells($name, $updates) {
    foreach ($updates as $update) {
        if (!preg_match('/^([A-Z]+)(\\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range ' . $update['range']);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $GLOBALS['kept_tables'][$name][(int) $m[2] - 1][$col - 1] = $update['values'][0][0];
    }
}
function molavi_domain_sheet_ensure($name, $headers) {
    if (empty($GLOBALS['kept_tables'][$name])) $GLOBALS['kept_tables'][$name] = [$headers];
}
function molavi_domain_sheet_append($name, $rows) {
    foreach ($rows as $row) $GLOBALS['kept_tables'][$name][] = $row;
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
    'domain-reference-backfill'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';
/* The file under test: present on the work branch, absent on the base (negative control). */
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
function match_rows() {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db, 'SELECT * FROM reference_product_matches ORDER BY product_id, source'));
}
function match_events($product = null) {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db,
        'SELECT * FROM reference_match_events' . ($product ? ' WHERE product_id=?' : '') . ' ORDER BY rowid', $product ? [$product] : []));
}

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
$sourceIds = ['70001', '70002', '70003', '70004', '70005', '70006', '70007'];
$nowIso = (new DateTimeImmutable('-3600 seconds', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.uP');
$snapshotId = hash('sha256', molavi_domain_json(['rows' => $catalog, 'extracted_at' => $nowIso]));
molavi_domain_tx('reference', static function (SQLite3 $db) use ($catalog, $sourceIds, $nowIso, $snapshotId): void {
    $payload = molavi_domain_json(['status' => 'staged', 'extracted_at' => $nowIso,
        'headers' => molavi_domain_reference_headers(), 'rows' => $catalog, 'source_product_ids' => $sourceIds]);
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)', [$snapshotId, $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active VALUES (1,?)', [$snapshotId]);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check VALUES (1,?,?)', [$snapshotId, $nowIso]);
});

function product_record($id, $name, $brand, $category, $variant, $volume, $consumer, $offer, $status = 'imported', $extra = []) {
    return array_merge(['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => $consumer, 'Sale Price' => 80000, 'Status' => $status, 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Reference Match ID' => '', 'Reference Snapshot ID' => '',
        'Reference Approval State' => '', 'Reference Decision ID' => '', 'Reference Price Role' => '',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''], $extra);
}
function offer_record($id, $name, $brand, $category, $variant, $volume, $consumer, $productId, $extra = []) {
    return array_merge(['Offer ID' => $id, 'Product ID' => $productId, 'Product Name' => $name, 'Brand' => $brand,
        'Category' => $category, 'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1,
        'Packaging' => '', 'Sale Price' => 80000, 'Consumer Price' => $consumer, 'Price' => 80000, 'Actual Price' => '',
        'Status' => 'imported', 'Review Reason' => '', 'Channel Username' => 'testchannel', 'Channel' => 'testchannel',
        'Message ID' => 'msg-' . $id, 'Batch ID' => 'batch-1', 'Message Timestamp' => '2026-11-01T00:00:00+00:00',
        'Source Timestamp Basis' => '', 'Import Timestamp' => '2026-11-20T00:00:00+00:00', 'Original Message' => 'test',
        'Source Post URL' => '', 'Reference Snapshot ID' => '', 'Reference Match State' => '', 'Reference Match ID' => '',
        'Reference Price Role Hint' => '', 'Reference Decision ID' => '', 'Owner Decision ID' => '', 'Derived From Offer ID' => ''], $extra);
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
    product_record('p_api_pick', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', 'مخصوص', '1 لیتر', 250000, 'offer-11', 'imported',
        ['Channel' => 'testchannel', 'Message Timestamp' => '2026-11-01T00:00:00+00:00', 'Batch ID' => 'batch-1']),
    product_record('p_exact', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'offer-10', 'imported',
        ['Channel Username' => 'testchannel2']),
    product_record('p_adopt_owner', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', 'سرخ‌کردنی', '1 لیتر', 250000, 'offer-12'),
    product_record('p_adopt_backfill', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', 'گلد', '200 گرم', 100000, 'offer-13'),
    product_record('p_adopt_nomatch', 'ماست موسیر کاله', 'کاله', 'لبنیات', 'ساده', '500 گرم', 90000, 'offer-14'),
    product_record('p_adopt_retired', 'برنج هاشمی گیلان', '', 'برنج', '', '10 کیلوگرم', 300000, 'offer-15'),
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
    offer_record('offer-11', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', 'مخصوص', '1 لیتر', 250000, 'p_api_pick',
        ['Reference Snapshot ID' => $snapshotId]),
    offer_record('offer-10', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 100000, 'p_exact',
        ['Channel Username' => 'testchannel2', 'Channel' => 'testchannel2',
            'Reference Match State' => 'exact_compatible_identity', 'Reference Match ID' => 'REF-001']),
    offer_record('offer-12', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', 'سرخ‌کردنی', '1 لیتر', 250000, 'p_adopt_owner'),
    offer_record('offer-13', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', 'گلد', '200 گرم', 100000, 'p_adopt_backfill'),
    offer_record('offer-14', 'ماست موسیر کاله', 'کاله', 'لبنیات', 'ساده', '500 گرم', 90000, 'p_adopt_nomatch'),
    offer_record('offer-15', 'برنج هاشمی گیلان', '', 'برنج', '', '10 کیلوگرم', 300000, 'p_adopt_retired'),
];
$GLOBALS['kept_tables'] = [
    'Products' => molavi_domain_rows_from_records(molavi_domain_product_headers(), $products),
    'Offers' => molavi_domain_rows_from_records(molavi_domain_offer_headers(), $offers),
    'Shop' => [],
];
$byId = static fn($id) => array_values(array_filter(molavi_domain_records($GLOBALS['kept_tables']['Products']),
    static fn($r) => $r['Product ID'] === $id))[0];
$offerById = static fn($id) => array_values(array_filter(molavi_domain_records($GLOBALS['kept_tables']['Offers']),
    static fn($r) => $r['Offer ID'] === $id))[0];

/* Seed pre-existing decisions for the adoption products (current case, current snapshot). */
function seed_decision($offerId, $choice, $referenceId, $note, $decisionId) {
    global $snapshotId;
    $offer = array_values(array_filter(molavi_domain_records($GLOBALS['kept_tables']['Offers']),
        static fn($r) => $r['Offer ID'] === $offerId))[0];
    $signature = molavi_domain_reference_signature(molavi_domain_offer_attributes($offer));
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($offerId, $choice, $referenceId, $note, $decisionId, $signature, $snapshotId): void {
        molavi_domain_sql($db, 'INSERT INTO reference_item_decisions (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,price_role,actor_id,note,created_at,supersedes_decision_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)',
            [$decisionId, molavi_domain_reference_case_id($offerId, $snapshotId), $offerId, $snapshotId, $signature, $choice, $referenceId, 'unit', 'wp:7', $note, '2026-11-26T00:00:00+00:00']);
    });
    return $decisionId;
}
$adoptOwnerDecision = seed_decision('offer-12', 'match', 'REF-004', 'Owner picked the oil from the card.', 'adopt-owner-decision');
$adoptBackfillDecision = seed_decision('offer-13', 'match', 'REF-002', 'Auto-match backfill: single catalog candidate.', 'adopt-backfill-decision');
seed_decision('offer-14', 'no_match', '', 'Owner says no catalog twin.', 'adopt-nomatch-decision');
$adoptRetiredDecision = seed_decision('offer-15', 'match', 'REF-006', 'Owner picked the rice.', 'adopt-retired-decision');
foreach (['p_adopt_owner' => $adoptOwnerDecision, 'p_adopt_backfill' => $adoptBackfillDecision,
    'p_adopt_nomatch' => 'adopt-nomatch-decision', 'p_adopt_retired' => $adoptRetiredDecision] as $pid => $did) {
    $headers = $GLOBALS['kept_tables']['Products'][0];
    $col = array_search('Reference Decision ID', $headers, true);
    foreach ($GLOBALS['kept_tables']['Products'] as $i => $row) {
        if ($i > 0 && $row[array_search('Product ID', $headers, true)] === $pid) $GLOBALS['kept_tables']['Products'][$i][$col] = $did;
    }
}

/* --- NEGATIVE CONTROL: the base has no writer, no tables, no 'adopted' --- */
if (!function_exists('molavi_domain_reference_match_record')) {
    check(function_exists('molavi_domain_reference_match_record'), false, 'negative: no writer on the base');
    check(molavi_domain_read('reference', static fn(SQLite3 $db) => (int) molavi_domain_row($db,
        "SELECT COUNT(*) AS n FROM sqlite_master WHERE name IN ('reference_product_matches','reference_match_events')")['n']), 0, 'negative: no match tables on the base');
    $dry = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => true], 'wp:7');
    check(array_key_exists('adopted', $dry), false, 'negative: no adopted count in the base backfill result');
    foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
    rmdir(MOLAVI_STATE_ROOT);
    echo 'NEGATIVE native reference kept matches ' . $count . " cases (new behaviour absent on the base)\n";
    exit(0);
}

/* --- DDL --- */
$matchCols = molavi_domain_read('reference', static fn(SQLite3 $db) => array_column(
    molavi_domain_rows($db, 'PRAGMA table_info(reference_product_matches)'), 'name'));
check($matchCols, ['product_id', 'source', 'offer_id', 'signature', 'reference_id', 'source_product_id', 'snapshot_id', 'price_role',
    'catalog_package_price', 'catalog_unit_price', 'catalog_consumer_price', 'catalog_supplier_price', 'sheet_consumer_price',
    'score', 'name_similarity', 'match_basis', 'decision_id', 'listing_id', 'url', 'provider', 'merchant', 'quote_json',
    'regular_price', 'pack_count', 'identity_json', 'actor_id', 'status', 'created_at', 'checked_at', 'verified_at', 'next_at'],
    'DDL: match table columns in order');
$eventCols = molavi_domain_read('reference', static fn(SQLite3 $db) => array_column(
    molavi_domain_rows($db, 'PRAGMA table_info(reference_match_events)'), 'name'));
check($eventCols, ['id', 'product_id', 'offer_id', 'title', 'kind', 'source', 'old_reference_id', 'new_reference_id',
    'old_price', 'new_price', 'change_ratio', 'code', 'occurred_at', 'publish_pending'], 'DDL: event table columns mirror market_source_events + match fields');
$marketCols = molavi_domain_read('reference', static fn(SQLite3 $db) => array_column(
    molavi_domain_rows($db, 'PRAGMA table_info(market_product_sources)'), 'name'));
check_true(in_array('match_basis', $marketCols, true) && in_array('matched_by', $marketCols, true)
    && in_array('matched_at', $marketCols, true) && in_array('match_status', $marketCols, true), 'DDL: market source columns present');
check(molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
    "SELECT sql FROM sqlite_master WHERE name='reference_product_matches_due'")['sql'] ?? ''),
    'CREATE INDEX reference_product_matches_due ON reference_product_matches(status, next_at)', 'DDL: (status, next_at) index');

/* --- Writer: owner decision creates the row + decision link + created event --- */
$snapshot = molavi_domain_reference_active();
$writerDecision = 'writer-owner-decision';
molavi_domain_tx('reference', static function (SQLite3 $db) use ($writerDecision, $snapshotId): void {
    molavi_domain_sql($db, 'INSERT INTO reference_item_decisions (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,price_role,actor_id,note,created_at,supersedes_decision_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)',
        [$writerDecision, 'item:writer-case', 'offer-1', $snapshotId, 'writer-signature', 'match', 'REF-001', 'unit', 'wp:7', 'Owner pick.', '2026-11-26T00:00:00+00:00']);
});
$attrs = molavi_domain_offer_attributes($offerById('offer-1'));
$row = molavi_domain_reference_match_record(molavi_domain_reference_match_from_snapshot([
    'product_id' => 'p_writer', 'source' => 'catalog', 'offer_id' => 'offer-1',
    'signature' => molavi_domain_reference_signature($attrs), 'reference_id' => 'REF-001',
    'price_role' => 'unit', 'snapshot_id' => $snapshotId, 'decision_id' => $writerDecision,
    'match_basis' => 'owner_pick', 'attributes' => $attrs, 'sheet_consumer_price' => 100000,
    'title' => 'چای سیاه احمد'], $snapshot), 'wp:7');
check($row['reference_id'], 'REF-001', 'writer: reference id kept');
check($row['decision_id'], $writerDecision, 'writer: decision id linked');
check($row['status'], 'active', 'writer: status active');
check([$row['catalog_package_price'], $row['catalog_unit_price'], $row['catalog_consumer_price']], [100000, 95000, 100000], 'writer: catalog prices from the snapshot row');
check($row['catalog_supplier_price'], null, 'writer: reserved supplier price stays NULL');
check($row['source_product_id'], '70001', 'writer: source product id from the snapshot');
check($row['sheet_consumer_price'], 100000, 'writer: sheet consumer price recorded');
check_true($row['score'] !== null && $row['score'] >= 0.92, 'writer: score from the live candidates');
check_true($row['name_similarity'] !== null && $row['name_similarity'] >= 0.92, 'writer: name similarity from the live candidates');
check($row['next_at'] - $row['created_at'], 1209600, 'writer: next_at is created_at + 14 days');
check_true(molavi_domain_reference_match_is_trusted($row), 'writer: row with a real decision is trusted');
$events = match_events('p_writer');
check(count($events), 1, 'writer: one event');
check([$events[0]['kind'], $events[0]['new_reference_id'], $events[0]['old_reference_id']], ['created', 'REF-001', null], 'writer: created event');

/* Untrusted: no decision row, or a decision id that does not exist. */
$untrusted = molavi_domain_reference_match_record([
    'product_id' => 'p_untrusted', 'source' => 'catalog', 'offer_id' => 'offer-2',
    'signature' => 'sig', 'reference_id' => 'REF-002', 'price_role' => 'unit',
    'snapshot_id' => $snapshotId, 'decision_id' => null, 'match_basis' => 'auto_exact',
    'catalog_package_price' => 100000, 'catalog_unit_price' => 98000, 'catalog_consumer_price' => 100000,
    'title' => 'قهوه فوری نسکافه'], 'system:extraction');
check_true(!molavi_domain_reference_match_is_trusted($untrusted), 'trust: match without a decision is untrusted');
check_true(!molavi_domain_reference_match_is_trusted(molavi_domain_reference_match_get('p_untrusted') + ['decision_id' => 'no-such-decision']),
    'trust: match with a dangling decision id is untrusted');

/* Reaffirm: same reference, same prices -> no new event, checked_at bumped. */
molavi_domain_tx('reference', static fn(SQLite3 $db) => molavi_domain_sql($db,
    "UPDATE reference_product_matches SET checked_at=1 WHERE product_id='p_writer'"));
$again = molavi_domain_reference_match_record(molavi_domain_reference_match_from_snapshot([
    'product_id' => 'p_writer', 'source' => 'catalog', 'offer_id' => 'offer-1',
    'signature' => molavi_domain_reference_signature($attrs), 'reference_id' => 'REF-001',
    'price_role' => 'unit', 'snapshot_id' => $snapshotId, 'decision_id' => $writerDecision,
    'match_basis' => 'owner_pick', 'attributes' => $attrs, 'sheet_consumer_price' => 100000,
    'title' => 'چای سیاه احمد'], $snapshot), 'wp:7');
check(count(match_rows()), 2, 'reaffirm: still one row per product (2 products so far)');
check(count(match_events('p_writer')), 1, 'reaffirm: unchanged re-record writes no event');
check_true($again['checked_at'] > 1, 'reaffirm: checked_at bumped');

/* Replace: a different reference for the same product -> replaced event. */
$replaced = molavi_domain_reference_match_record(molavi_domain_reference_match_from_snapshot([
    'product_id' => 'p_writer', 'source' => 'catalog', 'offer_id' => 'offer-2',
    'signature' => 'sig2', 'reference_id' => 'REF-002', 'price_role' => 'unit',
    'snapshot_id' => $snapshotId, 'decision_id' => $writerDecision,
    'match_basis' => 'owner_pick', 'attributes' => molavi_domain_offer_attributes($offerById('offer-2')),
    'sheet_consumer_price' => 102000, 'title' => 'قهوه فوری نسکافه'], $snapshot), 'wp:7');
check($replaced['reference_id'], 'REF-002', 'replace: new reference kept');
$events = match_events('p_writer');
check(count($events), 2, 'replace: second event recorded');
check([$events[1]['kind'], $events[1]['old_reference_id'], $events[1]['new_reference_id']], ['replaced', 'REF-001', 'REF-002'], 'replace: replaced event carries old and new reference');

/* needs_review blocks a replacement, but not a reaffirm of the same reference. */
molavi_domain_tx('reference', static fn(SQLite3 $db) => molavi_domain_sql($db,
    "UPDATE reference_product_matches SET status='needs_review' WHERE product_id='p_writer'"));
try {
    molavi_domain_reference_match_record(['product_id' => 'p_writer', 'source' => 'catalog', 'reference_id' => 'REF-003A',
        'match_basis' => 'owner_pick', 'decision_id' => $writerDecision], 'wp:7');
    $blocked = false;
} catch (MolaviWorkflowError $e) { $blocked = $e->getMessage() === 'match_needs_review'; }
check_true($blocked, 'needs_review: replacement blocked');
$safe = molavi_domain_reference_match_record(['product_id' => 'p_writer', 'source' => 'catalog', 'reference_id' => 'REF-002',
    'match_basis' => 'owner_pick', 'decision_id' => $writerDecision], 'wp:7');
check($safe['status'], 'needs_review', 'needs_review: same-reference reaffirm keeps the flag');

/* Retired is terminal. */
molavi_domain_tx('reference', static fn(SQLite3 $db) => molavi_domain_sql($db,
    "UPDATE reference_product_matches SET status='retired' WHERE product_id='p_writer'"));
foreach (['REF-002', 'REF-003A'] as $ref) {
    try {
        molavi_domain_reference_match_record(['product_id' => 'p_writer', 'source' => 'catalog', 'reference_id' => $ref,
            'match_basis' => 'owner_pick', 'decision_id' => $writerDecision], 'wp:7');
        $blocked = false;
    } catch (MolaviWorkflowError $e) { $blocked = $e->getMessage() === 'match_retired'; }
    check_true($blocked, 'retired: re-creation blocked for ' . $ref);
}

/* Market: the writer mirrors the match onto market_product_sources, no second store. */
molavi_domain_tx('reference', static fn(SQLite3 $db) => molavi_domain_sql($db,
    "INSERT INTO market_product_sources (target,product_id,offer_id,title,identity_json,changed_at) VALUES ('product:p_market','p_market','offer-9','قهوه فوری نسکافه','{}',?)", [time()]));
molavi_domain_reference_match_record([
    'product_id' => 'p_market', 'source' => 'market', 'offer_id' => 'offer-9', 'signature' => 'sig',
    'reference_id' => 'basalam-123', 'snapshot_id' => '', 'decision_id' => null, 'match_basis' => 'owner_pick',
    'listing_id' => 'basalam-123', 'url' => 'https://basalam.com/p/123', 'provider' => 'basalam',
    'merchant' => 'shop-a', 'regular_price' => 90000, 'pack_count' => 1, 'identity_json' => '{}',
    'target' => 'product:p_market', 'title' => 'قهوه فوری نسکافه'], 'wp:7');
$marketRow = molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
    "SELECT match_basis,matched_by,match_status FROM market_product_sources WHERE target='product:p_market'"));
check([$marketRow['match_basis'], $marketRow['matched_by'], $marketRow['match_status']], ['owner_pick', 'wp:7', 'active'], 'market: writer mirrors basis/by/status onto the market row');
check_true((int) molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
    "SELECT matched_at FROM market_product_sources WHERE target='product:p_market'")['matched_at']) > 0, 'market: matched_at stamped');
check(molavi_domain_reference_match_get('p_market', 'market')['reference_id'], 'basalam-123', 'market: match row kept with source market');

/* --- (a) extraction exact-compatible identity via the projection choke point --- */
$headers = array_map('strval', $GLOBALS['kept_tables']['Products'][0]);
$records = molavi_domain_records($GLOBALS['kept_tables']['Products']);
$index = molavi_domain_find_product($records, 'p_exact');
$matching = molavi_domain_offers_for_product(molavi_domain_records($GLOBALS['kept_tables']['Offers']),
    'چای سیاه احمد', 'testchannel2', molavi_domain_offer_identity_fields($records[$index]));
check(count($matching), 1, 'exact: one offer feeds the product');
molavi_domain_refresh_conflict_product(['product_id' => 'p_exact', 'product_name' => 'چای سیاه احمد', 'channel' => 'testchannel2'],
    $records[$index], $headers, $matching);
$exactRow = molavi_domain_reference_match_get('p_exact');
check($exactRow['reference_id'], 'REF-001', 'exact: kept match recorded from the extraction identity');
check($exactRow['match_basis'], 'auto_exact', 'exact: basis is auto_exact');
check($exactRow['decision_id'], null, 'exact: no decision linked');
check_true(!molavi_domain_reference_match_is_trusted($exactRow), 'exact: extraction match is untrusted');
check($byId('p_exact')['Reference Match ID'], 'REF-001', 'exact: projection still carries the match id');
check(count(match_events('p_exact')), 1, 'exact: created event recorded');

/* --- (b) owner pick via the real reviews API --- */
$apiCase = molavi_domain_reference_case_id('offer-11', $snapshotId);
$apiSig = molavi_domain_reference_signature(molavi_domain_offer_attributes($offerById('offer-11')));
$pick = molavi_domain_reference_reviews_api('POST', ['case_id' => $apiCase, 'offer_id' => 'offer-11',
    'snapshot_id' => $snapshotId, 'choice' => 'match', 'reference_id' => 'REF-004', 'price_role' => 'unit',
    'note' => 'Owner picked the oil.', 'expected_decision_id' => null], 'wp:7');
check($pick['choice'], 'match', 'owner-pick: API records the match choice');
$pickRow = molavi_domain_reference_match_get('p_api_pick');
check($pickRow['reference_id'], 'REF-004', 'owner-pick: kept match recorded');
check($pickRow['decision_id'], $pick['decision_id'], 'owner-pick: match links the API decision');
check($pickRow['match_basis'], 'owner_pick', 'owner-pick: basis is owner_pick');
check([$pickRow['catalog_package_price'], $pickRow['catalog_unit_price'], $pickRow['catalog_consumer_price']], [250000, 240000, 250000], 'owner-pick: catalog prices from the snapshot row');
check_true(molavi_domain_reference_match_is_trusted($pickRow), 'owner-pick: row is trusted');

/* Retired pre-existing match blocks adoption of that product. */
molavi_domain_reference_match_record([
    'product_id' => 'p_adopt_retired', 'source' => 'catalog', 'offer_id' => 'offer-15',
    'signature' => 'sig', 'reference_id' => 'REF-006', 'price_role' => 'unit',
    'snapshot_id' => $snapshotId, 'decision_id' => $adoptRetiredDecision, 'match_basis' => 'owner_pick',
    'status' => 'retired', 'title' => 'برنج هاشمی گیلان'], 'wp:7');

/* --- Adoption dry-run: would-adopt 2, writes nothing --- */
$dry = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => true], 'wp:7');
check($dry['adopted'], 2, 'adopt dry-run: two decisions would adopt (owner + backfill notes)');
check($dry['matched'], 3, 'adopt dry-run: three fixture products match (p_exact already matched by the exact hook)');
check(molavi_domain_reference_match_get('p_adopt_owner'), null, 'adopt dry-run: nothing written');

/* --- Backfill write run: apply creates matches, adoption creates the rest --- */
$write = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($write['written'], 3, 'backfill: three matches written');
check($write['adopted'], 2, 'backfill: two decisions adopted');
check($write['failures'], [], 'backfill: no failures');
$backfillRow = molavi_domain_reference_match_get('p_match_exact');
check($backfillRow['match_basis'], 'backfill', 'backfill: basis is backfill');
check($backfillRow['decision_id'], $byId('p_match_exact')['Reference Decision ID'], 'backfill: match links the backfill decision');
check_true(molavi_domain_reference_match_is_trusted($backfillRow), 'backfill: row is trusted');
$adoptOwnerRow = molavi_domain_reference_match_get('p_adopt_owner');
check($adoptOwnerRow['reference_id'], 'REF-004', 'adopt: owner decision adopted');
check($adoptOwnerRow['decision_id'], $adoptOwnerDecision, 'adopt: owner match links its decision');
check($adoptOwnerRow['match_basis'], 'owner_pick', 'adopt: owner note infers owner_pick');
check($adoptOwnerRow['catalog_consumer_price'], 250000, 'adopt: catalog price from the decision snapshot row');
$adoptBackfillRow = molavi_domain_reference_match_get('p_adopt_backfill');
check($adoptBackfillRow['match_basis'], 'backfill', 'adopt: backfill note infers backfill');
check($adoptBackfillRow['reference_id'], 'REF-002', 'adopt: backfill decision adopted');
check(molavi_domain_reference_match_get('p_adopt_nomatch'), null, 'adopt: no_match decision adopts no row');
check(molavi_domain_reference_match_get('p_adopt_retired')['status'], 'retired', 'adopt: retired row never re-created');

/* Second write run adopts nothing new. */
$again = molavi_domain_reference_backfill_api(['action' => 'backfill_reference_matches', 'dry_run' => false], 'wp:7');
check($again['adopted'], 0, 'adopt: second run adopts nothing');
check($again['written'], 0, 'adopt: second run writes nothing');

foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native reference kept matches ' . $count . " cases\n";
