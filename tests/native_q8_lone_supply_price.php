<?php
// Q8 lone Rezvan price (owner decision 2026-11-27): a lone Rezvan price
// is SUPPLY evidence only. The auto-verify catalog leg may trust only an
// explicitly-marked regular price (the v2 regular-pack column [23]); a
// kept match whose regular is empty must NOT satisfy the regular leg.
// The lone supply price instead corroborates the SUPPLIER leg: when the
// kept match's supply pack price ([21], or [3] on v1 rows) exactly equals
// the sheet Sale Price, the evidence records supply_agrees=true.
//
// Cases (FIXED mode), all with sheet Sale 80000 / Consumer 100000
// unless noted, link 'unchanged', kept match active + trusted:
//   (a) q8-a: regular present ([23]=100000) -> catalog leg, as before;
//       supply_price 80000, supply_agrees true.
//   (b) q8-b: lone supply ([23] empty, [21]=80000) with the title
//       figure [5]=100000 still equalling the sheet price -> regular
//       leg NOT satisfied ('catalog_no_regular_price'); the link leg
//       cannot rescue it (reason 'price_unchanged', not 'identical').
//   (c) q8-c: lone supply + FIX-1 rescue: the identical link check
//       proves the message's own regular price -> eligible via
//       'link_message', with the lone supply recorded as the supplier
//       corroboration (supply_price 80000, supply_agrees true).
//   (d) q8-d: lone supply alone never satisfies both legs: supply
//       agrees (80000) but the regular leg fails AND the link leg fails
//       (offer Consumer 90000 != sheet 100000) -> NOT eligible,
//       'catalog_no_regular_price'.
//   (e) q8-e: FIX-1 still works unchanged with NO kept match at all
//       (sheet Consumer 50000, identical link, offer price 50000) ->
//       eligible via 'link_message', supply_price null.
//   (f) q8-f: v1 row (21 columns, no regular column) -> regular leg
//       NOT satisfied ('catalog_no_regular_price').
//   (g) q8-g: lone supply DISAGREEING with the sheet sale ([21]=85000
//       vs sale 80000) + identical link -> eligible via 'link_message',
//       supply_price 85000, supply_agrees false.
// Plus: a dry-run tick over the queue (eligible a/c/e/g, skipped
// b/d/f as catalog_no_regular_price) and one() writes for (a) and (c)
// whose notes name the supply agreement.
//
// Negative control: run this file in a worktree at the wave2 base
// 878c0d1 WITHOUT the fix (no molavi_domain_auto_verify_catalog_prices):
// it PASSES by proving the old behaviour — (b), (d) and (f) ARE
// eligible via the catalog leg there, because the old leg trusted the
// title figure [5]. Run: php tests/native_q8_lone_supply_price.php
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-q8-lone-supply-'.bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-reference-store',
    'domain-reference-matching', 'domain-reference-scheduler', 'domain-sheet-conflicts', 'domain-products', 'domain-publisher',
    'domain-supplier-link-checks', 'domain-supplier-prices', 'domain-sale-offers', 'domain-reference-matches'] as $file)
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$autoVerifyFile = __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-supplier-auto-verify.php';
$isFixed = function_exists('molavi_domain_auto_verify_catalog_prices');
if (file_exists($autoVerifyFile)) require $autoVerifyFile;
$isFixed = $isFixed || function_exists('molavi_domain_auto_verify_catalog_prices');
$root = molavi_state_root();
register_shutdown_function(static function () use ($root) { foreach (glob($root.'/*') as $file) unlink($file); rmdir($root); });
(new SQLite3($root.'/pipeline.sqlite3'))->close();
(new SQLite3($root.'/reference.sqlite3'))->close();
$GLOBALS['options'] = ['molavi_state_active' => true];
$count = 0; $fail = 0;
function cmp_check($actual, $expected, $label) { global $count, $fail;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected)) { echo 'FAIL '.$label.' actual='.molavi_domain_json($actual).' expected='.molavi_domain_json($expected)."\n"; $fail++; return; } $count++; }
function cmp_true($actual, $label) { cmp_check($actual, true, $label); }
function cmp_false($actual, $label) { cmp_check($actual, false, $label); }
function cmp_null($actual, $label) { cmp_check($actual, null, $label); }

