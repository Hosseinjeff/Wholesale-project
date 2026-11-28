<?php
/** Q4 market-wins fix for auto-verify (owner decision 2026-11-27).
 *
 * The gap: the card display lets a fresh market source win — the card
 * shows the market price (molavi_domain_regular_price_evidence returns
 * it immediately) — but auto-verify judged every leg against the sheet
 * Consumer Price, so with sheet/catalog at 100000 and a fresh market
 * source at 90000, auto-verify certified 100000 while the card showed
 * 90000. The fix: a market-first leg, tried before the catalog leg's
 * success is returned, judges the market leg against the DISPLAYED
 * regular price and certifies that price. It needs a fresh picked
 * market source (re-verified within 24h, the display's own window),
 * the KEPT market match row (source 'market', status 'active', its
 * identity proof matching the current identity and its URL the picked
 * listing), and the display evidence itself confirming the market price
 * is current. When the source is stale/absent, or no kept row agrees
 * with it, the leg does not fire and the order falls back to catalog
 * -> link-message -> market exactly as before.
 *
 * Cases (FIXED mode, all sale 80000, sheet consumer 100000, pack 1):
 *   (a) q4-main: fresh market 90000 + kept market row + catalog 100000
 *       -> eligible, leg market, consumer_price 90000 (the displayed
 *       price), market_first true. THE bug case.
 *   (b) q4-nokept: same but NO kept market row -> catalog wins, 100000.
 *       Proves the kept market row is actually read.
 *   (c) q4-stale: market 90000 verified 25h ago + kept row -> catalog
 *       wins, 100000. Stale falls back.
 *   (d) q4-needsreview: kept market row needs_review + fresh market
 *       90000 -> catalog wins, 100000.
 *   (e) q4-link: no catalog match, no market, fresh 'identical' link
 *       -> FIX-1 link-message leg still works, 100000.
 *   (f) q4-lone: catalog row is a lone supply price ([23] empty) ->
 *       NOT eligible, reason catalog_no_regular_price (Q8 intact).
 *   (g) q4-nomarket: no market source at all -> catalog wins, 100000.
 *   (h) q4-agrees: fresh market 100000 == sheet -> market-first fires
 *       too, certifying the same 100000.
 *   (i) q4-drift: kept market row signature drifted -> catalog wins.
 *   (j) q4-wronglisting: kept market row URL is a different listing
 *       than the source row -> catalog wins.
 *   (k) q4-nocatalog: NO catalog match, fresh market 90000 + kept row
 *       -> market-first eligible at 90000 (does not need the catalog
 *       leg). Plus: display-evidence assertions (the card shows 90000
 *       for q4-main/q4-nocatalog, 100000 for q4-stale), a dry-run tick
 *       (10 eligible, 1 skipped as catalog_no_regular_price), and the
 *       one() write path (market-first note names the displayed price,
 *       catalog fallback still writes, lone writes nothing).
 *
 * Negative control: on the unmodified 61b5992 base (no
 * molavi_domain_auto_verify_market_first_leg) the same file reports
 * the old behaviour — q4-main certifies 100000 via the catalog leg
 * while the display already shows 90000, and q4-nocatalog is not
 * eligible at all. Run: php tests/native_q4_market_wins_autoverify.php
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-q4-market-wins-' . bin2hex(random_bytes(6)));
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

/* --- 26-column catalog rows (schema v2): [5] title figure, [16] ref id,
 * [21] supply pack (= sheet Sale Price, so the kept-match supply price
 * agrees), [23] explicitly-marked regular pack. q4-lone has [23] empty. --- */
