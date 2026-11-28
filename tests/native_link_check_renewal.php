<?php
// FIX-1 — identical-link renewal + auto-verify refresh (2026-11-26):
// (1) In molavi_domain_regular_price_evidence, a link check with outcome
//     'unchanged' + reason 'identical', fresh (checked_at within the same
//     14-day window as the direct deadline), stored sale price still equal
//     to the sheet Sale Price, latest offer, offer Consumer Price == sheet
//     Consumer Price, renews the direct deadline to checked_at + 14 days.
//     'identical' proves the WHOLE live post text is unchanged, so the
//     consumer price stated in the message is unchanged too.
// (2) In molavi_domain_auto_verify_check, (a) the 'already_verified' skip
//     applies ONLY when the saved evidence is still current (verified_at
//     within 14 days); stale evidence for the current revision is
//     re-verifiable. (b) The second leg becomes catalog-agrees OR the
//     identical-link-verified message itself states the regular price
//     equal to the sheet Consumer Price (same evidence as (1)).
// (3) When Source Timestamp Basis is empty, the direct-deadline stamp
//     falls back to the offer's Message Timestamp — but only to date an
//     already-stale price; it never by itself makes a price 'current'.
//
// Cases (FIXED mode):
//   Evidence (molavi_domain_regular_price_evidence, market null):
//   (a) identical+fresh renews: state current, expires_at checked_at+14d.
//   (b) reason 'price_unchanged' (text changed) does NOT renew.
//   (c) outcome 'changed' does NOT renew. (d) outcome 'uncheckable'
//       does NOT renew. (e) stored sale != sheet sale does NOT renew.
//   (f) offer Consumer Price != sheet Consumer Price does NOT renew.
//   (g) non-latest offer does NOT renew. (h) stale identical check
//       (checked 15d ago) does NOT renew.
//   (i) empty-basis fallback: old message, no link row -> 'expired' with
//       a real deadline (message timestamp + 14d), not 'unverified'.
//   (j) empty-basis fallback never makes a price current: fresh message,
//       no link row -> still 'unverified'.
//   (k) trusted basis still works: fresh message -> 'current' via the
//       direct deadline (regression guard for the untouched branch).
//   (l) trusted basis, old message -> 'expired' with the message deadline.
//   Auto-verify check (freshness offers):
//   (m) no reference match + identical+fresh link + offer consumer ==
//       sheet -> ELIGIBLE via the link_message leg.
//   (n) offer consumer != sheet -> NOT eligible, reason stays
//       no_reference_match (the catalog leg's first failing gate).
//   (o) no Offers row at all -> NOT eligible, no_reference_match.
//   (p) stale evidence (verified 20d ago, same revision) is
//       re-verifiable: eligible, and one() writes fresh method='auto'
//       evidence and confirms the link.
//   (q) fresh evidence (verified 1h ago) still hits already_verified;
//       one() writes nothing.
//   (r) catalog leg still passes when present: eligible, leg 'catalog'.
//   (s) changed / uncheckable links never verify, even with no reference
//       match. (t) reason 'price_unchanged' does NOT feed the link leg.
//   (u) stale identical check (15d ago) does NOT feed the link leg.
//   Tick (fresh link/evidence tables, t-series only):
//   (v) dry-run reports the link-leg items eligible and writes nothing.
//   (w) live tick verifies the link-leg + catalog-leg items (cap 5),
//       confirms their links, notes name the message evidence; a second
//       tick verifies 0.
//
// Negative control: run this file in a worktree at the base commit
// 4ac67ae WITHOUT the fix (no molavi_domain_link_check_regular_deadline):
// it PASSES by proving the new behaviour is absent there — the renewal
// does not happen, the link leg does not exist (no_reference_match),
// stale evidence still hits already_verified, and the empty-basis stamp
// still yields 'unverified'. A harness that passes on the base is
// worthless; this one asserts the opposite there.
// Run: php tests/native_link_check_renewal.php
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-link-renewal-'.bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-reference-store',
    'domain-reference-matching', 'domain-reference-scheduler', 'domain-sheet-conflicts', 'domain-products', 'domain-publisher',
    'domain-jobs', 'domain-source-projection', 'domain-supplier-link-checks', 'domain-supplier-prices',
    'domain-sale-offers', 'domain-supplier-auto-verify'] as $file)
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

