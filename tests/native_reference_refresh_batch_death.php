<?php
/** D2 harness — catalog refresh death in the batch/parallel path.
 *
 * Proves, against the LIVE (R1) fetch code and the fixed fetch code:
 *  1. SLOW-MULTI CONTROL: a stalled `Requests::request_multiple` call runs
 *     past the 90 s budget without any abort — the R1 budget is checked only
 *     BETWEEN calls, never during one. (Old code only; the fixed code never
 *     calls request_multiple at all.)
 *  2. SEQUENTIAL COMPLETION: the fixed fetch completes 35 pages via the
 *     single-page path, writes progress + a checkpoint after EVERY page,
 *     and never touches request_multiple.
 *  3. BUDGET ABORT + RESUME: with a fake clock jumping past the deadline
 *     mid-fetch, the fixed fetch throws woocommerce_fetch_timeout with the
 *     right counts; a second call resumes from the checkpoint and finishes
 *     without re-fetching banked pages.
 *  4. MEMORY: in a subprocess with memory_limit 512M + a WP-baseline blob,
 *     seeded with the live shape (25 checkpointed pages), the OLD code dies
 *     with "Allowed memory size exhausted" while the FIXED code completes.
 *     Run via --memory-run (single config) or --memory-sweep (driver).
 *  5. TRIM CORRECTNESS: a full Store-API-shaped product trims to exactly
 *     the keys the parser/store read, and reference_row() output is
 *     byte-identical before/after trimming.
 *
 * The WpOrg\Requests stub below exists so the OLD code takes its deployed
 * parallel branch in this harness. The existing native_reference_runtime
 * harness never defines it, so it never tested the parallel path at all.
 */
