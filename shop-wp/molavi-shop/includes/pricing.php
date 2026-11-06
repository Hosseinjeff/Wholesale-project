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
        'household_unlock_fallback' => 5000000,
        'tuna_shell_units' => 24,
        'shop_unlock_multiplier' => 4,
        'household_discount_cap_ratio' => 0.50,
        'shop_discount_cap_ratio' => 1.00,
        'household_discount_threshold_units' => 1,
        'shop_discount_threshold_units' => 4,
    ];
    $given = get_option('molavi_price_settings');
    if (!is_array($given)) {
        add_option('molavi_price_settings', $settings, '', false);
        return $settings;
    }
    foreach ($settings as $field => $default) {
        if (!isset($given[$field]) || !is_numeric($given[$field])) {
            continue;
        }
        $settings[$field] = in_array($field, ['min_profit', 'unlock_target',
            'household_unlock_fallback', 'tuna_shell_units', 'shop_unlock_multiplier',
            'household_discount_threshold_units', 'shop_discount_threshold_units'], true)
            ? (int) $given[$field]
            : (float) $given[$field];
    }
    $stored = array_merge($given, $settings);
    if ($stored !== $given) {
        update_option('molavi_price_settings', $stored, false);
    }
    return $settings;
}

function molavi_buyer_type(): string
{
    if (!function_exists('WC') || !WC()->session) { return 'household'; }
    $type = (string) WC()->session->get('molavi_buyer_type', 'household');
    return in_array($type, ['household', 'shop'], true) ? $type : 'household';
}

function molavi_set_buyer_type(string $type): bool
{
    if (!in_array($type, ['household', 'shop'], true) || !function_exists('WC') || !WC()->session) {
        return false;
    }
    WC()->session->set('molavi_buyer_type', $type);
    return true;
}

function molavi_profile_value($product, string $metaKey, int $fallback, int $minimum = 1, int $maximum = 999): int
{
    if (!$product instanceof WC_Product) { return max($minimum, min($maximum, $fallback)); }
    $value = $product->get_meta($metaKey, true);
    if ($value === '' || !is_numeric($value)) { return max($minimum, min($maximum, $fallback)); }
    return max($minimum, min($maximum, (int) $value));
}