$isFixed = function_exists('molavi_domain_link_check_regular_deadline');
echo 'MODE: '.($isFixed ? 'FIXED (link-check renewal present)' : 'BASE 4ac67ae (negative control: no link-check renewal)')."\n";

$now = molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$nowInt = (int)$now;
$isoNow = '2026-11-26T12:00:00+00:00';
$msgOldIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 20 * 86400);   // 20 days ago: direct deadline past
$msgFreshIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 2 * 86400);  // 2 days ago: direct deadline future
$checkFresh = $nowInt - 3600;                                       // checked 1h ago
$checkStale = $nowInt - 15 * 86400;                                 // checked 15d ago
$freshIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600);

// --- Fixtures: Products records (e/v/t series) + Offers rows ---
function product_record(string $id, string $sale, string $consumer, string $refId, string $msgIso, string $basis, string $approval = 'owner_approved'): array {
    return ['Product ID' => $id, 'Product Name' => 'Item '.$id, 'Channel Username' => 'supplier', 'Channel' => 'supplier',
        'Latest Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Sale Price' => $sale, 'Consumer Price' => $consumer,
        'Status' => 'imported', 'Message Timestamp' => $msgIso, 'Source Timestamp Basis' => $basis,
        'Source Post URL' => 'https://t.me/supplier/'.substr($id, 1), 'Original Message' => 'Item '.$id.' '.$sale,
        'Stock Status' => 'Unknown', 'Reference Snapshot ID' => $refId === '' ? '' : 'snap-1', 'Reference Match ID' => $refId,
        'Reference Approval State' => $approval];
}
function offer_record(string $id, string $consumer, string $msgIso, string $basis): array {
    return ['Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Product ID' => $id, 'Consumer Price' => $consumer,
        'Actual Price' => '', 'Sale Price' => '80000', 'Message Timestamp' => $msgIso, 'Source Timestamp Basis' => $basis,
        'Source Post Updated At' => '', 'Owner Decision ID' => '', 'Owner Decision At' => '',
        'Import Timestamp' => $msgIso, 'Package Count' => '', 'Source Post URL' => 'https://t.me/supplier/'.substr($id, 1)];
}
$products = [];
$offers = [];
foreach (['e1', 'e2', 'e3', 'e4', 'e5', 'e6', 'e7', 'e8', 'e9', 'e12'] as $id)
    $products[$id] = product_record($id, '80000', '100000', '', $msgOldIso, '');
$products['e10'] = product_record('e10', '80000', '100000', '', $msgFreshIso, '');
$products['e11'] = product_record('e11', '80000', '100000', '', $msgFreshIso, 'supplier_channel_update');
foreach (['e1', 'e2', 'e3', 'e4', 'e5', 'e7', 'e8', 'e9', 'e12'] as $id)
    $offers[$id] = offer_record($id, '100000', $msgOldIso, '');
$offers['e6'] = offer_record('e6', '110000', $msgOldIso, '');   // (f) offer consumer != sheet
$offers['e10'] = offer_record('e10', '100000', $msgFreshIso, '');
$offers['e11'] = offer_record('e11', '100000', $msgFreshIso, 'supplier_channel_update');
foreach (['v1', 'v2', 'v4', 'v5', 'v6', 'v7', 'v8', 'v9', 'v10'] as $id)
    $products[$id] = product_record($id, '80000', '100000', $id === 'v6' ? 'ref-v6' : '', $msgOldIso, '');
$products['v3'] = product_record('v3', '80000', '100000', '', $msgOldIso, '');
foreach (['v1', 'v4', 'v5', 'v6', 'v7', 'v8', 'v9', 'v10'] as $id)
    $offers[$id] = offer_record($id, '100000', $msgOldIso, '');
$offers['v2'] = offer_record('v2', '110000', $msgOldIso, '');   // (n) offer consumer != sheet
// v3 deliberately has NO Offers row (o).
foreach (['t1', 't2', 't3', 't5', 't6'] as $id)
    $products[$id] = product_record($id, '80000', '100000', $id === 't3' ? 'ref-t3' : '', $msgOldIso, '');
foreach (['t1', 't2', 't3', 't5'] as $id)
    $offers[$id] = offer_record($id, '100000', $msgOldIso, '');
