<?php
/** No-revision price entry (G1, owner request 2026-11-26): stub harness.
 * Run: php tests/native_no_revision_price_entry.php
 *
 * Fixtures mirror the legacy no-revision rows: a supplier price exists in
 * the Products sheet but the primary source revision is empty (the Sale
 * Price does not parse as a number), so FIX-2 derives a stable legacy
 * revision ('legacy:' + content hash) the link check and auto-verify can
 * key on. The G1 flow still fixes the row the same way: the owner types
 * the price, the sheet write makes the PRIMARY revision computable, and
 * the evidence is written for that primary revision.
 *  - p_norev: Sale Price '' (empty) -> legacy revision, primary ''.
 *  - p_norev_text: Sale Price 'توافقی' (text) -> legacy revision, primary ''.
 *  - p_ok (control): Sale Price '1250000' -> primary revision, no legacy tag.
 *
 * The sheet transport is stubbed faithfully: molavi_domain_sheet_fields
 * applies the field updates to the fixture sheet by header name and row,
 * exactly as the real one does; only the Google HTTP call is out of scope
 * (proven by the sheet-conflict and published-review flows). Everything
 * else is the real code: revision derivation, sheet-write confirmation,
 * evidence write (method manual), publish-job queue, and the saved-price
 * state that re-arms verify.
 */
