<?php
/** Native WooCommerce zone shipping method; register only, never change live zones. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

add_action('woocommerce_shipping_init', static function (): void {
    if (class_exists('Molavi_Tehran_Delivery') || !class_exists('WC_Shipping_Method')) { return; }
    class Molavi_Tehran_Delivery extends WC_Shipping_Method
    {
        public function __construct($instance_id = 0)
        {
            $this->id = 'molavi_tehran_delivery';
            $this->instance_id = absint($instance_id);
            $this->method_title = 'ارسال مولوی‌سل';
            $this->method_description = 'هزینه ثابت و ارسال رایگان بر اساس مبلغ کالا؛ فقط در محدوده حمل‌ونقل انتخاب‌شده.';
            $this->supports = ['shipping-zones', 'instance-settings'];
            $this->instance_form_fields = [
                'title' => ['title' => 'عنوان', 'type' => 'text', 'default' => 'ارسال مولوی‌سل'],
                'fee_toman' => ['title' => 'هزینه ارسال (تومان)', 'type' => 'number', 'default' => '250000', 'custom_attributes' => ['min' => '0', 'step' => '1']],
                'threshold_toman' => ['title' => 'حد ارسال رایگان (تومان)', 'type' => 'number', 'default' => '5000000', 'custom_attributes' => ['min' => '1', 'step' => '1']],
            ];
            $this->init_instance_settings();
            $this->title = (string) $this->get_option('title', 'ارسال مولوی‌سل');
            $this->enabled = 'yes';
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }

        public function calculate_shipping($package = []): void
        {
            if (!WC()->cart || WC()->cart->is_empty()) { return; }
            $currency = get_woocommerce_currency();
            if (!in_array($currency, ['IRT', 'IRR'], true)) { return; }
            $fee = (string) $this->get_option('fee_toman', '250000');
            $threshold = (string) $this->get_option('threshold_toman', '5000000');
            if (!ctype_digit($fee) || !ctype_digit($threshold) || (int) $threshold <= 0 || (int) $fee > 1000000000 || (int) $threshold > 1000000000) { return; }
            // One consolidated shipment per cart; do not charge a fixed fee once per package.
            $packages = WC()->cart->get_shipping_packages();
            if (count($packages) !== 1) { return; }
            $multiplier = $currency === 'IRR' ? 10 : 1;
            // Woo's contents total is after product/coupon discounts and before shipping.
            $goods = (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
            $qualifies = $goods >= ((int) $threshold * $multiplier);
            $this->add_rate(['id' => $this->get_rate_id(), 'label' => $this->title,
                'cost' => $qualifies ? 0 : (int) $fee * $multiplier, 'package' => $package]);
        }
    }
});
add_filter('woocommerce_shipping_methods', static function (array $methods): array {
    $methods['molavi_tehran_delivery'] = 'Molavi_Tehran_Delivery';
    return $methods;
});
