<?php
declare(strict_types=1);

// Exercise the same quote and WooCommerce price hook with synthetic products.
define('ABSPATH', __DIR__);

class WC_Product
{
    public string $price = '';
    public function __construct(private int $id) {}
    public function get_id(): int { return $this->id; }
    public function set_price(string $price): void { $this->price = $price; }
}

class WC_Cart
{
    public function __construct(private array $lines) {}
    public function get_cart(): array { return $this->lines; }
}

function WC(): object { return $GLOBALS['woocommerce']; }
function get_transient(string $key): array { return $GLOBALS['catalog']; }
function get_option(string $key): array { return $GLOBALS['options'][$key] ?? []; }
function update_option(string $key, array $value, bool $autoload): void { $GLOBALS['options'][$key] = $value; }
function add_action(string $name, $callback, int $priority = 10): void { $GLOBALS['actions'][$name][] = $callback; }
function add_filter(...$args): void {}
function is_admin(): bool { return false; }

require __DIR__ . '/../shop-wp/molavi-shop/includes/pricing.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assert_checkout_matches_quote(WC_Cart $cart, array $quote): void
{
    foreach ($GLOBALS['actions']['woocommerce_before_calculate_totals'] as $callback) {
        $callback($cart);
    }
    foreach ($cart->get_cart() as $line) {
        check((int) $line['data']->price === $quote['units'][$line['product_id']], 'Checkout must match cart quote');
    }
}

$tuna = ['name'=>'Tuna', 'pack_size'=>24, 'shelf'=>220000, 'sale'=>150000, 'floor'=>150000, 'ceiling'=>220000,
    'discountable'=>1, 'tuna_threshold_anchor'=>true, 'household_unit'=>4, 'shop_unit'=>12,
    'household_cap_ratio'=>0.5, 'shop_cap_ratio'=>1.0, 'household_threshold_units'=>1, 'shop_threshold_units'=>4,
    'household_max_discount'=>35000/220000, 'shop_max_discount'=>70000/220000];
$beans = array_replace($tuna, ['name'=>'Beans', 'pack_size'=>12, 'shelf'=>100000, 'sale'=>60000, 'floor'=>60000,
    'ceiling'=>100000, 'tuna_threshold_anchor'=>false, 'household_unit'=>3, 'shop_unit'=>6,
    'household_max_discount'=>0.2, 'shop_max_discount'=>0.4]);
$GLOBALS['catalog'] = [70=>$tuna, 68=>$beans];
$GLOBALS['woocommerce'] = (object) ['session'=>new class {
    public string $profile = 'household';
    public function get(string $key, string $default): string { return $this->profile; }
}];

foreach ([20, 24, 28, 48] as $quantity) {
    $tunaProduct = new WC_Product(70);
    $beansProduct = new WC_Product(68);
    // The same product can occupy multiple cart lines. It qualifies by combined quantity.
    $cart = new WC_Cart([
        ['product_id'=>70, 'quantity'=>4, 'data'=>$tunaProduct],
        ['product_id'=>70, 'quantity'=>$quantity - 4, 'data'=>new WC_Product(70)],
        ['product_id'=>68, 'quantity'=>3, 'data'=>$beansProduct],
    ]);
    WC()->cart = $cart;
    $quote = molavi_quote();
    $fullPack = $quantity % 24 === 0;
    $expectedReference = 300000 + ($fullPack ? 0 : $quantity * 220000);
    check($quote['eligible_reference'] === $expectedReference, 'Only exact full-pack lines are excluded from progress');
    check(abs($quote['progress'] - min(1, $expectedReference / 5280000)) < 0.000001, 'Remaining lines determine progress');
    check((($quote['full_pack_sizes']->{70} ?? 0) === 24) === $fullPack, 'Badge state must track pack multiples');
    check($quote['full_pack_savings'] === ($fullPack ? $quantity * 35000 : 0), 'Full-pack savings are tracked separately');
    if ($fullPack) check($quote['units'][70] === 185000 && $quote['units'][68] > 80000, 'Each line gets its own discount');
    check($quote['subtotal'] + $quote['savings'] === $quote['reference_total'], 'Full-pack savings still count in the cart');
    assert_checkout_matches_quote($cart, $quote);
}

WC()->session->profile = 'shop';
WC()->cart = new WC_Cart([['product_id'=>70, 'quantity'=>24, 'data'=>new WC_Product(70)]]);
$quote = molavi_quote();
check($quote['units'][70] === 150000 && (float) $quote['progress'] === 0.0, 'Shop floor applies with a full-pack-only cart');
assert_checkout_matches_quote(WC()->cart, $quote);
check(molavi_full_pack_size(array_replace($tuna, ['pack_size'=>1]), 24, 'shop') === 0, 'Single or unknown packs do not qualify');
check(molavi_full_pack_size(array_replace($tuna, ['household_cap_ratio'=>0]), 24, 'household') === 0, 'A profile without a discount does not qualify');
check(molavi_full_pack_size(array_replace($tuna, ['discountable'=>0]), 24, 'shop') === 0, 'Fixed prices do not qualify');
echo "Full-pack pricing, aggregation, quote and checkout checks passed\n";
