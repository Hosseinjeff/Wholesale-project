<?php
// Legacy-offer revisions (FIX-2, 2026-11-26): 10 legacy offers carry Sale
// Price '0'/'' (or unparseable text), so the primary source revision is
// '' and no link check or auto-verify gate can key on them. The fix
// derives a stable revision from the offer's own content (offer id,
// stored message, prices, currency, timestamp basis, source URL) with
// the same molavi_domain_hash helper, tagged 'legacy:' so it can never
// collide with a primary revision.
//
// Cases (FIXED mode):
//   (a) a no-price legacy offer (Sale Price '0', like the 10 live ones)
//       gets a non-empty revision starting with 'legacy:'.
//   (b) the same record derives the same revision twice (stable: no
//       re-arm on every tick).
//   (c) a priced offer keeps its primary revision byte-identical
//       (pinned parity hash), with no 'legacy:' tag.
//   (d) different offers get different revisions: different offer id,
//       different message, different consumer price.
//   (e) no offer id AND no product id -> still '' (nothing to key on).
//   (f) no offer id but a product id -> 'sheet:<id>'-based legacy revision.
//   (g) freshness NEVER writes 'source' evidence for a legacy row, even
//       with a trusted basis and a fresh message: there is no supplier
//       price to verify. A priced control with the same trusted basis
//       DOES get evidence (state current) — the guard blocks legacy only.
//   (h) seed re-arm: a link-check row stored with source_revision ''
//       (the live state of the 10) is re-armed to the legacy revision
//       with outcome reset (due now); a second seed writes nothing
//       (idempotent); a priced control row with a matching revision is
//       left untouched (cadence preserved).
//   (i) the derived revision is a valid seed-comparison shape: after
//       the re-arm the row's source_revision equals the live derivation.
//
// Negative control: run this file in a worktree at the base commit
// 4ac67ae WITHOUT the fix (no molavi_domain_source_revision_primary):
// it PASSES by proving the legacy revision does NOT exist there — the
// derivation returns '', the freshness guard function is absent, and
// the seed leaves the '' row alone. A harness that passes on the base
// is worthless; this one asserts the opposite there.
// Run: php tests/native_legacy_offer_revisions.php
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-legacy-revisions-'.bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }

foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-reference-store',
    'domain-products', 'domain-supplier-link-checks'] as $file)
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
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

$isFixed = function_exists('molavi_domain_source_revision_primary');
echo 'MODE: '.($isFixed ? 'FIXED (legacy revision derivation present)' : 'BASE 4ac67ae (negative control: no legacy revision)')."\n";

$now = molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$nowInt = (int)$now;
$msgIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600); // message 1h ago

// Mirror of a live legacy row: Sale Price '0', consumer price present,
// message + source URL present, no timestamp basis (legacy_unknown).
$legacy = ['Product ID' => 'p-legacy', 'Product Name' => 'نوشيدنى آلوورا بر پايه عسل ۱/۵ لیتر تعداد ۶ عددی',
    'Channel Username' => 'supplier', 'Channel' => 'supplier', 'Latest Offer ID' => 'a5d0caeba82845d3a21367928f5d4676:54:0',
    'Sale Price' => '0', 'Consumer Price' => '150000', 'Currency' => '', 'Status' => 'imported',
    'Message Timestamp' => $msgIso, 'Source Timestamp Basis' => '',
    'Source Post URL' => 'https://t.me/supplier/54', 'Original Message' => "نوشيدنى آلوورا بر پايه عسل ۱/۵ لیتر تعداد ۶ عددی\nقیمت مصرف کننده: 150000 تومان",
    'Stock Status' => 'Unknown'];
// Priced control: the same shape with a real sale price.
$priced = array_merge($legacy, ['Product ID' => 'p-priced', 'Product Name' => 'Item priced',
    'Latest Offer ID' => 'owner-confirm:'.hash('sha256', 'p-priced'), 'Sale Price' => '80000',
    'Consumer Price' => '100000', 'Source Post URL' => 'https://t.me/supplier/9',
    'Original Message' => 'Item priced 80000']);
// The parity fixture record syn1 pins its primary revision byte-identically.
$syn1 = json_decode(file_get_contents(__DIR__.'/fixtures/native-product-parity.json'), true, 128, JSON_THROW_ON_ERROR)['records'][0];
$syn1Revision = '9a5881724512176a6288cf1295fc033695d8724b73e3eb23dbbf9d1e896f9048';

