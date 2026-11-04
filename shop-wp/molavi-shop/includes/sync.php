<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The bot publishes the product sheet here after each batch.
 * The shop host cannot always reach the bot, so the bot uploads photo bytes.
 */
add_action('init', static function (): void {
    if (($_GET['molavi-sync'] ?? '') !== '1') {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        molavi_sync_reply(['ok' => false, 'error' => 'method'], 405);
    }
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        molavi_sync_reply(['ok' => false, 'error' => 'json'], 400);
    }
    $secret = (string) (molavi_legacy_config()['notify_secret'] ?? '');
    $sent = (string) ($_SERVER['HTTP_X_SHOP_TOKEN'] ?? '');
    if ($sent === '') {
        $sent = (string) ($payload['token'] ?? '');
    }
    unset($payload['token']);
    if ($secret === '' || $sent === '' || !hash_equals($secret, $sent)) {
        molavi_sync_reply(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    if (!class_exists('WC_Product_Simple')) {
        molavi_sync_reply(['ok' => false, 'error' => 'woocommerce'], 503);
    }
    if (($payload['mode'] ?? '') === 'images') {
        molavi_sync_reply(molavi_receive_images($payload));
    }
    molavi_sync_reply(molavi_apply_catalog($payload));
}, 20);

function molavi_sync_reply(array $body, int $status = 200): void
{
    status_header($status);
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode($body);
    exit;
}

function molavi_apply_catalog(array $payload): array
{
    $rows = $payload['products'] ?? null;
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'products'];
    }
    if (count($rows) > 400) {
        return ['ok' => false, 'error' => 'too many products'];
    }

    // Validate the complete snapshot before changing settings or product rows.
    // A malformed row must never turn an incomplete payload into deletions.
    $cleanRows = [];
    $seenIds = [];
    $seenRecordIds = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'invalid product', 'row' => (int) $index];
        }
        $clean = molavi_clean_product($row);
        if ($clean === null) {
            return ['ok' => false, 'error' => 'invalid product', 'row' => (int) $index];
        }
        if (isset($seenIds[$clean['id']])) {
            return ['ok' => false, 'error' => 'duplicate product id', 'row' => (int) $index];
        }
        $seenIds[$clean['id']] = true;
        if ($clean['source_record_id'] !== '') {
            if (isset($seenRecordIds[$clean['source_record_id']])) {
                return ['ok' => false, 'error' => 'duplicate source record id', 'row' => (int) $index];
            }
            $seenRecordIds[$clean['source_record_id']] = true;
        }
        $cleanRows[] = $clean;
    }

    $index = molavi_product_index();
    $created = 0;
    $updated = 0;
    $hidden = 0;
    $queue = [];
    $present = [];
    foreach ($cleanRows as $rowIndex => $clean) {
        $productId = molavi_match_product($clean, $index);
        if ($clean['status'] === 'hold') {
            if ($productId > 0) {
                $present[$productId] = true;
                molavi_mark_product_held($productId, $clean);
                $wasHidden = molavi_product_is_hidden($productId);
                if ($wasHidden || molavi_hide_product($productId)) {
                    if (!$wasHidden) { $hidden++; }
                } else {
                    molavi_forget_catalog();
                    molavi_purge_shop_cache();
                    return ['ok' => false, 'error' => 'product_hold_failed', 'row' => $rowIndex];
                }
            }
            continue;
        }
        $wasNew = $productId <= 0;
        $productId = molavi_upsert_product($productId, $clean);
        if ($productId <= 0) {
            molavi_forget_catalog();
            molavi_purge_shop_cache();
            return ['ok' => false, 'error' => 'product_write_failed'];
        }
        molavi_index_add($index, $clean, $productId);
        $present[$productId] = true;
        if ($wasNew) {
            $created++;
        } else {
            $updated++;
        }
        if ($clean['image'] !== '' && (string) get_post_meta($productId, '_molavi_image_id', true) !== $clean['image']) {
            $queue[] = ['product' => $productId, 'file' => $clean['image']];
        }
    }
    if (($payload['catalog_complete'] ?? false) === true) {
        foreach ($index['managed'] as $productId) {
            if (!isset($present[$productId])) {
                update_post_meta((int) $productId, '_molavi_catalog_state', 'removed');
                $wasHidden = molavi_product_is_hidden((int) $productId);
                if ($wasHidden || molavi_hide_product((int) $productId)) {
                    if (!$wasHidden) { $hidden++; }
                }
                else {
                    molavi_forget_catalog();
                    molavi_purge_shop_cache();
                    return ['ok' => false, 'error' => 'product_remove_failed'];
                }
            }
        }
    }
    $settings = $payload['settings'] ?? null;
    if (is_array($settings)) {
        molavi_apply_settings($settings);
    }
    update_option('molavi_image_queue', $queue, false);
    molavi_forget_catalog();
    molavi_purge_shop_cache();
    return [
        'ok' => true,
        'created' => $created,
        'updated' => $updated,
        'hidden' => $hidden,
        'attached' => 0,
        'images_left' => count($queue),
        'pending' => molavi_image_pending(1),
    ];
}

