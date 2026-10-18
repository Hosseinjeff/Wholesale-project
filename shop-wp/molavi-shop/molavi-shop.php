<?php
/**
 * Plugin Name: مولوی‌سل
 * Description: Shop rules and Elementor pieces for the WooCommerce store.
 * Version: 0.5.0
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/sync.php';

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'molavi-shop',
        plugins_url('shop.css', __FILE__),
        [],
        (string) filemtime(__DIR__ . '/shop.css')
    );
    if (!class_exists('WC_AJAX')) {
        return;
    }
    wp_enqueue_script(
        'molavi-shop',
        plugins_url('shop.js', __FILE__),
        ['jquery'],
        (string) filemtime(__DIR__ . '/shop.js'),
        true
    );
    $catalog = [];
    foreach (molavi_catalog_map() as $id => $row) {
        $catalog[(string) $id] = [
            'name' => (string) $row['name'],
            'min' => (int) $row['min'],
            'shelf' => (int) $row['shelf'],
            'sale' => (int) $row['sale'],
            'floor' => (int) $row['floor'],
            'ceiling' => (int) $row['ceiling'],
            'discountable' => !empty($row['discountable']) ? 1 : 0,
            'image' => (string) ($row['image'] ?? ''),
        ];
    }
    $quote = molavi_quote();
    wp_localize_script('molavi-shop', 'molaviShop', [
        'qty' => WC_AJAX::get_endpoint('molavi_qty'),
        'quote' => WC_AJAX::get_endpoint('molavi_quote'),
        'nonce' => wp_create_nonce('molavi-qty'),
        'unlock' => (int) molavi_price_settings()['unlock_target'],
        'checkout' => wc_get_checkout_url(),
        'qtys' => $quote['qtys'] === [] ? new stdClass() : $quote['qtys'],
        'catalog' => $catalog === [] ? new stdClass() : $catalog,
    ]);
});

add_filter('loop_shop_per_page', static function (): int {
    return 24;
});

add_action('wp_head', static function (): void {
    if (isset($GLOBALS['page']) && !is_numeric($GLOBALS['page'])) {
        $GLOBALS['page'] = 0;
    }
}, 0);

add_action('elementor/elements/categories_registered', static function ($elements): void {
    $elements->add_category('molavi', [
        'title' => 'مولوی‌سل',
        'icon' => 'fa fa-shopping-bag',
    ]);
});

add_action('elementor/widgets/register', static function ($widgets): void {
    require_once __DIR__ . '/widgets/deal.php';
    require_once __DIR__ . '/widgets/facts.php';
    require_once __DIR__ . '/widgets/price.php';
    require_once __DIR__ . '/widgets/cart.php';
    require_once __DIR__ . '/widgets/bar.php';
    require_once __DIR__ . '/widgets/basket.php';
    $widgets->register(new \Molavi\Elementor\Deal());
    $widgets->register(new \Molavi\Elementor\Facts());
    $widgets->register(new \Molavi\Elementor\Price());
    $widgets->register(new \Molavi\Elementor\Cart_Button());
    $widgets->register(new \Molavi\Elementor\Catalog_Bar());
    $widgets->register(new \Molavi\Elementor\Basket());
});

add_filter('woocommerce_quantity_input_args', static function (array $args, $product): array {
    $min = molavi_product_min($product);
    $args['min_value'] = $min;
    if ((int) ($args['input_value'] ?? 0) < $min) {
        $args['input_value'] = $min;
    }
    return $args;
}, 10, 2);

add_filter('woocommerce_add_to_cart_quantity', static function (int $quantity, int $productId): int {
    $product = wc_get_product($productId);
    $min = $product ? molavi_product_min($product) : 1;
    return max($min, $quantity);
}, 10, 2);

add_filter('woocommerce_product_get_short_description', static function (string $description, $product): string {
    if ($description !== '') {
        return $description;
    }
    $min = molavi_product_min($product);
    $supplier = (string) $product->get_attribute('supplier');
    $parts = [];
    if ($supplier !== '') {
        $parts[] = $supplier;
    }
    if ($min > 1) {
        $parts[] = 'حداقل خرید ' . molavi_digits((string) $min) . ' عدد';
    }
    return implode(' · ', $parts);
}, 10, 2);

add_action('woocommerce_checkout_order_processed', static function (int $orderId): void {
    $order = wc_get_order($orderId);
    if (!$order || $order->get_meta('_molavi_published') === '1') {
        return;
    }
    $config = molavi_legacy_config();
    $secret = (string) ($config['notify_secret'] ?? '');
    $bot = rtrim((string) ($config['bot_url'] ?? ''), '/');
    if ($secret === '' || $bot === '') {
        return;
    }
    $lines = [];
    foreach ($order->get_items() as $item) {
        $lines[] = [
            'name' => $item->get_name(),
            'qty' => $item->get_quantity(),
            'total' => (int) $item->get_total(),
        ];
    }
    $payload = [
        'notify' => true,
        'order' => [
            'id' => (string) $order->get_id(),
            'name' => $order->get_formatted_billing_full_name(),
            'phone' => $order->get_billing_phone(),
            'email' => $order->get_billing_email(),
            'address' => $order->get_billing_address_1(),
            'note' => $order->get_customer_note(),
            'total' => (int) $order->get_total(),
            'currency' => $order->get_currency(),
            'lines' => $lines,
        ],
    ];
    $response = wp_remote_post($bot . '/shop/order', [
        'timeout' => 12,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Shop-Token' => $secret,
        ],
        'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 300) {
        $order->update_meta_data('_molavi_published', '1');
        $order->save();
    }
}, 20);

function molavi_product_min($product): int
{
    if (!$product instanceof WC_Product) {
        return 1;
    }
    $set = (int) $product->get_meta('_molavi_min');
    return $set > 0 ? min(999, $set) : 1;
}

function molavi_digits(string $value): string
{
    return strtr($value, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ',' => '٬',
    ]);
}

function molavi_legacy_config(): array
{
    $candidates = [
        ABSPATH . 'private/config.php',
        dirname(ABSPATH) . '/private/config.php',
    ];
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }
    return [];
}