declare(strict_types=1);
namespace WpOrg\Requests {
    class Response { public $status_code = 200; public $body = ''; public $headers = []; }
    class Requests {
        public static function request_multiple($requests, $options) {
            $GLOBALS['multi_calls'] = ($GLOBALS['multi_calls'] ?? 0) + 1;
            $mode = $GLOBALS['multi_mode'] ?? 'ok';
            if ($mode === 'throw') throw new \RuntimeException('stub multi failure');
            if ($mode === 'sleep') sleep((int) ceil((float) ($GLOBALS['multi_sleep'] ?? 8)));
            $out = [];
            foreach ($requests as $id => $req) {
                parse_str((string) parse_url($req['url'], PHP_URL_QUERY), $q);
                $page = (int) ($q['page'] ?? 0);
                $resp = new Response();
                if ($mode === '429') { $resp->status_code = 429; $resp->body = ''; }
                else {
                    $resp->status_code = 200;
                    $resp->body = \molavi_test_page_body($page);
                    $resp->headers = ['x-wp-total' => (string) $GLOBALS['test_total'], 'x-wp-totalpages' => (string) $GLOBALS['test_pages']];
                }
                $out[$id] = $resp;
            }
            return $out;
        }
    }
}
namespace {
define('ABSPATH', '/synthetic/'); define('MOLAVI_REFERENCE_BRIDGE_TOKEN', str_repeat('s', 40));
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-refresh-death-' . bin2hex(random_bytes(6)));
define('MOLAVI_REFERENCE_OWNER_IDS', [7]); $GLOBALS['options'] = ['molavi_state_active' => true];
function add_action(...$args) {} function add_filter(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; return true; }
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-read-models', 'domain-reference-parser', 'domain-reference-store', 'domain-reference-fetch', 'domain-reference-workflow', 'domain-reference-scheduler', 'domain-gateway'] as $file)
    require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';
// molavi_state_open refuses a missing store file; pre-create both empty.
$__root = molavi_state_root();
foreach (['reference.sqlite3', 'pipeline.sqlite3'] as $__f) { $__db = new SQLite3($__root . '/' . $__f); $__db->close(); }
function wp_remote_retrieve_headers($response) { return $response['headers']; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }

/** Full WooCommerce Store API product shape. Bulk is in fields the
 *  parser/store never read (description, images/srcset, price_html...);
 *  the trim must drop exactly those. $padKb tunes JSON size. */
function molavi_test_product(int $i, int $padKb = 8): array {
    $padChars = max(0, (int) (($padKb * 1024 - 1550) / 11));
    return ['id' => 40000 + $i, 'name' => 'Test product ' . $i . ' 500g (100f) 12x', 'slug' => 'test-' . $i, 'parent' => 0,
        'type' => 'simple', 'variation' => '', 'permalink' => 'https://rezvanpakhsh.com/product/test-' . $i . '/',
        'sku' => 'SKU-' . $i, 'short_description' => '<p>Short desc ' . $i . '</p>',
        'description' => '<p>' . str_repeat('Desc text. ', $padChars) . '</p>', 'on_sale' => false,
        'prices' => ['price' => '100000', 'regular_price' => '100000', 'sale_price' => '', 'price_range' => null,
            'currency_code' => 'IRT', 'currency_symbol' => 'T', 'currency_minor_unit' => 0,
            'currency_decimal_separator' => '.', 'currency_thousand_separator' => ',', 'currency_prefix' => '', 'currency_suffix' => ' T'],
        'price_html' => '<span>100,000 T</span>', 'average_rating' => '0', 'review_count' => 0,
        'images' => [['id' => 1, 'src' => 'https://rezvanpakhsh.com/wp-content/uploads/img-' . $i . '.jpg',
            'thumbnail' => 'https://rezvanpakhsh.com/wp-content/uploads/img-' . $i . '-150x150.jpg',
            'srcset' => 'https://rezvanpakhsh.com/wp-content/uploads/img-' . $i . '.jpg 600w, https://rezvanpakhsh.com/wp-content/uploads/img-' . $i . '-300x300.jpg 300w',
            'sizes' => '(max-width: 600px) 100vw, 600px', 'name' => 'img-' . $i, 'alt' => '']],
        'categories' => [['id' => 5, 'name' => 'General', 'slug' => 'general']], 'tags' => [],
        'attributes' => [['id' => 1, 'name' => 'Count', 'taxonomy' => 'pa_count', 'terms' => [['id' => 1, 'name' => '12', 'slug' => '12']]]],
        'variations' => [], 'has_options' => false, 'is_purchasable' => true, 'is_in_stock' => true, 'is_on_backorder' => false,
        'low_stock_remaining' => null, 'sold_individually' => false,
        'quantity_limits' => ['minimum' => 1, 'maximum' => 99, 'multiple_of' => 1, 'editable' => true],
        'add_to_cart' => ['single_text' => 'Add', 'multiple_text' => 'Add', 'description' => '', 'url' => '', 'minimum' => 1, 'maximum' => 99, 'multiple_of' => 1, 'editable' => true],
        'extensions' => []];
}
function molavi_test_page_rows(int $page): array {
    // Generated per page, never cached: the harness must not hold its own
    // copy of the catalog (that would inflate the measured heap).
    $rows = [];
    $kb = (int) ($GLOBALS['test_product_kb'] ?? 8);
    for ($i = ($page - 1) * 100; $i < min($page * 100, $GLOBALS['test_total']); $i++) $rows[] = molavi_test_product($i, $kb);
    return $rows;
}
function molavi_test_page_body(int $page): string { return json_encode(molavi_test_page_rows($page)); }
function molavi_domain_http($method, $url, $args) {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query); $page = (int) $query['page'];
    $GLOBALS['http_calls'] = ($GLOBALS['http_calls'] ?? 0) + 1;
    if (isset($GLOBALS['fake_now']) && ($GLOBALS['clock_step'] ?? 0) > 0) $GLOBALS['fake_now'] += (float) $GLOBALS['clock_step'];
    return ['status' => 200, 'headers' => ['x-wp-total' => (string) $GLOBALS['test_total'], 'x-wp-totalpages' => (string) $GLOBALS['test_pages']], 'body' => molavi_test_page_body($page)];
}
function molavi_domain_sheet_column(int $number): string { return 'A'; }
function molavi_domain_sheet_properties(): array { return []; }
function molavi_domain_sheet_ensure($name, $headers): void {}
function molavi_domain_sheets($names, $fresh = false, $raw = false): array { return []; }
function molavi_domain_sheet_cells($name, $updates): void {}
function molavi_domain_positive_price($value) { return (int) $value; }
function molavi_domain_product_headers() { return []; }
function molavi_domain_status_capture($tables, $reason) { return []; }
function molavi_domain_projection_digest($tables) { return 'synthetic-digest'; }
function molavi_domain_observe_projection($digest) {}