echo 'MODE: '.($isFixed ? 'FIXED (Q8 lone-supply rule present)' : 'BASE 878c0d1 (negative control: title figure trusted as regular)')."\n";

$now = molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$nowInt = (int)$now;
$isoNow = '2026-11-26T12:00:00+00:00';
$freshIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600);

// --- Fixtures: products (pack 1, so unit == pack throughout) ---
function product_record(string $id, string $name, string $brand, string $category, string $volume, string $consumer, string $refId): array {
    return ['Product ID' => $id, 'Product Name' => $name, 'Channel Username' => 'supplier', 'Channel' => 'supplier',
        'Latest Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Sale Price' => '80000', 'Consumer Price' => $consumer,
        'Status' => 'imported', 'Message Timestamp' => '2026-11-20T08:00:00+00:00', 'Source Timestamp Basis' => '',
        'Source Post URL' => 'https://t.me/supplier/'.substr($id, 3), 'Original Message' => $name.' 80000',
        'Stock Status' => 'Unknown', 'Brand' => $brand, 'Category' => $category, 'Variation Type' => '',
        'Volume' => $volume, 'Weight' => '', 'Package Count' => 1,
        'Reference Snapshot ID' => 'snap-q8', 'Reference Match ID' => $refId,
        'Reference Approval State' => 'owner_approved'];
}
$products = [
    product_record('q8-a', 'چای سیاه احمد', 'احمد', 'چای', '500 گرم', '100000', 'REF-A'),
    product_record('q8-b', 'قهوه فوری نسکافه', 'نسکافه', 'قهوه', '200 گرم', '100000', 'REF-B'),
    product_record('q8-c', 'شکر سفید دولیتل', 'دولیتل', 'شکر', '', '100000', 'REF-C'),
    product_record('q8-d', 'روغن آفتابگردان اویلا', 'اویلا', 'روغن', '1 لیتر', '100000', 'REF-D'),
    product_record('q8-e', 'برنج هاشمی گیلان', '', 'برنج', '', '50000', ''),
    product_record('q8-f', 'چای سبز لیپتون', 'لیپتون', 'چای', '100 گرم', '100000', 'REF-F'),
    product_record('q8-g', 'چای سیاه گلستان', 'گلستان', 'چای', '500 گرم', '100000', 'REF-G'),
];
$products[4]['Reference Snapshot ID'] = '';
$products[4]['Reference Approval State'] = '';
$products[4]['Weight'] = '5 کیلوگرم';
// Offers rows: the latest offer per product. Offer Consumer Price is
// what the FIX-1 link leg compares against the sheet Consumer Price.
function offer_row(string $id, array $product, string $consumer): array {
    return ['Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Product Name' => $product['Product Name'],
        'Brand' => $product['Brand'], 'Category' => $product['Category'], 'Variation Type' => '',
        'Package Count' => 1, 'Packaging' => '', 'Volume' => $product['Volume'], 'Weight' => $product['Weight'],
        'Sale Price' => '80000', 'Consumer Price' => $consumer, 'Actual Price' => ''];
}
$offers = [
    offer_row('q8-a', $products[0], '100000'),
    offer_row('q8-b', $products[1], '100000'),
    offer_row('q8-c', $products[2], '100000'),
    offer_row('q8-d', $products[3], '90000'),   // (d): offer price != sheet price, link leg fails
    offer_row('q8-e', $products[4], '50000'),
    offer_row('q8-f', $products[5], '100000'),
    offer_row('q8-g', $products[6], '100000'),
];
$offerHeaders = ['Offer ID', 'Product Name', 'Brand', 'Category', 'Variation Type', 'Package Count',
    'Packaging', 'Volume', 'Weight', 'Sale Price', 'Consumer Price', 'Actual Price'];