if (!$isFixed) {
    // --- NEGATIVE CONTROL: the legacy revision must not exist on the base. ---
    cmp_false(function_exists('molavi_domain_source_revision_primary'), 'base: no primary revision function');
    cmp_check(molavi_domain_source_revision($legacy), '', 'base: legacy offer revision is empty');
    cmp_check(molavi_domain_source_revision($priced), molavi_domain_source_revision($priced), 'base: priced revision is stable (sanity)');
    cmp_true(molavi_domain_source_revision($priced) !== '', 'base: priced revision non-empty');
    // Freshness emits '' for the legacy row and writes no evidence for it.
    $fresh = molavi_domain_freshness([$legacy], (float)$nowInt, true);
    cmp_check($fresh[0]['source_revision'], '', 'base: freshness emits empty revision for the legacy row');
    cmp_check($fresh[0]['state'], 'needs_review', 'base: legacy row state needs_review');
    $ev = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
        'SELECT * FROM supplier_price_evidence WHERE offer_id=?', ['p-legacy']));
    cmp_null($ev, 'base: no evidence row for the legacy row');
    echo "PASS $count FAIL $fail\n";
    exit($fail === 0 ? 0 : 1);
}

// --- FIXED mode: (a) a no-price legacy offer gets a tagged revision ---
$rev = molavi_domain_source_revision($legacy);
cmp_true($rev !== '', '(a) legacy offer gets a non-empty revision');
cmp_true(str_starts_with($rev, 'legacy:'), '(a) legacy revision carries the legacy: tag');
cmp_check(molavi_domain_source_revision_primary($legacy), '', '(a) legacy offer primary revision is still empty');

// --- (b) stability: same record, same revision ---
cmp_check(molavi_domain_source_revision($legacy), $rev, '(b) same record derives the same revision twice');
$legacyCopy = $legacy;
cmp_check(molavi_domain_source_revision($legacyCopy), $rev, '(b) an identical copy derives the same revision');

// --- (c) a priced offer keeps its primary revision byte-identical ---
cmp_check(molavi_domain_source_revision($syn1), $syn1Revision, '(c) priced parity record revision is byte-identical');
cmp_check(molavi_domain_source_revision($priced), molavi_domain_source_revision_primary($priced), '(c) priced revision equals its primary revision');
cmp_false(str_starts_with(molavi_domain_source_revision($priced), 'legacy:'), '(c) priced revision has no legacy tag');

// --- (d) different offers get different revisions ---
$otherOffer = array_merge($legacy, ['Latest Offer ID' => 'a5d0caeba82845d3a21367928f5d4676:55:0']);
cmp_true(molavi_domain_source_revision($otherOffer) !== $rev, '(d) different offer id -> different revision');
$otherMessage = array_merge($legacy, ['Original Message' => $legacy['Original Message'].' (تخفیف)']);
cmp_true(molavi_domain_source_revision($otherMessage) !== $rev, '(d) different message -> different revision');
$otherConsumer = array_merge($legacy, ['Consumer Price' => '160000']);
cmp_true(molavi_domain_source_revision($otherConsumer) !== $rev, '(d) different consumer price -> different revision');
// "0" and "" are the same absence of a price: no spurious revision change.
$emptySale = array_merge($legacy, ['Sale Price' => '']);
cmp_check(molavi_domain_source_revision($emptySale), $rev, '(d) sale "0" and "" derive the same revision');

// --- (e) nothing to key on -> still '' ---
cmp_check(molavi_domain_source_revision(['Sale Price' => '0', 'Original Message' => 'x']), '', '(e) no offer id and no product id -> empty revision');

// --- (f) no offer id but a product id -> sheet:<id>-based legacy revision ---
$sheetOnly = array_merge($legacy, ['Latest Offer ID' => '']);
cmp_true(str_starts_with(molavi_domain_source_revision($sheetOnly), 'legacy:'), '(f) sheet-only legacy offer gets a legacy revision');
cmp_true(molavi_domain_source_revision($sheetOnly) !== $rev, '(f) sheet-only revision differs from the offer-id revision');

// --- (g) freshness never writes 'source' evidence for a legacy row ---
$trustedLegacy = array_merge($legacy, ['Source Timestamp Basis' => 'supplier_channel_update']);
$fresh = molavi_domain_freshness([$trustedLegacy], (float)$nowInt, true);
cmp_check($fresh[0]['source_revision'], molavi_domain_source_revision($trustedLegacy), '(g) freshness emits the legacy revision');
cmp_check($fresh[0]['state'], 'needs_review', '(g) legacy row state stays needs_review');
$ev = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
    'SELECT * FROM supplier_price_evidence WHERE offer_id=?', ['p-legacy']));
cmp_null($ev, '(g) no evidence row written for the legacy row despite a trusted basis');
// Priced control with the same trusted basis DOES get evidence.
$trustedPriced = array_merge($priced, ['Source Timestamp Basis' => 'supplier_channel_update']);
$freshPriced = molavi_domain_freshness([$trustedPriced], (float)$nowInt, true);
cmp_check($freshPriced[0]['state'], 'current', '(g) priced control with trusted basis is current');
$evPriced = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
    'SELECT * FROM supplier_price_evidence WHERE offer_id=?', ['p-priced']));
