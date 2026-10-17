<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function molavi_price_settings(): array
{
    $settings = [
        'min_margin' => 0.12,
        'min_profit' => 5000,
        'spread_low' => 0.10,
        'spread_high' => 0.60,
        'profit_share_low' => 0.70,
        'profit_share_high' => 0.35,
        'opening_share_low' => 0.20,
        'opening_share_high' => 0.25,
        'unlock_target' => 2000000,
    ];
    $given = get_option('molavi_price_settings');
    if (!is_array($given)) {
        return $settings;
    }
    foreach ($settings as $field => $default) {
        if (!isset($given[$field]) || !is_numeric($given[$field])) {
            continue;
        }
        $settings[$field] = $field === 'min_profit' || $field === 'unlock_target'
            ? (int) $given[$field]
            : (float) $given[$field];
    }
    return $settings;
}

function molavi_round_up(float $value): int
{
    $nearest = round($value, 8);
    $whole = (int) $nearest;
    if ($nearest - $whole > 1e-8) {
        return $whole + 1;
    }
    return $whole;
}

function molavi_round_down(float $value): int
{
    return (int) round($value, 8);
}

function molavi_round_half_up(float $value): int
{
    $nearest = round($value, 8);
    $whole = (int) $nearest;
    if ($nearest - $whole >= 0.5 - 1e-8) {
        return $whole + 1;
    }
    return $whole;
}

function molavi_band(int $cost, int $shelf): array
{
    $blank = [
        'floor' => $shelf > 0 ? $shelf : 0,
        'ceiling' => $shelf > 0 ? $shelf : 0,
        'max' => 0.0,
        'discountable' => false,
    ];
    $settings = molavi_price_settings();
    if ($cost <= 0 || $shelf <= 0 || $shelf <= $cost) {
        return $blank;
    }
    $gap = $shelf - $cost;
    $spread = $gap / $shelf;
    $span = $settings['spread_high'] - $settings['spread_low'];
    $t = $span <= 0 ? 0.0 : max(0.0, min(1.0, ($spread - $settings['spread_low']) / $span));
    $profitShare = $settings['profit_share_low'] + ($settings['profit_share_high'] - $settings['profit_share_low']) * $t;
    $openingShare = $settings['opening_share_low'] + ($settings['opening_share_high'] - $settings['opening_share_low']) * $t;
    $profit = max($profitShare * $gap, max($cost * $settings['min_margin'], (float) $settings['min_profit']));
    if ($cost + $profit >= $shelf) {
        return $blank;
    }
    $floor = molavi_round_up($cost + $profit);
    if ($floor >= $shelf) {
        return $blank;
    }
    $room = $shelf - $floor;
    $openingToman = min($openingShare * $gap, (float) $room);
    $ceiling = molavi_round_down($shelf - $openingToman);
    if ($ceiling < $floor) {
        $ceiling = $floor;
    }
    if ($ceiling > $shelf) {
        $ceiling = $shelf;
    }
    return [
        'floor' => $floor,
        'ceiling' => $ceiling,
        'max' => ($shelf - $floor) / $shelf,
        'discountable' => ($shelf - $floor) > 0,
    ];
}

function molavi_curve_from_prices(int $regular, int $sale): array
{
    if ($sale > 0 && $regular > $sale) {
        $shelf = $regular;
        $cost = $sale;
    } else {
        $shelf = 0;
        $cost = $regular > 0 ? $regular : $sale;
    }
    $band = molavi_band($cost, $shelf);
    return [
        'shelf' => $shelf,
        'sale' => $cost,
        'floor' => $band['floor'],
        'ceiling' => $band['ceiling'],
        'max' => $band['max'],
        'discountable' => $band['discountable'],
    ];
}

function molavi_store_curve(WC_Product $product): array
{
    $curve = molavi_curve_from_prices((int) $product->get_regular_price(), (int) $product->get_sale_price());
    $id = $product->get_id();
    update_post_meta($id, '_molavi_shelf', $curve['shelf']);
    update_post_meta($id, '_molavi_sale', $curve['sale']);
    update_post_meta($id, '_molavi_floor', $curve['floor']);
    update_post_meta($id, '_molavi_ceiling', $curve['ceiling']);
    update_post_meta($id, '_molavi_max', (string) $curve['max']);
    update_post_meta($id, '_molavi_discountable', $curve['discountable'] ? '1' : '0');
    update_post_meta($id, '_molavi_curve', '1');
    return $curve;
}

function molavi_curve(WC_Product $product): array
{
    if ((string) $product->get_meta('_molavi_curve') !== '1') {
        return molavi_store_curve($product);
    }
    return [
        'shelf' => (int) $product->get_meta('_molavi_shelf'),
        'sale' => (int) $product->get_meta('_molavi_sale'),
        'floor' => (int) $product->get_meta('_molavi_floor'),
        'ceiling' => (int) $product->get_meta('_molavi_ceiling'),
        'max' => (float) $product->get_meta('_molavi_max'),
        'discountable' => (string) $product->get_meta('_molavi_discountable') === '1',
    ];
}