$headers = molavi_domain_product_headers();
$GLOBALS['sheets'] = ['Products' => array_merge([$headers], array_map(
    static fn($r) => array_map(static fn($h) => $r[$h] ?? '', $headers), array_values($products))),
    'Offers' => molavi_domain_rows_from_records($offerHeaders, $offers)];
$sheetRecords = molavi_domain_records($GLOBALS['sheets']['Products']);
$offerRecords = molavi_domain_records($GLOBALS['sheets']['Offers']);
$revision = [];
foreach ($sheetRecords as $r) $revision[(string)$r['Product ID']] = molavi_domain_source_revision($r);
$offerById = static fn($offerId) => array_values(array_filter($offerRecords,
    static fn($r) => $r['Offer ID'] === $offerId))[0];
$sigOfOffer = static fn($offerId) => molavi_domain_reference_signature(molavi_domain_offer_attributes($offerById($offerId)));

// --- Reference catalog snapshot: 26-col rows, one 21-col row (q8-f) ---
// [3] supply pack, [4] supply unit, [5] title figure, [16] ref id,
// [21] supply pack, [22] supply unit, [23] regular pack, [24] regular unit.
function ref_row26(string $name, string $brand, string $category, string $volume, int $supply, int $title, $regular, string $refId): array {
    $row = array_fill(0, 26, '');
    $row[0] = $name; $row[1] = $name; $row[2] = 'key-'.$refId; $row[3] = (string)$supply; $row[4] = (string)$supply;
    $row[5] = (string)$title; $row[6] = 1; $row[8] = $volume; $row[12] = $brand; $row[13] = $category;
    $row[15] = 1; $row[16] = $refId; $row[17] = 'TOMAN'; $row[18] = 'rezvanpakhsh_woocommerce_store_api';
    $row[19] = 'https://rezvanpakhsh.com/wp-json/wc/store/v1/products'; $row[20] = '2026-11-26T00:00:00+00:00';
    $row[21] = (string)$supply; $row[22] = (string)$supply;
    $row[23] = $regular === null ? '' : (string)$regular; $row[24] = $regular === null ? '' : (string)$regular;
    return $row;
}
$refRows = [
    ref_row26('چای سیاه احمد', 'احمد', 'چای', '500 گرم', 80000, 100000, 100000, 'REF-A'),  // (a) regular present
    ref_row26('قهوه فوری نسکافه', 'نسکافه', 'قهوه', '200 گرم', 80000, 100000, null, 'REF-B'), // (b) lone supply
    ref_row26('شکر سفید دولیتل', 'دولیتل', 'شکر', '', 80000, 100000, null, 'REF-C'),       // (c) lone supply
    ref_row26('روغن آفتابگردان اویلا', 'اویلا', 'روغن', '1 لیتر', 80000, 100000, null, 'REF-D'), // (d) lone supply
    ref_row26('چای سیاه گلستان', 'گلستان', 'چای', '500 گرم', 85000, 100000, null, 'REF-G'), // (g) lone supply, disagreeing
];
$refRowF = array_fill(0, 21, '');  // (f) v1 row: no regular column at all
$refRowF[0] = 'چای سبز لیپتون'; $refRowF[1] = 'چای سبز لیپتون'; $refRowF[2] = 'key-REF-F';
$refRowF[3] = '80000'; $refRowF[4] = '80000'; $refRowF[5] = '100000'; $refRowF[6] = 1;
$refRowF[8] = '100 گرم'; $refRowF[12] = 'لیپتون'; $refRowF[13] = 'چای'; $refRowF[15] = 1; $refRowF[16] = 'REF-F';
$refRowF[17] = 'TOMAN';
$refRows[] = $refRowF;
$payload = json_encode(['schema_version' => 2, 'status' => 'staged', 'extracted_at' => $freshIso, 'rows' => $refRows],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
molavi_domain_tx('reference', static function (SQLite3 $db) use ($payload, $freshIso): void {
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots (snapshot_id,payload) VALUES (?,?)', ['snap-q8', $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active (singleton,snapshot_id) VALUES (1,?)', ['snap-q8']);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check (singleton,snapshot_id,checked_at) VALUES (1,?,?)', ['snap-q8', $freshIso]);
});