// t6 deliberately has NO Offers row.
$pHeaders = molavi_domain_product_headers();
$oHeaders = molavi_domain_offer_headers();
$GLOBALS['sheets'] = [
    'Products' => array_merge([$pHeaders], array_map(
        static fn($r) => array_map(static fn($h) => $r[$h] ?? '', $pHeaders), array_values($products))),
    'Offers' => array_merge([$oHeaders], array_map(
        static fn($r) => array_map(static fn($h) => $r[$h] ?? '', $oHeaders), array_values($offers))),
];
$sheetRecords = molavi_domain_records($GLOBALS['sheets']['Products']);
$revision = [];
foreach ($sheetRecords as $r) $revision[(string)$r['Product ID']] = molavi_domain_source_revision($r);
function record_for(string $id, array $sheetRecords): array {
    foreach ($sheetRecords as $r) if ((string)$r['Product ID'] === $id) return $r;
    return [];
}
function offer_for(string $id, array $sheetRecords, int $nowInt): array {
    foreach (molavi_domain_freshness($sheetRecords, (float)$nowInt, false) as $offer)
        if ($offer['product_id'] === $id) return $offer;
    return [];
}
function sheet_offer_for(string $id): array {
    foreach (molavi_domain_records($GLOBALS['sheets']['Offers']) as $r)
        if ((string)$r['Product ID'] === $id) return $r;
    return [];
}

// Reference catalog snapshot snap-1 (fresh, checked 1h ago) for the catalog leg.
function ref_row(string $name, int $consumer, string $refId): array {
    $row = array_fill(0, 21, ''); $row[0] = $name; $row[5] = (string)$consumer; $row[16] = $refId; return $row;
}
$payload = json_encode(['schema_version' => 1, 'status' => 'staged', 'extracted_at' => $freshIso,
    'rows' => [ref_row('Item v6', 100000, 'ref-v6'), ref_row('Item t3', 100000, 'ref-t3')]],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
molavi_domain_tx('reference', static function (SQLite3 $db) use ($payload, $freshIso): void {
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots (snapshot_id,payload) VALUES (?,?)', ['snap-1', $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active (singleton,snapshot_id) VALUES (1,?)', ['snap-1']);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check (singleton,snapshot_id,checked_at) VALUES (1,?,?)', ['snap-1', $freshIso]);
});

// Link-check rows.
function seed_link(string $id, string $outcome, string $reason, ?int $checkedAt, ?int $confirmedAt, string $sale, string $revision): void {
    global $nowInt;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $outcome, $reason, $checkedAt, $confirmedAt, $sale, $revision, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, 'owner-confirm:'.hash('sha256', $id), 'Item '.$id, 'https://t.me/supplier/'.substr($id, 1), $revision, $sale,
                'Item '.$id.' '.$sale, $outcome, $reason, $outcome === 'uncheckable' ? null : $sale, null, 'railway', $checkedAt, $nowInt + 86400, null,
                $confirmedAt, $confirmedAt === null ? null : '2026-11-26T11:00:00+00:00', $nowInt - 7200]);
    });
}
seed_link('e1', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['e1']);
seed_link('e2', 'unchanged', 'price_unchanged', $checkFresh, null, '80000', $revision['e2']);
seed_link('e3', 'changed', 'price_changed', $checkFresh, null, '80000', $revision['e3']);
seed_link('e4', 'uncheckable', 'price_not_found', $checkFresh, null, '80000', $revision['e4']);
seed_link('e5', 'unchanged', 'identical', $checkFresh, null, '85000', $revision['e5']);
seed_link('e6', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['e6']);
seed_link('e7', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['e7']);
seed_link('e8', 'unchanged', 'identical', $checkStale, null, '80000', $revision['e8']);
// e9, e10, e11, e12 deliberately have NO link row.
seed_link('v1', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['v1']);
seed_link('v2', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['v2']);
seed_link('v3', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['v3']);
seed_link('v4', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['v4']);
seed_link('v5', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['v5']);
seed_link('v6', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['v6']);
seed_link('v7', 'changed', 'price_changed', $checkFresh, null, '80000', $revision['v7']);
seed_link('v8', 'uncheckable', 'price_not_found', $checkFresh, null, '80000', $revision['v8']);
seed_link('v9', 'unchanged', 'price_unchanged', $checkFresh, null, '80000', $revision['v9']);
seed_link('v10', 'unchanged', 'identical', $checkStale, null, '80000', $revision['v10']);