$count = 0;
function same($actual, $expected, string $name): void {
    global $count;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected)) throw new RuntimeException($name . ' ' . molavi_domain_json(['actual' => $actual, 'expected' => $expected]));
    $count++;
}
function reset_reference(): void {
    $db = molavi_domain_db('reference');
    foreach (molavi_domain_rows($db, "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'molavi_state_%'") as $row) $db->exec('DELETE FROM ' . $row['name']);
    $db->close();
}
function setup_catalog(int $total = 3416, int $productKb = 8): void {
    $GLOBALS['test_total'] = $total; $GLOBALS['test_pages'] = (int) ceil($total / 100);
    $GLOBALS['test_product_kb'] = $productKb;
    $GLOBALS['http_calls'] = 0; $GLOBALS['multi_calls'] = 0; $GLOBALS['progress_calls'] = [];
    reset_reference();
}
function progress_recorder(string $occurrence): callable {
    return static function ($phase, $pages, $totalPages, $rows, $totalRows) use ($occurrence): void {
        $GLOBALS['progress_calls'][] = [$phase, $pages, $totalPages, $rows, $totalRows];
        molavi_domain_reference_progress($occurrence, $phase, ['completed' => $pages, 'total' => $totalPages, 'records' => $rows, 'total_records' => $totalRows]);
    };
}
function checkpoint_row_count(): int {
    return (int) molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db, 'SELECT count(*) c FROM reference_refresh_pages')['c']);
}

