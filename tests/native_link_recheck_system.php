<?php
// Link-recheck system (2026-11-28) — native harness.
//
// Covers, on the FIXED tree (branch fix/link-recheck-system):
//   (A) Classifier regression proof: the deployed ba89812 split is
//       already correct — HTTP 200 'Post not found' + live channel =>
//       post_not_found (NOT channel_unavailable); dead channel =>
//       channel_unavailable; HTTP 404 + live channel => post_not_found;
//       bad URL => no_url; Bale => bale_source.
//   (B) Per-outcome policy: unchanged re-read +7d, confirmed renewal
//       at +13d (run_one claims it, park skipped, confirmation
//       cleared only on a definitive outcome), changed re-read +3d
//       without adopting or writing evidence, retryable failures with
//       exponential backoff + escalation thresholds, data problems
//       with a weekly revisit and no retry burn, recovery clearing
//       all retry/escalation state, reason change restarting the count.
//   (C) Transport pause: a dead Railway fetch pauses the whole loop
//       (long backoff, no per-link burn, no outcome change), the tick
//       skips run_one until the probe time, the pause lengthens on a
//       second failure and clears on a served fetch; a per-link
//       fetch_failed never sets the pause. Plus the detailed fetch
//       split itself (WP_Error / non-200 / ok=false => transport).
//   (D) Seed: no state filter (a CURRENT-price product gets a row —
//       the dirty-checkout landmine), re-arm resets retry/escalation
//       state, the 24h seed gate still holds, seed() with unchanged
//       sheets re-arms nothing.
//   (E) Schema migration: an old table (no new columns) gains all six
//       columns in place with its rows intact; settings merge, clamp
//       and version; lists() carries escalated/transport/settings.
//
// Negative control: on the pristine base tip (37db720) the new API
// (molavi_link_check_settings / ensure_columns / telegram_fetch_detailed)
// does not exist, so this file FAILS there (0 PASS / 14 FAIL) —
// run it in a base worktree to prove the behaviour is new.
// Run: php tests/native_link_recheck_system.php
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-link-recheck-'.bin2hex(random_bytes(6)));
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
$GLOBALS['options'] = ['molavi_state_active' => true];
$count = 0; $fail = 0;
function cmp_check($actual, $expected, $label) { global $count, $fail;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected)) { echo 'FAIL '.$label.' actual='.molavi_domain_json($actual).' expected='.molavi_domain_json($expected)."\n"; $fail++; return; } $count++; }
function cmp_true($actual, $label) { cmp_check($actual, true, $label); }
function cmp_false($actual, $label) { cmp_check($actual, false, $label); }
function cmp_null($actual, $label) { cmp_check($actual, null, $label); }
function fail_case($label) { global $fail; echo 'FAIL '.$label."\n"; $fail++; }

// --- Negative control: the new API must exist, or every group fails. ---
$isFixed = function_exists('molavi_link_check_settings')
    && function_exists('molavi_domain_supplier_link_check_ensure_columns')
    && function_exists('molavi_domain_telegram_fetch_detailed');
echo 'MODE: '.($isFixed ? 'FIXED (link-recheck system present)' : 'BASE 37db720 (negative control: no recheck system)')."\n";
if (!$isFixed) {
    foreach (['migration adds six columns in place', 'settings merge/clamp/version', 'classifier split (200-no-text)',
        'unchanged +7d / changed +3d cadence', 'confirmed renewal at +13d', 'renewal park skip',
        'failed renewal keeps confirmation', 'fetch_failed backoff + escalate at 5',
        'fetch_blocked backoff + escalate at 4', 'price_not_found/ambiguous/stock/pack escalation',
        'data problems: weekly, no burn', 'recovery clears state', 'transport pause + tick guard',
        'seed: no state filter + re-arm reset + 24h gate'] as $group)
        fail_case('base: '.$group.' — new recheck API absent');
    echo "PASS $count FAIL $fail\n";
    exit(1);
}

$now = (int)molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$msgOldIso = gmdate('Y-m-d\\TH:i:s+00:00', $now - 20 * 86400);
$msgFreshIso = gmdate('Y-m-d\\TH:i:s+00:00', $now - 3600);
$MSG_STORED = "چای شهرزاد\n۸۵۰۰۰ تومان";
$MSG_CHANGED = "چای شهرزاد\n۹۰۰۰۰ تومان";
$MSG_CHANGED2 = "چای شهرزاد\n۹۱۰۰۰ تومان";
$MSG_AMBIG = "چای شهرزاد\n۸۵۰۰۰ تومان\n۹۰۰۰۰ تومان";
$MSG_NOPRICE = "چای شهرزاد\nبه زودی";
$MSG_STOCK = "چای شهرزاد\n۸۵۰۰۰ تومان\nناموجود";
$MSG_PACK = "چای شهرزاد\nبسته ۱۲ عددی\n۸۵۰۰۰ تومان";

