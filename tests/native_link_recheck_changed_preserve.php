<?php
// Link-changed preservation (round-4 follow-up, tasks 2+3) — native harness.
//
// BUG (proved in code, live since the 11:24 seed + old-code ticks):
//   a link-check row whose outcome is 'changed' (a pending owner decision
//   with the new live price as evidence) is re-read every 3 days. When
//   that re-read FAILS (fetch_failed / price_not_found / post_not_found
//   ...), execute() overwrites the row unconditionally — outcome becomes
//   'uncheckable', live_price/live_text become NULL. The FIX-4 adopt form
//   (owner-workflows.js workbenchHasChangedLinkCheck) reads exactly that
//   row (outcome === 'changed' AND live_price > 0, via
//   molavi_domain_attach_product_link_checks), so the adopt path is GONE
//   for the item: the owner can no longer accept the changed price, and
//   the only surviving trace is the supplier_link_check_events audit
//   row, which no card reads. The 6 items that were 'changed' at 03:49
//   (live 298,000 / 220,000 / 75,000 / 34,800 / 440,000 / 145,000) all
//   read fetch_failed at 15:56 for exactly this reason.
//
// FIX (molavi-domain-supplier-link-checks.php, execute()): a failed
//   re-read must never erase a pending owner decision. When the previous
//   outcome is 'changed' — or an unconfirmed 'unchanged' awaiting the
//   owner's confirm — and the new decision is uncheckable, the row keeps
//   its outcome, reason, live price/text, route and checked_at. The
//   failure still counts toward retry/escalation and the row is next due
//   at the failure backoff, so repeated failures escalate and surface on
//   the card. The failed attempt itself is still recorded as an
//   'uncheckable' event in the audit table.
//   Two deliberate boundaries, both pinned here:
//   (a) a CONFIRMED 'unchanged' row is NOT preserved: its confirmation
//       is already kept by $clearConfirmed and its outcome records the
//       failure (the failed-renewal behaviour the link-recheck harness
//       proves) — preserving it would break that contract.
//   (b) the seed re-arm on a changed revision/price/message still clears
//       the row: a new source revision is a fresh start by design, and
//       the old changed price belongs to the old revision.
//   Retry clock for a preserved row: the row's reason_code names the
//   pending decision ('price_changed'), not the last failure, so the
//   per-reason streak cannot be read from it — the consecutive-failure
//   streak is the clock for the preserved row's backoff and escalation
//   (a reason change between failed re-reads does not reset it; the
//   row has still failed N re-reads in a row).
//
// Covers:
//   (A) changed + fetch_failed / price_not_found / post_not_found:
//       outcome, reason, live price/text, checked_at preserved; retry
//       counts, backoff applied, escalation after 5 consecutive
//       fetch_failed re-reads; the failure is recorded as an event.
//   (B) unconfirmed unchanged + fetch_failed: confirm path preserved
//       (outcome, check_ref, live price kept).
//   (C) definitive re-reads still replace the evidence: a new changed
//       price overwrites, an identical read flips the row to unchanged.
//   (D) boundaries: confirmed unchanged records the failure (outcome
//       becomes uncheckable, confirmation kept); a pending row takes
//       the failure normally; the seed still re-arms on a price change.
//   (E) lists(): a preserved changed row stays in the changed list.
//
// Negative control: on the pristine df4a4d9 tip (no fix) the preserve
//   assertions FAIL (outcome becomes 'uncheckable', live_price NULL,
//   retry restarts at 1 every time) while the (C)/(D) controls PASS —
//   run this file in the branch worktree BEFORE applying the fix to
//   prove the behaviour is new, then after.
// Run: php tests/native_link_recheck_changed_preserve.php
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-link-changed-preserve-'.bin2hex(random_bytes(6)));
define('MOLAVI_REFERENCE_SERVER_URL', 'https://wholesale-project-staging-staging.up.railway.app');
define('MOLAVI_REFERENCE_BRIDGE_TOKEN', str_repeat('t', 40));
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_filter(...$args) {}
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function get_users($args = []) { return [1]; }
function home_url($path = '/') { return 'https://staging.molavisale.ir'.$path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($t) { return is_array($t) && !empty($t['__wp_error']); }
function wp_safe_remote_post($url, $args) { return $GLOBALS['railway_response']; }
function wp_remote_retrieve_response_code($r) { return (int)($r['code'] ?? 0); }
function wp_remote_retrieve_body($r) { return (string)($r['body'] ?? ''); }
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-reference-store',
    'domain-reference-matching', 'domain-reference-scheduler', 'domain-sheet-conflicts', 'domain-products', 'domain-publisher',
    'domain-jobs', 'domain-source-projection', 'domain-supplier-link-checks', 'domain-supplier-prices',
    'domain-sale-offers', 'domain-supplier-auto-verify', 'domain-extraction', 'domain-ai'] as $file)
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root = molavi_state_root();
register_shutdown_function(static function () use ($root) { foreach (glob($root.'/*') as $file) unlink($file); rmdir($root); });
(new SQLite3($root.'/reference.sqlite3'))->close();
(new SQLite3($root.'/pipeline.sqlite3'))->close(); // state host requires the file to exist; molavi_domain_db creates the schema on open.
$GLOBALS['options'] = ['molavi_state_active' => true];
$count = 0; $fail = 0;
function cmp_check($actual, $expected, $label) { global $count, $fail;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected)) { echo 'FAIL '.$label.' actual='.molavi_domain_json($actual).' expected='.molavi_domain_json($expected)."\n"; $fail++; return; } $count++; }
function cmp_true($actual, $label) { cmp_check($actual, true, $label); }
function cmp_null($actual, $label) { cmp_check($actual, null, $label); }

