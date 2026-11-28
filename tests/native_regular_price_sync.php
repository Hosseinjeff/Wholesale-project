<?php
// Regular-price catalogue/sheet sync (owner-approved 2026-11-26): the
// WooCommerce catalogue regular price (from the reference catalog) and
// the Products sheet Consumer Price are the SAME value, kept in sync.
// Catalogue wins by default; a human sheet edit that is newer than the
// catalogue price wins; a price in only one place is synced to the other;
// a price in NEITHER place is left alone (never invented).
//
// Cases (FIXED mode):
//   Decision (pure function):
//   (a) offer-derived sheet price differs from the catalogue -> catalogue
//       wins, write it to the sheet.
//   (b) human sheet price, system's last write newer than the catalogue
//       update -> human wins, no sheet write.
//   (c) human sheet price, catalogue update newer than the system's last
//       write -> catalogue wins (human not proven newer).
//   (d) catalogue only -> catalogue wins, write it to the sheet.
//   (e) sheet only (offer-derived) -> sheet price is the regular price.
//   (f) sheet only (human) -> human price is the regular price.
//   (g) price in NEITHER place -> no_price, left alone, nothing invented.
//   (h) both agree -> in_sync, nothing written.
//   (i) known human price, catalogue unchanged -> human retained.
//   (j) known human price, catalogue price CHANGED -> catalogue wins.
//   Tick (in-memory sheet + SQLite ledger):
//   (k) dry-run writes NOTHING but reports every decision.
//   (l) per-tick cap (7 need a write, cap 2 -> 2 written, 4 over cap).
//   (m) live tick: catalogue prices written to the sheet (p1, p3, p4),
//       human price kept (p2), sheet-only kept (p5), no-price left alone
//       (p6), in-sync untouched (p7); ledger rows recorded; a projection
//       job queued so WooCommerce picks the new regular price up.
//   (n) second tick is idempotent: written 0, nothing re-written.
//   (o) evidence: a synced human price (p2) and a sheet-only price (p5)
//       count as CURRENT via the sync-ledger deadline; a no-price product
//       (p6) is still missing.
//   (p) the 'regular_price_sync' API action (dry_run true/false, bad body
//       rejected).
//
// Negative control: run this file in a worktree at the base commit
// 2c5b6f5 WITHOUT the fix (no molavi-domain-regular-price-sync.php):
// it PASSES by proving the sync does NOT exist there — the decide/tick/
// deadline functions are absent and the 'regular_price_sync' API action
// is rejected as invalid_body. A harness that passes on the base is
// worthless; this one asserts the opposite there.
// Run: php tests/native_regular_price_sync.php
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-regular-price-sync-'.bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }

/* Sheets are stubbed in-memory (domain-connectors.php is NOT required, so no HTTP happens). */
function molavi_domain_sheets($names, $raw = false, $force = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['sync_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_cells($name, $updates) {
    foreach ($updates as $update) {
        if (!preg_match('/^([A-Z]+)(\d+)$/D', $update['range'], $m)) throw new RuntimeException('unsupported range '.$update['range']);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col*26 + (ord($ch)-64);
        $GLOBALS['sync_tables'][$name][(int)$m[2]-1][$col-1] = $update['values'][0][0];
    }
}
function molavi_domain_sheet_column($number) {
    $out = '';
    while ($number > 0) { $number--; $out = chr(65+$number%26).$out; $number = intdiv($number, 26); }
    return $out;
}

foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-reference-store',
    'domain-reference-matching', 'domain-reference-scheduler', 'domain-sheet-conflicts', 'domain-products', 'domain-publisher',
    'domain-jobs', 'domain-source-projection', 'domain-supplier-link-checks', 'domain-supplier-prices',
    'domain-sale-offers'] as $file)
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$syncFile = __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-regular-price-sync.php';
$isFixed = file_exists($syncFile);
if ($isFixed) require $syncFile;
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