// --- Kept matches (active, trusted): decision + match row each ---
function seed_kept_match(string $id, string $refId, int $package, int $unit, int $consumer, string $signature): void {
    global $nowInt;
    $offerId = 'owner-confirm:' . hash('sha256', $id);
    $decisionId = 'q8-decision-' . $id;
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($offerId, $decisionId, $signature, $refId): void {
        molavi_domain_sql($db, 'INSERT INTO reference_item_decisions (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,price_role,actor_id,note,created_at,supersedes_decision_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)',
            [$decisionId, 'item:q8-' . $offerId, $offerId, 'snap-q8', $signature, 'match', $refId, 'unit', 'wp:7', 'Owner pick (harness).', '2026-11-26T00:00:00+00:00']);
    });
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($id, $offerId, $decisionId, $signature, $refId, $package, $unit, $consumer, $nowInt): void {
        $cols = ['product_id', 'source', 'offer_id', 'signature', 'reference_id', 'source_product_id', 'snapshot_id', 'price_role',
            'catalog_package_price', 'catalog_unit_price', 'catalog_consumer_price', 'catalog_supplier_price', 'sheet_consumer_price',
            'score', 'name_similarity', 'match_basis', 'decision_id', 'listing_id', 'url', 'provider', 'merchant', 'quote_json',
            'regular_price', 'pack_count', 'identity_json', 'actor_id', 'status', 'created_at', 'checked_at', 'verified_at', 'next_at'];
        // Stored prices mirror the writer (from_snapshot): package/unit
        // from [3]/[4] (the supply price), consumer from [5]; the
        // RESERVED catalog_supplier_price stays NULL — the leg re-reads
        // the supply price from the current snapshot row.
        $vals = [$id, 'catalog', $offerId, $signature, $refId, '', 'snap-q8', 'unit', $package, $unit, $consumer, null, $consumer,
            1.0, 1.0, 'owner_pick', $decisionId, null, null, null, null, null, null, null, null, 'wp:7', 'active',
            $nowInt - 3600, $nowInt - 3600, null, $nowInt - 3600 + 14 * 86400];
        molavi_domain_sql($db, 'INSERT INTO reference_product_matches (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    });
}
seed_kept_match('q8-a', 'REF-A', 80000, 80000, 100000, $sigOfOffer('owner-confirm:'.hash('sha256', 'q8-a')));
seed_kept_match('q8-b', 'REF-B', 80000, 80000, 100000, $sigOfOffer('owner-confirm:'.hash('sha256', 'q8-b')));
seed_kept_match('q8-c', 'REF-C', 80000, 80000, 100000, $sigOfOffer('owner-confirm:'.hash('sha256', 'q8-c')));
seed_kept_match('q8-d', 'REF-D', 80000, 80000, 100000, $sigOfOffer('owner-confirm:'.hash('sha256', 'q8-d')));
seed_kept_match('q8-f', 'REF-F', 80000, 80000, 100000, $sigOfOffer('owner-confirm:'.hash('sha256', 'q8-f')));
seed_kept_match('q8-g', 'REF-G', 85000, 85000, 100000, $sigOfOffer('owner-confirm:'.hash('sha256', 'q8-g')));

// --- Link checks: 'price_unchanged' isolates the catalog leg; ---
// 'identical' lets the FIX-1 link leg try.
function seed_link(string $id, string $reason, int $checkedAt, string $sale, string $revision): void {
    global $nowInt;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $reason, $checkedAt, $sale, $revision, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, 'owner-confirm:'.hash('sha256', $id), 'Item '.$id, 'https://t.me/supplier/'.substr($id, 3), $revision, $sale,
                'Item '.$id.' '.$sale, 'unchanged', $reason, $sale, null, 'railway', $checkedAt, $nowInt + 86400, null,
                null, null, $nowInt - 7200]);
    });
}
$k = 0;
foreach ([['q8-a', 'price_unchanged'], ['q8-b', 'price_unchanged'], ['q8-c', 'identical'], ['q8-d', 'identical'],
    ['q8-e', 'identical'], ['q8-f', 'price_unchanged'], ['q8-g', 'identical']] as [$pid, $reason])
    seed_link($pid, $reason, $nowInt - 7000 + ($k++) * 1000, '80000', $revision[$pid]);