function molavi_product_profile(WC_Product $product): array
{
    $packSize = (int) $product->get_meta('_molavi_pack_size', true);
    $legacyMin = (int) $product->get_meta('_molavi_min', true);
    if ($packSize <= 0) {
        $packText = $product->get_name() . ' ' . (string) $product->get_meta('_molavi_packaging', true);
        if (preg_match('/(?<!\\d)(\\d{1,3})\\s*(?:عددی|تایی|عدد|ع)(?=$|[^\\p{L}\\p{N}])/u', $packText, $match)) {
            $packSize = (int) $match[1];
        }
    }
    $packSize = max(1, min(999, $packSize > 0 ? $packSize : ($legacyMin > 0 ? $legacyMin : 1)));
    // Confirmed per-pack customer minimums/steps. The fallback keeps coverage for
    // pack sizes that do not have an explicit business-approved row yet.
    $orderUnitsByPack = [
        6 => ['household' => 1, 'shop' => 3],
        12 => ['household' => 3, 'shop' => 6],
        18 => ['household' => 4, 'shop' => 9],
        24 => ['household' => 4, 'shop' => 12],
        36 => ['household' => 8, 'shop' => 18],
        48 => ['household' => 12, 'shop' => 24],
    ];
    $defaultShopUnit = $orderUnitsByPack[$packSize]['shop'] ?? max(1, (int) ceil($packSize / 4));
    $defaultHouseholdUnit = $orderUnitsByPack[$packSize]['household']
        ?? max(1, (int) ceil($defaultShopUnit / 2));
    $shopUnit = molavi_profile_value($product, '_molavi_shop_order_unit', $defaultShopUnit);
    $householdUnit = molavi_profile_value($product, '_molavi_household_order_unit', $defaultHouseholdUnit);
    $orderUnitsDefaulted = $householdUnit > $shopUnit;
    if ($orderUnitsDefaulted) {
        $shopUnit = $defaultShopUnit;
        $householdUnit = $defaultHouseholdUnit;
    }
    $settings = molavi_price_settings();
    $householdRatio = $product->get_meta('_molavi_household_discount_cap_ratio', true);
    $shopRatio = $product->get_meta('_molavi_shop_discount_cap_ratio', true);
    $householdThreshold = $product->get_meta('_molavi_household_discount_threshold_units', true);
    $shopThreshold = $product->get_meta('_molavi_shop_discount_threshold_units', true);
    $householdCap = is_numeric($householdRatio) && $householdRatio !== ''
        ? max(0.0, min(1.0, (float) $householdRatio))
        : max(0.0, min(1.0, (float) $settings['household_discount_cap_ratio']));
    $shopCap = is_numeric($shopRatio) && $shopRatio !== ''
        ? max(0.0, min(1.0, (float) $shopRatio))
        : max(0.0, min(1.0, (float) $settings['shop_discount_cap_ratio']));
    if ($shopCap <= $householdCap) {
        if ($householdCap >= 1.0) {
            $householdCap = 0.95;
            $shopCap = 1.0;
        } else {
            $shopCap = min(1.0, $householdCap + 0.05);
        }
    }
    return [
        'pack_size' => $packSize,
        'household_unit' => $householdUnit,
        'shop_unit' => $shopUnit,
        'order_units_defaulted' => $orderUnitsDefaulted,
        'household_threshold_units' => is_numeric($householdThreshold) && $householdThreshold !== ''
            ? max(1, min(100, (int) $householdThreshold)) : max(1, (int) $settings['household_discount_threshold_units']),
        'shop_threshold_units' => is_numeric($shopThreshold) && $shopThreshold !== ''
            ? max(1, min(100, (int) $shopThreshold)) : max(1, (int) $settings['shop_discount_threshold_units']),
        'household_cap_ratio' => $householdCap,
        'shop_cap_ratio' => $shopCap,
        'tuna_threshold_anchor' => (string) $product->get_meta('_molavi_tuna_threshold_anchor', true) === '1',
    ];
}

function molavi_profile_order_unit($product, ?string $buyerType = null): int
{
    if (!$product instanceof WC_Product) { return 1; }
    $profile = molavi_product_profile($product);
    return ($buyerType ?: molavi_buyer_type()) === 'shop'
        ? $profile['shop_unit'] : $profile['household_unit'];
}

function molavi_profile_curve(array $curve, array $profile, string $buyerType): array
{
    if (empty($curve['discountable']) || (int) $curve['shelf'] <= 0) { return $curve; }
    $ratio = max(0.0, min(1.0, (float) ($profile[$buyerType . '_cap_ratio'] ?? 1.0)));
    $shelf = (int) $curve['shelf'];
    $discountRoom = max(0, $shelf - (int) $curve['floor']);
    $floor = molavi_round_up($shelf - $discountRoom * $ratio);
    $curve['floor'] = max((int) $curve['floor'], min($shelf, $floor));
    $curve['ceiling'] = max($curve['floor'], min($shelf, (int) $curve['ceiling']));
    $curve['max'] = max(0.0, ($shelf - $curve['floor']) / $shelf);
    return $curve;
}

