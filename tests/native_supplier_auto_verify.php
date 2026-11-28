<?php
// Supplier auto-verify (owner-approved 2026-11-26): when the link check
// proves the supplier price is unchanged AND the reference catalog holds
// the same regular price, the system writes the verification itself
// (method 'auto') instead of waiting on the owner.
//
// Cases (FIXED mode):
//   (a) link 'unchanged' + unconfirmed + stored price == sheet price +
//       reference match, catalog price exactly == sheet Consumer Price,
//       fresh snapshot -> AUTO-VERIFIED (evidence written, link check
//       confirmed, publish job queued, note names the evidence).
//   (b) link 'changed' -> NOT verified. (c) link 'uncheckable' -> NOT
//       verified. (d) link 'unchanged' but catalog price != sheet price
//       -> NOT verified. (e) link 'unchanged' but NO reference match ->
//       NOT verified. (f) link 'unchanged' but stale catalog snapshot
//       (>24h) -> NOT verified (phase 2, snapshot re-aged).
//   (g) already confirmed / already verified for this revision ->
//       idempotent, no duplicate write. (h) dry-run writes NOTHING but
//       reports the eligible list. Plus: per-tick cap 5 (7 eligible,
//       first tick verifies 5, second tick the remaining 2), and the
//       'auto_verify' API action (dry_run true/false, bad body rejected).
//   Q8 (2026-11-27): the catalog fixtures are 26-column rows whose
//       regular-pack column [23] is the explicitly-marked regular price
//       the catalog leg trusts, and whose supply-pack column [21] equals
//       the sheet Sale Price, so the evidence also records the
//       kept-match supply agreement (supply_agrees). Lone-supply cases
//       live in tests/native_q8_lone_supply_price.php.
//
// Negative control: run this file in a worktree at the base commit
// 4a401b8 WITHOUT the fix (no molavi-domain-supplier-auto-verify.php):
// it PASSES by proving auto-verify does NOT exist there — the tick
// function is absent, verify_price rejects method 'auto', and the
// 'auto_verify' API action is rejected as invalid_body. A harness that
// passes on the base is worthless; this one asserts the opposite there.
// Run: php tests/native_supplier_auto_verify.php
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-auto-verify-'.bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-reference-store',
    'domain-reference-matching', 'domain-reference-scheduler', 'domain-sheet-conflicts', 'domain-products', 'domain-publisher',
    'domain-supplier-link-checks', 'domain-supplier-prices', 'domain-sale-offers', 'domain-reference-matches'] as $file)
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$autoVerifyFile = __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-supplier-auto-verify.php';
$isFixed = file_exists($autoVerifyFile);
if ($isFixed) require $autoVerifyFile;
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
function fails_with($fn, $code, $label) { global $count, $fail;
    try { $fn(); } catch (MolaviWorkflowError $e) { cmp_check($e->getMessage(), $code, $label); return; }
    echo 'FAIL '.$label.' — no error thrown'."\n"; $fail++; }

echo 'MODE: '.($isFixed ? 'FIXED (auto-verify present)' : 'BASE 4a401b8 (negative control: no auto-verify)')."\n";

$now = molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$nowInt = (int)$now;
$isoNow = '2026-11-26T12:00:00+00:00';
$freshIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600);

if (!$isFixed) {
    // --- NEGATIVE CONTROL: auto-verify must not exist on the base. ---
    cmp_false(function_exists('molavi_domain_auto_verify_tick'), 'base: no auto_verify_tick function');
    cmp_false(function_exists('molavi_domain_auto_verify_eligible'), 'base: no auto_verify_eligible function');
    cmp_false(function_exists('molavi_domain_auto_verify_one'), 'base: no auto_verify_one function');
    fails_with(static fn() => molavi_domain_verify_price('p-dummy', 'owner-confirm:'.hash('sha256', 'x'), 'wp:1',
        'note', '2026-11-26T12:00:00+00:00', null, 'auto'), 'verification_rejected', 'base: verify_price rejects method auto');
    fails_with(static fn() => molavi_domain_supplier_link_check_api('POST', [], ['action' => 'auto_verify', 'dry_run' => true], 'wp:1'),
        'invalid_body', 'base: link-check API rejects action auto_verify');
    echo "PASS $count FAIL $fail\n";
    exit($fail === 0 ? 0 : 1);
}