function q4_row(string $name, string $refId, ?int $regular, int $supply, int $title): array {
    $row = array_fill(0, 26, '');
    $row[0] = $name; $row[5] = (string) $title; $row[16] = $refId;
    $row[21] = (string) $supply; $row[23] = $regular === null ? '' : (string) $regular;
    return $row;
}
$catalog = [
    q4_row('چای سیاه احمد', 'REF-Q4M', 100000, 80000, 100000),
    q4_row('قهوه فوری نسکافه', 'REF-Q4N', 100000, 80000, 100000),
    q4_row('شکر سفید دولیتل', 'REF-Q4S', 100000, 80000, 100000),
    q4_row('روغن آفتابگردان اویلا', 'REF-Q4R', 100000, 80000, 100000),
    q4_row('چای سبز لیپتون', 'REF-Q4L', null, 80000, 80000),
    q4_row('زعفران قائنات اصل', 'REF-Q4C', 100000, 80000, 100000),
    q4_row('کره حیوانی پاک', 'REF-Q4A', 100000, 80000, 100000),
    q4_row('ماست موسیر هراز', 'REF-Q4D', 100000, 80000, 100000),
    q4_row('پنیر لیقوان', 'REF-Q4W', 100000, 80000, 100000),
];
$snapshotId = hash('sha256', molavi_domain_json(['rows' => $catalog, 'extracted_at' => $freshIso]));
molavi_domain_tx('reference', static function (SQLite3 $db) use ($catalog, $freshIso, $snapshotId): void {
    $payload = molavi_domain_json(['status' => 'staged', 'extracted_at' => $freshIso,
        'headers' => molavi_domain_reference_headers(), 'rows' => $catalog,
        'source_product_ids' => ['70001', '70002', '70003', '70004', '70005', '70006', '70007', '70008', '70009']]);
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)', [$snapshotId, $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active VALUES (1,?)', [$snapshotId]);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check VALUES (1,?,?)', [$snapshotId, $freshIso]);
});

/* --- Fixtures: Products + Offers (all sale 80000, consumer 100000, pack 1) --- */
function product_record($id, $name, $brand, $category, $variant, $volume, $offer, $extra = []) {
    return array_merge(['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => 100000, 'Sale Price' => 80000, 'Status' => 'imported', 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Channel' => 'testchannel', 'Message Timestamp' => '2026-11-20T08:00:00+00:00',
        'Source Timestamp Basis' => '', 'Reference Match ID' => '', 'Reference Snapshot ID' => '',
        'Reference Approval State' => '', 'Reference Decision ID' => '', 'Reference Price Role' => '',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''], $extra);
}
function offer_record($id, $name, $brand, $category, $variant, $volume, $productId) {
    return ['Offer ID' => $id, 'Product ID' => $productId, 'Product Name' => $name, 'Brand' => $brand,
        'Category' => $category, 'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1,
        'Packaging' => '', 'Sale Price' => 80000, 'Consumer Price' => 100000, 'Price' => 80000, 'Actual Price' => '',
        'Status' => 'imported', 'Review Reason' => '', 'Channel Username' => 'testchannel', 'Channel' => 'testchannel',
        'Message ID' => 'msg-' . $id, 'Batch ID' => 'batch-1', 'Message Timestamp' => '2026-11-20T08:00:00+00:00',
        'Source Timestamp Basis' => '', 'Import Timestamp' => '2026-11-20T00:00:00+00:00', 'Original Message' => 'test',
        'Source Post URL' => '', 'Reference Snapshot ID' => '', 'Reference Match State' => '', 'Reference Match ID' => '',
        'Reference Price Role Hint' => '', 'Reference Decision ID' => '', 'Owner Decision ID' => '', 'Derived From Offer ID' => ''];
}
$refFields = static fn($refId) => ['Reference Match ID' => $refId, 'Reference Snapshot ID' => $snapshotId,
    'Reference Approval State' => 'owner_approved'];