function molavi_unlock_targets(array $map): array
{
    $settings = molavi_price_settings();
    $fallback = max(1, (int) $settings['household_unlock_fallback']);
    $shellUnits = max(1, (int) $settings['tuna_shell_units']);
    $multiplier = max(1, (int) $settings['shop_unlock_multiplier']);
    $candidates = [];
    foreach ($map as $id => $row) {
        $preferred = !empty($row['tuna_threshold_anchor']);
        if ((!$preferred && empty($row['tuna_threshold_candidate'])) || (int) ($row['shelf'] ?? 0) <= 0) { continue; }
        $candidates[] = [
            'id' => (string) $id,
            'name' => (string) ($row['name'] ?? ''),
            'unit_price' => (int) $row['shelf'],
            'preferred' => $preferred,
        ];
    }
    $preferred = array_values(array_filter($candidates, static fn(array $row): bool => $row['preferred']));
    $pool = $preferred ?: $candidates;
    usort($pool, static fn(array $a, array $b): int => ($a['unit_price'] <=> $b['unit_price']) ?: strcmp($a['id'], $b['id']));
    $anchor = $pool ? $pool[(int) floor((count($pool) - 1) / 2)] : null;
    $unitPrice = $anchor ? (int) $anchor['unit_price'] : 0;
    $household = $unitPrice > 0 ? $unitPrice * $shellUnits : $fallback;
    return [
        'household' => $household,
        'shop' => $household * $multiplier,
        'shell_units' => $shellUnits,
        'shop_multiplier' => $multiplier,
        'anchor_unit_price' => $unitPrice,
        'anchor_name' => $anchor ? $anchor['name'] : '',
        'anchor_product_id' => $anchor ? $anchor['id'] : '',
        'anchor_count' => count($pool),
        'dynamic' => $unitPrice > 0,
    ];
}

function molavi_is_tuna_threshold_candidate(string $name): bool
{
    $normalized = strtolower(strtr($name, ['ي' => 'ی', 'ك' => 'ک']));
    $normalized = (string) preg_replace('/[\x{200b}-\x{200f}\x{feff}\s]+/u', '', $normalized);
    $normalized = (string) preg_replace('/[\x{064b}-\x{065f}\x{0670}]/u', '', $normalized);
    if (!preg_match('/(?:تنماهی|ماهیتن|تونا|tuna)/iu', $normalized)) {
        return false;
    }
    return !preg_match('/(?:طعم|نودل|سوپ|رشته|پاستا|چیپس|اسنک|noodles?|flavou?r)/iu', $name);
}

function molavi_eligible_reference_total(array $map, array $qtys): int
{
    $total = 0;
    foreach ($qtys as $id => $quantity) {
        $row = $map[(string) $id] ?? null;
        $qty = max(0, (int) $quantity);
        if ($row && $qty > 0 && !empty($row['discountable'])) {
            $total += max(0, (int) ($row['shelf'] ?? 0)) * $qty;
        }
    }
    return $total;
}

function molavi_catalog_product_price(array $row, int $quantity, string $buyerType, ?float $cartProgress = null): array
{
    $unit = max(1, (int) ($row[$buyerType . '_unit'] ?? $row['min'] ?? 1));
    $thresholdUnits = max(1, (int) ($row[$buyerType . '_threshold_units'] ?? 1));
    $profiled = molavi_profile_curve($row, $row, $buyerType);
    $progress = $cartProgress === null
        ? min(1.0, max(0, $quantity) / ($unit * $thresholdUnits))
        : min(1.0, max(0.0, $cartProgress));
    return [
        'price' => molavi_unit_price($profiled, $progress),
        'progress' => $progress,
        'order_unit' => $unit,
        'threshold_units' => $thresholdUnits,
        'threshold_quantity' => $unit * $thresholdUnits,
        'curve' => $profiled,
    ];
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
    $storedShelf = $product->get_meta('_molavi_market_shelf_toman', true);
    $storedCost = $product->get_meta('_molavi_supplier_cost_toman', true);
    if ($storedShelf !== '' && $storedCost !== '') {
        $curve = molavi_curve_from_prices((int) $storedShelf, (int) $storedCost);
    } else {
        // Read earlier private cost fields before falling back to legacy Woo prices.
        $cost = (int) $product->get_meta('_molavi_cost');
        $shelf = $storedShelf !== '' ? (int) $storedShelf : (int) $product->get_regular_price();
        if ($cost > 0 && $shelf > 0) {
            $curve = molavi_curve_from_prices($shelf, $cost);
        } else {
            // Migrate products created before supplier cost was separated from
            // the public WooCommerce sale price.
            $curve = molavi_curve_from_prices((int) $product->get_regular_price(), (int) $product->get_sale_price());
        }
        update_post_meta($product->get_id(), '_molavi_market_shelf_toman', $curve['shelf']);
        update_post_meta($product->get_id(), '_molavi_supplier_cost_toman', $curve['sale']);
    }
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

function molavi_catalog_map(): array
{
    $map = get_transient('molavi_catalog_v4');
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    foreach (wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'objects']) as $product) {
        $curve = molavi_curve($product);
        $profile = molavi_product_profile($product);
        $householdCurve = molavi_profile_curve($curve, $profile, 'household');
        $shopCurve = molavi_profile_curve($curve, $profile, 'shop');
        $map[(string) $product->get_id()] = [
            'name' => $product->get_name(),
            'tuna_threshold_candidate' => molavi_is_tuna_threshold_candidate($product->get_name()),
            'tuna_threshold_anchor' => $profile['tuna_threshold_anchor'],
            // Keep the cached legacy minimum independent of the visitor session.
            // Profile-specific quantities use household_unit/shop_unit below.
            'min' => $profile['shop_unit'],
            'pack_size' => $profile['pack_size'],
            'household_unit' => $profile['household_unit'],
            'shop_unit' => $profile['shop_unit'],
            'household_threshold_units' => $profile['household_threshold_units'],
            'shop_threshold_units' => $profile['shop_threshold_units'],
            'household_cap_ratio' => $profile['household_cap_ratio'],
            'shop_cap_ratio' => $profile['shop_cap_ratio'],
            'household_max_discount' => (float) $householdCurve['max'],
            'shop_max_discount' => (float) $shopCurve['max'],
            'shelf' => $curve['shelf'],
            'sale' => $curve['sale'],
            'floor' => $curve['floor'],
            'ceiling' => $curve['ceiling'],
            'max' => $curve['max'],
            'discountable' => $curve['discountable'] ? 1 : 0,
        ];
    }
    set_transient('molavi_catalog_v4', $map, DAY_IN_SECONDS);
    return $map;
}