$now = (int)molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$msgOldIso = gmdate('Y-m-d\\TH:i:s+00:00', $now - 20 * 86400);
$MSG_STORED = "چای شهرزاد\n۸۵۰۰۰ تومان";
$MSG_CHANGED = "چای شهرزاد\n۹۰۰۰۰ تومان";
$MSG_CHANGED2 = "چای شهرزاد\n۹۱۰۰۰ تومان";
$MSG_NOPRICE = "چای شهرزاد\nبه زودی";
$MSG_STOCK = "چای شهرزاد\n۸۵۰۰۰ تومان\nناموجود";

function product_record(string $id, int $num, string $msgIso): array {
    global $MSG_STORED;
    return ['Product ID' => $id, 'Product Name' => 'چای شهرزاد', 'Channel Username' => 'supplier', 'Channel' => 'supplier',
        'Latest Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Sale Price' => '85000', 'Consumer Price' => '100000',
        'Status' => 'imported', 'Message Timestamp' => $msgIso, 'Source Timestamp Basis' => '',
        'Source Post URL' => 'https://t.me/supplier/'.$num, 'Original Message' => $MSG_STORED,
        'Stock Status' => 'Unknown', 'Reference Snapshot ID' => '', 'Reference Match ID' => '', 'Reference Approval State' => 'owner_approved'];
}
function offer_record(string $id, int $num, string $msgIso): array {
    return ['Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Product ID' => $id, 'Consumer Price' => '100000',
        'Actual Price' => '', 'Sale Price' => '85000', 'Message Timestamp' => $msgIso, 'Source Timestamp Basis' => '',
        'Source Post Updated At' => '', 'Owner Decision ID' => '', 'Owner Decision At' => '',
        'Import Timestamp' => $msgIso, 'Package Count' => '', 'Source Post URL' => 'https://t.me/supplier/'.$num];
}
$ids = ['c1','c2','c3','c4','c5','c6','c7','u1','u2','n1'];
$products = []; $offers = [];
foreach ($ids as $i => $id) {
    $products[$id] = product_record($id, 200 + $i, $msgOldIso);
    $offers[$id] = offer_record($id, 200 + $i, $msgOldIso);
}
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
function db_row(string $store, string $sql, array $params = []): ?array {
    return molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_row($db, $sql, $params));
}
function db_count(string $store, string $sql, array $params = []): int {
    return (int)(db_row($store, $sql, $params)['n'] ?? 0);
}
function insert_row(string $id, ?string $outcome, ?string $reason, ?string $livePrice, ?string $liveText, ?int $checkedAt, ?int $confirmedAt, int $nextAt): void {
    global $revision, $MSG_STORED, $now;
    $num = 200 + array_search($id, ['c1','c2','c3','c4','c5','c6','c7','u1','u2','n1']);
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $outcome, $reason, $livePrice, $liveText, $checkedAt, $confirmedAt, $nextAt, $num, $revision, $now): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, 'owner-confirm:'.hash('sha256', $id), 'چای شهرزاد', 'https://t.me/supplier/'.$num, $revision[$id], '85000', $GLOBALS['MSG_STORED'],
                $outcome, $reason, $livePrice, $liveText, 'railway', $checkedAt, $nextAt, null,
                $confirmedAt, $confirmedAt === null ? null : '2026-11-26T11:00:00+00:00', $now - 7200]);
    });
}
function claim_row(string $id, string $lease): ?array {
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $lease): void {
        molavi_domain_sql($db, 'UPDATE supplier_link_checks SET lease=? WHERE product_id=?', [$lease, $id]);
    });
    return db_row('pipeline', 'SELECT * FROM supplier_link_checks WHERE product_id=?', [$id]);
}
function run_check(string $id, int $now, callable $fetch): array {
    $lease = 'lease-'.$id;
    $row = claim_row($id, $lease);
    return molavi_domain_supplier_link_check_execute($row, $lease, $now, $fetch);
}
function raw_row(string $id): ?array {
    return db_row('pipeline', 'SELECT * FROM supplier_link_checks WHERE product_id=?', [$id]);
}
function post_body(string $text): string {
    return '<html><body><div class="tgme_widget_message_text">'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</div></body></html>';
}
function post_body_notext(): string {
    return '<html><body>Post not found</body></html>';
}
function make_fetch(array $post, int $channelStatus, array &$calls): callable {
    return static function (string $url) use ($post, $channelStatus, &$calls): array {
        if (str_contains($url, '?embed=1')) { $calls['post']++; return $post; }
        $calls['channel']++; return ['status'=>$channelStatus, 'body'=>''];
    };
}