// Evidence rows: v4 stale (20d ago), v5 fresh (1h ago), same revision.
function seed_evidence(string $id, string $verifiedAt, string $method, string $revision): void {
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $verifiedAt, $method, $revision): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)',
            [$id, $revision, $verifiedAt, $method, 'wp:1']);
        molavi_domain_sql($db, 'INSERT INTO supplier_price_evidence_history (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,?,?,?)',
            [$id, $revision, $verifiedAt, $method, 'wp:1', 'seed']);
    });
}
seed_evidence('v4', gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 20 * 86400), 'source', $revision['v4']);
seed_evidence('v5', gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600), 'source', $revision['v5']);

function db_row(string $store, string $sql, array $params = []): ?array {
    return molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_row($db, $sql, $params));
}
function db_count(string $store, string $sql, array $params = []): int {
    return (int)(db_row($store, $sql, $params)['n'] ?? 0);
}
function evidence_for(string $id, array $sheetRecords, int $nowInt): array {
    return molavi_domain_regular_price_evidence(record_for($id, $sheetRecords), sheet_offer_for($id), null, $nowInt);
}
$msgOldDeadline = (int)molavi_domain_timestamp($msgOldIso) + 14 * 86400;
$renewedDeadline = $checkFresh + 14 * 86400;

if (!$isFixed) {
    // --- NEGATIVE CONTROL: the new behaviour must be absent on the base. ---
    $ev = evidence_for('e1', $sheetRecords, $nowInt);
    cmp_check($ev['state'], 'unverified', 'base: identical+fresh link does NOT renew (e1 unverified)');
    cmp_check($ev['expires_at'], 0, 'base: e1 has no deadline at all');
    $ev9 = evidence_for('e9', $sheetRecords, $nowInt);
    cmp_check($ev9['state'], 'unverified', 'base: empty-basis stamp still yields unverified (e9)');
    cmp_check($ev9['expires_at'], 0, 'base: e9 has no deadline at all');
    $ev11 = evidence_for('e11', $sheetRecords, $nowInt);
    cmp_check($ev11['state'], 'current', 'base: trusted-basis fresh message still current (e11)');
    $chk1 = molavi_domain_auto_verify_check(record_for('v1', $sheetRecords), offer_for('v1', $sheetRecords, $nowInt), $nowInt);
    cmp_false($chk1['eligible'], 'base: no-reference identical-link product NOT eligible (v1)');
    cmp_check($chk1['reason'], 'no_reference_match', 'base: v1 reason no_reference_match (no link leg)');
    $chk4 = molavi_domain_auto_verify_check(record_for('v4', $sheetRecords), offer_for('v4', $sheetRecords, $nowInt), $nowInt);
    cmp_false($chk4['eligible'], 'base: stale evidence still already_verified (v4)');
    cmp_check($chk4['reason'], 'already_verified', 'base: v4 reason already_verified');
    $chk5 = molavi_domain_auto_verify_check(record_for('v5', $sheetRecords), offer_for('v5', $sheetRecords, $nowInt), $nowInt);
    cmp_false($chk5['eligible'], 'base: fresh evidence already_verified (v5)');
    cmp_check($chk5['reason'], 'already_verified', 'base: v5 reason already_verified');
    $chk6 = molavi_domain_auto_verify_check(record_for('v6', $sheetRecords), offer_for('v6', $sheetRecords, $nowInt), $nowInt);
    cmp_true($chk6['eligible'], 'base: catalog leg still eligible (v6)');
    cmp_check($chk6['evidence']['catalog_price'] ?? null, 100000, 'base: v6 catalog price 100000');
    $chk9 = molavi_domain_auto_verify_check(record_for('v9', $sheetRecords), offer_for('v9', $sheetRecords, $nowInt), $nowInt);
    cmp_false($chk9['eligible'], 'base: price_unchanged not eligible (v9)');
    cmp_check($chk9['reason'], 'no_reference_match', 'base: v9 reason no_reference_match');
    echo "PASS $count FAIL $fail\n";
    exit($fail === 0 ? 0 : 1);
}