function molavi_apply_settings(array $settings): void
{
    $current = molavi_price_settings();
    $next = $current;
    foreach ($current as $field => $default) {
        if (!isset($settings[$field]) || !is_numeric($settings[$field])) {
            continue;
        }
        $next[$field] = in_array($field, ['min_profit', 'unlock_target',
            'household_unlock_fallback', 'tuna_shell_units', 'shop_unlock_multiplier',
            'household_discount_threshold_units', 'shop_discount_threshold_units'], true)
            ? (int) $settings[$field]
            : (float) $settings[$field];
    }
    if ($next == $current) {
        return;
    }
    update_option('molavi_price_settings', $next);
    global $wpdb;
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_molavi_curve']);
}

function molavi_valid_source_post_url(string $value): string
{
    $url = trim($value);
    if ($url === '' || strlen($url) > 500) { return ''; }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !in_array(strtolower((string) ($parts['host'] ?? '')),
            ['t.me', 'www.t.me', 'bale.ai', 'www.bale.ai'], true)
        || empty($parts['path']) || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    return esc_url_raw($url, ['https']);
}

function molavi_clean_product(array $row): ?array
{
    $name = trim(wp_strip_all_tags((string) ($row['name'] ?? '')));
    $status = (string) ($row['status'] ?? '');
    if ($status !== 'sell' && $status !== 'hold') {
        return null;
    }
    $sourceRecordId = preg_match('/^[A-Za-z0-9._:-]{1,200}$/', (string)($row['source_record_id'] ?? ''))
        ? (string)$row['source_record_id'] : '';
    if (($name === '' && !($status === 'hold' && $sourceRecordId !== '')) || mb_strlen($name) > 180) {
        return null;
    }
    $id = (string) ($row['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        return null;
    }
    $sale = max(0, min(100000000, (int) ($row['sale'] ?? 0)));
    if ($status === 'sell' && $sale <= 0) {
        return null;
    }
    $image = (string) ($row['image_file_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{8,300}$/', $image)) {
        $image = '';
    }
    $key = trim((string) ($row['key'] ?? ''));
    if ($key === '') {
        $key = molavi_name_key($name);
    }
    return [
        'id' => $id,
        'name' => $name,
        'key' => mb_strtolower($key),
        'supplier' => trim(wp_strip_all_tags((string) ($row['supplier'] ?? ''))),
        'packaging' => mb_substr(trim(wp_strip_all_tags((string) ($row['packaging'] ?? ''))), 0, 120),
        'category' => mb_substr(trim(wp_strip_all_tags((string) ($row['category'] ?? ''))), 0, 80),
        'sale' => $sale,
        'shelf' => max(0, min(100000000, (int) ($row['shelf'] ?? 0))),
        'min' => max(1, min(999, (int) ($row['min'] ?? 1))),
        'pack_size' => max(1, min(999, (int) ($row['pack_size'] ?? ($row['min'] ?? 1)))),
        'source_post_url' => molavi_valid_source_post_url((string) ($row['source_post_url'] ?? '')),
        'reference_snapshot_id' => preg_match('/^[a-f0-9]{64}$/', (string) ($row['reference_snapshot_id'] ?? ''))
            ? (string) $row['reference_snapshot_id'] : '',
        'reference_match_id' => mb_substr(trim(wp_strip_all_tags((string) ($row['reference_match_id'] ?? ''))), 0, 300),
        'reference_price_role' => in_array(($row['reference_price_role'] ?? ''), ['unit','package'], true)
            ? (string) $row['reference_price_role'] : '',
        'reference_approval_state' => in_array(($row['reference_approval_state'] ?? ''),
            ['owner_approved','approved_compatible','approved_within_margin','outside_margin','reference_missing','price_unavailable','needs_review'], true)
            ? (string) $row['reference_approval_state'] : '',
        'reference_price_change_percent' => max(0, min(100000, (float) ($row['reference_price_change_percent'] ?? 0))),
        'reference_price_checked_at' => is_string($row['reference_price_checked_at'] ?? null)
            && strtotime($row['reference_price_checked_at']) !== false
            ? gmdate('c', strtotime($row['reference_price_checked_at'])) : '',
        'reference_review_reason' => mb_substr(trim(wp_strip_all_tags((string) ($row['reference_review_reason'] ?? ''))), 0, 500),
        'image' => $image,
        'status' => $status,
        'supplier_availability' => in_array(($row['supplier_availability'] ?? ''), ['instock', 'outofstock'], true) ? $row['supplier_availability'] : 'unknown',
        'source_record_id' => $sourceRecordId,
        'supplier_price_state' => in_array(($row['supplier_price_state'] ?? ''), ['current','warning','blocked','needs_review'], true) ? $row['supplier_price_state'] : '',
        'supplier_price_verified_at' => is_string($row['supplier_price_verified_at'] ?? null) && strtotime($row['supplier_price_verified_at']) !== false ? gmdate('c', strtotime($row['supplier_price_verified_at'])) : '',
    ];
}

function molavi_name_key(string $name): string
{
    $text = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}]+/u', '', trim($name));
    $text = preg_replace('/\s+/u', ' ', is_string($text) ? $text : trim($name));
    return mb_strtolower(trim(is_string($text) ? $text : $name));
}