function molavi_forget_catalog(): void
{
    delete_transient('molavi_catalog_v1');
    delete_transient('molavi_catalog_v2');
    delete_transient('molavi_catalog_v3');
    delete_transient('molavi_catalog_v4');
}

add_action('woocommerce_update_product', 'molavi_forget_catalog');
add_action('woocommerce_new_product', 'molavi_forget_catalog');
add_action('update_option_molavi_price_settings', 'molavi_forget_catalog');

function molavi_price_for_product(WC_Product $product, ?int $quantity = null, ?string $buyerType = null): array
{
    $type = $buyerType ?: molavi_buyer_type();
    $row = molavi_catalog_map()[(string) $product->get_id()] ?? null;
    if (!is_array($row)) {
        $row = array_merge(molavi_curve($product), molavi_product_profile($product));
        $row['min'] = molavi_profile_order_unit($product, $type);
    }
    return molavi_catalog_product_price($row,
        $quantity === null ? molavi_cart_qty($product->get_id()) : $quantity,
        $type, molavi_cart_progress($type));
}

function molavi_cart_progress(?string $buyerType = null): float
{
    if (!function_exists('WC') || !WC()->cart) {
        return 0.0;
    }
    $map = molavi_catalog_map();
    $qtys = [];
    foreach (WC()->cart->get_cart() as $item) {
        $id = (string) $item['product_id'];
        $qtys[$id] = ($qtys[$id] ?? 0) + (int) $item['quantity'];
    }
    $buyer = $buyerType ?: molavi_buyer_type();
    $targets = molavi_unlock_targets($map);
    $target = max(1, (int) $targets[$buyer]);
    return min(1.0, molavi_eligible_reference_total($map, $qtys) / $target);
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

function molavi_invalid_cart_lines(string $buyerType, array $map, array $qtys): array
{
    $invalid = [];
    foreach ($qtys as $id => $quantity) {
        $row = $map[(string) $id] ?? null;
        $qty = (int) $quantity;
        if (!$row || $qty < 1) { continue; }
        $step = max(1, (int) ($row[$buyerType . '_unit'] ?? 1));
        if ($qty >= $step && $qty % $step === 0) { continue; }
        $invalid[] = [
            'product_id' => (string) $id,
            'name' => (string) ($row['name'] ?? ''),
            'quantity' => $qty,
            'order_unit' => $step,
            'needed_quantity' => max($step, (int) ceil($qty / $step) * $step),
        ];
    }
    return $invalid;
}

function molavi_buyer_profile_suggestion(string $buyerType, array $map, array $qtys, array $targets): ?array
{
    if (!$qtys) { return null; }
    $householdValid = true;
    foreach ($qtys as $id => $quantity) {
        $row = $map[(string) $id] ?? null;
        $qty = (int) $quantity;
        if (!$row || $qty < 1) { continue; }
        $houseUnit = max(1, (int) ($row['household_unit'] ?? 1));
        if ($qty < $houseUnit || $qty % $houseUnit !== 0) { $householdValid = false; }
    }
    $eligible = molavi_eligible_reference_total($map, $qtys);
    if ($buyerType === 'household' && $eligible > (int) $targets['household']) {
        return ['buyer_type' => 'shop', 'reason' => 'cart_exceeds_household_threshold',
            'eligible_reference' => $eligible, 'target' => (int) $targets['shop']];
    }
    if ($buyerType === 'shop' && $eligible < (int) $targets['shop'] && $householdValid) {
        return ['buyer_type' => 'household', 'reason' => 'cart_below_shop_discount_threshold',
            'eligible_reference' => $eligible, 'target' => (int) $targets['household']];
    }
    return null;
}

function molavi_quote(): array
{
    $buyer = molavi_buyer_type();
    $units = [];
    $qtys = [];
    $map = molavi_catalog_map();
    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $item) {
            $id = (string) $item['product_id'];
            $qtys[$id] = ($qtys[$id] ?? 0) + (int) $item['quantity'];
        }
    }
    $targets = molavi_unlock_targets($map);
    $eligibleReference = molavi_eligible_reference_total($map, $qtys);
    $target = max(1, (int) $targets[$buyer]);
    $cartProgress = min(1.0, $eligibleReference / $target);
    foreach ($map as $id => $row) {
        $price = molavi_catalog_product_price($row, (int) ($qtys[$id] ?? 0), $buyer, $cartProgress);
        $units[$id] = $price['price'];
    }
    $reference = 0;
    $subtotal = 0;
    $savings = 0;
    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $item) {
            $id = (string) $item['product_id'];
            $qty = (int) $item['quantity'];
            if ($qty > 0) {
                $row = $map[$id] ?? null;
                if (!$row) {
                    continue;
                }
                $shelf = (int) $row['shelf'];
                $unit = (int) ($units[$id] ?? $row['sale']);
                $subtotal += $unit * $qty;
                if ($shelf > 0) {
                    $reference += $shelf * $qty;
                    $savings += max(0, $shelf - $unit) * $qty;
                }
            }
        }
    }
    $invalid = molavi_invalid_cart_lines($buyer, $map, $qtys);
    $suggestion = molavi_buyer_profile_suggestion($buyer, $map, $qtys, $targets);
    return [
        'progress' => $cartProgress,
        'unlock' => $target,
        'remaining' => max(0, $target - $eligibleReference),
        'buyer_type' => $buyer,
        'invalid_lines' => $invalid,
        'switch_suggestion' => $suggestion,
        'profiles' => array_map(static function ($row): array {
            return ['household_unit' => $row['household_unit'], 'shop_unit' => $row['shop_unit'],
                    'household_threshold_units' => $row['household_threshold_units'],
                    'shop_threshold_units' => $row['shop_threshold_units'],
                    'household_max_discount' => $row['household_max_discount'],
                    'shop_max_discount' => $row['shop_max_discount']];
        }, $map),
        'eligible_reference' => $eligibleReference,
        'household_unlock' => (int) $targets['household'],
        'shop_unlock' => (int) $targets['shop'],
        'tuna_shell_units' => (int) $targets['shell_units'],
        'shop_unlock_multiplier' => (int) $targets['shop_multiplier'],
        'tuna_anchor_unit_price' => (int) $targets['anchor_unit_price'],
        'tuna_anchor_name' => (string) $targets['anchor_name'],
        'tuna_anchor_product_id' => (string) $targets['anchor_product_id'],
        'threshold_dynamic' => (bool) $targets['dynamic'],
        'reference_total' => $reference,
        'subtotal' => $subtotal,
        'savings' => $savings,
        'savings_rate' => $reference > 0 ? $savings / $reference : 0.0,
        'count' => array_sum($qtys),
        'kinds' => count($qtys),
        'units' => $units,
        'qtys' => $qtys,
    ];
}