// --- FIXED mode fixtures ---
function product_record(string $id, string $sale, string $consumer, string $refId, string $approval = 'owner_approved'): array {
    return ['Product ID' => $id, 'Product Name' => 'Item '.$id, 'Channel Username' => 'supplier', 'Channel' => 'supplier',
        'Latest Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Sale Price' => $sale, 'Consumer Price' => $consumer,
        'Status' => 'imported', 'Message Timestamp' => '2026-11-20T08:00:00+00:00', 'Source Timestamp Basis' => '',
        'Source Post URL' => 'https://t.me/supplier/'.substr($id, 1), 'Original Message' => 'Item '.$id.' '.$sale,
        'Stock Status' => 'Unknown', 'Reference Snapshot ID' => 'snap-1', 'Reference Match ID' => $refId,
        'Reference Approval State' => $approval];
}
$records = [];
for ($i = 1; $i <= 7; $i++) $records['p'.$i] = product_record('p'.$i, '80000', '100000', 'ref-p'.$i);
$records['p-b'] = product_record('p-b', '80000', '100000', 'ref-b');       // (b) changed
$records['p-c'] = product_record('p-c', '80000', '100000', 'ref-c');       // (c) uncheckable
$records['p-d'] = product_record('p-d', '80000', '100000', 'ref-d');       // (d) catalog price mismatch
$records['p-e'] = product_record('p-e', '80000', '100000', '');            // (e) no reference match
$records['p-g1'] = product_record('p-g1', '80000', '100000', 'ref-g1');    // (g) already confirmed
$records['p-g2'] = product_record('p-g2', '80000', '100000', 'ref-g2');    // (g) already verified, link unconfirmed
$records['p-f'] = product_record('p-f', '80000', '100000', 'ref-f');       // (f) stale snapshot, phase 2
$headers = molavi_domain_product_headers();
$GLOBALS['sheets'] = ['Products' => array_merge([$headers], array_map(
    static fn($r) => array_map(static fn($h) => $r[$h] ?? '', $headers), array_values($records)))];
$sheetRecords = molavi_domain_records($GLOBALS['sheets']['Products']);
$revision = [];
foreach ($sheetRecords as $r) $revision[(string)$r['Product ID']] = molavi_domain_source_revision($r);

// Reference catalog snapshot snap-1: 26-column rows (schema v2, Q8).
// [0] name, [5] consumer (title figure), [16] reference id,
// [21] supply pack price (= sheet Sale Price, so the kept-match supply
// price agrees), [23] regular pack price (= sheet Consumer Price, the
// explicitly-marked regular price the catalog leg trusts). p-d's
// regular (120000) mismatches the sheet price, as before.
function ref_row(string $name, int $consumer, string $refId): array {
    $row = array_fill(0, 26, ''); $row[0] = $name; $row[5] = (string)$consumer; $row[16] = $refId;
    $row[21] = '80000'; $row[23] = (string)$consumer; return $row;
}
$refRows = [];
for ($i = 1; $i <= 7; $i++) $refRows[] = ref_row('Item p'.$i, 100000, 'ref-p'.$i);
$refRows[] = ref_row('Item p-b', 100000, 'ref-b');
$refRows[] = ref_row('Item p-c', 100000, 'ref-c');
$refRows[] = ref_row('Item p-d', 120000, 'ref-d');   // catalog 120000 vs sheet 100000 -> mismatch
$refRows[] = ref_row('Item p-g1', 100000, 'ref-g1');
$refRows[] = ref_row('Item p-g2', 100000, 'ref-g2');
$refRows[] = ref_row('Item p-f', 100000, 'ref-f');
$payload = json_encode(['schema_version' => 2, 'status' => 'staged', 'extracted_at' => $freshIso, 'rows' => $refRows],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
molavi_domain_tx('reference', static function (SQLite3 $db) use ($payload, $freshIso): void {
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots (snapshot_id,payload) VALUES (?,?)', ['snap-1', $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active (singleton,snapshot_id) VALUES (1,?)', ['snap-1']);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check (singleton,snapshot_id,checked_at) VALUES (1,?,?)', ['snap-1', $freshIso]);
});

