<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The bot publishes the product sheet here after each batch.
 * Photos are pulled from the bot's media stream, a few at a time.
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
        molavi_sync_reply(molavi_drain_images(6));
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
    $settings = $payload['settings'] ?? null;
    if (is_array($settings)) {
        molavi_apply_settings($settings);
    }
    $rows = $payload['products'] ?? null;
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'products'];
    }
    if (count($rows) > 400) {
        return ['ok' => false, 'error' => 'too many products'];
    }

    $index = molavi_product_index();
    $created = 0;
    $updated = 0;
    $hidden = 0;
    $queue = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $clean = molavi_clean_product($row);
        if ($clean === null) {
            continue;
        }
        $productId = molavi_match_product($clean, $index);
        if ($clean['status'] === 'hold') {
            if ($productId > 0 && molavi_hide_product($productId)) {
                $hidden++;
            }
            continue;
        }
        $wasNew = $productId <= 0;
        $productId = molavi_upsert_product($productId, $clean);
        if ($productId <= 0) {
            continue;
        }
        $index['source'][$clean['id']] = $productId;
        $index['name'][$clean['key']][] = $productId;
        if ($wasNew) {
            $created++;
        } else {
            $updated++;
        }
        if ($clean['image'] !== '' && (string) get_post_meta($productId, '_molavi_image_id', true) !== $clean['image']) {
            $queue[] = ['product' => $productId, 'file' => $clean['image']];
        }
    }
    update_option('molavi_image_queue', $queue, false);
    molavi_forget_catalog();
    $images = molavi_drain_images(4);
    molavi_purge_shop_cache();
    return [
        'ok' => true,
        'created' => $created,
        'updated' => $updated,
        'hidden' => $hidden,
        'images_left' => (int) $images['images_left'],
        'attached' => (int) $images['attached'],
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
        $next[$field] = $field === 'min_profit' || $field === 'unlock_target'
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

function molavi_clean_product(array $row): ?array
{
    $name = trim(wp_strip_all_tags((string) ($row['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 180) {
        return null;
    }
    $status = (string) ($row['status'] ?? '');
    if ($status !== 'sell' && $status !== 'hold') {
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
        'sale' => $sale,
        'shelf' => max(0, min(100000000, (int) ($row['shelf'] ?? 0))),
        'min' => max(1, min(999, (int) ($row['min'] ?? 1))),
        'image' => $image,
        'status' => $status,
    ];
}

function molavi_name_key(string $name): string
{
    $text = trim($name);
    $patterns = [
        '/^(?P<name>.+?)(?:\s*[،,]\s*|\s+)(?P<pack>(?:در\s*)?(?:کارتن|باکس|بسته)\s*[\d۰-۹]+\s*(?:عددی|عدد|تایی))\s*$/u',
        '/^(?P<name>.+?)\s*[،,]\s*(?P<pack>[\d۰-۹]+\s*(?:عددی|عدد|تایی))\s*$/u',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $text, $match)) {
            continue;
        }
        $peeled = preg_replace('/[\s،,:：\-]+$/u', '', $match['name']);
        $peeled = trim(is_string($peeled) ? $peeled : $match['name']);
        if (mb_strlen($peeled) > 2 && trim($match['pack']) !== '') {
            $text = $peeled;
            break;
        }
    }
    return mb_strtolower(trim($text));
}

function molavi_product_index(): array
{
    $ids = get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'private', 'pending'],
        'posts_per_page' => 500,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    $index = ['source' => [], 'name' => []];
    foreach ($ids as $id) {
        $id = (int) $id;
        $source = (string) get_post_meta($id, '_molavi_source_id', true);
        if ($source !== '') {
            $index['source'][$source] = $id;
        }
        $title = get_the_title($id);
        $key = molavi_name_key($title);
        if ($key !== '') {
            $index['name'][$key][] = $id;
        }
    }
    return $index;
}

function molavi_match_product(array $clean, array $index): int
{
    if (isset($index['source'][$clean['id']])) {
        return (int) $index['source'][$clean['id']];
    }
    $candidates = $index['name'][$clean['key']] ?? [];
    $candidates = array_values(array_unique(array_map('intval', $candidates)));
    if (count($candidates) === 1) {
        return $candidates[0];
    }
    return 0;
}

function molavi_upsert_product(int $productId, array $clean): int
{
    $product = $productId > 0 ? wc_get_product($productId) : new WC_Product_Simple();
    if (!$product instanceof WC_Product) {
        $product = new WC_Product_Simple();
    }
    $shelf = $clean['shelf'] > $clean['sale'] ? $clean['shelf'] : 0;
    $product->set_name($clean['name']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price((string) ($shelf > 0 ? $shelf : $clean['sale']));
    $product->set_sale_price($shelf > 0 ? (string) $clean['sale'] : '');
    $product->set_short_description($clean['supplier']);
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');
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
    $saved = wc_get_product($id);
    if ($saved instanceof WC_Product) {
        molavi_store_curve($saved);
    }
    return $id;
}

function molavi_hide_product(int $productId): bool
{
    $product = wc_get_product($productId);
    if (!$product instanceof WC_Product) {
        return false;
    }
    if ($product->get_status() === 'draft' && $product->get_catalog_visibility() === 'hidden') {
        return false;
    }
    $product->set_status('draft');
    $product->set_catalog_visibility('hidden');
    $product->save();
    return true;
}

function molavi_drain_images(int $limit): array
{
    $queue = get_option('molavi_image_queue', []);
    if (!is_array($queue)) {
        $queue = [];
    }
    $base = rtrim((string) (molavi_legacy_config()['media_base'] ?? molavi_legacy_config()['bot_url'] ?? ''), '/');
    $attached = 0;
    $attempts = 0;
    $failed = [];
    $deferred = [];
    $started = microtime(true);
    foreach ($queue as $item) {
        $productId = (int) ($item['product'] ?? 0);
        $file = (string) ($item['file'] ?? '');
        if ($productId <= 0 || $file === '') {
            continue;
        }
        $keep = ['product' => $productId, 'file' => $file];
        if ($base === '' || $attempts >= $limit || (microtime(true) - $started) > 20) {
            $deferred[] = $keep;
            continue;
        }
        $attempts++;
        if (!molavi_attach_image($productId, $file, $base)) {
            $failed[] = $keep;
            continue;
        }
        $attached++;
    }
    $left = array_merge($deferred, $failed);
    update_option('molavi_image_queue', $left, false);
    if ($attached > 0) {
        molavi_purge_shop_cache();
    }
    return [
        'ok' => true,
        'attached' => $attached,
        'images_left' => count($left),
    ];
}

function molavi_attach_image(int $productId, string $fileId, string $base): bool
{
    if (!preg_match('/^[A-Za-z0-9_-]{8,300}$/', $fileId)) {
        return false;
    }
    if ((string) get_post_meta($productId, '_molavi_image_id', true) === $fileId && (int) get_post_thumbnail_id($productId) > 0) {
        return true;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = download_url($base . '/media/file/' . rawurlencode($fileId), 20);
    if (is_wp_error($tmp)) {
        return false;
    }
    $attachmentId = media_handle_sideload([
        'name' => 'molavi-' . substr(md5($fileId), 0, 12) . '.jpg',
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