$products = [
    product_record('q4-main', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 'offer-q4-main', $refFields('REF-Q4M')),
    product_record('q4-nokept', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 'offer-q4-nokept', $refFields('REF-Q4N')),
    product_record('q4-stale', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 'offer-q4-stale', $refFields('REF-Q4S')),
    product_record('q4-needsreview', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 'offer-q4-needsreview', $refFields('REF-Q4R')),
    product_record('q4-link', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 'offer-q4-link'),
    product_record('q4-lone', 'چای سبز لیپتون', 'لیپتون', 'چای', '', '100 گرم', 'offer-q4-lone', $refFields('REF-Q4L')),
    product_record('q4-nomarket', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 'offer-q4-nomarket', $refFields('REF-Q4C')),
    product_record('q4-agrees', 'کره حیوانی پاک', 'پاک', 'لبنیات', '', '100 گرم', 'offer-q4-agrees', $refFields('REF-Q4A')),
    product_record('q4-drift', 'ماست موسیر هراز', 'هراز', 'لبنیات', '', '900 گرم', 'offer-q4-drift', $refFields('REF-Q4D')),
    product_record('q4-wronglisting', 'پنیر لیقوان', '', 'لبنیات', '', '400 گرم', 'offer-q4-wronglisting', $refFields('REF-Q4W')),
    product_record('q4-nocatalog', 'خرما مضافتی بم', '', 'خرما', '', '900 گرم', 'offer-q4-nocatalog'),
];
$offers = [
    offer_record('offer-q4-main', 'چای سیاه احمد', 'احمد', 'چای', '', '500 گرم', 'q4-main'),
    offer_record('offer-q4-nokept', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '', '200 گرم', 'q4-nokept'),
    offer_record('offer-q4-stale', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '', 'q4-stale'),
    offer_record('offer-q4-needsreview', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '', '1 لیتر', 'q4-needsreview'),
    offer_record('offer-q4-link', 'برنج هاشمی گیلان', '', 'برنج', '', '5 کیلوگرم', 'q4-link'),
    offer_record('offer-q4-lone', 'چای سبز لیپتون', 'لیپتون', 'چای', '', '100 گرم', 'q4-lone'),
    offer_record('offer-q4-nomarket', 'زعفران قائنات اصل', 'قائنات', 'زعفران', '', '', 'q4-nomarket'),
    offer_record('offer-q4-agrees', 'کره حیوانی پاک', 'پاک', 'لبنیات', '', '100 گرم', 'q4-agrees'),
    offer_record('offer-q4-drift', 'ماست موسیر هراز', 'هراز', 'لبنیات', '', '900 گرم', 'q4-drift'),
    offer_record('offer-q4-wronglisting', 'پنیر لیقوان', '', 'لبنیات', '', '400 گرم', 'q4-wronglisting'),
    offer_record('offer-q4-nocatalog', 'خرما مضافتی بم', '', 'خرما', '', '900 گرم', 'q4-nocatalog'),
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
function q4_market_row(string $pid, array $record): ?array {
    $identity = molavi_domain_market_identity($record);
    if (!$identity) return null;
    $rows = molavi_domain_market_source_rows();
    $found = molavi_domain_market_source_for(
        molavi_domain_market_source_keys($pid, (string)($record['Latest Offer ID'] ?? ''),
            molavi_domain_auto_verify_market_parents()),
        $rows, $identity);
    return $found['price'] ?? null;
}

/* --- Seed decisions + kept catalog matches --- */
function seed_decision(string $offerId, string $refId, string $signature, string $decisionId): void {
    global $snapshotId;
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($offerId, $refId, $signature, $decisionId, $snapshotId): void {
        molavi_domain_sql($db, 'INSERT INTO reference_item_decisions (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,price_role,actor_id,note,created_at,supersedes_decision_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)',
            [$decisionId, molavi_domain_reference_case_id($offerId, $snapshotId), $offerId, $snapshotId, $signature, 'match', $refId, 'unit', 'wp:7', 'Owner pick (harness).', '2026-11-26T00:00:00+00:00']);
    });
}
function seed_match(string $pid, string $offerId, string $refId, string $signature, string $decisionId): void {
    global $snapshotId, $nowInt;
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($pid, $offerId, $refId, $signature, $decisionId, $snapshotId, $nowInt): void {
        $cols = ['product_id', 'source', 'offer_id', 'signature', 'reference_id', 'source_product_id', 'snapshot_id', 'price_role',
            'catalog_package_price', 'catalog_unit_price', 'catalog_consumer_price', 'catalog_supplier_price', 'sheet_consumer_price',
            'score', 'name_similarity', 'match_basis', 'decision_id', 'listing_id', 'url', 'provider', 'merchant', 'quote_json',
            'regular_price', 'pack_count', 'identity_json', 'actor_id', 'status', 'created_at', 'checked_at', 'verified_at', 'next_at'];
        $vals = [$pid, 'catalog', $offerId, $signature, $refId, '', $snapshotId, 'unit', null, null, 100000, null, 100000,
            1.0, 1.0, 'owner_pick', $decisionId, null, null, null, null, null, null, null, null, 'wp:7', 'active',
            $nowInt - 3600, $nowInt - 3600, null, $nowInt - 3600 + 14 * 86400];
        molavi_domain_sql($db, 'INSERT INTO reference_product_matches (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    });
}
$sigOfOffer = static fn($offerId) => molavi_domain_reference_signature(molavi_domain_offer_attributes($offerById($offerId)));
foreach (['q4-main' => 'REF-Q4M', 'q4-nokept' => 'REF-Q4N', 'q4-stale' => 'REF-Q4S', 'q4-needsreview' => 'REF-Q4R',
    'q4-lone' => 'REF-Q4L', 'q4-nomarket' => 'REF-Q4C', 'q4-agrees' => 'REF-Q4A', 'q4-drift' => 'REF-Q4D',
    'q4-wronglisting' => 'REF-Q4W'] as $pid => $ref) {
    $did = 'q4-decision-' . $pid;
    seed_decision('offer-' . $pid, $ref, $sigOfOffer('offer-' . $pid), $did);
    seed_match($pid, 'offer-' . $pid, $ref, $sigOfOffer('offer-' . $pid), $did);
}

/* --- Seed picked market sources + kept market matches --- */
function seed_market_source(string $pid, string $offerId, int $regular, int $verifiedAt, string $url): void {
    global $nowInt, $recordById;
    $record = $recordById($pid);
    $identity = molavi_domain_market_identity($record);
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($pid, $offerId, $regular, $verifiedAt, $url, $identity, $record, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO market_product_sources (target,product_id,offer_id,title,identity_json,url,quote_json,regular_price,next_at,checked_at,verified_at,changed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            ['product:' . $pid, $pid, $offerId, (string)($record['Product Name'] ?? ''), molavi_domain_json($identity), $url, null, $regular, $nowInt + 86400, $verifiedAt, $verifiedAt, $nowInt]);
    });
}
function seed_market_match(string $pid, string $offerId, string $url, int $regular, string $status = 'active',
    ?string $signature = null, ?string $matchUrl = null): void {
    global $nowInt, $recordById;
    $record = $recordById($pid);
    $identity = molavi_domain_market_identity($record);
    $signature = $signature ?? molavi_domain_hash($identity);
    $listing = 'basalam-' . basename($matchUrl ?? $url);
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($pid, $offerId, $url, $regular, $status, $signature, $listing, $matchUrl, $identity, $nowInt): void {
        $cols = ['product_id', 'source', 'offer_id', 'signature', 'reference_id', 'source_product_id', 'snapshot_id', 'price_role',
            'catalog_package_price', 'catalog_unit_price', 'catalog_consumer_price', 'catalog_supplier_price', 'sheet_consumer_price',
            'score', 'name_similarity', 'match_basis', 'decision_id', 'listing_id', 'url', 'provider', 'merchant', 'quote_json',
            'regular_price', 'pack_count', 'identity_json', 'actor_id', 'status', 'created_at', 'checked_at', 'verified_at', 'next_at'];
        $vals = [$pid, 'market', $offerId, $signature, $listing, '', '', 'unit', null, null, null, null, null,
            null, null, 'owner_pick', null, $listing, $matchUrl ?? $url, 'basalam', 'فروشگاه نمونه', null,
            $regular, 1, molavi_domain_json($identity), 'wp:7', $status,
            $nowInt - 3600, $nowInt - 3600, null, $nowInt - 3600 + 14 * 86400];
        molavi_domain_sql($db, 'INSERT INTO reference_product_matches (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    });
}
seed_market_source('q4-main', 'offer-q4-main', 90000, $nowInt - 3600, 'https://basalam.com/p/9001');
seed_market_match('q4-main', 'offer-q4-main', 'https://basalam.com/p/9001', 90000);
seed_market_source('q4-nokept', 'offer-q4-nokept', 90000, $nowInt - 3600, 'https://basalam.com/p/9002');
// q4-nokept deliberately has NO kept market row (case b).
seed_market_source('q4-stale', 'offer-q4-stale', 90000, $nowInt - 25 * 3600, 'https://basalam.com/p/9003');
seed_market_match('q4-stale', 'offer-q4-stale', 'https://basalam.com/p/9003', 90000);
seed_market_source('q4-needsreview', 'offer-q4-needsreview', 90000, $nowInt - 3600, 'https://basalam.com/p/9004');
seed_market_match('q4-needsreview', 'offer-q4-needsreview', 'https://basalam.com/p/9004', 90000, 'needs_review');
seed_market_source('q4-agrees', 'offer-q4-agrees', 100000, $nowInt - 3600, 'https://basalam.com/p/9008');
seed_market_match('q4-agrees', 'offer-q4-agrees', 'https://basalam.com/p/9008', 100000);
$driftSig = molavi_domain_hash(molavi_domain_market_identity($recordById('q4-nomarket')));
seed_market_source('q4-drift', 'offer-q4-drift', 90000, $nowInt - 3600, 'https://basalam.com/p/9009');
seed_market_match('q4-drift', 'offer-q4-drift', 'https://basalam.com/p/9009', 90000, 'active', $driftSig);
seed_market_source('q4-wronglisting', 'offer-q4-wronglisting', 90000, $nowInt - 3600, 'https://basalam.com/p/9010');
seed_market_match('q4-wronglisting', 'offer-q4-wronglisting', 'https://basalam.com/p/9010', 90000, 'active', null, 'https://basalam.com/p/9999');
seed_market_source('q4-nocatalog', 'offer-q4-nocatalog', 90000, $nowInt - 3600, 'https://basalam.com/p/9011');
seed_market_match('q4-nocatalog', 'offer-q4-nocatalog', 'https://basalam.com/p/9011', 90000);

/* --- Seed link checks: 'price_unchanged' isolates the market/catalog
 * legs (the link leg needs 'identical'); only q4-link is identical. --- */
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
foreach (['q4-main', 'q4-nokept', 'q4-stale', 'q4-needsreview', 'q4-lone', 'q4-nomarket', 'q4-agrees',
    'q4-drift', 'q4-wronglisting', 'q4-nocatalog'] as $pid)
    seed_link($pid, 'price_unchanged', $nowInt - 9000 + ($k++) * 500, '80000', $revision[$pid]);
seed_link('q4-link', 'identical', $nowInt - 9000 + ($k++) * 500, '80000', $revision['q4-link']);

function db_row(string $store, string $sql, array $params = []): ?array {
    return molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_row($db, $sql, $params));
}
function db_count(string $store, string $sql, array $params = []): int {
    return (int)(db_row($store, $sql, $params)['n'] ?? 0);
}
function q4_check(string $pid, array $sheetRecords, int $nowInt): array {
    global $recordById;
    return molavi_domain_auto_verify_check($recordById($pid), offer_for($pid, $sheetRecords, $nowInt), $nowInt);
}
function q4_eligible(string $pid, array $sheetRecords, int $nowInt): ?array {
    global $recordById;
    return molavi_domain_auto_verify_eligible($recordById($pid), offer_for($pid, $sheetRecords, $nowInt), $nowInt);
}

$isFixed = function_exists('molavi_domain_auto_verify_market_first_leg');
echo 'MODE: ' . ($isFixed ? 'FIXED (Q4 market-first present)' : 'BASE 61b5992 (negative control: no market-first)') . "\n";

if (!$isFixed) {
    // --- NEGATIVE CONTROL: the base certifies the sheet price while the
    // card already shows the market price, and cannot verify a product
    // whose only regular price is the market one. ---
    $evMain = q4_eligible('q4-main', $sheetRecords, $nowInt);
    check_true($evMain !== null, 'negative: q4-main eligible on the base');
    check($evMain['leg'] ?? null, 'catalog', 'negative: q4-main certifies via the catalog leg on the base');
    check($evMain['consumer_price'] ?? null, 100000, 'negative: q4-main certifies 100000 (the sheet price) on the base');
    check($evMain['catalog_price'] ?? null, 100000, 'negative: q4-main catalog price 100000 on the base');
    $dispMain = molavi_domain_regular_price_evidence($recordById('q4-main'), offer_for('q4-main', $sheetRecords, $nowInt),
        q4_market_row('q4-main', $recordById('q4-main')), $nowInt);
    check($dispMain['amount'] ?? null, 90000, 'negative: the card already shows 90000 for q4-main on the base');
    check($dispMain['state'] ?? null, 'current', 'negative: the displayed 90000 is current on the base');
    $chkNoCat = q4_check('q4-nocatalog', $sheetRecords, $nowInt);
    check($chkNoCat['eligible'], false, 'negative: q4-nocatalog not eligible on the base');
    check($chkNoCat['reason'] ?? null, 'no_reference_match', 'negative: q4-nocatalog reason is the catalog leg on the base');
    $evAgrees = q4_eligible('q4-agrees', $sheetRecords, $nowInt);
    check_true($evAgrees !== null, 'negative: q4-agrees eligible on the base');
    check($evAgrees['leg'] ?? null, 'catalog', 'negative: q4-agrees takes the catalog leg on the base');
    $evLink = q4_eligible('q4-link', $sheetRecords, $nowInt);
    check_true($evLink !== null, 'negative: q4-link eligible on the base');
    check($evLink['leg'] ?? null, 'link_message', 'negative: q4-link leg is link_message on the base');
    $chkLone = q4_check('q4-lone', $sheetRecords, $nowInt);
    check($chkLone['eligible'], false, 'negative: q4-lone not eligible on the base');
    check($chkLone['reason'] ?? null, 'catalog_no_regular_price', 'negative: q4-lone reason is Q8 catalog_no_regular_price on the base');
    cleanup();
    echo 'NEGATIVE native Q4 market-wins ' . $count . " cases (old behaviour on the base) FAIL $fail\n";
    exit($fail === 0 ? 0 : 1);
}

/* --- (a) THE bug case: fresh market 90000, sheet/catalog 100000 --- */
$evMain = q4_eligible('q4-main', $sheetRecords, $nowInt);
check_true($evMain !== null, '(a) q4-main eligible');
check($evMain['leg'] ?? null, 'market', '(a) q4-main leg is market');
check($evMain['consumer_price'] ?? null, 90000, '(a) q4-main certifies 90000 (the displayed price), NOT 100000');
check($evMain['market_regular_price'] ?? null, 90000, '(a) q4-main market regular price 90000');
check($evMain['displayed_price'] ?? null, 90000, '(a) q4-main displayed price 90000');
check($evMain['market_first'] ?? null, true, '(a) q4-main market_first flag');
check($evMain['match_id'] ?? null, 'q4-main:market', '(a) q4-main match id');
check($evMain['reference_id'] ?? null, 'basalam-9001', '(a) q4-main kept market reference id');
check($evMain['market_url'] ?? null, 'https://basalam.com/p/9001', '(a) q4-main market url');
check($evMain['market_verified_at'] ?? null, $nowInt - 3600, '(a) q4-main market verified_at');
check($evMain['catalog_price'] ?? null, null, '(a) q4-main no catalog price in the evidence');
check($evMain['supply_price'] ?? null, 80000, '(a) q4-main supply price 80000 (catalog corroboration kept)');
check($evMain['supply_agrees'] ?? null, true, '(a) q4-main supply agrees with sheet sale price');
// The display itself shows 90000 for q4-main while the sheet says 100000.
$dispMain = molavi_domain_regular_price_evidence($recordById('q4-main'), offer_for('q4-main', $sheetRecords, $nowInt),
    q4_market_row('q4-main', $recordById('q4-main')), $nowInt);
check($dispMain['amount'] ?? null, 90000, '(a) display: the card shows 90000 for q4-main');
check($dispMain['state'] ?? null, 'current', '(a) display: the shown 90000 is current');

/* --- (b) no kept market row: the market-first leg cannot fire --- */
$evNokept = q4_eligible('q4-nokept', $sheetRecords, $nowInt);
check_true($evNokept !== null, '(b) q4-nokept eligible');
check($evNokept['leg'] ?? null, 'catalog', '(b) q4-nokept leg is catalog (market-first needs the kept row)');
check($evNokept['consumer_price'] ?? null, 100000, '(b) q4-nokept certifies the sheet 100000');
check($evNokept['market_first'] ?? null, null, '(b) q4-nokept has no market_first flag');

/* --- (c)(d)(i)(j) kept-row / freshness gates: catalog wins --- */
foreach ([['q4-stale', '(c) stale market source falls back to catalog'],
    ['q4-needsreview', '(d) needs_review kept market row falls back to catalog'],
    ['q4-drift', '(i) drifted kept market signature falls back to catalog'],
    ['q4-wronglisting', '(j) kept market row for another listing falls back to catalog']] as [$pid, $label]) {
    $ev = q4_eligible($pid, $sheetRecords, $nowInt);
    check_true($ev !== null, $label . ' eligible');
    check($ev['leg'] ?? null, 'catalog', $label . ' leg is catalog');
    check($ev['consumer_price'] ?? null, 100000, $label . ' certifies 100000');
    check($ev['market_first'] ?? null, null, $label . ' no market_first flag');
}
// (c) the stale display falls back to the sheet price too.
$dispStale = molavi_domain_regular_price_evidence($recordById('q4-stale'), offer_for('q4-stale', $sheetRecords, $nowInt),
    q4_market_row('q4-stale', $recordById('q4-stale')), $nowInt);
check($dispStale['amount'] ?? null, 100000, '(c) display: stale market falls back to 100000');
check($dispStale['state'] ?? null, 'current', '(c) display: the fallback 100000 is current');

/* --- (e) FIX-1 link-message leg still works as fallback --- */
$evLink = q4_eligible('q4-link', $sheetRecords, $nowInt);
check_true($evLink !== null, '(e) q4-link eligible');
check($evLink['leg'] ?? null, 'link_message', '(e) q4-link leg is link_message');
check($evLink['consumer_price'] ?? null, 100000, '(e) q4-link certifies 100000');

/* --- (f) Q8 lone supply price still fails the catalog leg --- */
$chkLone = q4_check('q4-lone', $sheetRecords, $nowInt);
check($chkLone['eligible'], false, '(f) q4-lone not eligible');
check($chkLone['reason'] ?? null, 'catalog_no_regular_price', '(f) q4-lone reason catalog_no_regular_price');
check(q4_eligible('q4-lone', $sheetRecords, $nowInt), null, '(f) q4-lone eligible null');

/* --- (g) no market source at all: catalog wins --- */
$evNomarket = q4_eligible('q4-nomarket', $sheetRecords, $nowInt);
check_true($evNomarket !== null, '(g) q4-nomarket eligible');
check($evNomarket['leg'] ?? null, 'catalog', '(g) q4-nomarket leg is catalog');
check($evNomarket['consumer_price'] ?? null, 100000, '(g) q4-nomarket certifies 100000');

/* --- (h) market agrees with the sheet: market-first fires too --- */
$evAgrees = q4_eligible('q4-agrees', $sheetRecords, $nowInt);
check_true($evAgrees !== null, '(h) q4-agrees eligible');
check($evAgrees['leg'] ?? null, 'market', '(h) q4-agrees leg is market');
check($evAgrees['consumer_price'] ?? null, 100000, '(h) q4-agrees certifies 100000');
check($evAgrees['market_first'] ?? null, true, '(h) q4-agrees market_first flag');

/* --- (k) no catalog match: market-first verifies on its own --- */
$evNoCat = q4_eligible('q4-nocatalog', $sheetRecords, $nowInt);
check_true($evNoCat !== null, '(k) q4-nocatalog eligible');
check($evNoCat['leg'] ?? null, 'market', '(k) q4-nocatalog leg is market');
check($evNoCat['consumer_price'] ?? null, 90000, '(k) q4-nocatalog certifies 90000');
check($evNoCat['market_first'] ?? null, true, '(k) q4-nocatalog market_first flag');
check($evNoCat['supply_price'] ?? null, null, '(k) q4-nocatalog has no catalog supply corroboration');
$dispNoCat = molavi_domain_regular_price_evidence($recordById('q4-nocatalog'), offer_for('q4-nocatalog', $sheetRecords, $nowInt),
    q4_market_row('q4-nocatalog', $recordById('q4-nocatalog')), $nowInt);
check($dispNoCat['amount'] ?? null, 90000, '(k) display: the card shows 90000 for q4-nocatalog');
check($dispNoCat['state'] ?? null, 'current', '(k) display: the shown 90000 is current');

/* --- Dry-run tick: 10 eligible, the lone product skipped --- */
$dry = molavi_domain_auto_verify_tick($nowInt, $isoNow, true);
check_true($dry['dry_run'], 'tick: dry-run flag');
check($dry['eligible_count'], 10, 'tick: dry-run eligible count 10');
check(array_column($dry['eligible'], 'product_id'),
    ['q4-main', 'q4-nokept', 'q4-stale', 'q4-needsreview', 'q4-nomarket', 'q4-agrees', 'q4-drift', 'q4-wronglisting', 'q4-nocatalog', 'q4-link'],
    'tick: dry-run eligible list in queue order (q4-link seeded last, so it is last)');
check($dry['skipped'], ['catalog_no_regular_price' => 1], 'tick: dry-run skipped reasons');
check($dry['scanned'], 11, 'tick: dry-run scanned 11 queue rows');
$dryById = [];
foreach ($dry['eligible'] as $e) $dryById[$e['product_id']] = $e;
check($dryById['q4-main']['market_first'] ?? null, true, 'tick: q4-main eligible entry is market-first');
check($dryById['q4-main']['consumer_price'] ?? null, 90000, 'tick: q4-main eligible entry certifies 90000');
check($dryById['q4-nocatalog']['market_first'] ?? null, true, 'tick: q4-nocatalog eligible entry is market-first');
check($dryById['q4-nokept']['leg'] ?? null, 'catalog', 'tick: q4-nokept eligible entry is catalog');
check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence'), 0, 'tick: dry-run writes no evidence');

/* --- one() write path: market-first writes, catalog fallback writes, lone writes nothing --- */
$oneMain = molavi_domain_auto_verify_one($recordById('q4-main'), offer_for('q4-main', $sheetRecords, $nowInt), $nowInt, $isoNow);
check_true(!empty($oneMain['ok']) && empty($oneMain['idempotent']), 'one(): market-first writes the verification');
$histMain = db_row('pipeline', 'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY rowid DESC LIMIT 1', ['q4-main']);
check_true(str_contains((string)($histMain['note'] ?? ''), 'تأیید خودکار'), 'one(): market-first note names auto-verify');
check_true(str_contains((string)($histMain['note'] ?? ''), 'بازار'), 'one(): market-first note names the market source');
check_true(str_contains((string)($histMain['note'] ?? ''), 'نمایشی'), 'one(): market-first note names the displayed price');
check_true(str_contains((string)($histMain['note'] ?? ''), '90000'), 'one(): market-first note names 90000');
$rowMain = db_row('pipeline', 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', ['q4-main']);
check($rowMain['method'] ?? null, 'auto', 'one(): market-first evidence method auto');
check($rowMain['verified_by'] ?? null, 'system:auto-verify', 'one(): market-first evidence actor');
check_true(db_row('pipeline', 'SELECT confirmed_at FROM supplier_link_checks WHERE product_id=?', ['q4-main'])['confirmed_at'] !== null,
    'one(): market-first link check confirmed');
check(db_count('pipeline', "SELECT COUNT(*) n FROM jobs WHERE id LIKE 'publish:supplier-verification:q4-main:%'"), 1,
    'one(): market-first publish job queued');
$oneNokept = molavi_domain_auto_verify_one($recordById('q4-nokept'), offer_for('q4-nokept', $sheetRecords, $nowInt), $nowInt, $isoNow);
check_true(!empty($oneNokept['ok']) && empty($oneNokept['idempotent']), 'one(): catalog fallback writes the verification');
$histNokept = db_row('pipeline', 'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY rowid DESC LIMIT 1', ['q4-nokept']);
check_true(str_contains((string)($histNokept['note'] ?? ''), 'snapshot '), 'one(): catalog note names the snapshot');
$oneLone = molavi_domain_auto_verify_one($recordById('q4-lone'), offer_for('q4-lone', $sheetRecords, $nowInt), $nowInt, $isoNow);
check($oneLone['ok'], false, 'one(): lone product is not a write');
check($oneLone['reason'] ?? null, 'catalog_no_regular_price', 'one(): lone reason catalog_no_regular_price');
check(db_row('pipeline', 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', ['q4-lone']), null, 'one(): no evidence for the lone product');

cleanup();
echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