// --- Fixtures: one product per case, all with the same name/price. ---
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
$ids = ['k1','k2','k3','k4','k5','r1','r2','r3','r4','r5','r6','r7','r8','r9','g1','n1','n2','n3','n4','n5','n6','t1','s1','s2'];
$products = []; $offers = [];
foreach ($ids as $i => $id) {
    $products[$id] = product_record($id, 100 + $i, $msgOldIso);
    $offers[$id] = offer_record($id, 100 + $i, $msgOldIso);
}
// k5 is the true no_url case: no Source Post URL anywhere (sheet,
// offer, recorded links), so the seed cannot rebuild one for it.
$products['k5']['Source Post URL'] = '';
$offers['k5']['Source Post URL'] = '';
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
function insert_link(string $id, ?string $outcome, ?string $reason, ?int $checkedAt, ?int $confirmedAt, int $nextAt, ?string $url = null, array $extra = []): void {
    global $revision, $MSG_STORED, $now;
    $num = 100 + array_search($id, ['k1','k2','k3','k4','k5','r1','r2','r3','r4','r5','r6','r7','r8','r9','g1','n1','n2','n3','n4','n5','n6','t1','s1','s2']);
    if ($url === null) $url = 'https://t.me/supplier/'.$num;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $outcome, $reason, $checkedAt, $confirmedAt, $nextAt, $url, $revision, $extra, $now): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,live_price,live_text,route,checked_at,next_at,lease,confirmed_at,confirmed_verified_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, 'owner-confirm:'.hash('sha256', $id), 'چای شهرزاد', $url, $revision[$id], '85000', $GLOBALS['MSG_STORED'],
                $outcome, $reason, $outcome === 'uncheckable' ? null : '85000', null, 'railway', $checkedAt, $nextAt, null,
                $confirmedAt, $confirmedAt === null ? null : '2026-11-26T11:00:00+00:00', $now - 7200]);
        foreach ($extra as $col => $val)
            molavi_domain_sql($db, "UPDATE supplier_link_checks SET $col=? WHERE product_id=?", [$val, $id]);
    });
}
function seed_evidence(string $id, string $verifiedAt): void {
    global $revision;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $verifiedAt, $revision): void {
        molavi_domain_sql($db, 'INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)',
            [$id, $revision[$id], $verifiedAt, 'source', null]);
    });
}
function set_next_at_all(int $ts): void {
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($ts): void {
        molavi_domain_sql($db, 'UPDATE supplier_link_checks SET next_at=?', [$ts]);
    });
}
function set_next_at(string $id, int $ts): void {
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $ts): void {
        molavi_domain_sql($db, 'UPDATE supplier_link_checks SET next_at=? WHERE product_id=?', [$ts, $id]);
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
    // A 'Post not found' page carries no message-text div at all.
    return '<html><body>Post not found</body></html>';
}
function make_fetch(array $post, int $channelStatus, array &$calls): callable {
    return static function (string $url) use ($post, $channelStatus, &$calls): array {
        if (str_contains($url, '?embed=1')) { $calls['post']++; return $post; }
        $calls['channel']++; return ['status'=>$channelStatus, 'body'=>''];
    };
}

// --- (E) Migration: old table gains six columns in place. ---
// Build the pre-migration table BEFORE any molavi_domain_db('pipeline') call.
$oldSql = "CREATE TABLE supplier_link_checks (
    product_id TEXT PRIMARY KEY, offer_id TEXT NOT NULL,
    title TEXT NOT NULL, url TEXT NOT NULL, source_revision TEXT NOT NULL,
    stored_sale_price TEXT NOT NULL, stored_message TEXT NOT NULL,
    outcome TEXT, reason_code TEXT, live_price TEXT, live_text TEXT,
    route TEXT, checked_at INTEGER, next_at INTEGER NOT NULL,
    lease TEXT, confirmed_at INTEGER, confirmed_verified_at TEXT,
    created_at INTEGER NOT NULL
)";
$dbh = new SQLite3($root.'/pipeline.sqlite3');
$dbh->exec($oldSql);
$dbh->exec("INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,checked_at,next_at,created_at) VALUES ('legacy1','o1','چای شهرزاد','https://t.me/supplier/999','rev1','85000','".$MSG_STORED."','unchanged','identical',".($now - 3600).",".($now + 86400).",".($now - 7200).")");
molavi_domain_supplier_link_check_ensure_columns($dbh);
$cols = array_column(molavi_domain_rows($dbh, 'PRAGMA table_info(supplier_link_checks)'), 'name');
foreach (['retry_count','consecutive_failures','last_error','escalated','escalation_count','escalated_at'] as $col)
    cmp_true(in_array($col, $cols, true), 'migration: column '.$col.' added');