function molavi_unit_price(array $curve, float $progress): int
{
    $shelf = (int) $curve['shelf'];
    $sale = (int) $curve['sale'];
    if ($shelf <= 0) {
        return $sale;
    }
    $ceiling = (int) $curve['ceiling'];
    $floor = (int) $curve['floor'];
    $unit = molavi_round_half_up($ceiling + ($floor - $ceiling) * $progress);
    $low = min($floor, $ceiling);
    $high = min(max($floor, $ceiling, $low), $shelf);
    if ($unit < $low) {
        $unit = $low;
    }
    if ($unit > $high) {
        $unit = $high;
    }
    return $unit;
}

function molavi_cart_progress(): float
{
    if (!function_exists('WC') || !WC()->cart) {
        return 0.0;
    }
    $measure = 0;
    foreach (WC()->cart->get_cart() as $item) {
        $product = $item['data'] instanceof WC_Product ? $item['data'] : wc_get_product((int) $item['product_id']);
        if (!$product instanceof WC_Product) {
            continue;
        }
        $curve = molavi_curve($product);
        if ($curve['discountable'] && $curve['shelf'] > 0) {
            $measure += $curve['shelf'] * (int) $item['quantity'];
        }
    }
    $unlock = (int) molavi_price_settings()['unlock_target'];
    if ($unlock < 1 || $measure < 1) {
        return 0.0;
    }
    return min(1.0, $measure / $unlock);
}

function molavi_cart_qty(int $productId): int
{
    if ($productId < 1 || !function_exists('WC') || !WC()->cart) {
        return 0;
    }
    $qty = 0;
    foreach (WC()->cart->get_cart() as $item) {
        if ((int) $item['product_id'] === $productId) {
            $qty += (int) $item['quantity'];
        }
    }
    return $qty;
}

function molavi_quote(): array
{
    $progress = molavi_cart_progress();
    $units = [];
    $qtys = [];
    $products = wc_get_products([
        'status' => 'publish',
        'limit' => -1,
        'return' => 'objects',
    ]);
    foreach ($products as $product) {
        $id = (string) $product->get_id();
        $units[$id] = molavi_unit_price(molavi_curve($product), $progress);
        $qty = molavi_cart_qty($product->get_id());
        if ($qty > 0) {
            $qtys[$id] = $qty;
        }
    }
    return [
        'progress' => $progress,
        'unlock' => (int) molavi_price_settings()['unlock_target'],
        'units' => $units,
        'qtys' => $qtys,
    ];
}

function molavi_toman(int $amount): string
{
    return molavi_digits(number_format($amount, 0, '.', ',')) . ' تومان';
}

function molavi_percent(float $rate): string
{
    return molavi_digits((string) (int) round($rate * 100)) . '٪';
}

add_action('woocommerce_before_calculate_totals', static function ($cart): void {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }
    if (!$cart instanceof WC_Cart) {
        return;
    }
    $progress = molavi_cart_progress();
    foreach ($cart->get_cart() as $item) {
        $product = $item['data'] ?? null;
        if (!$product instanceof WC_Product) {
            continue;
        }
        $product->set_price((string) molavi_unit_price(molavi_curve($product), $progress));
    }
}, 20);

function molavi_ajax_quote(): void
{
    wp_send_json_success(molavi_quote());
}

function molavi_ajax_qty(): void
{
    check_ajax_referer('molavi-qty', 'nonce');
    $product = wc_get_product((int) ($_POST['product_id'] ?? 0));
    if (!$product instanceof WC_Product || !$product->is_purchasable()) {
        wp_send_json_error(['message' => 'کالا پیدا نشد.'], 404);
    }
    $qty = (int) ($_POST['qty'] ?? 0);
    $min = molavi_product_min($product);
    if ($qty > 0 && $qty < $min) {
        $qty = $min;
    }
    $qty = min(999, max(0, $qty));
    $cart = WC()->cart;
    $found = '';
    foreach ($cart->get_cart() as $key => $item) {
        if ((int) $item['product_id'] === $product->get_id() && (int) ($item['variation_id'] ?? 0) === 0) {
            $found = (string) $key;
            break;
        }
    }
    if ($qty < 1) {
        if ($found !== '') {
            $cart->remove_cart_item($found);
        }
    } elseif ($found !== '') {
        $cart->set_quantity($found, $qty, true);
    } else {
        $cart->add_to_cart($product->get_id(), $qty);
    }
    $cart->calculate_totals();
    ob_start();
    woocommerce_mini_cart();
    $mini = (string) ob_get_clean();
    $fragments = apply_filters('woocommerce_add_to_cart_fragments', [
        'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>',
    ]);
    wp_send_json_success([
        'quote' => molavi_quote(),
        'fragments' => $fragments,
        'cart_hash' => $cart->get_cart_hash(),
    ]);
}

add_action('wc_ajax_molavi_quote', 'molavi_ajax_quote');
add_action('wc_ajax_nopriv_molavi_quote', 'molavi_ajax_quote');
add_action('wc_ajax_molavi_qty', 'molavi_ajax_qty');
add_action('wc_ajax_nopriv_molavi_qty', 'molavi_ajax_qty');