// Kept-match leg (persistent matching, slice 4): the catalog leg reads the
// kept match row, so every product the old sheet-field leg used to verify
// now needs a decision row + an active trusted match row. The signature is
// computed from the Products record attributes — no Offers sheet is seeded
// here, so the leg takes its record-derived fallback path (the
// latest-offer path is covered by the kept-match wiring harness).
function seed_kept_match(string $id, string $refId, int $consumer, string $role = 'unit'): string {
    global $sheetRecords, $nowInt;
    $record = null;
    foreach ($sheetRecords as $r) if ((string)$r['Product ID'] === $id) { $record = $r; break; }
    $offerId = 'owner-confirm:' . hash('sha256', $id);
    $decisionId = 'auto-verify-decision-' . $id;
    $signature = molavi_domain_reference_signature(molavi_domain_offer_attributes($record));
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($offerId, $decisionId, $signature, $refId, $role): void {
        molavi_domain_sql($db, 'INSERT INTO reference_item_decisions (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,price_role,actor_id,note,created_at,supersedes_decision_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)',
            [$decisionId, 'item:auto-verify-' . $offerId, $offerId, 'snap-1', $signature, 'match', $refId, $role, 'wp:1', 'Owner pick (harness).', '2026-11-26T00:00:00+00:00']);
    });
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($id, $offerId, $decisionId, $signature, $refId, $role, $consumer, $nowInt): void {
        $cols = ['product_id', 'source', 'offer_id', 'signature', 'reference_id', 'source_product_id', 'snapshot_id', 'price_role',
            'catalog_package_price', 'catalog_unit_price', 'catalog_consumer_price', 'catalog_supplier_price', 'sheet_consumer_price',
            'score', 'name_similarity', 'match_basis', 'decision_id', 'listing_id', 'url', 'provider', 'merchant', 'quote_json',
            'regular_price', 'pack_count', 'identity_json', 'actor_id', 'status', 'created_at', 'checked_at', 'verified_at', 'next_at'];
        $vals = [$id, 'catalog', $offerId, $signature, $refId, '', 'snap-1', $role, null, null, $consumer, null, $consumer,
            1.0, 1.0, 'owner_pick', $decisionId, null, null, null, null, null, null, null, null, 'wp:1', 'active',
            $nowInt - 3600, $nowInt - 3600, null, $nowInt - 3600 + 14 * 86400];
        molavi_domain_sql($db, 'INSERT INTO reference_product_matches (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
    });
    return $decisionId;
}
$keptDecisions = [];
foreach (['p1' => 'ref-p1', 'p2' => 'ref-p2', 'p3' => 'ref-p3', 'p4' => 'ref-p4', 'p5' => 'ref-p5',
    'p6' => 'ref-p6', 'p7' => 'ref-p7', 'p-b' => 'ref-b', 'p-c' => 'ref-c', 'p-d' => 'ref-d',
    'p-f' => 'ref-f', 'p-g1' => 'ref-g1', 'p-g2' => 'ref-g2'] as $pid => $ref)
    $keptDecisions[$pid] = seed_kept_match($pid, $ref, $pid === 'p-d' ? 120000 : 100000);

