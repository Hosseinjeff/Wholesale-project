<?php

declare(strict_types=1);

namespace Molavi\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/** Published information pages that have an agreed navigation label. */
function molavi_site_page_links(): array
{
    if (!function_exists('wc_get_page_id')) {
        return [];
    }

    $links = [];
    foreach (get_pages(['post_status' => 'publish', 'number' => 50]) as $page) {
        if ((int) $page->ID === (int) wc_get_page_id('shop')) {
            continue;
        }
        $slug = strtolower((string) $page->post_name);
        $title = (string) $page->post_title;
        if (in_array($slug, ['about', 'about-us', 'aboutus', 'about-molavisale'], true)
            || strpos($title, 'درباره') !== false) {
            $links['درباره ما'] = get_permalink($page);
        } elseif (in_array($slug, ['contact', 'contact-us', 'contactus'], true)
            || strpos($title, 'تماس') !== false) {
            $links['تماس با ما'] = get_permalink($page);
        }
    }
    return $links;
}

function molavi_render_site_header(): void
{
    if (is_admin() || wp_doing_ajax() || !function_exists('wc_get_page_permalink')) {
        return;
    }

    $shopUrl = wc_get_page_permalink('shop') ?: home_url('/');
    $accountUrl = wc_get_page_permalink('myaccount') ?: home_url('/');
    $cartUrl = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/');
    $isStorefront = function_exists('molavi_is_storefront_request') && \molavi_is_storefront_request();
    $count = 0;
    if (function_exists('WC') && WC() && WC()->cart) {
        $count = (int) WC()->cart->get_cart_contents_count();
    }
    $query = isset($_GET['molavi_q']) && is_scalar($_GET['molavi_q'])
        ? sanitize_text_field(wp_unslash((string) $_GET['molavi_q']))
        : '';
    $favoriteUrl = add_query_arg('molavi_favorites', '1', $shopUrl) . '#molavi-products';
    $pageLinks = molavi_site_page_links();
    ?>
    <header class="molavi-site-header" dir="rtl">
        <div class="molavi-site-header-inner">
            <a class="molavi-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="فروشگاه مولوی‌سل، خانه">
                <svg viewBox="0 0 32 36" aria-hidden="true"><path d="M5 12h22l-2 21H7L5 12Z"></path><path d="M10 13V9a6 6 0 0 1 12 0v4"></path></svg>
                <strong>فروشگاه مولوی‌سل</strong>
            </a>
            <nav class="molavi-main-nav" aria-label="راهنمای سایت">
                <a<?php echo !$isStorefront && is_front_page() ? ' class="is-current"' : ''; ?> href="<?php echo esc_url(home_url('/')); ?>">خانه</a>
                <a<?php echo $isStorefront ? ' class="is-current"' : ''; ?> href="<?php echo esc_url($shopUrl); ?>">فروشگاه شما</a>
                <a href="<?php echo esc_url($shopUrl . '#molavi-products'); ?>">دسته‌بندی‌ها</a>
                <?php foreach ($pageLinks as $label => $url): ?>
                    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <form class="molavi-search" action="<?php echo esc_url($shopUrl); ?>" method="get" role="search" data-molavi-search-form>
                <label class="screen-reader-text" for="molavi-site-search">جستجوی محصولات</label>
                <input id="molavi-site-search" type="search" name="molavi_q" data-molavi-search value="<?php echo esc_attr($query); ?>" placeholder="جستجوی محصولات..." autocomplete="off">
                <button class="molavi-search-submit" type="submit" aria-label="جستجو">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
                </button>
            </form>
            <div class="molavi-header-actions">
                <a class="molavi-header-icon" href="<?php echo esc_url($accountUrl); ?>" aria-label="حساب کاربری">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4 21v-2a8 8 0 0 1 16 0v2"></path></svg>
                </a>
                <?php if ($isStorefront): ?>
                    <button class="molavi-header-icon" type="button" data-molavi-wishlist-view aria-label="نمایش کالاهای موردعلاقه" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg>
                    </button>
                    <button class="molavi-header-cart" type="button" data-molavi-open-basket aria-controls="molavi-basket-dialog" aria-expanded="false" aria-label="مشاهده سبد خرید">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h2l2 11h9l2-8H8"></path><circle cx="10" cy="20" r="1"></circle><circle cx="17" cy="20" r="1"></circle></svg>
                        <span data-molavi-count><?php echo esc_html(\molavi_digits((string) $count)); ?></span>
                    </button>
                <?php else: ?>
                    <a class="molavi-header-icon" href="<?php echo esc_url($favoriteUrl); ?>" aria-label="نمایش کالاهای موردعلاقه">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg>
                    </a>
                    <a class="molavi-header-cart" href="<?php echo esc_url($cartUrl); ?>" aria-label="مشاهده سبد خرید">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h2l2 11h9l2-8H8"></path><circle cx="10" cy="20" r="1"></circle><circle cx="17" cy="20" r="1"></circle></svg>
                        <span data-molavi-count><?php echo esc_html(\molavi_digits((string) $count)); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <?php
}

function molavi_render_site_footer(): void
{
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $shopUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
    ?>
    <footer class="molavi-site-footer" dir="rtl">
        <div class="molavi-site-footer-inner">
            <a class="molavi-footer-brand" href="<?php echo esc_url(home_url('/')); ?>">فروشگاه مولوی‌سل</a>
            <nav aria-label="پیوندهای فروشگاه">
                <a href="<?php echo esc_url($shopUrl); ?>">فروشگاه شما</a>
                <?php foreach (molavi_site_page_links() as $label => $url): ?>
                    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
                <?php if (function_exists('wc_get_page_permalink')): ?>
                    <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">حساب کاربری</a>
                <?php endif; ?>
            </nav>
        </div>
    </footer>
    <?php
}