function molavi_render_buyer_profile_selector(string $context = 'shop'): void
{
    $selected = molavi_buyer_type();
    $compact = $context !== 'shop';
    $label = $compact ? 'نوع خرید' : 'قیمت برای';
    $targets = molavi_unlock_targets(molavi_catalog_map());
    $benchmarkText = $targets['dynamic']
        ? 'مبنای روز: ' . (string) $targets['anchor_name'] . ' با قیمت مرجع ' . molavi_toman((int) $targets['anchor_unit_price']) . ' برای هر قوطی.'
        : 'قیمت مرجع تن‌ماهی هنوز در کاتالوگ فروشگاه نیست؛ تا ثبت آن، آستانه خانوار روی مقدار فعلی ' . molavi_toman((int) $targets['household']) . ' می‌ماند.';
    ?>
    <section class="molavi-buyer-choice<?php echo $compact ? ' molavi-buyer-choice-compact' : ''; ?>" data-molavi-buyer-choice aria-label="انتخاب نوع خریدار">
        <strong class="molavi-buyer-choice-title"><?php echo esc_html($label); ?></strong>
        <div class="molavi-buyer-choice-buttons" role="group" aria-label="نوع خریدار">
            <button type="button" data-molavi-buyer="household" aria-pressed="<?php echo $selected === 'household' ? 'true' : 'false'; ?>"><?php echo $compact ? 'خانوار' : 'خریدار خانوار'; ?></button>
            <button type="button" data-molavi-buyer="shop" aria-pressed="<?php echo $selected === 'shop' ? 'true' : 'false'; ?>"><?php echo $compact ? 'فروشگاه' : 'صاحب فروشگاه'; ?></button>
        </div>
        <?php if (!$compact): ?>
            <details class="molavi-buyer-choice-details">
                <summary aria-label="توضیح نوع خرید">راهنما</summary>
                <p class="molavi-buyer-choice-help" data-molavi-buyer-help><?php echo esc_html(
                    $benchmarkText . ' آستانه خانوار ارزش ' . molavi_digits((string) $targets['shell_units']) . ' قوطی تن‌ماهی (' . molavi_toman((int) $targets['household']) . ') است؛ آستانه فروشگاه ' . molavi_digits((string) $targets['shop_multiplier']) . ' برابر آن (' . molavi_toman((int) $targets['shop']) . '). تخفیف فروشگاه می‌تواند تا کف مجاز قیمت و نزدیک‌تر به بهای تأمین‌کننده برسد.'
                ); ?></p>
            </details>
        <?php endif; ?>
        <div class="molavi-buyer-feedback" data-molavi-buyer-feedback role="status" aria-live="polite" hidden></div>
    </section>
    <?php
}