// --- Fixtures: changed rows c1-c7 (live 90000, checked 3d ago, due now),
// unconfirmed unchanged u1, confirmed unchanged u2, pending n1. ---
$duePast = $now - 3600;
$changedAt = $now - 3 * 86400;
foreach (['c1','c2','c3','c4','c5','c6','c7'] as $id)
    insert_row($id, 'changed', 'price_changed', '90000', $MSG_CHANGED, $changedAt, null, $duePast);
insert_row('u1', 'unchanged', 'identical', '85000', $MSG_STORED, $now - 3600, null, $duePast);
insert_row('u2', 'unchanged', 'identical', '85000', $MSG_STORED, $now - 14 * 86400, $now - 13 * 86400 - 60, $duePast);
insert_row('n1', null, null, null, null, null, null, $duePast);

// --- (A) Changed + fetch_failed: the pending decision survives. ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('c1', $now, make_fetch(['status'=>500, 'body'=>''], 200, $calls));
cmp_check($res['outcome'], 'changed', 'preserve: fetch_failed keeps changed outcome');
cmp_check($res['reason_code'], 'price_changed', 'preserve: fetch_failed keeps price_changed reason');
cmp_check($res['live_price'], '90000', 'preserve: fetch_failed keeps live price');
cmp_check(raw_row('c1')['live_text'], $MSG_CHANGED, 'preserve: fetch_failed keeps live text');
cmp_check($res['checked_at'], $changedAt, 'preserve: fetch_failed keeps evidence checked_at');
cmp_check($res['retry_count'], 1, 'preserve: fetch_failed counts retry 1');
cmp_check($res['consecutive_failures'], 1, 'preserve: fetch_failed counts consecutive 1');
cmp_check($res['next_read_at'], $now + 3600, 'preserve: fetch_failed re-read at the 1h backoff');
cmp_check(db_count('pipeline', "SELECT COUNT(*) n FROM supplier_link_check_events WHERE product_id=? AND kind='uncheckable'", ['c1']), 1,
    'preserve: failed re-read is still recorded as an uncheckable event');

// --- (A) Changed + 5x fetch_failed: backoff climbs, escalation at 5,
// evidence still intact (the consecutive streak is the retry clock:
// the row's reason names the pending decision, not the last failure). ---
$H = 3600;
$t = $now;
foreach ([1*$H, 2*$H, 4*$H, 8*$H, 16*$H] as $i => $delta) {
    $tmp = ['post'=>0, 'channel'=>0];
    $res = run_check('c2', $t, make_fetch(['status'=>500, 'body'=>''], 200, $tmp));
    cmp_check($res['outcome'], 'changed', 'preserve5: attempt '.($i + 1).' outcome still changed');
    cmp_check($res['live_price'], '90000', 'preserve5: attempt '.($i + 1).' live price intact');
    cmp_check($res['retry_count'], $i + 1, 'preserve5: attempt '.($i + 1).' retry_count');
    cmp_check($res['consecutive_failures'], $i + 1, 'preserve5: attempt '.($i + 1).' consecutive');
    cmp_check($res['next_read_at'], $t + $delta, 'preserve5: attempt '.($i + 1).' backoff');
    $t += $delta;
}
cmp_check($res['escalated'], true, 'preserve5: escalated after 5 consecutive failed re-reads');
cmp_check($res['escalation_count'], 1, 'preserve5: escalation counted once');
cmp_check($res['reason_code'], 'price_changed', 'preserve5: reason still names the pending decision');