// --- FIXED mode: evidence function ---
// (a) identical+fresh renews to checked_at + 14d.
$ev = evidence_for('e1', $sheetRecords, $nowInt);
cmp_check($ev['state'], 'current', '(a) identical+fresh renews to current');
cmp_check($ev['amount'], 100000, '(a) renewed amount 100000');
cmp_check($ev['expires_at'], $renewedDeadline, '(a) renewed expiry is checked_at + 14d');
// (b)–(e),(h) no renewal: expired with the (fallback) message deadline.
foreach ([['e2', '(b) price_unchanged does NOT renew'], ['e3', '(c) changed does NOT renew'],
    ['e4', '(d) uncheckable does NOT renew'], ['e5', '(e) stored sale != sheet sale does NOT renew'],
    ['e8', '(h) stale identical check does NOT renew']] as [$pid, $label]) {
    $ev = evidence_for($pid, $sheetRecords, $nowInt);
    cmp_check($ev['state'], 'expired', $label.' -> expired');
    cmp_check($ev['expires_at'], $msgOldDeadline, $label.' expiry is the message deadline');
}
// (f) offer consumer != sheet consumer: the offer does not state the sheet
// price at all, so there is no direct deadline — unverified, no renewal.
$ev6 = evidence_for('e6', $sheetRecords, $nowInt);
cmp_check($ev6['state'], 'unverified', '(f) offer consumer != sheet consumer does NOT renew -> unverified');
cmp_check($ev6['expires_at'], 0, '(f) offer consumer != sheet consumer has no deadline');
// (g) non-latest offer: no direct deadline and no renewal.
$otherOffer = offer_record('e7', '100000', $msgOldIso, '');
$otherOffer['Offer ID'] = 'owner-confirm:'.hash('sha256', 'some-other-offer');
$evG = molavi_domain_regular_price_evidence(record_for('e7', $sheetRecords), $otherOffer, null, $nowInt);
cmp_check($evG['state'], 'unverified', '(g) non-latest offer does NOT renew -> unverified');
cmp_check($evG['expires_at'], 0, '(g) non-latest offer has no deadline');
// Direct helper tests: each renewal condition in isolation.
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e1', $sheetRecords), sheet_offer_for('e1'), $nowInt), $renewedDeadline, 'helper: identical+fresh renews to checked_at+14d');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e2', $sheetRecords), sheet_offer_for('e2'), $nowInt), 0, 'helper: price_unchanged -> 0');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e3', $sheetRecords), sheet_offer_for('e3'), $nowInt), 0, 'helper: changed -> 0');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e4', $sheetRecords), sheet_offer_for('e4'), $nowInt), 0, 'helper: uncheckable -> 0');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e5', $sheetRecords), sheet_offer_for('e5'), $nowInt), 0, 'helper: stored sale != sheet sale -> 0');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e6', $sheetRecords), sheet_offer_for('e6'), $nowInt), 0, 'helper: offer consumer != sheet consumer -> 0');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e7', $sheetRecords), $otherOffer, $nowInt), 0, 'helper: non-latest offer -> 0');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e8', $sheetRecords), sheet_offer_for('e8'), $nowInt), 0, 'helper: stale identical check -> 0');
cmp_check(molavi_domain_link_check_regular_deadline(record_for('e9', $sheetRecords), sheet_offer_for('e9'), $nowInt), 0, 'helper: no link row -> 0');
// (i) empty-basis fallback: old message, no link row -> expired with a real deadline.
$ev9 = evidence_for('e9', $sheetRecords, $nowInt);
cmp_check($ev9['state'], 'expired', '(i) empty-basis old message -> expired, not unverified');
cmp_check($ev9['expires_at'], $msgOldDeadline, '(i) fallback deadline is message timestamp + 14d');
// (j) empty-basis fallback never makes a price current.
$ev10 = evidence_for('e10', $sheetRecords, $nowInt);
cmp_check($ev10['state'], 'unverified', '(j) empty-basis fresh message stays unverified');
cmp_check($ev10['expires_at'], 0, '(j) empty-basis fresh message has no deadline');
// (k) trusted basis still works.
$ev11 = evidence_for('e11', $sheetRecords, $nowInt);
cmp_check($ev11['state'], 'current', '(k) trusted-basis fresh message still current');
cmp_check($ev11['expires_at'], (int)molavi_domain_timestamp($msgFreshIso) + 14 * 86400, '(k) trusted-basis expiry is message + 14d');
// (l) trusted basis, old message -> expired with the message deadline.
$ev12 = evidence_for('e12', $sheetRecords, $nowInt);
cmp_check($ev12['state'], 'expired', '(l) trusted-basis old message -> expired');
cmp_check($ev12['expires_at'], $msgOldDeadline, '(l) trusted-basis expiry is the message deadline');

