<?php
/**
 * Store archive used on the WooCommerce Shop page and the configured static front page.
 */
defined('ABSPATH') || exit;

get_header();
?>
<main id="primary" class="molavi-custom-shop-main">
    <?php \Molavi\Elementor\molavi_render_storefront(); ?>
</main>
<?php
get_footer();