function molavi_normalize_order_quantity(int $quantity, int $step): int
{
    $step = max(1, min(999, $step));
    if ($quantity < 1) { return 0; }
    return min(999 - (999 % $step), max($step, (int) ceil($quantity / $step) * $step));
}

function molavi_buyer_profile_ajax(): void
{
    check_ajax_referer('molavi-qty', 'nonce');
    $type = isset($_POST['buyer_type']) ? sanitize_key(wp_unslash((string) $_POST['buyer_type'])) : '';
    if (!molavi_set_buyer_type($type)) {
        wp_send_json_error(['message' => 'نوع خریدار معتبر نیست.'], 400);
    }
    if (WC()->cart instanceof WC_Cart) { WC()->cart->calculate_totals(); }
    wp_send_json_success(['quote' => molavi_quote()]);
}

function molavi_toman(int $amount): string
{
    return molavi_digits(number_format($amount, 0, '.', ',')) . ' تومان';
}

function molavi_percent(float $rate): string
{
    $percent = round($rate * 100, 1);
    $text = abs($percent - round($percent)) < 0.00001
        ? (string) (int) round($percent)
        : number_format($percent, 1, '.', '');
    return molavi_digits($text) . '٪';
}

add_action('woocommerce_before_calculate_totals', static function ($cart): void {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }
    if (!$cart instanceof WC_Cart) {
        return;
    }
    $map = molavi_catalog_map();
    $buyer = molavi_buyer_type();
    $cartProgress = molavi_cart_progress($buyer);
    foreach ($cart->get_cart() as $item) {
        $product = $item['data'] ?? null;
        if (!$product instanceof WC_Product) {
            continue;
        }
        $row = $map[(string) $product->get_id()] ?? null;
        if ($row === null) {
            continue;
        }
        $calculated = molavi_catalog_product_price($row, (int) $item['quantity'], $buyer, $cartProgress);
        $product->set_price((string) $calculated['price']);
    }
}, 20);