// Link-check rows. checked_at staggered oldest-first: p1 oldest.
function seed_link(string $id, string $outcome, string $reason, ?int $checkedAt, ?int $confirmedAt, string $sale, string $revision, ?string $live = null): void {
    global $nowInt;
    $livePrice = $outcome === 'uncheckable' ? null : ($live ?? $sale);
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $outcome, $reason, $checkedAt, $confirmedAt, $sale, $revision, $livePrice, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, 'owner-confirm:'.hash('sha256', $id), 'Item '.$id, 'https://t.me/supplier/'.substr($id, 1), $revision, $sale,
                'Item '.$id.' '.$sale, $outcome, $reason, $livePrice, null, 'railway', $checkedAt, $nowInt + 86400, null,
                $confirmedAt, $confirmedAt === null ? null : '2026-11-26T11:00:00+00:00', $nowInt - 7200]);
    });
}
for ($i = 1; $i <= 7; $i++) seed_link('p'.$i, 'unchanged', 'identical', $nowInt - 8000 + $i * 1000, null, '80000', $revision['p'.$i]);
seed_link('p-b', 'changed', 'price_changed', $nowInt - 600, null, '80000', $revision['p-b'], '85000');
seed_link('p-c', 'uncheckable', 'price_not_found', $nowInt - 600, null, '80000', $revision['p-c']);
seed_link('p-d', 'unchanged', 'identical', $nowInt - 500, null, '80000', $revision['p-d']);
seed_link('p-e', 'unchanged', 'identical', $nowInt - 400, null, '80000', $revision['p-e']);
seed_link('p-g1', 'unchanged', 'identical', $nowInt - 9000, $nowInt - 3600, '80000', $revision['p-g1']);
seed_link('p-g2', 'unchanged', 'identical', $nowInt - 300, null, '80000', $revision['p-g2']);
// p-f's link row is seeded in phase 2 only: under the fresh snapshot it
// would be eligible and get verified before the stale-snapshot test.
// (g) pre-existing manual evidence for the same revision.
foreach (['p-g1', 'p-g2'] as $gid) {
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($gid, $revision): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)',
            [$gid, $revision[$gid], '2026-11-26T11:00:00+00:00', 'manual', 'wp:1']);
        molavi_domain_sql($db, 'INSERT INTO supplier_price_evidence_history (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,?,?,?)',
            [$gid, $revision[$gid], '2026-11-26T11:00:00+00:00', 'manual', 'wp:1', 'owner confirmed']);
    });
}
function db_row(string $store, string $sql, array $params = []): ?array {
    return molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_row($db, $sql, $params));
}
function db_count(string $store, string $sql, array $params = []): int {
    return (int)(db_row($store, $sql, $params)['n'] ?? 0);
}
function offer_for(string $id, array $sheetRecords, int $nowInt): array {
    foreach (molavi_domain_freshness($sheetRecords, (float)$nowInt, false) as $offer)
        if ($offer['product_id'] === $id) return $offer;
    return [];
}
function record_for(string $id, array $sheetRecords): array {
    foreach ($sheetRecords as $r) if ((string)$r['Product ID'] === $id) return $r;
    return [];
}
$byId = [];
foreach ($sheetRecords as $r) $byId[(string)$r['Product ID']] = $r;

