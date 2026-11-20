<?php
declare(strict_types=1);

define('ABSPATH', '/synthetic/');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['discount_options'] = [];
$GLOBALS['discount_transients'] = [];
$GLOBALS['discount_products'] = [];

function add_action(...$args): void {}
function add_filter(...$args): void {}
function get_option($name, $default = false) { return $GLOBALS['discount_options'][$name] ?? $default; }
function add_option($name, $value, ...$args): bool { return update_option($name, $value); }
function update_option($name, $value, ...$args): bool {
    $GLOBALS['discount_options'][$name] = $value;
    return true;
}
function get_transient($key) { return $GLOBALS['discount_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl): void { $GLOBALS['discount_transients'][$key] = $value; }
function delete_transient($key): void { unset($GLOBALS['discount_transients'][$key]); }
function wp_cache_delete(...$args): void {}
function molavi_purge_shop_cache(): void {}
function molavi_domain_lock($name, $work) { return $work(); }
function molavi_domain_fail($code, $status): void { throw new RuntimeException($code, $status); }
function molavi_domain_body_keys(array $body, array $keys): void {
    if (array_diff(array_keys($body), $keys) || array_diff($keys, array_keys($body))) {
        molavi_domain_fail('invalid_body', 400);
    }
}
function wc_get_products(array $args): array {
    return array_keys(array_filter($GLOBALS['discount_products'], static fn($p) => $p->status === 'publish'));
}
function wc_get_product(int $id): ?WC_Product { return $GLOBALS['discount_products'][$id] ?? null; }
function update_post_meta($id, $key, $value): void { wc_get_product($id)->meta[$key] = $value; }

class WC_Product {
    public string $status = 'publish';
    public string $regular = '100000';
    public string $sale = '95000';
    public array $meta = ['_molavi_market_shelf_toman'=>100000,'_molavi_supplier_cost_toman'=>68750];
    public function __construct(public int $id) {}
    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_regular_price($context = 'view') { return $this->regular; }
    public function get_sale_price($context = 'view') { return $this->sale; }
    public function set_regular_price($regular) { $this->regular = $regular; }
    public function set_sale_price($sale) { $this->sale = $sale; }
    public function save() { return $this->id; }
}

require __DIR__.'/../shop-wp/molavi-shop/includes/pricing.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/discount-policy.php';
function price_policy_check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$base=molavi_curve_from_prices(100000,68750);
$profile=['household_cap_ratio'=>0.45,'shop_cap_ratio'=>0.70];
$house=molavi_profile_curve($base,$profile,'household');
$shop=molavi_profile_curve($base,$profile,'shop');
price_policy_check($house['floor']===85938 && $shop['floor']===78125, 'derive both Rani caps directly from the spread');
price_policy_check(molavi_unit_price($shop,0.5)===85605 && molavi_unit_price($shop,1)===78125, 'keep linear movement toward the shop cap');
price_policy_check(molavi_profile_curve(molavi_curve_from_prices(100000,99000),$profile,'shop')['max']===0.007,
    'the direct cap applies to a narrow positive spread');
price_policy_check(molavi_profile_curve(molavi_curve_from_prices(100000,0),$profile,'shop')['max']===0.0,
    'evidence of both prices is needed for a discount');
for ($id=1;$id<=22;$id++) {
    $GLOBALS['discount_products'][$id]=new WC_Product($id);
}
$GLOBALS['discount_products'][22]->status='draft';
$rules=['household_bp'=>0,'shop_bp'=>7000,'household_target'=>4100000,'shop_target'=>15000000];
$payload=['action'=>'save_discount_policy']+$rules;
try {
    molavi_discount_policy_api('POST',array_replace($payload,['household_bp'=>10001]));
    throw new LogicException('An invalid discount was accepted');
} catch (RuntimeException $error) {
    price_policy_check($error->getMessage()==='invalid_discount_policy','validate all owner inputs');
}
$run=molavi_discount_policy_api('POST',$payload)['run'];
price_policy_check($run['state']==='queued' && $run['total']===21, 'queue published products only');
price_policy_check(molavi_unlock_targets()['household']===4100000 && molavi_unlock_targets()['shop']===15000000,
    'use independent saved basket targets');
try {
    molavi_discount_policy_api('POST',$payload);
    throw new LogicException('An overlapping run was accepted');
} catch (RuntimeException $error) {
    price_policy_check($error->getMessage()==='discount_update_running','protect active run from overlapping saves');
}
molavi_reprice_tick();
$status=molavi_discount_activity(['watched'=>(object)[],'groups'=>[]],[['key'=>$run['id']]]);
price_policy_check($status['watched']->{$run['id']}['state']==='running'
    && $status['watched']->{$run['id']}['completed']===20,'report durable intermediate progress');
molavi_reprice_tick();
$finished=molavi_discount_policy_api('GET')['run'];
price_policy_check($finished['completed']===21 && $finished['state']==='completed','complete the batch');
price_policy_check(wc_get_product(1)->sale==='' && wc_get_product(22)->sale==='95000','update Woo sale price and keep the draft untouched');
price_policy_check(molavi_discount_policy_api('POST',$payload)['state']==='unchanged','repeated settings are idempotent');
echo "PASS discount caps, basket targets, and catalog repricing\n";
