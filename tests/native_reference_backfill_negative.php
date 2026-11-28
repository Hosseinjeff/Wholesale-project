<?php
/** Negative control for the reference auto-match backfill.
 *
 * Run against the BASE code (a pristine 4a401b8 checkout, without the
 * backfill change): proves the backfill does not match anything there —
 * the functions do not exist and the API action is rejected as an invalid
 * body. A harness that also passes on the base is worthless.
 *
 * It is EXPECTED to fail on a tree that contains the change: there the
 * scheduler dispatches the action instead of rejecting it, which is
 * itself the signal that the change is present. This file deliberately
 * does NOT require molavi-domain-reference-backfill.php.
 */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-reference-backfill-negative-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-gateway',
    'domain-reference-scheduler'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';

$count = 0;
function check($actual, $expected, $label) {
    global $count;
    if ($actual !== $expected) throw new RuntimeException($label . ' actual=' . var_export($actual, true) . ' expected=' . var_export($expected, true));
    $count++;
}
check(function_exists('molavi_domain_reference_backfill_api'), false, 'base has no backfill API function');
check(function_exists('molavi_domain_reference_backfill_scan'), false, 'base has no backfill scan function');
check(function_exists('molavi_domain_reference_backfill_decide'), false, 'base has no backfill decision function');
try {
    molavi_domain_reference_api(['action' => 'backfill_reference_matches', 'dry_run' => true], 'wp:7');
    throw new RuntimeException('base accepted the backfill action');
} catch (MolaviWorkflowError $e) {
    check($e->getMessage(), 'invalid_body', 'base rejects the backfill action as invalid_body');
}
foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
@rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native reference backfill negative control ' . $count . " cases\n";