echo 'MODE: '.($isFixed ? 'FIXED (regular-price sync present)' : 'BASE 2c5b6f5 (negative control: no regular-price sync)')."\n";

$now = molavi_domain_timestamp('2026-11-26T12:00:00+00:00');
$nowInt = (int)$now;
$isoNow = '2026-11-26T12:00:00+00:00';
$freshIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 3600);      // catalogue updated 1h ago
$oldIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 2 * 86400);  // 2 days ago
$offerIso = gmdate('Y-m-d\TH:i:s+00:00', $nowInt - 2 * 86400);

if (!$isFixed) {
    // --- NEGATIVE CONTROL: the sync must not exist on the base. ---
    cmp_false(function_exists('molavi_domain_regular_price_sync_decide'), 'base: no sync decide function');
    cmp_false(function_exists('molavi_domain_regular_price_sync_tick'), 'base: no sync tick function');
    cmp_false(function_exists('molavi_domain_regular_price_sync_deadline'), 'base: no sync deadline function');
    cmp_false(function_exists('molavi_domain_regular_price_sync_row'), 'base: no sync ledger read');
    fails_with(static fn() => molavi_domain_supplier_link_check_api('POST', [], ['action' => 'regular_price_sync', 'dry_run' => true], 'wp:1'),
        'invalid_body', 'base: link-check API rejects action regular_price_sync');
    echo "PASS $count FAIL $fail\n";
    exit($fail === 0 ? 0 : 1);
}

// --- FIXED mode: decision-function unit tests (pure, no DB/sheet) ---
function decide(array $over): array {
    global $nowInt;
    $base = ['now' => $nowInt, 'sheet_price' => 0, 'catalogue_price' => 0, 'catalogue_updated_at' => 0.0,
        'snapshot_checked_at' => 0.0, 'offer_price' => 0, 'offer_imported_at' => 0.0, 'ledger' => null];
    return molavi_domain_regular_price_sync_decide(array_merge($base, $over));
}
$freshAt = (float)($nowInt - 3600);
$oldAt = (float)($nowInt - 2 * 86400);
$offerAt = (float)($nowInt - 2 * 86400);
// (a) offer-derived sheet price differs from the catalogue -> catalogue wins.
$d = decide(['sheet_price' => 100000, 'catalogue_price' => 120000, 'catalogue_updated_at' => $freshAt,
    'snapshot_checked_at' => $freshAt, 'offer_price' => 100000, 'offer_imported_at' => $offerAt]);
cmp_check($d['action'], 'catalogue_wins', '(a) catalogue wins over offer-derived sheet price');
cmp_check($d['price'], 120000, '(a) catalogue price 120000');
cmp_true($d['write_sheet'], '(a) catalogue price written to the sheet');
cmp_check($d['sheet_price'], 120000, '(a) sheet price becomes 120000');
// (b) human sheet price, system's last write newer than the catalogue -> human wins.
$d = decide(['sheet_price' => 110000, 'catalogue_price' => 100000, 'catalogue_updated_at' => $oldAt,
    'snapshot_checked_at' => $freshAt, 'offer_price' => 100000, 'offer_imported_at' => $offerAt,
    'ledger' => ['sheet_price' => 100000, 'sheet_price_source' => 'offer', 'sheet_written_at' => $freshAt,
        'catalogue_price' => 100000, 'regular_price_deadline' => 0, 'human_observed_at' => null]]);
cmp_check($d['action'], 'human_wins', '(b) newer human sheet price wins');
cmp_check($d['price'], 110000, '(b) human price 110000');
cmp_false($d['write_sheet'], '(b) human price is NOT written to the sheet (already there)');
cmp_check($d['reason'], 'human_newer', '(b) reason human_newer');
// (c) human sheet price, catalogue update newer than the system's last write -> catalogue wins.
$d = decide(['sheet_price' => 110000, 'catalogue_price' => 100000, 'catalogue_updated_at' => $freshAt,
    'snapshot_checked_at' => $freshAt, 'offer_price' => 100000, 'offer_imported_at' => $offerAt,
    'ledger' => ['sheet_price' => 100000, 'sheet_price_source' => 'offer', 'sheet_written_at' => $oldAt,
        'catalogue_price' => 100000, 'regular_price_deadline' => 0, 'human_observed_at' => null]]);