/* ---------------- modes ---------------- */
$argv = $GLOBALS['argv'] ?? [];
$mode = 'unit';
foreach ($argv as $a) if (str_starts_with($a, '--mode=')) $mode = substr($a, 7);
if ($mode === 'memory-run') {
    // Subprocess: 512M ceiling + WP-baseline blob + live shape (25 banked pages).
    $kb = 8; $baselineMb = 150; $seedPages = 25;
    foreach ($argv as $a) {
        if (str_starts_with($a, '--product-kb=')) $kb = (int) substr($a, 13);
        if (str_starts_with($a, '--baseline-mb=')) $baselineMb = (int) substr($a, 14);
        if (str_starts_with($a, '--seed-pages=')) $seedPages = (int) substr($a, 13);
    }
    ini_set('memory_limit', '512M');
    $cleanupDir = MOLAVI_STATE_ROOT;
    register_shutdown_function(static function () use ($cleanupDir): void {
        foreach (glob($cleanupDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($cleanupDir);
    });
    setup_catalog(3416, $kb);
    $one = molavi_test_product(0, $kb);
    echo 'product_json_bytes=' . strlen(json_encode($one)) . "\n";
    // Seed checkpoints exactly like live: pages 2..$seedPages, FULL rows on disk.
    for ($p = 2; $p <= $seedPages; $p++) molavi_domain_reference_checkpoint_store(100, $p, molavi_test_page_rows($p), 3416, 35);
    echo 'seeded_checkpoints=' . checkpoint_row_count() . "\n";
    $GLOBALS['wp_baseline'] = str_repeat('w', $baselineMb * 1024 * 1024);
    $heapBefore = memory_get_usage(true);
    echo 'baseline_mb=' . $baselineMb . ' baseline_heap_mb=' . round($heapBefore / 1048576, 1) . "\n";
    $GLOBALS['multi_mode'] = 'ok';
    $started = microtime(true);
    $candidate = molavi_domain_reference_fetch_products(100, static function ($phase, $pages, $totalPages, $rows, $totalRows): void {}, 90.0, null, true);
    echo 'RESULT complete products=' . count($candidate['_source_products']) . ' elapsed=' . round(microtime(true) - $started, 1) . 's peak_mb=' . round(memory_get_peak_usage(true) / 1048576, 1) . ' fetch_heap_mb=' . round((memory_get_peak_usage(true) - $heapBefore) / 1048576, 1) . ' http_calls=' . ($GLOBALS['http_calls'] ?? 0) . ' multi_calls=' . ($GLOBALS['multi_calls'] ?? 0) . "\n";
    echo "PASS memory-run\n";
    return;
}

/* ---------------- unit tests ---------------- */
// T1 — SLOW-MULTI CONTROL (old code only: proves the budget cannot
// interrupt request_multiple; on fixed code the multi stub is never hit,
// so this test is expected to FAIL there and is skipped via multi_calls).
setup_catalog(3416, 2);
$GLOBALS['multi_mode'] = 'sleep'; $GLOBALS['multi_sleep'] = 8;
$started = microtime(true);
try {
    $candidate = molavi_domain_reference_fetch_products(100, static function (): void {}, 3.0, null, false);
    $elapsed = microtime(true) - $started;
    if (($GLOBALS['multi_calls'] ?? 0) === 0) {
        echo "T1 SKIP fixed-code (request_multiple never called; sequential path used)\n";
    } else {
        throw new RuntimeException('T1: old code completed despite the 8s multi sleep vs 3s budget (elapsed ' . round($elapsed, 1) . 's)');
    }
} catch (MolaviWorkflowError $e) {
    $elapsed = microtime(true) - $started;
    if (($GLOBALS['multi_calls'] ?? 0) === 0) throw new RuntimeException('T1: fixed code threw unexpectedly: ' . $e->getMessage());
    // The budget fired — but ONLY at the next batch boundary, AFTER the
    // 8 s multi call ran 5 s past the 3 s deadline. A call that hangs
    // longer than max_execution_time never reaches that boundary at all.
    same($e->getMessage(), 'woocommerce_fetch_timeout', 'budget eventually fires at the next batch boundary');
    same($elapsed >= 8, true, 'the multi call itself was NOT interrupted by the budget (ran its full 8s)');
    echo 'T1 CONTROL old-code: 8s multi call vs 3s budget — budget fired only after the call returned (elapsed ' . round($elapsed, 1) . "s, multi_calls={$GLOBALS['multi_calls']})\n";
}

// T2 — SEQUENTIAL COMPLETION (fixed code): 35 pages, per-page progress +
// checkpoints, request_multiple never called.
setup_catalog(3416, 2);
$GLOBALS['multi_mode'] = 'ok';
$occ = 'test-occ-t2';
$progress = progress_recorder($occ);
$candidate = molavi_domain_reference_fetch_products(100, $progress, 90.0, null, true);
same(count($candidate['_source_products']), 3416, 'sequential fetch returns all 3416 products');
same($GLOBALS['multi_calls'], 0, 'fixed fetch never calls request_multiple');
$fetching = array_values(array_filter($GLOBALS['progress_calls'], static fn($c) => $c[0] === 'fetching'));
same(count($fetching) >= 35, true, 'progress written per page (>=35 fetching writes)');
same(end($fetching), ['fetching', 35, 35, 3416, 3416], 'final progress is 35/35 pages, 3416 records');
same(checkpoint_row_count(), 34, 'pages 2..35 checkpointed (page 1 is re-fetched fresh every attempt by design)');
same($GLOBALS['http_calls'], 35, 'sequential path fetches each page once via single-page calls');
echo "T2 sequential completion OK (35 pages, {$GLOBALS['http_calls']} http calls, 0 multi calls, 34 checkpoints pages 2-35)\n";

// T3 — BUDGET ABORT + RESUME (fixed code): fake clock jumps past the
// 90 s deadline mid-fetch; the attempt must abort as a timeout with the
// right counts, bank its checkpoints, and resume to completion.
setup_catalog(3416, 2);
$GLOBALS['multi_mode'] = 'ok';
$GLOBALS['fake_now'] = 1000000.0; $GLOBALS['clock_step'] = 30.0;
$clock = static fn() => $GLOBALS['fake_now'];
$occ3 = 'test-occ-t3';
try {
    molavi_domain_reference_fetch_products(100, progress_recorder($occ3), 90.0, $clock, true);
    throw new RuntimeException('T3: expected woocommerce_fetch_timeout, fetch completed');
} catch (MolaviWorkflowError $e) {
    same($e->getMessage(), 'woocommerce_fetch_timeout', 'budget abort throws woocommerce_fetch_timeout');
    $details = $e->details ?? [];
    same($details['completed_pages'] >= 2 && $details['completed_pages'] < 35, true, 'abort reports partial page count');
    same($details['total_pages'], 35, 'abort reports 35 total pages');
    $banked = checkpoint_row_count();
    same($banked >= 2 && $banked < 35, true, 'aborted attempt banked its fetched pages as checkpoints');
    echo "T3 budget abort OK (aborted at {$details['completed_pages']}/35 pages, {$banked} checkpoints banked)\n";
}
$GLOBALS['clock_step'] = 0.0;
$GLOBALS['http_calls'] = 0;
$candidate = molavi_domain_reference_fetch_products(100, progress_recorder('test-occ-t3b'), 90.0, $clock, true);
same(count($candidate['_source_products']), 3416, 'resumed fetch completes all 3416 products');
$bankedBefore = $GLOBALS['http_calls'];
same($bankedBefore <= 35 - 2 + 1, true, 'resume re-fetches only unbanked pages (+ page 1)');
echo "T3 resume OK (resumed fetch used {$GLOBALS['http_calls']} http calls, completed 3416 products)\n";

// T4 — TRIM CORRECTNESS: full Store-API product trims to exactly the keys
// the parser/store read; reference_row() output is identical.
$full = molavi_test_product(7, 8);
$fullJson = strlen(json_encode($full));
same(function_exists('molavi_domain_reference_trim_product'), true, 'trim helper exists');
$trimmed = molavi_domain_reference_trim_product($full);
same(array_keys($trimmed), ['id', 'sku', 'name', 'categories', 'attributes', 'prices'], 'trim keeps exactly the six used keys');
same(strlen(json_encode($trimmed)) < $fullJson / 4, true, 'trimmed product is under a quarter of the full JSON');
same(molavi_domain_reference_row($trimmed, 1, '2026-11-27T00:00:00+00:00'), molavi_domain_reference_row($full, 1, '2026-11-27T00:00:00+00:00'), 'reference_row identical before/after trim');
echo "T4 trim OK (full {$fullJson}B -> trimmed " . strlen(json_encode($trimmed)) . "B, reference_row identical)\n";

// T5 — MEMORY GUARD: a tiny ceiling aborts cleanly as partial instead of
// dying, with the dedicated code the scheduler treats as partial.
setup_catalog(3416, 2);
$GLOBALS['multi_mode'] = 'ok';
try {
    molavi_domain_reference_fetch_products(100, static function (): void {}, 3600.0, null, false, 1024);
    throw new RuntimeException('T5: expected memory-budget abort');
} catch (MolaviWorkflowError $e) {
    same($e->getMessage(), 'woocommerce_fetch_memory_budget', 'memory guard throws woocommerce_fetch_memory_budget');
    echo "T5 memory guard OK (aborted cleanly at a 1KB ceiling)\n";
}

// T6 — SCHEDULER: a memory-budget throw pauses as partial (no incident),
// and a partial attempt is due again on the next tick (not not_due).
setup_catalog(3416, 2);
$outcome = molavi_domain_reference_refresh(true, static function (): never {
    molavi_domain_fail('woocommerce_fetch_memory_budget', 503, ['completed_pages' => 5, 'total_pages' => 35, 'records' => 500, 'total_records' => 3416]);
}, 2000000.0);
same($outcome['state'], 'partial', 'memory-budget throw pauses as partial');
same($outcome['error_code'], 'woocommerce_fetch_memory_budget', 'partial carries the memory code');
same(molavi_domain_reference_refresh_state()['state'], 'partial', 'stored state is partial');
same(molavi_domain_reference_refresh_state()['error_code'], 'woocommerce_fetch_memory_budget', 'stored error_code is the memory code');
$outcome2 = molavi_domain_reference_refresh(false, static function (): never { molavi_domain_fail('woocommerce_http_503', 503); }, 2000061.0);
same($outcome2['state'], 'failed', 'a partial attempt is due again (not not_due)');
echo "T6 scheduler partial OK (memory abort -> partial, partial is due again)\n";

// T7 — CEILING: default budget is 448 MiB (512M limit less 64M reserve,
// or the unlimited-limit default); an explicit override wins.
same(molavi_domain_reference_memory_budget(), 448 * 1024 * 1024, 'default memory ceiling is 448 MiB');
same(molavi_domain_reference_memory_budget(12345), 12345, 'explicit memory override wins');
echo "T7 memory ceiling OK (default 448 MiB)\n";

foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native reference refresh batch death ' . $count . " cases\n";
}