// --- FIXED mode: auto-verify check ---
// (m) link-message leg: eligible, leg link_message.
$chk1 = molavi_domain_auto_verify_check(record_for('v1', $sheetRecords), offer_for('v1', $sheetRecords, $nowInt), $nowInt);
cmp_true($chk1['eligible'], '(m) no-ref identical-link product eligible via link leg');
cmp_check($chk1['evidence']['leg'] ?? null, 'link_message', '(m) v1 evidence leg link_message');
cmp_check($chk1['evidence']['consumer_price'] ?? null, 100000, '(m) v1 evidence consumer price 100000');
cmp_check($chk1['evidence']['link_deadline'] ?? null, $renewedDeadline, '(m) v1 link deadline checked_at + 14d');
// (n)(o) link leg fails -> catalog reason is reported.
foreach ([['v2', '(n) offer consumer != sheet -> not eligible'],
    ['v3', '(o) no Offers row -> not eligible']] as [$pid, $label]) {
    $chk = molavi_domain_auto_verify_check(record_for($pid, $sheetRecords), offer_for($pid, $sheetRecords, $nowInt), $nowInt);
    cmp_false($chk['eligible'], $label);
    cmp_check($chk['reason'], 'no_reference_match', $label.' reason no_reference_match');
}
// (p) stale evidence is re-verifiable.
$chk4 = molavi_domain_auto_verify_check(record_for('v4', $sheetRecords), offer_for('v4', $sheetRecords, $nowInt), $nowInt);
cmp_true($chk4['eligible'], '(p) stale evidence re-verifiable (v4)');
cmp_check($chk4['evidence']['leg'] ?? null, 'link_message', '(p) v4 evidence leg link_message');
$histBefore = db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history WHERE offer_id=?', ['v4']);
$one4 = molavi_domain_auto_verify_one(record_for('v4', $sheetRecords), offer_for('v4', $sheetRecords, $nowInt), $nowInt, $isoNow);
cmp_true($one4['ok'], '(p) one() on stale evidence writes');
cmp_false($one4['idempotent'] ?? false, '(p) one() on stale evidence is NOT idempotent');
$row4 = db_row('pipeline', 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', ['v4']);
cmp_check($row4['method'] ?? null, 'auto', '(p) v4 evidence method auto after re-verify');
cmp_check($row4['verified_at'] ?? null, $isoNow, '(p) v4 verified_at refreshed to now');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history WHERE offer_id=?', ['v4']), $histBefore + 1, '(p) v4 history gained one row');
$hist4 = db_row('pipeline', 'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY id DESC LIMIT 1', ['v4']);
cmp_true(str_contains((string)($hist4['note'] ?? ''), 'تأیید خودکار'), '(p) v4 history note says auto-verify');
cmp_true(str_contains((string)($hist4['note'] ?? ''), 'پیام منبع'), '(p) v4 history note names the source message');
cmp_true(str_contains((string)($hist4['note'] ?? ''), 'link-check:v4@'), '(p) v4 history note names check ref');
$link4 = db_row('pipeline', 'SELECT confirmed_at FROM supplier_link_checks WHERE product_id=?', ['v4']);
cmp_true($link4['confirmed_at'] !== null, '(p) v4 link check confirmed');
// (q) fresh evidence still hits already_verified; one() writes nothing.
$chk5 = molavi_domain_auto_verify_check(record_for('v5', $sheetRecords), offer_for('v5', $sheetRecords, $nowInt), $nowInt);
cmp_false($chk5['eligible'], '(q) fresh evidence still already_verified (v5)');
cmp_check($chk5['reason'], 'already_verified', '(q) v5 reason already_verified');
$hist5Before = db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history WHERE offer_id=?', ['v5']);
$one5 = molavi_domain_auto_verify_one(record_for('v5', $sheetRecords), offer_for('v5', $sheetRecords, $nowInt), $nowInt, $isoNow);
cmp_false($one5['ok'], '(q) one() on fresh evidence is not a write');
cmp_check($one5['reason'] ?? null, 'already_verified', '(q) one() reason already_verified');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history WHERE offer_id=?', ['v5']), $hist5Before, '(q) no duplicate history row for v5');
// (r) catalog leg still passes when present.
$chk6 = molavi_domain_auto_verify_check(record_for('v6', $sheetRecords), offer_for('v6', $sheetRecords, $nowInt), $nowInt);
cmp_true($chk6['eligible'], '(r) catalog leg still eligible (v6)');
cmp_check($chk6['evidence']['leg'] ?? null, 'catalog', '(r) v6 evidence leg catalog');
cmp_check($chk6['evidence']['catalog_price'] ?? null, 100000, '(r) v6 catalog price 100000');
cmp_check($chk6['evidence']['snapshot_id'] ?? null, 'snap-1', '(r) v6 snapshot snap-1');
// (s)(t)(u) never verify.
foreach ([['v7', 'link_check_changed', '(s) changed never verifies'],
    ['v8', 'link_check_uncheckable', '(s) uncheckable never verifies'],
    ['v9', 'no_reference_match', '(t) price_unchanged does NOT feed the link leg'],
    ['v10', 'no_reference_match', '(u) stale identical check does NOT feed the link leg']] as [$pid, $reason, $label]) {
    $chk = molavi_domain_auto_verify_check(record_for($pid, $sheetRecords), offer_for($pid, $sheetRecords, $nowInt), $nowInt);
    cmp_false($chk['eligible'], $label);
    cmp_check($chk['reason'], $reason, $label.' reason');
}

// --- FIXED mode: tick (fresh link/evidence tables, t-series only) ---
molavi_domain_tx('pipeline', static function (SQLite3 $db): void {
    molavi_domain_sql($db, 'DELETE FROM supplier_link_checks');
    molavi_domain_sql($db, 'DELETE FROM supplier_price_evidence');
    molavi_domain_sql($db, 'DELETE FROM supplier_price_evidence_history');
    molavi_domain_sql($db, "DELETE FROM jobs WHERE id LIKE 'publish:supplier-verification:%'");
});
seed_link('t1', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['t1']);
seed_link('t2', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['t2']);
seed_link('t3', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['t3']);
seed_link('t5', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['t5']);
seed_link('t6', 'unchanged', 'identical', $checkFresh, null, '80000', $revision['t6']);
seed_evidence('t5', gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600), 'source', $revision['t5']);
// (v) dry-run reports the link-leg items eligible and writes nothing.
$dry = molavi_domain_auto_verify_tick($nowInt, $isoNow, true);
cmp_true($dry['dry_run'], '(v) dry-run flag');
cmp_check($dry['scanned'], 5, '(v) dry-run scanned 5 queue rows');
cmp_check($dry['eligible_count'], 3, '(v) dry-run eligible 3 (t1,t2 link leg + t3 catalog leg)');
cmp_check(array_column($dry['eligible'], 'product_id'), ['t1', 't2', 't3'], '(v) dry-run eligible list t1,t2,t3');
cmp_check($dry['skipped'], ['already_verified' => 1, 'no_reference_match' => 1], '(v) dry-run skipped reasons');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence'), 1, '(v) dry-run: evidence still only the seeded t5 row');
cmp_check(db_count('pipeline', "SELECT COUNT(*) n FROM jobs WHERE id LIKE 'publish:supplier-verification:%'"), 0, '(v) dry-run: no publish jobs');
// (w) live tick verifies all 3; second tick verifies 0.
$tick1 = molavi_domain_auto_verify_tick($nowInt, $isoNow, false);
cmp_check($tick1['verified_count'], 3, '(w) live tick verifies 3');
cmp_check(array_column($tick1['verified'], 'product_id'), ['t1', 't2', 't3'], '(w) live tick verifies t1,t2,t3');
foreach (['t1', 't2'] as $pid) {
    $row = db_row('pipeline', 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', [$pid]);
    cmp_check($row['method'] ?? null, 'auto', "(w) $pid evidence method auto");
    $hist = db_row('pipeline', 'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY id DESC LIMIT 1', [$pid]);
    cmp_true(str_contains((string)($hist['note'] ?? ''), 'پیام منبع'), "(w) $pid history note names the source message");
    $link = db_row('pipeline', 'SELECT confirmed_at FROM supplier_link_checks WHERE product_id=?', [$pid]);
    cmp_true($link['confirmed_at'] !== null, "(w) $pid link check confirmed");
}
$tick2 = molavi_domain_auto_verify_tick($nowInt, $isoNow, false);
cmp_check($tick2['verified_count'], 0, '(w) second tick verifies 0');
cmp_check($tick2['eligible_count'], 0, '(w) second tick eligible 0');
cmp_check($tick2['skipped'], ['already_verified' => 1, 'no_reference_match' => 1], '(w) second tick skipped reasons');
echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