cmp_check($d['action'], 'catalogue_wins', '(c) stale human price does NOT overwrite the catalogue');
cmp_check($d['price'], 100000, '(c) catalogue price 100000');
cmp_true($d['write_sheet'], '(c) catalogue price written to the sheet');
cmp_check($d['reason'], 'human_not_proven_newer', '(c) reason human_not_proven_newer');
// (d) catalogue only.
$d = decide(['sheet_price' => 0, 'catalogue_price' => 100000, 'catalogue_updated_at' => $freshAt,
    'snapshot_checked_at' => $freshAt]);
cmp_check($d['action'], 'catalogue_only', '(d) catalogue-only price synced to the sheet');
cmp_check($d['price'], 100000, '(d) catalogue price 100000');
cmp_true($d['write_sheet'], '(d) catalogue price written to the sheet');
// (e) sheet only, offer-derived.
$d = decide(['sheet_price' => 100000, 'catalogue_price' => 0, 'offer_price' => 100000, 'offer_imported_at' => $offerAt]);
cmp_check($d['action'], 'sheet_only', '(e) sheet-only offer price is the regular price');
cmp_check($d['price'], 100000, '(e) sheet price 100000');
cmp_false($d['write_sheet'], '(e) no sheet write needed');
// (f) sheet only, human.
$d = decide(['sheet_price' => 110000, 'catalogue_price' => 0, 'offer_price' => 100000, 'offer_imported_at' => $offerAt]);
cmp_check($d['action'], 'sheet_only', '(f) sheet-only human price is the regular price');
cmp_check($d['price'], 110000, '(f) human price 110000');
cmp_check($d['reason'], 'sheet_only_human', '(f) reason sheet_only_human');
// (g) price in NEITHER place -> left alone, nothing invented.
$d = decide([]);
cmp_check($d['action'], 'no_price', '(g) no price anywhere -> no_price');
cmp_check($d['price'], 0, '(g) no price invented');
cmp_false($d['write_sheet'], '(g) nothing written');
// (h) both agree.
$d = decide(['sheet_price' => 100000, 'catalogue_price' => 100000, 'catalogue_updated_at' => $freshAt,
    'snapshot_checked_at' => $freshAt, 'offer_price' => 100000, 'offer_imported_at' => $offerAt]);
cmp_check($d['action'], 'in_sync', '(h) agreeing prices are in sync');
cmp_false($d['write_sheet'], '(h) nothing written');
// (i) known human price, catalogue unchanged -> human retained.
$d = decide(['sheet_price' => 110000, 'catalogue_price' => 100000, 'catalogue_updated_at' => $oldAt,
    'snapshot_checked_at' => $freshAt, 'offer_price' => 100000, 'offer_imported_at' => $offerAt,
    'ledger' => ['sheet_price' => 110000, 'sheet_price_source' => 'human', 'sheet_written_at' => $oldAt,
        'catalogue_price' => 100000, 'regular_price_deadline' => $nowInt + 7 * 86400, 'human_observed_at' => $oldAt]]);
cmp_check($d['action'], 'human_retained', '(i) known human price retained while catalogue unchanged');
cmp_check($d['price'], 110000, '(i) human price 110000');
// (j) known human price, catalogue price CHANGED -> catalogue wins.
$d = decide(['sheet_price' => 110000, 'catalogue_price' => 105000, 'catalogue_updated_at' => $freshAt,
    'snapshot_checked_at' => $freshAt, 'offer_price' => 100000, 'offer_imported_at' => $offerAt,
    'ledger' => ['sheet_price' => 110000, 'sheet_price_source' => 'human', 'sheet_written_at' => $oldAt,
        'catalogue_price' => 100000, 'regular_price_deadline' => $nowInt + 7 * 86400, 'human_observed_at' => $oldAt]]);