cmp_true($evPriced !== null, '(g) evidence row exists for the priced control');
cmp_check($evPriced['source_revision'] ?? null, molavi_domain_source_revision($trustedPriced), '(g) priced evidence is keyed on the primary revision');

// --- (h) seed re-arm: an old '' row is re-armed once, then left alone ---
$headers = molavi_domain_product_headers();
function sheet_rows(array $records): array {
    $headers = molavi_domain_product_headers();
    $out = [$headers];
    foreach ($records as $r) $out[] = array_map(static fn($h) => (string)($r[$h] ?? ''), $headers);
    return $out;
}
$GLOBALS['sheets'] = [
    'Products' => sheet_rows([$legacy, $priced]),
    'Offers' => [[
        'Offer ID' => $legacy['Latest Offer ID'], 'Product ID' => 'p-legacy',
        'Product Name' => $legacy['Product Name'], 'Channel Username' => 'supplier',
        'Batch ID' => '', 'Message ID' => '54', 'Message Timestamp' => $msgIso,
        'Source Post URL' => $legacy['Source Post URL'], 'Original Message' => $legacy['Original Message'],
        'Sale Price' => '0', 'Status' => 'needs_review',
    ], [
        'Offer ID' => $priced['Latest Offer ID'], 'Product ID' => 'p-priced',
        'Product Name' => $priced['Product Name'], 'Channel Username' => 'supplier',
        'Batch ID' => '', 'Message ID' => '9', 'Message Timestamp' => $msgIso,
        'Source Post URL' => $priced['Source Post URL'], 'Original Message' => $priced['Original Message'],
        'Sale Price' => '80000', 'Status' => 'needs_review',
    ]],
    'MessageData' => [[
        'Channel Username' => 'supplier', 'Batch ID' => '', 'ID' => '54',
        'URL' => $legacy['Source Post URL'], 'Content' => $legacy['Original Message'],
    ], [
        'Channel Username' => 'supplier', 'Batch ID' => '', 'ID' => '9',
        'URL' => $priced['Source Post URL'], 'Content' => $priced['Original Message'],
    ]],
];
// The live state of the 10: outcome 'unchanged'/'identical', revision ''.
molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($legacy, $nowInt): void {
    molavi_domain_sql($db, 'INSERT INTO supplier_link_checks
        (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,
         live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['p-legacy', $legacy['Latest Offer ID'], $legacy['Product Name'], $legacy['Source Post URL'], '',
         '0', $legacy['Original Message'], 'unchanged', 'identical', null, null, 'railway',
         $nowInt - 3600, $nowInt - 3600, null, null, null, $nowInt - 7200]);
});
// Priced control: already checked for the current revision; the seed must not touch it.
$pricedRev = molavi_domain_source_revision($priced);
molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($priced, $pricedRev, $nowInt): void {
    molavi_domain_sql($db, 'INSERT INTO supplier_link_checks
        (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,
         live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['p-priced', $priced['Latest Offer ID'], $priced['Product Name'], $priced['Source Post URL'], $pricedRev,
         '80000', $priced['Original Message'], 'unchanged', 'identical', null, null, 'railway',
         $nowInt - 3600, $nowInt + 86400, null, null, null, $nowInt - 7200]);
});
function link_row(string $id): ?array {
    return molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_supplier_link_check_row($db, $id));
}
$seeded = molavi_domain_supplier_link_check_seed($nowInt);
cmp_check($seeded, 1, '(h) seed re-arms exactly the legacy row');
$row = link_row('p-legacy');
cmp_check($row['source_revision'] ?? null, $rev, '(h) re-armed row carries the legacy revision');
cmp_null($row['outcome'] ?? null, '(h) re-armed row outcome is reset (due for a fresh check)');
cmp_null($row['reason_code'] ?? null, '(h) re-armed row reason is reset');
cmp_null($row['checked_at'] ?? null, '(h) re-armed row checked_at is reset');
cmp_check((int)($row['next_at'] ?? 0), $nowInt, '(h) re-armed row is due now');
// (i) the re-armed row matches the live derivation: the seed comparison shape.
cmp_check($row['source_revision'] ?? null, molavi_domain_source_revision($legacy), '(i) re-armed row revision equals the live derivation');
// Second seed: nothing changed, nothing re-armed.
cmp_check(molavi_domain_supplier_link_check_seed($nowInt), 0, '(h) second seed re-arms nothing (idempotent)');
// The priced control row is untouched: its check cadence is preserved.
$control = link_row('p-priced');
cmp_check($control['source_revision'] ?? null, $pricedRev, '(h) priced control row revision untouched');
cmp_check($control['outcome'] ?? null, 'unchanged', '(h) priced control row outcome untouched');
cmp_check((int)($control['checked_at'] ?? 0), $nowInt - 3600, '(h) priced control row checked_at untouched');
cmp_check((int)($control['next_at'] ?? 0), $nowInt + 86400, '(h) priced control row next_at untouched');

echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