function offer_for(string $id, array $sheetRecords, int $nowInt): array {
    foreach (molavi_domain_freshness($sheetRecords, (float)$nowInt, false) as $offer)
        if ($offer['product_id'] === $id) return $offer;
    return [];
}
function db_row(string $store, string $sql, array $params = []): ?array {
    return molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_row($db, $sql, $params));
}
$byId = [];
foreach ($sheetRecords as $r) $byId[(string)$r['Product ID']] = $r;

if (!$isFixed) {
    // --- NEGATIVE CONTROL: on the wave2 base the old catalog leg ---
    // trusts the title figure [5], so lone-supply products verify.
    foreach ([['q8-b', '(b) lone supply verifies on the base'],
        ['q8-d', '(d) lone supply verifies on the base'],
        ['q8-f', '(f) v1 row verifies on the base']] as [$pid, $label]) {
        $ev = molavi_domain_auto_verify_eligible($byId[$pid], offer_for($pid, $sheetRecords, $nowInt), $nowInt);
        cmp_true($ev !== null, 'negative: '.$label);
        cmp_check($ev['leg'] ?? null, 'catalog', 'negative: '.$label.' leg is catalog');
        cmp_check($ev['catalog_price'] ?? null, 100000, 'negative: '.$label.' catalog price is the title figure');
    }
    echo "NEGATIVE native Q8 lone supply $count cases (new behaviour absent on the base)\n";
    exit($fail === 0 ? 0 : 1);
}