cmp_check($d['action'], 'catalogue_wins', '(j) fresh catalogue price beats a known human price');
cmp_check($d['price'], 105000, '(j) new catalogue price 105000');
cmp_check($d['reason'], 'catalogue_price_changed', '(j) reason catalogue_price_changed');

// --- FIXED mode: tick fixtures (in-memory sheet + SQLite ledger) ---
function product_record(string $id, string $sale, string $consumer, string $refId, string $offerId, string $approval = 'owner_approved'): array {
    return ['Product ID' => $id, 'Product Name' => 'Item '.$id, 'Channel Username' => 'supplier', 'Channel' => 'supplier',
        'Latest Offer ID' => $offerId, 'Sale Price' => $sale, 'Consumer Price' => $consumer,
        'Status' => 'imported', 'Message Timestamp' => '2026-11-20T08:00:00+00:00', 'Source Timestamp Basis' => '',
        'Source Post URL' => 'https://t.me/supplier/'.substr($id, 1), 'Original Message' => 'Item '.$id.' '.$sale,
        'Stock Status' => 'Unknown', 'Reference Snapshot ID' => 'snap-1', 'Reference Match ID' => $refId,
        'Reference Approval State' => $approval];
}
function offer_record(string $id, string $consumer, string $importedAt): array {
    return ['Offer ID' => 'owner-confirm:'.hash('sha256', $id), 'Product ID' => $id, 'Consumer Price' => $consumer,
        'Import Timestamp' => $importedAt, 'Sale Price' => '80000', 'Message Timestamp' => '2026-11-20T08:00:00+00:00',
        'Source Timestamp Basis' => '', 'Source Post URL' => 'https://t.me/supplier/'.substr($id, 1)];
}
function ref_row(string $name, int $consumer, string $refId, string $updatedAt): array {
    $row = array_fill(0, 21, ''); $row[0] = $name; $row[5] = (string)$consumer; $row[16] = $refId; $row[20] = $updatedAt; return $row;
}
function seed_sheets(): void {
    global $offerIso;
    $products = [
        product_record('p1', '80000', '100000', 'ref-p1', 'owner-confirm:'.hash('sha256', 'p1')), // (a) catalogue wins
        product_record('p2', '80000', '110000', 'ref-p2', 'owner-confirm:'.hash('sha256', 'p2')), // (b) human wins
        product_record('p3', '80000', '110000', 'ref-p3', 'owner-confirm:'.hash('sha256', 'p3')), // (c) catalogue wins
        product_record('p4', '80000', '', 'ref-p4', ''),                                        // (d) catalogue only
        product_record('p5', '80000', '100000', '', 'owner-confirm:'.hash('sha256', 'p5')),       // (e) sheet only
        product_record('p6', '80000', '', '', ''),                                               // (g) no price anywhere
        product_record('p7', '80000', '100000', 'ref-p7', 'owner-confirm:'.hash('sha256', 'p7')), // (h) in sync
    ];
    $offers = [
        offer_record('p1', '100000', $offerIso),
        offer_record('p2', '100000', $offerIso),
        offer_record('p3', '100000', $offerIso),
        offer_record('p5', '100000', $offerIso),
        offer_record('p7', '100000', $offerIso),
    ];
    $pHeaders = molavi_domain_product_headers();
    $oHeaders = molavi_domain_offer_headers();
    $GLOBALS['sync_tables'] = [
        'Products' => array_merge([$pHeaders], array_map(
            static fn($r) => array_map(static fn($h) => $r[$h] ?? '', $pHeaders), $products)),
        'Offers' => array_merge([$oHeaders], array_map(
            static fn($r) => array_map(static fn($h) => $r[$h] ?? '', $oHeaders), $offers)),
        'Shop' => [],
    ];
}
function seed_ledger_row(string $id, int $sheetPrice, string $sheetSource, float $sheetWrittenAt, int $catPrice, float $catAt, int $regPrice, string $regSource, float $deadline): void {
    global $nowInt;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $sheetPrice, $sheetSource, $sheetWrittenAt, $catPrice, $catAt, $regPrice, $regSource, $deadline, $nowInt): void {
        molavi_domain_sql($db, 'INSERT INTO regular_price_sync_state
            (product_id, sheet_price, sheet_price_source, sheet_written_at, catalogue_price, catalogue_updated_at,
             reference_id, snapshot_id, regular_price, regular_price_source, regular_price_deadline, human_observed_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,?)',
            [$id, $sheetPrice, $sheetSource, $sheetWrittenAt, $catPrice, $catAt, 'ref-'.$id, 'snap-1',
             $regPrice, $regSource, $deadline, (float)$nowInt]);
    });
}
function reset_state(): void {
    global $nowInt;
    seed_sheets();
    molavi_domain_tx('pipeline', static function (SQLite3 $db): void {
        molavi_domain_sql($db, 'DELETE FROM regular_price_sync_state');
        molavi_domain_sql($db, "DELETE FROM jobs WHERE id LIKE 'publish:sheet-change:%'");
    });
    // p2: system's last write 1h ago (newer than its old catalogue update) -> human wins.
    seed_ledger_row('p2', 100000, 'offer', (float)($nowInt - 3600), 100000, (float)($nowInt - 2 * 86400),
        100000, 'catalogue', (float)($nowInt + 23 * 3600));
    // p3: system's last write 2d ago (older than its fresh catalogue update) -> catalogue wins.
    seed_ledger_row('p3', 100000, 'offer', (float)($nowInt - 2 * 86400), 100000, (float)($nowInt - 3600),
        100000, 'catalogue', (float)($nowInt + 23 * 3600));
}
function db_row(string $store, string $sql, array $params = []): ?array {
    return molavi_domain_read($store, static fn(SQLite3 $db) => molavi_domain_row($db, $sql, $params));
}
function db_count(string $store, string $sql, array $params = []): int {
    return (int)(db_row($store, $sql, $params)['n'] ?? 0);
}
function sheet_consumer(string $id): int {
    foreach (molavi_domain_records($GLOBALS['sync_tables']['Products']) as $r)
        if ((string)$r['Product ID'] === $id) return molavi_domain_positive_price($r['Consumer Price'] ?? null);
    return -1;
}
function record_for(string $id): array {
    foreach (molavi_domain_records($GLOBALS['sync_tables']['Products']) as $r)
        if ((string)$r['Product ID'] === $id) return $r;
    return [];
}
function offer_for(string $id): array {
    foreach (molavi_domain_records($GLOBALS['sync_tables']['Offers']) as $r)
        if ((string)$r['Product ID'] === $id) return $r;
    return [];
}