$legacy = molavi_domain_row($dbh, 'SELECT * FROM supplier_link_checks WHERE product_id=?', ['legacy1']);
cmp_check($legacy['outcome'] ?? null, 'unchanged', 'migration: legacy row outcome intact');
cmp_check((int)($legacy['retry_count'] ?? -1), 0, 'migration: legacy row retry_count defaults 0');
cmp_check((int)($legacy['escalated'] ?? -1), 0, 'migration: legacy row escalated defaults 0');
$dbh->close();

// --- (E) Settings: defaults, merge, clamp, version. ---
$s = molavi_link_check_settings();
cmp_check($s['unchanged_recheck_days'], 7, 'settings: unchanged recheck 7d default');
cmp_check($s['confirmed_renewal_days'], 13, 'settings: confirmed renewal 13d default');
cmp_check($s['changed_reread_days'], 3, 'settings: changed reread 3d default');
cmp_check($s['fetch_failed_base_seconds'], 3600, 'settings: fetch_failed base 1h default');
cmp_check($s['fetch_failed_escalate_after'], 5, 'settings: fetch_failed escalates after 5');
cmp_check($s['fetch_blocked_base_seconds'], 21600, 'settings: fetch_blocked base 6h default');
cmp_check($s['fetch_blocked_escalate_after'], 4, 'settings: fetch_blocked escalates after 4');
cmp_check($s['source_stock_changed_escalate_after'], 2, 'settings: stock escalates after 2');
cmp_check($s['transport_backoff_base_seconds'], 21600, 'settings: transport backoff base 6h default');
cmp_true((bool)preg_match('/^[0-9a-f]{12}$/D', molavi_link_check_settings_version($s)), 'settings: version is 12 hex chars');
$GLOBALS['options']['molavi_link_check_settings_v1'] = ['unchanged_recheck_days'=>3, 'bogus_key'=>99, 'fetch_failed_escalate_after'=>0];
$s2 = molavi_link_check_settings();
cmp_check($s2['unchanged_recheck_days'], 3, 'settings: stored override merges');
cmp_check($s2['fetch_failed_escalate_after'], 1, 'settings: escalate_after clamps to >=1');
cmp_false(array_key_exists('bogus_key', $s2), 'settings: unknown keys dropped');
cmp_true(molavi_link_check_settings_version($s2) !== molavi_link_check_settings_version($s), 'settings: version changes with values');
unset($GLOBALS['options']['molavi_link_check_settings_v1']);

// --- (A) Classifier, pure: URL problems need no fetch. ---
$stored = ['url'=>'https://t.me/supplier/101', 'message'=>$MSG_STORED, 'sale_price'=>'85000', 'name'=>'چای شهرزاد'];
cmp_check(molavi_domain_supplier_link_check_classify(array_merge($stored, ['url'=>'https://example.com/x']), null)['reason_code'], 'no_url', 'classify: bad URL => no_url');
cmp_check(molavi_domain_supplier_link_check_classify(array_merge($stored, ['url'=>'https://bale.ai/supplier/123']), null)['reason_code'], 'bale_source', 'classify: bale => bale_source');
cmp_check(molavi_domain_supplier_link_check_classify(array_merge($stored, ['url'=>'']), null)['reason_code'], 'no_url', 'classify: empty URL + message => no_url');
cmp_check(molavi_domain_supplier_link_check_classify(array_merge($stored, ['url'=>'', 'message'=>'']), null)['reason_code'], 'legacy_no_reference', 'classify: empty URL + empty message => legacy_no_reference');

