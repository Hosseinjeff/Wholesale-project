<?php
/** Shared customer shell; WooCommerce retains its forms, endpoints and payment handlers. */
defined('ABSPATH') || exit;
$GLOBALS['molavi_customer_shell'] = true;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
<?php wp_body_open(); \Molavi\Elementor\molavi_render_site_header(true); ?>
<main id="primary" class="molavi-customer-main">
    <nav class="molavi-customer-breadcrumb" aria-label="مسیر خرید">
        <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">فروشگاه</a>
        <span aria-hidden="true">/</span><span><?php echo esc_html(molavi_customer_page_title() ?? 'خرید'); ?></span>
    </nav>
    <?php
    if (is_product() || (function_exists('is_product_taxonomy') && is_product_taxonomy())) {
        woocommerce_content();
    } elseif (is_cart()) {
        echo do_shortcode('[woocommerce_cart]');
    } elseif (is_checkout()) {
        echo do_shortcode('[woocommerce_checkout]');
    } elseif (is_account_page()) {
        echo '<h1 class="molavi-customer-title">' . esc_html(molavi_customer_page_title() ?? 'حساب کاربری') . '</h1>';
        echo do_shortcode('[woocommerce_my_account]');
    }
    ?>
</main>
<?php \Molavi\Elementor\molavi_render_site_footer(true); wp_footer(); ?>
</body></html>