function molavi_ajax_quote(): void
{
    wp_send_json_success(molavi_quote());
}

function molavi_set_cart_qty(int $productId, int $qty): bool
{
    $product = wc_get_product($productId);
    if (!$product instanceof WC_Product || !$product->is_purchasable()) {
        return false;
    }
    $qty = min(999, max(0, $qty));
    $min = molavi_product_min($product);
    $qty = molavi_normalize_order_quantity($qty, $min);
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
        return true;
    }
    if ($found !== '') {
        $cart->set_quantity($found, $qty, false);
        return true;
    }
    return (bool) $cart->add_to_cart($product->get_id(), $qty);
}

function molavi_ajax_qty(): void
{
    check_ajax_referer('molavi-qty', 'nonce');
    $cart = WC()->cart;
    if (!$cart instanceof WC_Cart) {
        wp_send_json_error(['message' => 'سبد در دسترس نیست.'], 500);
    }
    $items = [];
    $raw = isset($_POST['items']) ? wp_unslash((string) $_POST['items']) : '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $id => $qty) {
                if (!is_numeric($id) || !is_numeric($qty)) {
                    continue;
                }
                $items[(int) $id] = (int) $qty;
                if (count($items) >= 40) {
                    break;
                }
            }
        }
    }
    if ($items === [] && is_numeric($_POST['product_id'] ?? null)) {
        $items[(int) $_POST['product_id']] = (int) ($_POST['qty'] ?? 0);
    }
    $changed = false;
    foreach ($items as $id => $qty) {
        if (molavi_set_cart_qty($id, $qty)) {
            $changed = true;
        }
    }
    if ($changed) {
        $cart->calculate_totals();
    }
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
add_action('wc_ajax_molavi_buyer', 'molavi_buyer_profile_ajax');
add_action('wc_ajax_nopriv_molavi_buyer', 'molavi_buyer_profile_ajax');

add_action('woocommerce_before_cart', static function (): void {
    molavi_render_buyer_profile_selector('cart');
}, 5);

add_action('woocommerce_check_cart_items', static function (): void {
    if (!function_exists('WC') || !WC()->cart) { return; }
    $invalid = molavi_invalid_cart_lines(molavi_buyer_type(), molavi_catalog_map(), molavi_quote()['qtys']);
    if (!$invalid) { return; }
    $names = implode('، ', array_map(static fn($item): string =>
        $item['name'] . ' (' . molavi_digits((string) $item['quantity']) . '؛ لازم: ' . molavi_digits((string) $item['needed_quantity']) . ')', $invalid));
    wc_add_notice('تعداد این کالاها با واحد سفارش انتخاب‌شده هماهنگ نیست: ' . $names . '. تعداد را اصلاح کنید یا نوع خریدار را تغییر دهید.', 'error');
});

add_filter('woocommerce_update_cart_validation', static function (bool $passed, string $cartItemKey, array $values, int $quantity): bool {
    if ($quantity < 1) { return $passed; }
    $product = $values['data'] ?? null;
    $step = $product instanceof WC_Product ? molavi_product_min($product) : 1;
    if ($quantity % $step !== 0) {
        wc_add_notice('تعداد این کالا باید مضربی از واحد سفارش (' . molavi_digits((string) $step) . ') باشد.', 'error');
        return false;
    }
    return $passed;
}, 10, 4);