// --- (a) eligible: evidence shape ---
$ev = molavi_domain_auto_verify_eligible($byId['p1'], offer_for('p1', $sheetRecords, $nowInt), $nowInt);
cmp_true($ev !== null, '(a) p1 eligible');
cmp_check($ev['catalog_price'] ?? null, 100000, '(a) p1 catalog price 100000');
cmp_check($ev['snapshot_id'] ?? null, 'snap-1', '(a) p1 snapshot snap-1');
cmp_check($ev['check_ref'] ?? null, 'link-check:p1@'.($nowInt - 7000), '(a) p1 check ref');
cmp_check($ev['leg'] ?? null, 'catalog', '(a) p1 leg is catalog');
cmp_check($ev['decision_id'] ?? null, $keptDecisions['p1'], '(a) p1 decision id');
cmp_check($ev['match_id'] ?? null, 'p1:catalog', '(a) p1 match id');
if (function_exists('molavi_domain_auto_verify_catalog_prices')) {
    cmp_check($ev['supply_price'] ?? null, 80000, '(a) p1 supply price 80000 (Q8: kept-match supply)');
    cmp_true($ev['supply_agrees'] ?? false, '(a) p1 supply agrees with sheet sale price');
}
// --- (b)(c)(d)(e)(g) not eligible, with the right gate ---
foreach ([['p-b', 'link_check_changed', '(b) changed not eligible'],
    ['p-c', 'link_check_uncheckable', '(c) uncheckable not eligible'],
    ['p-d', 'catalog_price_mismatch', '(d) catalog mismatch not eligible'],
    ['p-e', 'no_reference_match', '(e) no reference match not eligible'],
    ['p-g1', 'link_check_confirmed', '(g) already confirmed not eligible'],
    ['p-g2', 'already_verified', '(g) already verified not eligible']] as [$pid, $reason, $label]) {
    $chk = molavi_domain_auto_verify_check($byId[$pid], offer_for($pid, $sheetRecords, $nowInt), $nowInt);
    cmp_false($chk['eligible'], $label);
    cmp_check($chk['reason'], $reason, $label.' reason');
    cmp_null(molavi_domain_auto_verify_eligible($byId[$pid], offer_for($pid, $sheetRecords, $nowInt), $nowInt), $label.' eligible null');
}
// --- (g) one() on the already-verified product writes nothing ---
$histBefore = db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history WHERE offer_id=?', ['p-g2']);
$oneG2 = molavi_domain_auto_verify_one($byId['p-g2'], offer_for('p-g2', $sheetRecords, $nowInt), $nowInt, $isoNow);
cmp_false($oneG2['ok'], '(g) one() on already-verified is not a write');
cmp_check($oneG2['reason'] ?? null, 'already_verified', '(g) one() reason already_verified');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history WHERE offer_id=?', ['p-g2']), $histBefore, '(g) no duplicate history row');
cmp_check(db_count('pipeline', "SELECT COUNT(*) n FROM jobs WHERE id LIKE 'publish:supplier-verification:p-g2:%'"), 0, '(g) no publish job for p-g2');
cmp_null(db_row('pipeline', 'SELECT confirmed_at FROM supplier_link_checks WHERE product_id=?', ['p-g2'])['confirmed_at'], '(g) p-g2 link still unconfirmed');
// --- (h) dry-run writes NOTHING but reports the eligible list ---
$dry = molavi_domain_auto_verify_tick($nowInt, $isoNow, true);
cmp_true($dry['dry_run'], '(h) dry-run flag');
cmp_check($dry['eligible_count'], 7, '(h) dry-run eligible count 7 (p1-p7)');
cmp_check(array_column($dry['eligible'], 'product_id'), ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'], '(h) dry-run eligible list p1-p7');
cmp_check($dry['verified_count'], 0, '(h) dry-run verifies 0');
cmp_check($dry['scanned'], 10, '(h) dry-run scanned 10 queue rows');
cmp_check($dry['skipped'], ['catalog_price_mismatch' => 1, 'no_reference_match' => 1, 'already_verified' => 1], '(h) dry-run skipped reasons');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence'), 2, '(h) dry-run: evidence still only the 2 seeded rows');
cmp_check(db_count('pipeline', "SELECT COUNT(*) n FROM jobs WHERE id LIKE 'publish:supplier-verification:%'"), 0, '(h) dry-run: no publish jobs');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_link_checks WHERE outcome=? AND confirmed_at IS NOT NULL', ['unchanged']), 1, '(h) dry-run: only p-g1 confirmed');
// --- (a) live tick 1: cap 5, oldest first ---
$tick1 = molavi_domain_auto_verify_tick($nowInt, $isoNow, false);
cmp_check($tick1['verified_count'], 5, 'tick1 verifies 5 (cap)');
cmp_check(array_column($tick1['verified'], 'product_id'), ['p1', 'p2', 'p3', 'p4', 'p5'], 'tick1 verifies p1-p5 oldest first');
cmp_check($tick1['eligible_count'], 7, 'tick1 eligible 7 (5 verified + p6,p7 over cap)');
foreach (['p1', 'p2', 'p3', 'p4', 'p5'] as $pid) {
    $row = db_row('pipeline', 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', [$pid]);
    cmp_check($row['method'] ?? null, 'auto', "(a) $pid evidence method auto");
    cmp_check($row['verified_by'] ?? null, 'system:auto-verify', "(a) $pid evidence actor system:auto-verify");
    cmp_check($row['source_revision'] ?? null, $revision[$pid], "(a) $pid evidence revision");
    $hist = db_row('pipeline', 'SELECT note FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY id DESC LIMIT 1', [$pid]);
    cmp_true(str_contains((string)($hist['note'] ?? ''), 'تأیید خودکار'), "(a) $pid history note says auto-verify");
    cmp_true(str_contains((string)($hist['note'] ?? ''), 'link-check:'.$pid.'@'), "(a) $pid history note names check ref");
    cmp_true(str_contains((string)($hist['note'] ?? ''), 'snapshot snap-1'), "(a) $pid history note names snapshot");
    if (function_exists('molavi_domain_auto_verify_catalog_prices')) {
        cmp_true(str_contains((string)($hist['note'] ?? ''), 'قیمت تأمین‌کننده'), "(a) $pid history note names the supply agreement");
    }
    $link = db_row('pipeline', 'SELECT confirmed_at,confirmed_verified_at FROM supplier_link_checks WHERE product_id=?', [$pid]);
    cmp_true($link['confirmed_at'] !== null, "(a) $pid link check confirmed");
    cmp_check($link['confirmed_verified_at'] ?? null, $row['verified_at'], "(a) $pid confirmed_verified_at == verified_at");
    cmp_check(db_count('pipeline', "SELECT COUNT(*) n FROM jobs WHERE id LIKE 'publish:supplier-verification:".$pid.":%'"), 1, "(a) $pid publish job queued");
}
// --- tick 2: the remaining 2 over-cap items ---
$tick2 = molavi_domain_auto_verify_tick($nowInt, $isoNow, false);
cmp_check($tick2['verified_count'], 2, 'tick2 verifies remaining 2');
cmp_check(array_column($tick2['verified'], 'product_id'), ['p6', 'p7'], 'tick2 verifies p6,p7');
// --- (b)(c)(d)(e) still untouched after live ticks ---
foreach (['p-b', 'p-c', 'p-d', 'p-e'] as $pid) {
    cmp_null(db_row('pipeline', 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', [$pid]), "($pid) no evidence after live ticks");
    cmp_null(db_row('pipeline', 'SELECT confirmed_at FROM supplier_link_checks WHERE product_id=?', [$pid])['confirmed_at'], "($pid) link still unconfirmed");
}
// --- API action: dry_run=false now verifies nothing new; bad body rejected ---
$api = molavi_domain_supplier_link_check_api('POST', [], ['action' => 'auto_verify', 'dry_run' => false], 'wp:1');
cmp_check($api['verified_count'], 0, 'API auto_verify dry_run=false verifies 0 new');
cmp_check($api['scanned'], 3, 'API auto_verify scanned 3 remaining queue rows (p-d,p-e,p-g2)');
fails_with(static fn() => molavi_domain_supplier_link_check_api('POST', [], ['action' => 'auto_verify', 'dry_run' => 'yes'], 'wp:1'),
    'invalid_body', 'API auto_verify rejects non-bool dry_run');
$apiDry = molavi_domain_supplier_link_check_api('POST', [], ['action' => 'auto_verify', 'dry_run' => true], 'wp:1');
cmp_true($apiDry['dry_run'], 'API auto_verify dry_run=true reports dry run');
cmp_check($apiDry['eligible_count'], 0, 'API dry-run eligible 0 after all verified');
// --- method 'auto' accepted by verify_price ---
$probe = molavi_domain_verify_price('p-dummy', 'owner-confirm:'.hash('sha256', 'dummy'), 'system:auto-verify',
    'probe', $isoNow, null, 'auto');
cmp_true(isset($probe['verified_at']), "verify_price accepts method auto");
// --- (f) phase 2: seed p-f's link row, then re-age the snapshot past 24h; p-f must NOT verify ---
seed_link('p-f', 'unchanged', 'identical', $nowInt - 200, null, '80000', $revision['p-f']);
molavi_domain_tx('reference', static function (SQLite3 $db) use ($nowInt): void {
    molavi_domain_sql($db, 'UPDATE reference_source_check SET checked_at=? WHERE singleton=1',
        [gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 25 * 3600)]);
});
$chkF = molavi_domain_auto_verify_check($byId['p-f'], offer_for('p-f', $sheetRecords, $nowInt), $nowInt);
cmp_false($chkF['eligible'], '(f) stale snapshot not eligible');
cmp_check($chkF['reason'], 'catalog_stale', '(f) stale snapshot reason catalog_stale');
$dry2 = molavi_domain_auto_verify_tick($nowInt, $isoNow, true);
cmp_check($dry2['skipped']['catalog_stale'] ?? 0, 1, '(f) dry-run skips p-f as catalog_stale');
cmp_null(db_row('pipeline', 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', ['p-f']), '(f) no evidence for p-f');
echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