// --- (a) regular present -> catalog leg, as before ---
$ev = molavi_domain_auto_verify_eligible($byId['q8-a'], offer_for('q8-a', $sheetRecords, $nowInt), $nowInt);
cmp_true($ev !== null, '(a) regular present is eligible');
cmp_check($ev['leg'] ?? null, 'catalog', '(a) leg is catalog');
cmp_check($ev['catalog_price'] ?? null, 100000, '(a) catalog price is the explicit regular price 100000');
cmp_check($ev['supply_price'] ?? null, 80000, '(a) supply price 80000');
cmp_true($ev['supply_agrees'] ?? false, '(a) supply agrees with sheet sale price');
// --- (b) lone supply -> regular leg NOT satisfied ---
$chk = molavi_domain_auto_verify_check($byId['q8-b'], offer_for('q8-b', $sheetRecords, $nowInt), $nowInt);
cmp_false($chk['eligible'], '(b) lone supply is not eligible');
cmp_check($chk['reason'], 'catalog_no_regular_price', '(b) reason is catalog_no_regular_price');
cmp_null(molavi_domain_auto_verify_eligible($byId['q8-b'], offer_for('q8-b', $sheetRecords, $nowInt), $nowInt), '(b) eligible is null');
// --- (c) lone supply + FIX-1 rescue -> link_message leg, supply recorded ---
$ev = molavi_domain_auto_verify_eligible($byId['q8-c'], offer_for('q8-c', $sheetRecords, $nowInt), $nowInt);
cmp_true($ev !== null, '(c) lone supply + identical link is eligible');
cmp_check($ev['leg'] ?? null, 'link_message', '(c) leg is link_message (FIX-1), not catalog');
cmp_null($ev['catalog_price'] ?? null, '(c) no catalog price');
cmp_check($ev['supply_price'] ?? null, 80000, '(c) lone supply price 80000 recorded');
cmp_true($ev['supply_agrees'] ?? false, '(c) lone supply agrees with sheet sale price');
// --- (d) lone supply alone never satisfies both legs ---
$chk = molavi_domain_auto_verify_check($byId['q8-d'], offer_for('q8-d', $sheetRecords, $nowInt), $nowInt);
cmp_false($chk['eligible'], '(d) lone supply alone is not eligible');
cmp_check($chk['reason'], 'catalog_no_regular_price', '(d) reason stays the catalog leg reason');
// --- (e) FIX-1 unchanged with no kept match at all ---
$ev = molavi_domain_auto_verify_eligible($byId['q8-e'], offer_for('q8-e', $sheetRecords, $nowInt), $nowInt);
cmp_true($ev !== null, '(e) identical link with no match is eligible');
cmp_check($ev['leg'] ?? null, 'link_message', '(e) leg is link_message');
cmp_null($ev['supply_price'] ?? null, '(e) no kept match, no supply price');
cmp_false($ev['supply_agrees'] ?? false, '(e) no supply agreement without a kept match');
// --- (f) v1 row: no regular column -> regular leg NOT satisfied ---
$chk = molavi_domain_auto_verify_check($byId['q8-f'], offer_for('q8-f', $sheetRecords, $nowInt), $nowInt);
cmp_false($chk['eligible'], '(f) v1 row is not eligible');
cmp_check($chk['reason'], 'catalog_no_regular_price', '(f) reason is catalog_no_regular_price');
// --- (g) lone supply disagreeing -> link leg wins, agreement is false ---
$ev = molavi_domain_auto_verify_eligible($byId['q8-g'], offer_for('q8-g', $sheetRecords, $nowInt), $nowInt);
cmp_true($ev !== null, '(g) disagreeing lone supply + identical link is eligible');
cmp_check($ev['leg'] ?? null, 'link_message', '(g) leg is link_message');
cmp_check($ev['supply_price'] ?? null, 85000, '(g) supply price 85000 recorded');
cmp_false($ev['supply_agrees'] ?? false, '(g) supply does NOT agree with sheet sale price');
// --- Dry-run tick over the queue ---
$dry = molavi_domain_auto_verify_tick($nowInt, $isoNow, true);
cmp_true($dry['dry_run'], 'tick: dry-run flag');
cmp_check($dry['eligible_count'], 4, 'tick: eligible count 4 (a, c, e, g)');
cmp_check(array_column($dry['eligible'], 'product_id'), ['q8-a', 'q8-c', 'q8-e', 'q8-g'], 'tick: eligible list a/c/e/g oldest first');
cmp_check($dry['verified_count'], 0, 'tick: dry-run verifies 0');
cmp_check($dry['skipped'], ['catalog_no_regular_price' => 3], 'tick: skipped b/d/f as catalog_no_regular_price');
// --- one() writes name the supply agreement ---
$oneA = molavi_domain_auto_verify_one($byId['q8-a'], offer_for('q8-a', $sheetRecords, $nowInt), $nowInt, $isoNow);
cmp_true(!empty($oneA['ok']) && empty($oneA['idempotent']), 'one(): (a) writes the verification');
$histA = db_row('pipeline', 'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY rowid DESC LIMIT 1', ['q8-a']);
cmp_true(str_contains((string)($histA['note'] ?? ''), 'قیمت تأمین‌کننده'), 'one(): (a) note names the supply agreement');
$oneC = molavi_domain_auto_verify_one($byId['q8-c'], offer_for('q8-c', $sheetRecords, $nowInt), $nowInt, $isoNow);
cmp_true(!empty($oneC['ok']) && empty($oneC['idempotent']), 'one(): (c) writes the verification');
$histC = db_row('pipeline', 'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY rowid DESC LIMIT 1', ['q8-c']);
cmp_true(str_contains((string)($histC['note'] ?? ''), 'قیمت مصرف‌کننده'), 'one(): (c) note names the message regular price (FIX-1)');
cmp_true(str_contains((string)($histC['note'] ?? ''), 'قیمت تأمین‌کننده'), 'one(): (c) note names the supply agreement too');
echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
