<?php
/**
 * Plugin Name: مولوی‌سل
 * Description: Shop rules and Elementor pieces for the WooCommerce store.
 * Version: 0.6.0
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Production bridge values stay outside source control and the deployment archive.
$molavi_runtime_config = __DIR__ . '/includes/runtime-config.php';
if (is_readable($molavi_runtime_config)) {
    require_once $molavi_runtime_config;
}

require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/sync.php';
require_once __DIR__ . '/includes/reference-decisions.php';
require_once __DIR__ . '/includes/reference-snapshots.php';
require_once __DIR__ . '/includes/reference-item-reviews.php';
require_once __DIR__ . '/includes/ai-confirmations.php';
require_once __DIR__ . '/includes/pipeline-holds.php';
require_once __DIR__ . '/includes/operations-dashboard.php';
require_once __DIR__ . '/includes/clearance-pricing.php';
require_once __DIR__ . '/includes/inventory-management.php';
require_once __DIR__ . '/includes/order-handoff.php';
require_once __DIR__ . '/includes/delivery.php';

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'molavi-shop',
        plugins_url('shop.css', __FILE__),
        [],
        (string) filemtime(__DIR__ . '/shop.css')
    );
    wp_enqueue_style(
        'molavi-storefront',
        plugins_url('storefront.css', __FILE__),
        ['molavi-shop'],
        (string) filemtime(__DIR__ . '/storefront.css')
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
            'fixed' => (int) $row['shelf'] > 0 ? 0 : molavi_unit_price($row, 0.0),
            'floor' => (int) $row['floor'],
            'ceiling' => (int) $row['ceiling'],
            'max' => (float) $row['max'],
            'discountable' => !empty($row['discountable']) ? 1 : 0,
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
        'initial' => $quote,
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
    require_once __DIR__ . '/widgets/storefront.php';
    $widgets->register(new \Molavi\Elementor\Deal());
    $widgets->register(new \Molavi\Elementor\Facts());
    $widgets->register(new \Molavi\Elementor\Price());
    $widgets->register(new \Molavi\Elementor\Cart_Button());
    $widgets->register(new \Molavi\Elementor\Catalog_Bar());
    $widgets->register(new \Molavi\Elementor\Basket());
    $widgets->register(new \Molavi\Elementor\Storefront());
    add_shortcode('molavi_storefront', '\\Molavi\\Elementor\\molavi_render_storefront');
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

add_filter('woocommerce_get_price_html', static function (string $html, $product): string {
    if (!$product instanceof WC_Product) {
        return $html;
    }
    $curve = molavi_curve($product);
    $unit = molavi_unit_price($curve, molavi_cart_progress());
    $shelf = (int) $curve['shelf'];
    $rate = $shelf > $unit ? ($shelf - $unit) / $shelf : 0.0;
    $markup = '<span class="molavi-public-price">';
    if ($shelf > 0) {
        $markup .= '<span class="molavi-public-reference">قیمت مصرف <del>' . esc_html(molavi_toman($shelf)) . '</del></span>';
    }
    $markup .= '<span class="molavi-public-current">' . esc_html(molavi_toman($unit)) . '</span>';
    if ($rate > 0) {
        $markup .= '<span class="molavi-rate-label">تخفیف فعلی ' . esc_html(molavi_percent($rate)) . '</span>';
    } elseif ($shelf <= 0) {
        $markup .= '<span class="molavi-public-fixed">قیمت ثابت</span>';
    }
    return $markup . '</span>';
}, 20, 2);

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