declare(strict_types=1);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-norev-test-'.bin2hex(random_bytes(5)));
define('ABSPATH', MOLAVI_STATE_ROOT.'/wp/');
function add_action(...$args) {}
function add_filter(...$args) {}
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['cache'][$key]); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function get_current_user_id() { return 7; }
// Fixture sheet: the Products table. molavi_domain_sheets returns it, and
// molavi_domain_sheet_fields applies writes to it by header + row.
function molavi_domain_sheets(array $names, bool $fresh = false, bool $unformatted = false): array {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['sheets'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields(string $name, int $row, array $headers, array $values): void {
    foreach ($values as $field => $value) {
        $column = array_search($field, $headers, true);
        if ($column === false) throw new RuntimeException('sheet_header_missing: '.$field);
        $GLOBALS['sheets'][$name][$row - 1][$column] = (string)$value;
    }
}
$root = __DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-products',
          'domain-sheet-conflicts', 'domain-supplier-prices'] as $module) {
    require $root.'molavi-'.$module.'.php';
}
foreach (['reference', 'pipeline'] as $store) { $db = new SQLite3(molavi_state_root().'/'.$store.'.sqlite3'); $db->close(); }
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
function expectFail(callable $fn, string $code, string $label): void {
    try { $fn(); } catch (MolaviWorkflowError $e) {
        if ($e->getMessage() !== $code) throw new RuntimeException('FAIL '.$label.' (wrong code: '.$e->getMessage().', expected '.$code.')');
        echo 'PASS '.$label."\n";
        return;
    }
    throw new RuntimeException('FAIL '.$label.' (no error thrown)');
}
function sheet_row(array $values): array {
    static $headers = null;
    $headers ??= molavi_domain_product_headers();
    $row = array_fill(0, count($headers), '');
    foreach ($values as $key => $value) $row[array_search($key, $headers, true)] = $value;
    return $row;
}
function product_record(string $id): array {
    $records = molavi_domain_records($GLOBALS['sheets']['Products']);
    foreach ($records as $record) if ((string)molavi_domain_py_value($record['Product ID'] ?? null) === $id) return $record;
    throw new RuntimeException('fixture product not found: '.$id);
}
function evidence_row(string $id): ?array {
    return molavi_domain_read('pipeline', static fn(SQLite3 $db) =>
        molavi_domain_row($db, 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', [$id]));
}

// --- Fixtures ---
$headers = molavi_domain_product_headers();
$GLOBALS['sheets'] = ['Products' => [$headers,
    sheet_row(['Product ID' => 'p_norev', 'Product Name' => 'کالای بدون قیمت', 'Sale Price' => '',
        'Currency' => '', 'Latest Offer ID' => 'offer-norev', 'Source Post URL' => '', 'Status' => 'imported']),
    sheet_row(['Product ID' => 'p_norev_text', 'Product Name' => 'کالای قیمت متنی', 'Sale Price' => 'توافقی',
        'Currency' => '', 'Latest Offer ID' => 'offer-norev-text', 'Source Post URL' => '', 'Status' => 'imported']),
    sheet_row(['Product ID' => 'p_ok', 'Product Name' => 'کالای سالم', 'Sale Price' => '1250000',
        'Currency' => '', 'Latest Offer ID' => 'offer-ok', 'Source Post URL' => '', 'Status' => 'imported']),
]];

// --- (0) The fixtures reproduce the legacy case: no primary revision, stable legacy revision ---
check(molavi_domain_source_revision_primary(product_record('p_norev')) === '', 'empty sale price -> primary revision empty');
check(molavi_domain_source_revision_primary(product_record('p_norev_text')) === '', 'text sale price -> primary revision empty');
$legacyNorev = molavi_domain_source_revision(product_record('p_norev'));
$legacyText = molavi_domain_source_revision(product_record('p_norev_text'));
check(str_starts_with($legacyNorev, 'legacy:'), 'empty sale price -> legacy revision');
check(str_starts_with($legacyText, 'legacy:'), 'text sale price -> legacy revision');
check($legacyNorev !== $legacyText, 'different legacy offers get different revisions');
check(molavi_domain_source_revision(product_record('p_ok')) !== '', 'valid sale price -> revision non-empty');
check(!str_starts_with(molavi_domain_source_revision(product_record('p_ok')), 'legacy:'), 'valid sale price -> primary revision, no legacy tag');

// --- (b) Registering the price writes the evidence (method manual) and re-arms verify ---
$result = molavi_domain_register_price_from_message([
    'action' => 'register_price_from_message', 'product_id' => 'p_norev', 'sale_price' => 1250000,
    'source_url' => 'https://t.me/bonakdarjavan/7218', 'note' => 'قیمت از پیام خوانده شد.',
], 'wp:7');
check(($result['ok'] ?? false) === true, 'register returns ok');
check($result['product_id'] === 'p_norev', 'register returns the product id');
check(is_string($result['source_revision'] ?? null) && $result['source_revision'] !== '', 'register returns a non-empty revision');
check(!empty($result['verified_at']), 'register returns verified_at');
check(!empty($result['publish_job']), 'register queues a publish job');
check(($result['sale_price'] ?? null) === 1250000, 'register returns the entered price');
// The sheet now carries the entered price and the source link.
$updated = product_record('p_norev');
check((string)molavi_domain_py_value($updated['Sale Price'] ?? null) === '1250000', 'sheet sale price is the entered price');
check((string)($updated['Source Post URL'] ?? '') === 'https://t.me/bonakdarjavan/7218', 'sheet source URL is the entered link');
check((string)molavi_domain_py_value($updated['Previous Sale Price'] ?? null) === '', 'previous sale price stays empty when the old price was empty');
// The revision from the updated row matches the receipt.
check(molavi_domain_source_revision($updated) === $result['source_revision'], 'live revision matches the receipt revision');
// Registering the price switches the revision from legacy to primary.
check($result['source_revision'] !== $legacyNorev, 'registered price replaces the legacy revision with the primary revision');
// The evidence row is method manual, by the owner.
$evidence = evidence_row('p_norev');
check($evidence !== null, 'evidence row exists');
check($evidence['source_revision'] === $result['source_revision'], 'evidence revision matches the receipt');
check($evidence['method'] === 'manual', 'evidence method is manual');
check($evidence['verified_by'] === 'wp:7', 'evidence verified_by is the owner');
// The saved-price state is now current: verify is re-armed (the revision
// exists and the verification is fresh).
$saved = molavi_domain_read('pipeline', 'molavi_domain_price_evidence_by_product');
$state = molavi_domain_product_saved_price($updated, $saved, microtime(true));
check(($state['state'] ?? '') === 'current', 'saved-price state is current after registering');
check(($state['method'] ?? '') === 'manual', 'saved-price method is manual');

// --- Retry is safe: the same price again succeeds and does not move Previous ---
$again = molavi_domain_register_price_from_message([
    'action' => 'register_price_from_message', 'product_id' => 'p_norev', 'sale_price' => 1250000,
    'source_url' => 'https://t.me/bonakdarjavan/7218',
], 'wp:7');
check(($again['ok'] ?? false) === true, 'retry with the same price succeeds');
check($again['source_revision'] === $result['source_revision'], 'retry returns the same revision');
check((string)molavi_domain_py_value(product_record('p_norev')['Previous Sale Price'] ?? null) === '', 'retry does not set Previous when the price is unchanged');

// --- A text price is fixable the same way ---
$textResult = molavi_domain_register_price_from_message([
    'action' => 'register_price_from_message', 'product_id' => 'p_norev_text', 'sale_price' => 890000,
], 'wp:7');
check(($textResult['ok'] ?? false) === true, 'text-price row registers too');
check((string)molavi_domain_py_value(product_record('p_norev_text')['Sale Price'] ?? null) === '890000', 'text-price sheet row is fixed');
check((string)molavi_domain_py_value(product_record('p_norev_text')['Previous Sale Price'] ?? null) === 'توافقی', 'previous sale price keeps the old text price');

// --- Validation: bad inputs are rejected, nothing is written ---
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok', 'sale_price' => 0], 'wp:7'),
    'invalid_sale_price', 'price 0 is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok', 'sale_price' => -5], 'wp:7'),
    'invalid_sale_price', 'negative price is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok', 'sale_price' => 'abc'], 'wp:7'),
    'invalid_sale_price', 'text price is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok', 'sale_price' => '12.5'], 'wp:7'),
    'invalid_sale_price', 'fractional price is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok', 'sale_price' => 100, 'source_url' => 'ftp://example.test/x'], 'wp:7'),
    'invalid_source_url', 'non-https source URL is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok', 'sale_price' => 100, 'source_url' => 'not-a-url'], 'wp:7'),
    'invalid_source_url', 'bare source text is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_missing', 'sale_price' => 100], 'wp:7'),
    'product_not_found', 'unknown product is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok'], 'wp:7'),
    'invalid_body', 'missing sale_price is rejected');
expectFail(fn() => molavi_domain_register_price_from_message(
    ['action' => 'register_price_from_message', 'product_id' => 'p_ok', 'sale_price' => 100, 'bogus' => 1], 'wp:7'),
    'invalid_body', 'unknown body key is rejected');

// --- (c) The control product is untouched ---
check((string)molavi_domain_py_value(product_record('p_ok')['Sale Price'] ?? null) === '1250000', 'control row sale price unchanged');
check(evidence_row('p_ok') === null, 'control row has no evidence row');

echo "\nALL NO-REVISION PRICE-ENTRY (PHP) CHECKS PASSED\n";