// --- (A) Changed + price_not_found / post_not_found: same preservation. ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('c3', $now, make_fetch(['status'=>200, 'body'=>post_body($MSG_NOPRICE)], 200, $calls));
cmp_check($res['outcome'], 'changed', 'preserve: price_not_found keeps changed outcome');
cmp_check($res['live_price'], '90000', 'preserve: price_not_found keeps live price');
cmp_check($res['retry_count'], 1, 'preserve: price_not_found counts retry 1');
cmp_check($res['next_read_at'], $now + 86400, 'preserve: price_not_found re-read at the 1d backoff');
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('c4', $now, make_fetch(['status'=>200, 'body'=>post_body_notext()], 200, $calls));
cmp_check($res['reason_code'], 'price_changed', 'preserve: post_not_found keeps price_changed reason');
cmp_check($res['outcome'], 'changed', 'preserve: post_not_found keeps changed outcome');
cmp_check($res['live_price'], '90000', 'preserve: post_not_found keeps live price');
cmp_check($res['next_read_at'], $now + 86400, 'preserve: post_not_found re-read at the 1d backoff');

// --- (B) Unconfirmed unchanged + fetch_failed: the confirm path survives. ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('u1', $now, make_fetch(['status'=>500, 'body'=>''], 200, $calls));
cmp_check($res['outcome'], 'unchanged', 'preserve: fetch_failed keeps unconfirmed unchanged outcome');
cmp_check($res['reason_code'], 'identical', 'preserve: fetch_failed keeps identical reason');
cmp_check($res['live_price'], '85000', 'preserve: fetch_failed keeps unchanged live price');
cmp_check($res['checked_at'], $now - 3600, 'preserve: fetch_failed keeps unchanged checked_at');
cmp_check($res['check_ref'], 'link-check:u1@'.($now - 3600), 'preserve: check_ref still matches the kept checked_at');
cmp_check($res['retry_count'], 1, 'preserve: unchanged failure counts retry 1');
cmp_check($res['next_read_at'], $now + 3600, 'preserve: unchanged failure re-read at the 1h backoff');

// --- (C) Definitive re-reads still replace the evidence. ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('c5', $now, make_fetch(['status'=>200, 'body'=>post_body($MSG_CHANGED2)], 200, $calls));
cmp_check($res['outcome'], 'changed', 'definitive: new changed price stays changed');
cmp_check($res['live_price'], '91000', 'definitive: new changed price replaces the old evidence');
cmp_check($res['retry_count'], 0, 'definitive: new changed price clears retries');
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('c6', $now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_check($res['outcome'], 'unchanged', 'definitive: identical read flips the row to unchanged');
cmp_check($res['live_price'], '85000', 'definitive: identical read restores the stored price');
cmp_check($res['retry_count'], 0, 'definitive: identical read clears retries');

// --- (D) Boundary: a CONFIRMED unchanged row records the failure
// (outcome becomes uncheckable, confirmation kept) — NOT preserved. ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('u2', $now, make_fetch(['status'=>500, 'body'=>''], 200, $calls));
cmp_check($res['outcome'], 'uncheckable', 'boundary: confirmed row outcome records the failure');
cmp_check($res['reason_code'], 'fetch_failed', 'boundary: confirmed row reason is fetch_failed');
cmp_check($res['confirmed_at'], $now - 13 * 86400 - 60, 'boundary: confirmed row keeps its confirmation');
cmp_check($res['retry_count'], 1, 'boundary: confirmed row failure counts retry 1');

// --- (D) Boundary: a pending row takes the failure normally. ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('n1', $now, make_fetch(['status'=>500, 'body'=>''], 200, $calls));
cmp_check($res['outcome'], 'uncheckable', 'boundary: pending row takes fetch_failed');
cmp_check($res['reason_code'], 'fetch_failed', 'boundary: pending row reason is fetch_failed');
cmp_null($res['live_price'], 'boundary: pending row has no live price to keep');

// --- (E) Lists: preserved changed rows stay in the changed list. ---
$lists = molavi_domain_supplier_link_check_lists();
cmp_true(in_array('c1', array_column($lists['changed'], 'product_id'), true), 'lists: c1 still in the changed list');
cmp_true(in_array('c2', array_column($lists['changed'], 'product_id'), true), 'lists: c2 still in the changed list');
cmp_true(($lists['counts']['changed'] ?? 0) >= 4, 'lists: changed count still covers the preserved rows');
cmp_true(in_array('c2', array_column($lists['escalated'], 'product_id'), true), 'lists: escalated c2 surfaces for the owner');

// --- (D) Boundary: the seed still re-arms on a changed price/revision
// (a new revision is a fresh start by design; the old changed price
// belongs to the old revision). ---
foreach ($GLOBALS['sheets']['Products'] as &$sheetRow) {
    if (($sheetRow[1] ?? '') === 'c7') $sheetRow[4] = '86000';
}
unset($sheetRow);
cmp_true(molavi_domain_supplier_link_check_seed($now + 60) >= 1, 'seed: price change re-arms c7');
$c7row = raw_row('c7');
cmp_null($c7row['outcome'] ?? null, 'seed: re-armed changed row outcome cleared');
cmp_check($c7row['stored_sale_price'] ?? null, '86000', 'seed: re-armed row records the new stored price');

echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