function molavi_supplier_name_key(string $supplier, string $key): string
{
    return mb_strtolower(trim($supplier)) . "\0" . $key;
}

function molavi_index_add(array &$index, array $clean, int $productId): void
{
    foreach ([
        ['source', $clean['id']],
        ['record', $clean['source_record_id']],
        ['name', $clean['key']],
        ['supplier_name', molavi_supplier_name_key($clean['supplier'], $clean['key'])],
    ] as [$bucket, $key]) {
        if ($key === '') { continue; }
        if (!isset($index[$bucket][$key])) { $index[$bucket][$key] = []; }
        if (!in_array($productId, $index[$bucket][$key], true)) {
            $index[$bucket][$key][] = $productId;
        }
    }
    $index['managed'][$productId] = $productId;
}

function molavi_unique_index_match(array $index, string $bucket, string $key): int
{
    $matches = array_values(array_unique(array_map('intval', $index[$bucket][$key] ?? [])));
    return count($matches) === 1 ? $matches[0] : 0;
}

function molavi_product_index(): array
{
    $ids = get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'private', 'pending'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    $index = ['source' => [], 'record' => [], 'name' => [], 'supplier_name' => [], 'managed' => []];
    foreach ($ids as $id) {
        $id = (int) $id;
        $source = (string) get_post_meta($id, '_molavi_source_id', true);
        $record = (string) get_post_meta($id, '_molavi_supplier_offer_id', true);
        $title = get_the_title($id);
        $key = molavi_name_key($title);
        $supplier = (string) get_post_meta($id, '_molavi_supplier', true);
        if ($source !== '' || $record !== '') {
            $index['managed'][$id] = $id;
        }
        foreach ([['source', $source], ['record', $record], ['name', $key],
                  ['supplier_name', molavi_supplier_name_key($supplier, $key)]] as [$bucket, $value]) {
            if ($value !== '') { $index[$bucket][$value][] = $id; }
        }
    }
    return $index;
}

