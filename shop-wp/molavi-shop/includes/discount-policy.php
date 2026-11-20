<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_discount_run_receipt(array $run): array
{
    return array_intersect_key($run, array_flip(['id', 'state', 'total', 'completed', 'failed']));
}

function molavi_discount_run_active(array $run): bool
{
    return in_array($run['state'] ?? '', ['queued', 'running'], true);
}

function molavi_discount_policy_api(string $method, array $body = []): array
{
    $option = 'molavi_discount_policy_v1';
    if ($method === 'GET') {
        $policy = get_option($option, []);
        return ['ok' => true, 'rules' => molavi_discount_rules(),
            'run' => molavi_discount_run_receipt($policy['run'] ?? [])];
    }
    molavi_domain_body_keys($body, ['action', 'household_bp', 'shop_bp', 'household_target', 'shop_target']);
    if ($body['action'] !== 'save_discount_policy') {
        molavi_domain_fail('invalid_body', 400);
    }
    $limits = ['household_bp' => [0, 10000], 'shop_bp' => [0, 10000],
        'household_target' => [1, 1000000000000], 'shop_target' => [1, 1000000000000]];
    $rules = [];
    foreach ($limits as $field => [$min, $max]) {
        $value = $body[$field];
        if (!is_int($value) || $value < $min || $value > $max) {
            molavi_domain_fail('invalid_discount_policy', 400);
        }
        $rules[$field] = $value;
    }
    return molavi_domain_lock('publication', static function () use ($option, $rules): array {
        $policy = get_option($option, []);
        $previous = $policy['run'] ?? [];
        if (molavi_discount_run_active($previous)) {
            molavi_domain_fail('discount_update_running', 409);
        }
        if (($previous['state'] ?? '') === 'completed' && $rules === molavi_discount_rules()) {
            return ['ok' => true, 'state' => 'unchanged', 'rules' => $rules, 'run' => molavi_discount_run_receipt($previous)];
        }

        $ids = array_map('intval', wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids']));
        $run = ['id' => bin2hex(random_bytes(16)), 'state' => $ids ? 'queued' : 'completed',
            'total' => count($ids), 'completed' => 0, 'failed' => 0, 'ids' => $ids];
        $history = $policy['history'] ?? [];
        if (isset($previous['id'])) {
            $history[$previous['id']] = molavi_discount_run_receipt($previous);
        }
        $oldCacheKey = molavi_discount_catalog_key();
        if (!update_option($option, ['rules' => $rules, 'run' => $run,
            'history' => array_slice($history, -10, null, true)], false)) {
            molavi_domain_fail('discount_policy_unavailable', 503);
        }
        delete_transient($oldCacheKey);
        molavi_forget_catalog();
        molavi_purge_shop_cache();
        return ['ok' => true, 'state' => $run['state'], 'rules' => $rules, 'run' => molavi_discount_run_receipt($run)];
    });
}

function molavi_reprice_tick(): void
{
    $option = 'molavi_discount_policy_v1';
    $policy = get_option($option, []);
    if (!molavi_discount_run_active($policy['run'] ?? [])) {
        return;
    }

    molavi_domain_lock('publication', static function () use ($option): void {
        wp_cache_delete($option, 'options');
        $policy = get_option($option, []);
        $run = $policy['run'] ?? [];
        if (!molavi_discount_run_active($run)) {
            return;
        }
        $run['state'] = 'running';
        $policy['run'] = $run;
        update_option($option, $policy, false);
        $deadline = microtime(true) + 8;
        $batch = array_slice($run['ids'], $run['completed'], 20);
        foreach ($batch as $id) {
            try {
                $product = wc_get_product($id);
                if ($product instanceof WC_Product && $product->get_status() === 'publish') {
                    $curve = molavi_public_opening_curve(molavi_store_curve($product));
                    if ($curve['shelf'] > 0) {
                        $regular = (string) $curve['shelf'];
                        $sale = $curve['discountable'] ? (string) $curve['ceiling'] : '';
                        if ((string) $product->get_regular_price('edit') !== $regular || (string) $product->get_sale_price('edit') !== $sale) {
                            $product->set_regular_price($regular);
                            $product->set_sale_price($sale);
                            if (!$product->save()) {
                                throw new RuntimeException('discount_product_not_saved');
                            }
                        }
                    }
                }
            } catch (Throwable $error) {
                $run['failed']++;
            }
            $run['completed']++;
            if (microtime(true) >= $deadline) {
                break;
            }
        }

        if ($run['completed'] >= $run['total']) {
            $run['state'] = $run['failed'] ? 'partial' : 'completed';
            unset($run['ids']);
        }
        $policy['run'] = $run;
        update_option($option, $policy, false);
        if (!molavi_discount_run_active($run)) {
            molavi_forget_catalog();
            molavi_purge_shop_cache();
        }
    });
}

function molavi_discount_activity(array $report, array $watches): array
{
    $policy = get_option('molavi_discount_policy_v1', []);
    $run = $policy['run'] ?? [];
    foreach ($watches as $watch) {
        $key = $watch['key'];
        $receipt = ($run['id'] ?? '') === $key ? $run : ($policy['history'][$key] ?? []);
        $report['watched']->{$key} = $receipt ? molavi_discount_run_receipt($receipt) : ['state' => 'unknown'];
    }
    if (molavi_discount_run_active($run)) {
        $report['groups'][] = ['kind' => 'repricing'] + molavi_discount_run_receipt($run);
    }
    return $report;
}