// --- (C) Detailed fetch split (ai.php under harness stubs). ---
$GLOBALS['railway_response'] = ['__wp_error'=>true];
$d = molavi_domain_telegram_fetch_detailed('https://t.me/supplier/101');
cmp_false($d['ok'], 'detailed fetch: WP_Error => not ok');
cmp_true($d['transport'], 'detailed fetch: WP_Error => transport');
cmp_check($d['code'], 'http_error', 'detailed fetch: WP_Error code http_error');
$GLOBALS['railway_response'] = ['code'=>404, 'body'=>'Application not found'];
$d = molavi_domain_telegram_fetch_detailed('https://t.me/supplier/101');
cmp_check($d['code'], 'http_404', 'detailed fetch: Railway 404 => transport http_404');
$GLOBALS['railway_response'] = ['code'=>200, 'body'=>'{"ok":false,"error":"bad url"}'];
$d = molavi_domain_telegram_fetch_detailed('https://t.me/supplier/101');
cmp_check($d['code'], 'bad_response', 'detailed fetch: ok=false => transport bad_response');
$GLOBALS['railway_response'] = ['code'=>200, 'body'=>'{"ok":true,"status":200,"text":"hello"}'];
$d = molavi_domain_telegram_fetch_detailed('https://t.me/supplier/101');
cmp_true($d['ok'], 'detailed fetch: served ok=true');
cmp_check($d['status'], 200, 'detailed fetch: served status passes through');
cmp_check(molavi_domain_telegram_fetch('https://t.me/supplier/101'), ['status'=>200, 'text'=>'hello'], 'wrapper fetch: served shape preserved');
$GLOBALS['railway_response'] = ['code'=>500, 'body'=>'err'];
cmp_null(molavi_domain_telegram_fetch('https://t.me/supplier/101'), 'wrapper fetch: non-200 still null (old contract)');

// --- Setup link rows (all due except where noted). ---
$duePast = $now - 3600;
insert_link('k1', null, null, null, null, $duePast);
insert_link('k2', null, null, null, null, $duePast);
insert_link('k3', null, null, null, null, $duePast);
insert_link('k4', null, null, null, null, $duePast);
insert_link('k5', 'uncheckable', 'no_url', $now - 86400, null, $duePast, '');
insert_link('r1', null, null, null, null, $duePast);
insert_link('r2', null, null, null, null, $duePast);
insert_link('r3', null, null, null, null, $duePast);
insert_link('r4', null, null, null, null, $duePast);
insert_link('r5', null, null, null, null, $duePast);
insert_link('r6', null, null, null, null, $duePast);
insert_link('r7', null, null, null, null, $duePast);
insert_link('r8', null, null, null, null, $duePast);
insert_link('r9', null, null, null, null, $duePast);
insert_link('g1', null, null, null, null, $duePast);
insert_link('n1', 'unchanged', 'identical', $now - 14 * 86400, $now - 13 * 86400 - 60, $duePast);
insert_link('n2', 'unchanged', 'identical', $now - 2 * 86400, $now - 86400, $duePast);
insert_link('n3', 'unchanged', 'identical', $now - 14 * 86400, $now - 13 * 86400 - 60, $duePast);
insert_link('n4', 'unchanged', 'identical', $now - 3600, null, $duePast);
insert_link('n5', 'unchanged', 'identical', $now - 14 * 86400, $now - 13 * 86400 - 60, $duePast);
insert_link('n6', null, null, null, null, $duePast, null, ['lease'=>'abandoned']);
insert_link('t1', null, null, null, null, $duePast);
// s1 deliberately has NO row yet; s2 has an escalated row to be re-armed.
insert_link('s2', 'uncheckable', 'fetch_failed', $now - 86400, null, $duePast, null,
    ['retry_count'=>5, 'consecutive_failures'=>5, 'escalated'=>1, 'escalation_count'=>1, 'escalated_at'=>$now - 86400]);
seed_evidence('n3', $msgFreshIso);
seed_evidence('n4', $msgFreshIso);
seed_evidence('s1', $msgFreshIso);

// --- (D) Seed: no state filter, re-arm reset, no spam. ---
// s1 is CURRENT (fresh evidence) — the dirty seed's needs_review/blocked
// filter would have skipped it entirely.
$st = molavi_domain_freshness([product_record('s1', 122, $msgOldIso)], (float)$now, false)[0]['state'] ?? null;
cmp_check($st, 'current', 'seed fixture: s1 is current');
cmp_null(raw_row('s1'), 'seed: s1 has no row before seed');
$seeded = molavi_domain_supplier_link_check_seed($now);
cmp_true($seeded >= 1, 'seed: seeded at least s1');
$s1row = raw_row('s1');
cmp_true($s1row !== null, 'seed: current-price product gets a row (no state filter)');
cmp_null($s1row['outcome'] ?? null, 'seed: new row is pending (outcome null)');
cmp_check((int)($s1row['next_at'] ?? 0), $now, 'seed: new row due now');
// Re-arm resets retry/escalation state: change s2's sheet price.
foreach ($GLOBALS['sheets']['Products'] as &$sheetRow) {
    if (($sheetRow[1] ?? '') === 's2') $sheetRow[4] = '86000';
}
unset($sheetRow);
$seeded2 = molavi_domain_supplier_link_check_seed($now + 60);
cmp_true($seeded2 >= 1, 'seed: price change re-arms s2');
$s2row = raw_row('s2');
cmp_null($s2row['outcome'] ?? null, 'seed re-arm: outcome cleared');
cmp_check((int)($s2row['retry_count'] ?? -1), 0, 'seed re-arm: retry_count reset');
cmp_check((int)($s2row['escalated'] ?? -1), 0, 'seed re-arm: escalated reset');
cmp_check((int)($s2row['escalation_count'] ?? -1), 0, 'seed re-arm: escalation_count reset');
cmp_null($s2row['last_error'] ?? null, 'seed re-arm: last_error cleared');
cmp_check($s2row['stored_sale_price'] ?? null, '86000', 'seed re-arm: new stored price recorded');
// Restore s2's sheet price so later freshness/seed calls stay consistent:
// the next seed re-arms it back to 85000, and the one after that must
// re-arm nothing.
foreach ($GLOBALS['sheets']['Products'] as &$sheetRow) {
    if (($sheetRow[1] ?? '') === 's2') $sheetRow[4] = '85000';
}
unset($sheetRow);
cmp_true(molavi_domain_supplier_link_check_seed($now + 90) >= 1, 'seed: restoring the price re-arms s2 back');
cmp_check(molavi_domain_supplier_link_check_seed($now + 120), 0, 'seed: unchanged sheets re-arm nothing');