function molavi_match_product(array $clean, array $index): int
{
    if ($clean['source_record_id'] !== '') {
        $recordMatch = molavi_unique_index_match($index, 'record', $clean['source_record_id']);
        if ($recordMatch > 0) { return $recordMatch; }
    }
    $sourceMatch = molavi_unique_index_match($index, 'source', $clean['id']);
    if ($sourceMatch > 0) { return $sourceMatch; }
    if ($clean['source_record_id'] !== '' || $clean['supplier'] === '') { return 0; }
    return molavi_unique_index_match($index, 'supplier_name',
        molavi_supplier_name_key($clean['supplier'], $clean['key']));
}

function molavi_upsert_product(int $productId, array $clean): int
{
    if ($productId > 0) {
        $product = wc_get_product($productId);
        if (!$product instanceof WC_Product) {
            return 0;
        }
    } else {
        $product = new WC_Product_Simple();
    }
    $shelf = max(0, (int) $clean['shelf']);
    $supplierCost = max(0, (int) $clean['sale']);
    $curve = molavi_curve_from_prices($shelf, $supplierCost);
    $product->set_name($clean['name']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    // Woo's public prices are customer prices. Keep the supplier purchase price
    // in private metadata so the Store API and product feeds never expose cost.
    $product->set_regular_price($shelf > 0 ? (string) $shelf : '');
    $product->set_sale_price(!empty($curve['discountable']) ? (string) $curve['ceiling'] : '');
    $product->update_meta_data('_molavi_market_shelf_toman', $shelf);
    $product->update_meta_data('_molavi_supplier_cost_toman', $supplierCost);
    $product->update_meta_data('_molavi_supplier_availability', $clean['supplier_availability']);
    $product->update_meta_data('_molavi_catalog_state', 'sell');
    $product->update_meta_data('_molavi_pack_size', $clean['pack_size']);
    $product->update_meta_data('_molavi_source_post_url', $clean['source_post_url']);
    $product->update_meta_data('_molavi_reference_snapshot_id', $clean['reference_snapshot_id']);
    $product->update_meta_data('_molavi_reference_match_id', $clean['reference_match_id']);
    $product->update_meta_data('_molavi_reference_price_role', $clean['reference_price_role']);
    $product->update_meta_data('_molavi_reference_approval_state', $clean['reference_approval_state']);
    $product->update_meta_data('_molavi_reference_price_change_percent', $clean['reference_price_change_percent']);
    $product->update_meta_data('_molavi_reference_price_checked_at', $clean['reference_price_checked_at']);
    $product->update_meta_data('_molavi_reference_review_reason', $clean['reference_review_reason']);
    $product->set_short_description($clean['supplier']);
    if ($clean['source_record_id'] !== '') {
        $product->update_meta_data('_molavi_supplier_offer_id', $clean['source_record_id']);
        $product->update_meta_data('_molavi_supplier_price_state', $clean['supplier_price_state']);
        $product->update_meta_data('_molavi_supplier_price_verified_at', $clean['supplier_price_verified_at']);
    }
    // Out-of-stock offers are projected as held by the source publisher. A fresh,
    // current offer with unknown stock remains visible so a customer can submit a
    // request; the request is not a promise of supplier inventory.
    if ($clean['status'] === 'hold' || $clean['supplier_availability'] === 'outofstock') {
        $product->set_stock_status('outofstock');
    } elseif ($clean['status'] === 'sell') {
        $product->set_stock_status('instock');
    }

    if ($product->get_sku() === '') {
        try {
            $product->set_sku($clean['id']);
        } catch (Throwable $e) {
            // Another product already carries this SKU.
        }
    }
    $product->save();
    $id = (int) $product->get_id();
    if ($id <= 0) {
        return 0;
    }
    update_post_meta($id, '_molavi_source_id', $clean['id']);
    update_post_meta($id, '_molavi_min', $clean['min']);
    update_post_meta($id, '_molavi_pack_size', $clean['pack_size']);
    update_post_meta($id, '_molavi_source_post_url', $clean['source_post_url']);
    update_post_meta($id, '_molavi_supplier', $clean['supplier']);
    update_post_meta($id, '_molavi_packaging', $clean['packaging']);
    update_post_meta($id, '_molavi_category', $clean['category']);
    update_post_meta($id, '_molavi_cost', $clean['sale']);
    $saved = wc_get_product($id);
    if ($saved instanceof WC_Product) {
        molavi_store_curve($saved);
    }
    return $id;
}

/** Enforce the server's verified-price lifetime in both classic and Store API carts. */
function molavi_supplier_offer_is_purchasable(bool $purchasable, $product): bool
{
    if (!$purchasable || !$product instanceof WC_Product) { return $purchasable; }
    $catalogState=(string)$product->get_meta('_molavi_catalog_state', true);
    if ($catalogState !== '' && $catalogState !== 'sell') { return false; }
    if ((string)$product->get_meta('_molavi_supplier_offer_id', true) === '') { return $purchasable; }
    $state=(string)$product->get_meta('_molavi_supplier_price_state', true);
    $verified=strtotime((string)$product->get_meta('_molavi_supplier_price_verified_at', true));
    $age=$verified === false ? -1 : time() - $verified;
    return in_array($state,['current','warning'],true) && $age >= 0 && $age < (14 * DAY_IN_SECONDS);
}
add_filter('woocommerce_is_purchasable','molavi_supplier_offer_is_purchasable',10,2);

function molavi_mark_product_held(int $productId, array $clean): void
{
    update_post_meta($productId, '_molavi_catalog_state', 'hold');
    update_post_meta($productId, '_molavi_supplier_availability', $clean['supplier_availability']);
    if ($clean['source_record_id'] !== '') {
        update_post_meta($productId, '_molavi_supplier_offer_id', $clean['source_record_id']);
        update_post_meta($productId, '_molavi_supplier_price_state', $clean['supplier_price_state']);
        update_post_meta($productId, '_molavi_supplier_price_verified_at', $clean['supplier_price_verified_at']);
    }
}

function molavi_product_is_hidden(int $productId): bool
{
    $product = wc_get_product($productId);
    return $product instanceof WC_Product
        && $product->get_status() === 'draft'
        && $product->get_catalog_visibility() === 'hidden'
        && $product->get_stock_status() === 'outofstock';
}

function molavi_hide_product(int $productId): bool
{
    $product = wc_get_product($productId);
    if (!$product instanceof WC_Product) {
        return false;
    }
    if (molavi_product_is_hidden($productId)) {
        return false;
    }
    $product->set_status('draft');
    $product->set_catalog_visibility('hidden');
    $product->set_stock_status('outofstock');
    $saved = $product->save();
    if (!$saved) { return false; }
    $verified = wc_get_product($productId);
    return $verified instanceof WC_Product
        && $verified->get_status() === 'draft'
        && $verified->get_catalog_visibility() === 'hidden'
        && $verified->get_stock_status() === 'outofstock';
}

function molavi_receive_images(array $payload): array
{
    $queue = molavi_image_queue();
    $attached = 0;
    $images = $payload['images'] ?? [];
    if (is_array($images)) {
        foreach (array_slice($images, 0, 4) as $image) {
            if (!is_array($image)) {
                continue;
            }
            $productId = (int) ($image['product'] ?? 0);
            $file = (string) ($image['file'] ?? '');
            if (!molavi_store_image($productId, $file, (string) ($image['data'] ?? ''), (string) ($image['mime'] ?? ''))) {
                continue;
            }
            $attached++;
            $queue = array_values(array_filter($queue, static function (array $item) use ($productId, $file): bool {
                return (int) ($item['product'] ?? 0) !== $productId || (string) ($item['file'] ?? '') !== $file;
            }));
        }
    }
    $skips = $payload['skip'] ?? [];
    if (is_array($skips)) {
        foreach ($skips as $skip) {
            if (!is_array($skip)) {
                continue;
            }
            $productId = (int) ($skip['product'] ?? 0);
            $file = (string) ($skip['file'] ?? '');
            $moved = null;
            $rest = [];
            foreach ($queue as $item) {
                if ($moved === null && (int) ($item['product'] ?? 0) === $productId && (string) ($item['file'] ?? '') === $file) {
                    $moved = $item;
                    continue;
                }
                $rest[] = $item;
            }
            if ($moved !== null) {
                $rest[] = $moved;
            }
            $queue = $rest;
        }
    }
    update_option('molavi_image_queue', $queue, false);
    if ($attached > 0) {
        molavi_purge_shop_cache();
    }
    return [
        'ok' => true,
        'attached' => $attached,
        'images_left' => count($queue),
        'pending' => molavi_image_pending(1),
    ];
}

function molavi_image_queue(): array
{
    $queue = get_option('molavi_image_queue', []);
    if (!is_array($queue)) {
        return [];
    }
    $clean = [];
    foreach ($queue as $item) {
        if (!is_array($item)) {
            continue;
        }
        $productId = (int) ($item['product'] ?? 0);
        $file = (string) ($item['file'] ?? '');
        if ($productId > 0 && $file !== '') {
            $clean[] = ['product' => $productId, 'file' => $file];
        }
    }
    return $clean;
}

function molavi_image_pending(int $limit): array
{
    return array_slice(molavi_image_queue(), 0, max(0, $limit));
}

function molavi_store_image(int $productId, string $fileId, string $data, string $mime): bool
{
    if ($productId <= 0 || !preg_match('/^[A-Za-z0-9_-]{8,300}$/', $fileId)) {
        return false;
    }
    if ((string) get_post_meta($productId, '_molavi_image_id', true) === $fileId && (int) get_post_thumbnail_id($productId) > 0) {
        return true;
    }
    $raw = base64_decode($data, true);
    if (!is_string($raw) || strlen($raw) < 32 || strlen($raw) > 4000000) {
        return false;
    }
    $ext = '';
    if (str_starts_with($raw, "\xFF\xD8\xFF")) {
        $ext = 'jpg';
    } elseif (str_starts_with($raw, "\x89PNG")) {
        $ext = 'png';
    } elseif (str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WEBP') {
        $ext = 'webp';
    }
    if ($ext === '' || ($mime !== '' && !str_starts_with($mime, 'image/'))) {
        return false;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = wp_tempnam('molavi');
    if (!is_string($tmp) || file_put_contents($tmp, $raw) === false) {
        return false;
    }
    $attachmentId = media_handle_sideload([
        'name' => 'molavi-' . substr(md5($fileId), 0, 12) . '.' . $ext,
        'tmp_name' => $tmp,
    ], $productId, get_the_title($productId));
    if (is_wp_error($attachmentId)) {
        @unlink($tmp);
        return false;
    }
    set_post_thumbnail($productId, (int) $attachmentId);
    update_post_meta($productId, '_molavi_image_id', $fileId);
    return true;
}

function molavi_purge_shop_cache(): void
{
    if (function_exists('litespeed_purge_all')) {
        litespeed_purge_all();
    }
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
}