// Reference catalog snapshot snap-1 (fresh, checked 1h ago).
$refRows = [
    ref_row('Item p1', 120000, 'ref-p1', $freshIso), // catalogue 120000 vs sheet 100000
    ref_row('Item p2', 100000, 'ref-p2', $oldIso),   // catalogue update 2d ago (old)
    ref_row('Item p3', 100000, 'ref-p3', $freshIso), // catalogue update 1h ago (fresh)
    ref_row('Item p4', 100000, 'ref-p4', $freshIso),
    ref_row('Item p7', 100000, 'ref-p7', $freshIso),
];
$payload = json_encode(['schema_version' => 1, 'status' => 'staged', 'extracted_at' => $freshIso, 'rows' => $refRows],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
molavi_domain_tx('reference', static function (SQLite3 $db) use ($payload, $freshIso): void {
    molavi_domain_sql($db, 'INSERT INTO reference_snapshots (snapshot_id,payload) VALUES (?,?)', ['snap-1', $payload]);
    molavi_domain_sql($db, 'INSERT INTO reference_active (singleton,snapshot_id) VALUES (1,?)', ['snap-1']);
    molavi_domain_sql($db, 'INSERT INTO reference_source_check (singleton,snapshot_id,checked_at) VALUES (1,?,?)', ['snap-1', $freshIso]);
});

// --- (k) dry-run writes NOTHING but reports every decision ---
reset_state();
$dry = molavi_domain_regular_price_sync_tick($nowInt, $isoNow, true);
cmp_true($dry['dry_run'], '(k) dry-run flag');
cmp_check($dry['scanned'], 7, '(k) dry-run scanned 7 products');
cmp_check($dry['written'], 0, '(k) dry-run writes 0');
cmp_check($dry['sheet_written'], 0, '(k) dry-run sheet writes 0');
cmp_check($dry['actions'], ['catalogue_wins' => 2, 'human_wins' => 1, 'catalogue_only' => 1, 'sheet_only' => 1, 'no_price' => 1, 'in_sync' => 1],
    '(k) dry-run actions: 2 catalogue_wins (p1,p3), 1 human_wins (p2), 1 catalogue_only (p4), 1 sheet_only (p5), 1 no_price (p6), 1 in_sync (p7)');
cmp_check(sheet_consumer('p1'), 100000, '(k) dry-run: p1 sheet unchanged');
cmp_check(sheet_consumer('p3'), 110000, '(k) dry-run: p3 sheet unchanged');
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM regular_price_sync_state'), 2, '(k) dry-run: ledger still only the 2 seeded rows');
cmp_check(db_count('pipeline', "SELECT COUNT(*) n FROM jobs WHERE id LIKE 'publish:sheet-change:%'"), 0, '(k) dry-run: no projection job');

// --- (l) per-tick cap: 6 need a write, cap 2 -> 2 written, 4 over cap ---
reset_state();
$cap = molavi_domain_regular_price_sync_tick($nowInt, $isoNow, false, 2);
cmp_check($cap['written'], 2, '(l) cap 2 writes 2');
cmp_check($cap['over_cap'], 4, '(l) cap 2 leaves 4 over cap (p3,p4,p5,p7)');
cmp_check($cap['sheet_written'], 1, '(l) cap 2 sheet-writes 1 (p1 only; p2 is a ledger-only human win)');
cmp_check(sheet_consumer('p1'), 120000, '(l) p1 sheet written to 120000 under the cap');
cmp_check(sheet_consumer('p3'), 110000, '(l) p3 sheet NOT written under the cap (over cap)');

// --- (m) live tick: full sync ---
reset_state();
$tick = molavi_domain_regular_price_sync_tick($nowInt, $isoNow, false);
cmp_check($tick['scanned'], 7, '(m) live tick scanned 7');
cmp_check($tick['written'], 6, '(m) live tick writes 6 (all but p6 no_price)');
cmp_check($tick['over_cap'], 0, '(m) live tick over cap 0');
cmp_check($tick['sheet_written'], 3, '(m) live tick sheet-writes 3 (p1, p3, p4)');
cmp_check($tick['sheet_failures'], [], '(m) live tick sheet failures none');
cmp_check($tick['actions'], ['catalogue_wins' => 2, 'human_wins' => 1, 'catalogue_only' => 1, 'sheet_only' => 1, 'no_price' => 1, 'in_sync' => 1],
    '(m) live tick actions match the dry-run');
// Sheet values after the sync.
cmp_check(sheet_consumer('p1'), 120000, '(m) p1 sheet now 120000 (catalogue wins)');
cmp_check(sheet_consumer('p2'), 110000, '(m) p2 sheet stays 110000 (human wins, not overwritten)');
cmp_check(sheet_consumer('p3'), 100000, '(m) p3 sheet now 100000 (stale human price overwritten by catalogue)');
cmp_check(sheet_consumer('p4'), 100000, '(m) p4 sheet now 100000 (catalogue-only synced in)');
cmp_check(sheet_consumer('p5'), 100000, '(m) p5 sheet stays 100000 (sheet-only, nothing to write)');
cmp_check(sheet_consumer('p6'), 0, '(m) p6 sheet stays empty (no price invented)');
cmp_check(sheet_consumer('p7'), 100000, '(m) p7 sheet stays 100000 (in sync)');
// Ledger rows after the sync.
cmp_check(db_count('pipeline', 'SELECT COUNT(*) n FROM regular_price_sync_state'), 6, '(m) ledger has 6 rows (all but p6)');
$ledP1 = db_row('pipeline', 'SELECT * FROM regular_price_sync_state WHERE product_id=?', ['p1']);
cmp_check((int)($ledP1['regular_price'] ?? 0), 120000, '(m) p1 ledger regular price 120000');
cmp_check($ledP1['regular_price_source'] ?? null, 'catalogue', '(m) p1 ledger source catalogue');
cmp_check($ledP1['sheet_price_source'] ?? null, 'catalogue', '(m) p1 ledger sheet source catalogue');
$ledP2 = db_row('pipeline', 'SELECT * FROM regular_price_sync_state WHERE product_id=?', ['p2']);
cmp_check((int)($ledP2['regular_price'] ?? 0), 110000, '(m) p2 ledger regular price 110000 (human)');
cmp_check($ledP2['regular_price_source'] ?? null, 'sheet', '(m) p2 ledger source sheet');
cmp_check($ledP2['sheet_price_source'] ?? null, 'human', '(m) p2 ledger sheet source human');
cmp_true((float)($ledP2['regular_price_deadline'] ?? 0) > $nowInt, '(m) p2 ledger deadline in the future (14d)');
cmp_check((int)($ledP2['sheet_price'] ?? 0), 110000, '(m) p2 ledger sheet price 110000');
$ledP6 = db_row('pipeline', 'SELECT * FROM regular_price_sync_state WHERE product_id=?', ['p6']);
cmp_null($ledP6, '(m) p6 has NO ledger row (no price anywhere)');
// Projection triggered so WooCommerce picks the new regular price up.
cmp_check(db_count('pipeline', "SELECT COUNT(*) n FROM jobs WHERE id LIKE 'publish:sheet-change:%'"), 1, '(m) one projection job queued');
cmp_true($tick['publish_job'] !== null, '(m) tick reports the projection job id');

// --- (n) second tick is idempotent ---
$tick2 = molavi_domain_regular_price_sync_tick($nowInt, $isoNow, false);
cmp_check($tick2['written'], 0, '(n) second tick writes 0');
cmp_check($tick2['sheet_written'], 0, '(n) second tick sheet-writes 0');
cmp_check($tick2['over_cap'], 0, '(n) second tick over cap 0');
cmp_check($tick2['actions'], ['human_retained' => 1, 'in_sync' => 4, 'sheet_only' => 1, 'no_price' => 1],
    '(n) second tick actions: p2 human_retained, p1/p3/p4/p7 in_sync, p5 sheet_only (no catalogue price), p6 no_price');
cmp_check(sheet_consumer('p1'), 120000, '(n) p1 sheet still 120000 after second tick');
cmp_check(sheet_consumer('p2'), 110000, '(n) p2 sheet still 110000 after second tick');

// --- (o) evidence: synced prices count as CURRENT via the sync deadline ---
$evP2 = molavi_domain_regular_price_evidence(record_for('p2'), offer_for('p2'), null, $nowInt);
cmp_check($evP2['state'], 'current', '(o) p2 human price counts as current via the sync deadline');
cmp_check($evP2['amount'], 110000, '(o) p2 evidence amount 110000');
cmp_true($evP2['expires_at'] > $nowInt, '(o) p2 evidence expiry in the future');
$evP5 = molavi_domain_regular_price_evidence(record_for('p5'), offer_for('p5'), null, $nowInt);
cmp_check($evP5['state'], 'current', '(o) p5 sheet-only price counts as current via the sync deadline');
cmp_check($evP5['amount'], 100000, '(o) p5 evidence amount 100000');
$evP6 = molavi_domain_regular_price_evidence(record_for('p6'), [], null, $nowInt);
cmp_check($evP6['state'], 'missing', '(o) p6 no-price product still missing');
cmp_check($evP6['amount'], 0, '(o) p6 evidence amount 0');
// The sync deadline itself: 0 when the sheet price no longer matches the ledger.
cmp_check(molavi_domain_regular_price_sync_deadline(record_for('p2'), 99999, $nowInt), 0, '(o) sync deadline 0 when the sheet price differs from the ledger');
cmp_true(molavi_domain_regular_price_sync_deadline(record_for('p2'), 110000, $nowInt) > $nowInt, '(o) sync deadline in the future for the recorded price');

// --- (p) the 'regular_price_sync' API action ---
$apiDry = molavi_domain_supplier_link_check_api('POST', [], ['action' => 'regular_price_sync', 'dry_run' => true], 'wp:1');
cmp_true($apiDry['dry_run'], '(p) API regular_price_sync dry_run=true reports dry run');
cmp_check($apiDry['written'], 0, '(p) API dry-run writes 0');
$api = molavi_domain_supplier_link_check_api('POST', [], ['action' => 'regular_price_sync', 'dry_run' => false], 'wp:1');
cmp_check($api['written'], 0, '(p) API regular_price_sync after the live tick writes 0 (idempotent)');
fails_with(static fn() => molavi_domain_supplier_link_check_api('POST', [], ['action' => 'regular_price_sync', 'dry_run' => 'yes'], 'wp:1'),
    'invalid_body', '(p) API regular_price_sync rejects non-bool dry_run');
fails_with(static fn() => molavi_domain_supplier_link_check_api('POST', [], ['action' => 'regular_price_sync', 'extra' => 1], 'wp:1'),
    'invalid_body', '(p) API regular_price_sync rejects extra keys');

// --- (q) Q2: an auto-adopted match flows through the sync like a manual accept ---
// Reconcile writes Reference Approval State 'approved_auto_adopted' for a
// >5% move it adopts; the sync tick must treat that state as approved so
// the new catalogue price reaches the sheet Consumer Price (and WooCommerce)
// with no separate write step. Control: an unapproved state must not flow.
reset_state();
$approvalCol = array_search('Reference Approval State', $GLOBALS['sync_tables']['Products'][0], true);
molavi_domain_sheet_cells('Products', [['range' => molavi_domain_sheet_column($approvalCol + 1) . '2', 'values' => [['approved_auto_adopted']]]]);
$tickQ = molavi_domain_regular_price_sync_tick($nowInt, $isoNow, false);
cmp_check(sheet_consumer('p1'), 120000, '(q) approved_auto_adopted: p1 sheet written to the new catalogue price 120000');
cmp_check($tickQ['actions']['catalogue_wins'] ?? 0, 2, '(q) approved_auto_adopted: 2 catalogue_wins (p1, p3)');
reset_state();
molavi_domain_sheet_cells('Products', [['range' => molavi_domain_sheet_column($approvalCol + 1) . '2', 'values' => [['outside_margin']]]]);
$tickQ2 = molavi_domain_regular_price_sync_tick($nowInt, $isoNow, false);
cmp_check(sheet_consumer('p1'), 100000, '(q) outside_margin control: p1 sheet NOT overwritten by the catalogue price');
cmp_check($tickQ2['actions']['catalogue_wins'] ?? 0, 1, '(q) outside_margin control: only p3 catalogue-wins (p1 falls back to sheet-only)');
echo "PASS $count FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