// --- (A) Classifier split via execute (deployed ba89812 — verify, do not rewrite). ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('k1', $now, make_fetch(['status'=>200, 'body'=>post_body_notext()], 200, $calls));
cmp_check($res['outcome'], 'uncheckable', 'split: 200-no-text + live channel outcome uncheckable');
cmp_check($res['reason_code'], 'post_not_found', 'split: 200-no-text + live channel => post_not_found');
cmp_check($calls['channel'], 1, 'split: channel page was consulted for the 200 case');
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('k2', $now, make_fetch(['status'=>200, 'body'=>post_body_notext()], 404, $calls));
cmp_check($res['reason_code'], 'channel_unavailable', 'split: 200-no-text + dead channel => channel_unavailable');
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('k3', $now, make_fetch(['status'=>404, 'body'=>''], 200, $calls));
cmp_check($res['reason_code'], 'post_not_found', 'split: HTTP 404 + live channel => post_not_found');
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('k4', $now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_check($res['outcome'], 'unchanged', 'execute: identical text => unchanged');
cmp_check($res['reason_code'], 'identical', 'execute: identical text => identical');
cmp_check($res['next_read_at'], $now + 7 * 86400, 'policy: unchanged next re-read +7d');
cmp_check($res['retry_count'], 0, 'policy: unchanged counters zero');
cmp_check($res['escalated'], false, 'policy: unchanged not escalated');
// No-URL rows: weekly revisit, no retry burn.
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('k5', $now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_check($res['reason_code'], 'no_url', 'policy: no_url row stays no_url');
cmp_check($res['next_read_at'], $now + 7 * 86400, 'policy: no_url revisit +7d');
cmp_check($res['retry_count'], 0, 'policy: no_url burns no retries');
cmp_check($calls['post'], 0, 'policy: no_url performs no fetch');

// --- (B) Changed: re-read +3d, never adopts, never writes evidence. ---
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('g1', $now, make_fetch(['status'=>200, 'body'=>post_body($MSG_CHANGED)], 200, $calls));
cmp_check($res['outcome'], 'changed', 'changed: new live price => changed');
cmp_check($res['live_price'], '90000', 'changed: live price surfaced');
cmp_check($res['next_read_at'], $now + 3 * 86400, 'changed: re-read +3d while owner-pending');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history'), 0, 'changed: re-read writes no evidence');
$res = run_check('g1', $now + 3 * 86400, make_fetch(['status'=>200, 'body'=>post_body($MSG_CHANGED2)], 200, $calls));
cmp_check($res['outcome'], 'changed', 'changed: second re-read stays changed');
cmp_check($res['live_price'], '91000', 'changed: re-read updates the surfaced live price');
cmp_null($res['confirmed_at'], 'changed: re-read never confirms');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM supplier_price_evidence_history'), 0, 'changed: second re-read still writes no evidence');

// --- (B) Retryable failures: backoff + escalation, then recovery. ---
function fail_sequence(string $id, int $startNow, array $post, int $channelStatus, array $expectNext, int $escalateAt, string $reason, string $label): void {
    $t = $startNow;
    foreach ($expectNext as $i => $delta) {
        $c = ['post'=>0, 'channel'=>0];
        $res = run_check($id, $t, make_fetch($post, $channelStatus, $c));
        cmp_check($res['reason_code'], $reason, $label.': attempt '.($i + 1).' reason');
        cmp_check($res['next_read_at'], $t + $delta, $label.': attempt '.($i + 1).' backoff');
        cmp_check($res['retry_count'], $i + 1, $label.': attempt '.($i + 1).' retry_count');
        cmp_check($res['consecutive_failures'], $i + 1, $label.': attempt '.($i + 1).' consecutive_failures');
        $escalated = ($i + 1) >= $escalateAt;
        cmp_check($res['escalated'], $escalated, $label.': attempt '.($i + 1).' escalated='.($escalated ? 'yes' : 'no'));
        $t += $delta;
    }
}
$H = 3600; $D = 86400;
// fetch_failed: 1h,2h,4h,8h,16h — escalate at 5, cap 24h on the 6th.
fail_sequence('r1', $now, ['status'=>500, 'body'=>''], 200,
    [1*$H, 2*$H, 4*$H, 8*$H, 16*$H, 24*$H], 5, 'fetch_failed', 'fetch_failed');
// fetch_blocked (429): 6h,12h,24h,24h — escalate at 4.
fail_sequence('r2', $now, ['status'=>429, 'body'=>''], 200,
    [6*$H, 12*$H, 24*$H, 24*$H], 4, 'fetch_blocked', 'fetch_blocked');
// price_not_found: 1d,2d,4d — escalate at 3.
fail_sequence('r3', $now, ['status'=>200, 'body'=>post_body($MSG_NOPRICE)], 200,
    [1*$D, 2*$D, 4*$D], 3, 'price_not_found', 'price_not_found');
// price_ambiguous: 1d,2d,4d — escalate at 3; detail carries the prices.
$t = $now;
for ($i = 1; $i <= 3; $i++) {
    $tmp = ['post'=>0, 'channel'=>0];
    $res = run_check('r4', $t, make_fetch(['status'=>200, 'body'=>post_body($MSG_AMBIG)], 200, $tmp));
    cmp_check($res['reason_code'], 'price_ambiguous', 'price_ambiguous: attempt '.$i.' reason');
    cmp_check($res['last_detail'], ['prices'=>[85000, 90000]], 'price_ambiguous: attempt '.$i.' detail shows both prices');
    $t += [1*$D, 2*$D, 4*$D][$i - 1];
}
cmp_true(raw_row('r4')['escalated'] == 1, 'price_ambiguous: escalated after 3');
// source_stock_changed: escalate at 2 (confirm on re-read).
fail_sequence('r5', $now, ['status'=>200, 'body'=>post_body($MSG_STOCK)], 200,
    [1*$D, 2*$D], 2, 'source_stock_changed', 'source_stock_changed');
// source_pack_changed: 1d,2d,4d — escalate at 3.
fail_sequence('r6', $now, ['status'=>200, 'body'=>post_body($MSG_PACK)], 200,
    [1*$D, 2*$D, 4*$D], 3, 'source_pack_changed', 'source_pack_changed');
// post_not_found: 1d,2d,4d — escalate at 3.
fail_sequence('r7', $now, ['status'=>200, 'body'=>post_body_notext()], 200,
    [1*$D, 2*$D, 4*$D], 3, 'post_not_found', 'post_not_found');
// channel_unavailable: 1d,2d,4d — escalate at 3.
fail_sequence('r8', $now, ['status'=>200, 'body'=>post_body_notext()], 404,
    [1*$D, 2*$D, 4*$D], 3, 'channel_unavailable', 'channel_unavailable');
// Escalation count stays 1 on further failures (no re-count).
$tmp = ['post'=>0, 'channel'=>0];
$res = run_check('r1', $now + 40 * $H, make_fetch(['status'=>500, 'body'=>''], 200, $tmp));
cmp_check($res['escalated'], true, 'escalation: still escalated on 7th failure');
cmp_check($res['escalation_count'], 1, 'escalation: count stays 1 (no re-count)');
cmp_check($res['next_read_at'], $now + 40 * $H + 24 * $H, 'escalation: re-checks continue at the 24h cap');
// Reason change restarts the per-reason count but not the consecutive count.
$tmp = ['post'=>0, 'channel'=>0];
$res = run_check('r9', $now, make_fetch(['status'=>500, 'body'=>''], 200, $tmp));
$res = run_check('r9', $now + $H, make_fetch(['status'=>500, 'body'=>''], 200, $tmp));
cmp_check($res['retry_count'], 2, 'reason change fixture: two fetch_failed => retry 2');
$res = run_check('r9', $now + 3 * $H, make_fetch(['status'=>200, 'body'=>post_body($MSG_NOPRICE)], 200, $tmp));
cmp_check($res['reason_code'], 'price_not_found', 'reason change: new reason recorded');
cmp_check($res['retry_count'], 1, 'reason change: per-reason count restarts at 1');
cmp_check($res['consecutive_failures'], 3, 'reason change: consecutive failures keep counting');
// Recovery clears everything.
$res = run_check('r1', $now + 41 * $H, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $tmp));
cmp_check($res['outcome'], 'unchanged', 'recovery: identical read after escalation => unchanged');
cmp_check($res['retry_count'], 0, 'recovery: retry_count cleared');
cmp_check($res['consecutive_failures'], 0, 'recovery: consecutive_failures cleared');
cmp_check($res['escalated'], false, 'recovery: escalated cleared');
cmp_check($res['escalation_count'], 0, 'recovery: escalation_count cleared');
cmp_null(raw_row('r1')['last_error'], 'recovery: last_error cleared');

// --- (B) Confirmed renewal via run_one (1/tick claim kept). ---
set_next_at_all($now + 30 * $D);
set_next_at('n1', $duePast);
$calls = ['post'=>0, 'channel'=>0];
$res = molavi_domain_supplier_link_check_run_one($now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_true($res !== null, 'renewal: 13d-old confirmation is claimed');
cmp_check($res['product_id'], 'n1', 'renewal: the claimed row is n1');
cmp_check($res['outcome'], 'unchanged', 'renewal: re-check returns unchanged');
cmp_null($res['confirmed_at'], 'renewal: confirmation clears on the definitive re-check');
cmp_check($calls['post'], 1, 'renewal: the live post was actually re-read');
cmp_check($res['next_read_at'], $now + 7 * $D, 'renewal: re-checked row follows the unchanged +7d cadence');
// A 1d-old confirmation is NOT due.
set_next_at_all($now + 30 * $D);
set_next_at('n2', $duePast);
$calls = ['post'=>0, 'channel'=>0];
cmp_null(molavi_domain_supplier_link_check_run_one($now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls)), 'renewal: 1d-old confirmation is not claimed');
cmp_check($calls['post'], 0, 'renewal: nothing fetched when nothing is due');
// Renewal + current price: the park must NOT swallow the re-attestation.
set_next_at_all($now + 30 * $D);
set_next_at('n3', $duePast);
$calls = ['post'=>0, 'channel'=>0];
$res = molavi_domain_supplier_link_check_run_one($now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_true($res !== null && !isset($res['skipped']), 'renewal+current: renewal row is not parked');
cmp_check($calls['post'], 1, 'renewal+current: the live post was re-read despite a current price');
cmp_check($res['outcome'] ?? null, 'unchanged', 'renewal+current: re-check returns unchanged');
// Non-renewal + current price: the old park still applies.
set_next_at_all($now + 30 * $D);
set_next_at('n4', $duePast);
$calls = ['post'=>0, 'channel'=>0];
$res = molavi_domain_supplier_link_check_run_one($now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_check($res['skipped'] ?? null, 'already_current', 'park: current non-renewal row parks');
cmp_check($calls['post'], 0, 'park: parked row performs no fetch');
cmp_check(raw_row('n4')['next_at'], $now + $D, 'park: parked row due again in 1d');
cmp_check(raw_row('n4')['outcome'], 'unchanged', 'park: parked row keeps its outcome');
// Failed renewal keeps the old confirmation (no silent un-confirm).
set_next_at_all($now + 30 * $D);
set_next_at('n5', $duePast);
$calls = ['post'=>0, 'channel'=>0];
$res = molavi_domain_supplier_link_check_run_one($now, make_fetch(['status'=>500, 'body'=>''], 200, $calls));
cmp_check($res['reason_code'], 'fetch_failed', 'failed renewal: outcome records the failure');
cmp_check($res['confirmed_at'], $now - 13 * $D - 60, 'failed renewal: old confirmation is preserved');
cmp_check($res['retry_count'], 1, 'failed renewal: retry counts the failure');
// …and the preserved confirmation keeps it renewal-due, so the retry loop continues.
set_next_at('n5', $now + $H - 60);
$res = molavi_domain_supplier_link_check_run_one($now + $H, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_true($res !== null && ($res['product_id'] ?? '') === 'n5', 'failed renewal: row is claimable again at its backoff');
cmp_check($res['outcome'], 'unchanged', 'failed renewal: retry re-checks unchanged');
cmp_null($res['confirmed_at'], 'failed renewal: confirmation clears once the re-check succeeds');

// Abandoned lease: run_one resumes a stale lease instead of stranding it.
set_next_at_all($now + 30 * $D);
set_next_at('n6', $duePast);
$calls = ['post'=>0, 'channel'=>0];
$res = molavi_domain_supplier_link_check_run_one($now, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
cmp_true($res !== null && ($res['product_id'] ?? '') === 'n6', 'abandoned lease: run_one resumes a stale lease');
cmp_check($calls['post'], 1, 'abandoned lease: the resumed check actually fetches');
cmp_check($res['outcome'] ?? null, 'unchanged', 'abandoned lease: resumed check returns unchanged');
cmp_null(raw_row('n6')['lease'], 'abandoned lease: lease released after the check');

// --- (C) Transport pause. ---
$GLOBALS['options']['molavi_link_check_seed_v3_at'] = $now + 30 * $D; // keep the seed out of the tick tests
set_next_at_all($now + 30 * $D);
set_next_at('t1', $duePast);
$calls = ['post'=>0, 'channel'=>0];
$res = run_check('t1', $now, make_fetch(['code'=>'transport_unavailable', 'detail'=>'http_404'], 200, $calls));
cmp_check($res['transport_paused'] ?? false, true, 'transport: execute reports the pause');
cmp_null($res['outcome'], 'transport: row outcome untouched by the outage');
cmp_check($res['next_read_at'], $now + 6 * $H, 'transport: row parked for the 6h probe backoff');
cmp_check($res['retry_count'], 0, 'transport: no per-link retry burn');
$tr = molavi_link_check_transport_state();
cmp_true($tr['paused'], 'transport: pause option set');
cmp_check($tr['failures'], 1, 'transport: first failure counted once');
cmp_check($tr['next_retry_at'], $now + 6 * $H, 'transport: next probe in 6h');
// The tick claims nothing while paused.
set_next_at('t1', $now + 60);
$calls = ['post'=>0, 'channel'=>0];
molavi_domain_supplier_link_check_tick($now + 60, make_fetch(['code'=>'transport_unavailable', 'detail'=>'http_404'], 200, $calls));
cmp_check($calls['post'], 0, 'transport: tick performs no fetch while paused');
cmp_check(raw_row('t1')['next_at'], $now + 60, 'transport: tick leaves the paused row alone');
// Second failure at the probe time lengthens the backoff to 12h.
set_next_at('t1', $now + 6 * $H);
$calls = ['post'=>0, 'channel'=>0];
molavi_domain_supplier_link_check_tick($now + 6 * $H, make_fetch(['code'=>'transport_unavailable', 'detail'=>'http_404'], 200, $calls));
$tr = molavi_link_check_transport_state();
cmp_check($tr['failures'], 2, 'transport: second failure counted');
cmp_check($tr['next_retry_at'], $now + 6 * $H + 12 * $H, 'transport: backoff lengthens to 12h');
cmp_check(raw_row('t1')['next_at'], $now + 6 * $H + 12 * $H, 'transport: row parked for the 12h probe backoff');
// A served fetch clears the pause and the check proceeds normally.
set_next_at('t1', $now + 18 * $H);
$calls = ['post'=>0, 'channel'=>0];
molavi_domain_supplier_link_check_tick($now + 18 * $H, make_fetch(['status'=>200, 'body'=>post_body($MSG_STORED)], 200, $calls));
$tr = molavi_link_check_transport_state();
cmp_false($tr['paused'], 'transport: served fetch clears the pause');
cmp_check($tr['failures'], 0, 'transport: failures reset on recovery');
cmp_check($calls['post'], 1, 'transport: recovery tick actually fetched');
cmp_check(raw_row('t1')['outcome'], 'unchanged', 'transport: recovery check records unchanged');
// A per-link fetch_failed while healthy never sets the pause.
set_next_at_all($now + 30 * $D);
set_next_at('r9', $duePast);
$tmp = ['post'=>0, 'channel'=>0];
$res = molavi_domain_supplier_link_check_run_one($now + 19 * $H, make_fetch(['status'=>500, 'body'=>''], 200, $tmp));
cmp_check($res['reason_code'], 'fetch_failed', 'transport: per-link failure still classifies fetch_failed');
cmp_false(molavi_link_check_transport_state()['paused'], 'transport: per-link failure does not pause');

// --- (E) Lists: escalated / transport / settings ride along. ---
$lists = molavi_domain_supplier_link_check_lists();
cmp_true(($lists['counts']['escalated'] ?? 0) >= 5, 'lists: escalated count present (>=5 still escalated)');
cmp_true(in_array('r2', array_column($lists['escalated'], 'product_id'), true), 'lists: escalated list names r2');
cmp_true(isset($lists['transport']['paused']), 'lists: transport block present');
cmp_false($lists['transport']['paused'], 'lists: transport not paused after recovery');
cmp_true(isset($lists['settings']['changed_reread_days']), 'lists: settings present');
cmp_check($lists['settings_version'], molavi_link_check_settings_version(molavi_link_check_settings()), 'lists: settings version matches');
cmp_check($lists['counts']['total'], 25, 'lists: total rows (24 products + legacy1)');

echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
