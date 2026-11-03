<?php
/**
 * Store archive using Elementor Theme Builder locations without the theme shell.
 */
defined('ABSPATH') || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
if (function_exists('wp_body_open')) {
    wp_body_open();
}
\Molavi\Elementor\molavi_render_site_header(true);
?>
<main id="primary" class="molavi-custom-shop-main">
    <?php \Molavi\Elementor\molavi_render_storefront(); ?>
</main>
<?php \Molavi\Elementor\molavi_render_site_footer(true); ?>
<?php wp_footer(); ?>
</body>
</html>
