<?php

declare(strict_types=1);

namespace Molavi\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

function molavi_render_storefront(): void
{
    if (!function_exists('wc_get_products') || !function_exists('WC')) {
        echo '<p class="molavi-store-message">برای نمایش فروشگاه، ووکامرس باید فعال باشد.</p>';
        return;
    }

    $products = wc_get_products([
        'status' => 'publish',
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects',
    ]);
    $products = array_values(array_filter($products, static function ($product): bool {
        return $product instanceof \WC_Product && $product->is_purchasable() && $product->is_in_stock();
    }));
    $quote = molavi_quote();
    $categories = [];
    foreach ($products as $product) {
        $category = trim((string) $product->get_meta('_molavi_category'));
        if ($category === '') {
            $terms = get_the_terms($product->get_id(), 'product_cat');
            if (is_array($terms) && $terms !== []) {
                $category = (string) $terms[0]->name;
            }
        }
        if ($category !== '') {
            $categories[$category] = true;
        }
    }
    $categories = array_keys($categories);
    sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
    $shopUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
    $accountUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
    $pageLinks = [];
    foreach (get_pages(['post_status' => 'publish', 'number' => 50]) as $page) {
        if ((int) $page->ID === (int) wc_get_page_id('shop')) {
            continue;
        }
        $slug = strtolower((string) $page->post_name);
        $label = '';
        if (in_array($slug, ['about', 'about-us', 'aboutus', 'about-molavisale'], true) || strpos((string) $page->post_title, 'درباره') !== false) {
            $label = 'درباره ما';
        } elseif (in_array($slug, ['contact', 'contact-us', 'contactus'], true) || strpos((string) $page->post_title, 'تماس') !== false) {
            $label = 'تماس با ما';
        }
        if ($label !== '') {
            $pageLinks[$label] = get_permalink($page);
        }
    }
    ?>
    <section class="molavi-storefront" data-molavi-storefront aria-label="فروشگاه مولوی‌سل">
        <header class="molavi-site-header">
            <div class="molavi-site-header-inner">
                <a class="molavi-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="فروشگاه مولوی‌سل، خانه">
                    <svg viewBox="0 0 32 36" aria-hidden="true"><path d="M5 12h22l-2 21H7L5 12Z"></path><path d="M10 13V9a6 6 0 0 1 12 0v4"></path></svg>
                    <strong>فروشگاه مولوی‌سل</strong>
                </a>
                <nav class="molavi-main-nav" aria-label="راهنمای سایت">
                    <a href="<?php echo esc_url(home_url('/')); ?>">خانه</a>
                    <a class="is-current" href="<?php echo esc_url($shopUrl); ?>">فروشگاه شما</a>
                    <a href="#molavi-products">دسته‌بندی‌ها</a>
                    <?php foreach ($pageLinks as $label => $url): ?>
                        <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </nav>
                <label class="molavi-search">
                    <span class="screen-reader-text">جستجوی محصولات</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
                    <input type="search" data-molavi-search placeholder="جستجوی محصولات..." autocomplete="off">
                </label>
                <div class="molavi-header-actions">
                    <a class="molavi-header-icon" href="<?php echo esc_url($accountUrl); ?>" aria-label="حساب کاربری">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4 21v-2a8 8 0 0 1 16 0v2"></path></svg>
                    </a>
                    <button class="molavi-header-icon" type="button" data-molavi-wishlist-view aria-label="نمایش کالاهای موردعلاقه" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg>
                    </button>
                    <button class="molavi-header-cart" type="button" data-molavi-open-basket aria-controls="molavi-basket-dialog" aria-expanded="false" aria-label="مشاهده سبد خرید">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h2l2 11h9l2-8H8"></path><circle cx="10" cy="20" r="1"></circle><circle cx="17" cy="20" r="1"></circle></svg>
                        <span data-molavi-count><?php echo esc_html(molavi_digits((string) ($quote['count'] ?? 0))); ?></span>
                    </button>
                </div>
            </div>
        </header>

        <div class="molavi-store-layout">
            <div class="molavi-product-area">
                <div class="molavi-product-toolbar" id="molavi-products">
                    <label class="molavi-sort">
                        <span>مرتب‌سازی:</span>
                        <select data-molavi-sort aria-label="مرتب‌سازی محصولات">
                            <option value="newest">جدیدترین</option>
                            <option value="cheap">قیمت: کم به زیاد</option>
                            <option value="dear">قیمت: زیاد به کم</option>
                        </select>
                    </label>
                    <span class="molavi-result-count" data-molavi-result-count><?php echo esc_html(molavi_digits((string) count($products))); ?> کالا</span>
                    <div class="molavi-view-toggle" role="group" aria-label="نحوه نمایش محصولات">
                        <button type="button" class="is-active" data-molavi-view="grid" aria-label="نمایش شبکه‌ای" aria-pressed="true"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="2" y="2" width="6" height="6" rx="1"></rect><rect x="12" y="2" width="6" height="6" rx="1"></rect><rect x="2" y="12" width="6" height="6" rx="1"></rect><rect x="12" y="12" width="6" height="6" rx="1"></rect></svg></button>
                        <button type="button" data-molavi-view="list" aria-label="نمایش فهرستی" aria-pressed="false"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 4h11M7 10h11M7 16h11"></path><circle cx="3" cy="4" r="1"></circle><circle cx="3" cy="10" r="1"></circle><circle cx="3" cy="16" r="1"></circle></svg></button>
                    </div>
                    <label class="molavi-category-filter">
                        <span>دسته‌بندی‌ها</span>
                        <select data-molavi-category-select aria-label="فیلتر دسته‌بندی محصولات">
                            <option value="">همه دسته‌بندی‌ها</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category); ?>"><?php echo esc_html($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="molavi-product-grid" data-molavi-grid>
                    <?php foreach ($products as $product): ?>
                        <?php molavi_render_storefront_card($product, $quote); ?>
                    <?php endforeach; ?>
                </div>
                <p class="molavi-store-empty" data-molavi-empty hidden>محصولی با این مشخصات پیدا نشد.</p>
            </div>

            <div class="molavi-basket-shell" data-molavi-basket-shell>
                <button type="button" class="molavi-mobile-basket" data-molavi-open-basket aria-controls="molavi-basket-dialog" aria-expanded="false">
                    <span class="molavi-mobile-cart-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 5h2l2 11h9l2-8H8"></path><circle cx="10" cy="20" r="1"></circle><circle cx="17" cy="20" r="1"></circle></svg></span>
                    <span class="molavi-mobile-copy"><strong>مشاهده سبد</strong><small><span data-molavi-mobile-count><?php echo esc_html(molavi_digits((string) ($quote['count'] ?? 0))); ?></span> کالا · <span data-molavi-mobile-saving><?php echo esc_html(molavi_toman((int) ($quote['savings'] ?? 0))); ?></span> صرفه‌جویی</small></span>
                    <strong class="molavi-mobile-total" data-molavi-mobile-total><?php echo esc_html(molavi_toman((int) ($quote['subtotal'] ?? 0))); ?></strong>
                </button>
                <button type="button" class="molavi-basket-backdrop" data-molavi-close-basket aria-label="بستن سبد خرید" hidden></button>
                <?php molavi_render_storefront_basket($quote); ?>
            </div>
        </div>
        <p class="molavi-store-status" data-molavi-status role="status" aria-live="polite" hidden></p>
    </section>
    <?php
}

function molavi_render_storefront_card(\WC_Product $product, array $quote): void
{
    $id = $product->get_id();
    $curve = molavi_curve($product);
    $unit = (int) ($quote['units'][(string) $id] ?? molavi_unit_price($curve, (float) ($quote['progress'] ?? 0)));
    $shelf = (int) $curve['shelf'];
    $max = (float) $curve['max'];
    $rate = $shelf > $unit ? ($shelf - $unit) / $shelf : 0.0;
    $qty = molavi_cart_qty($id);
    $min = molavi_product_min($product);
    $supplier = trim((string) $product->get_meta('_molavi_supplier'));
    if ($supplier === '') {
        $supplier = trim(wp_strip_all_tags($product->get_short_description()));
    }
    $packaging = trim((string) $product->get_meta('_molavi_packaging'));
    $category = trim((string) $product->get_meta('_molavi_category'));
    if ($category === '') {
        $terms = get_the_terms($id, 'product_cat');
        if (is_array($terms) && $terms !== []) {
            $category = (string) $terms[0]->name;
        }
    }
    $search = trim(implode(' ', array_filter([$product->get_name(), $supplier, $packaging, $category])));
    $image = $product->get_image('woocommerce_thumbnail', [
        'alt' => $product->get_name(),
        'loading' => 'lazy',
    ]);
    $stock = $product->get_stock_quantity();
    $created = $product->get_date_created();
    $createdAt = $created ? $created->getTimestamp() : 0;
    $stockLabel = $product->managing_stock() && $stock !== null
        ? 'موجود در انبار: ' . molavi_digits((string) max(0, (int) $stock)) . ' عدد'
        : 'موجود در انبار';
    ?>
    <article class="molavi-product-card e-loop-item" data-molavi-card data-name="<?php echo esc_attr($product->get_name()); ?>" data-category="<?php echo esc_attr($category); ?>" data-search="<?php echo esc_attr($search); ?>" data-price="<?php echo esc_attr((string) $unit); ?>" data-created="<?php echo esc_attr((string) $createdAt); ?>">
        <div class="molavi-card-photo">
            <?php echo wp_kses_post($image); ?>
            <?php if ($max > 0): ?>
                <span class="molavi-deal" aria-label="حداکثر <?php echo esc_attr(molavi_percent($max)); ?> تخفیف">تا <?php echo esc_html(molavi_percent($max)); ?> تخفیف</span>
            <?php else: ?>
                <span class="molavi-deal molavi-deal-fixed">قیمت ثابت</span>
            <?php endif; ?>
            <button type="button" class="molavi-favorite" data-molavi-favorite="<?php echo esc_attr((string) $id); ?>" aria-label="افزودن <?php echo esc_attr($product->get_name()); ?> به علاقه‌مندی‌ها" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg></button>
        </div>
        <div class="molavi-card-body">
            <h3><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
            <div class="molavi-card-facts">
                <?php if ($packaging !== ''): ?><span><?php echo esc_html($packaging); ?></span><?php endif; ?>
                <?php if ($category !== ''): ?><span><?php echo esc_html($category); ?></span><?php endif; ?>
                <?php if ($supplier !== ''): ?><span><?php echo esc_html($supplier); ?></span><?php endif; ?>
                <span class="molavi-stock"><i aria-hidden="true"></i><?php echo esc_html($stockLabel); ?></span>
                <span class="screen-reader-text">حداقل خرید <?php echo esc_html(molavi_digits((string) $min)); ?> عدد</span>
            </div>
            <div class="molavi-card-price" data-molavi-price data-product="<?php echo esc_attr((string) $id); ?>" data-name="<?php echo esc_attr($product->get_name()); ?>" data-min="<?php echo esc_attr((string) $min); ?>" data-discountable="<?php echo $curve['discountable'] ? '1' : '0'; ?>" data-shelf="<?php echo esc_attr((string) $shelf); ?>" data-floor="<?php echo esc_attr((string) $curve['floor']); ?>" data-ceiling="<?php echo esc_attr((string) $curve['ceiling']); ?>" data-fixed-price="<?php echo esc_attr((string) ($shelf > 0 ? 0 : $unit)); ?>" data-unit="<?php echo esc_attr((string) $unit); ?>">
                <?php if ($shelf > 0): ?><span class="molavi-reference-label">قیمت مصرف <s class="molavi-was"<?php echo $shelf > $unit ? '' : ' hidden'; ?>><?php echo esc_html(molavi_toman($shelf)); ?></s></span><?php endif; ?>
                <div class="molavi-current-price">
                    <span class="molavi-amount"><?php echo esc_html(molavi_toman($unit)); ?></span>
                    <span class="molavi-rate-label"<?php echo $rate > 0 ? '' : ' hidden'; ?>>تخفیف فعلی <b class="molavi-rate"><?php echo esc_html(molavi_percent($rate)); ?></b></span>
                    <?php if ($shelf <= 0): ?><span class="molavi-fixed-label">قیمت ثابت</span><?php endif; ?>
                </div>
            </div>
            <?php molavi_render_storefront_control($product, $qty, $min); ?>
        </div>
    </article>
    <?php
}

function molavi_render_storefront_control(\WC_Product $product, int $qty, int $min): void
{
    echo '<div class="molavi-control' . ($qty > 0 ? ' is-in' : '') . '" data-molavi-cart data-product="' . esc_attr((string) $product->get_id()) . '" data-min="' . esc_attr((string) $min) . '" data-qty="' . esc_attr((string) $qty) . '">';
    if ($qty > 0) {
        echo '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-1" aria-label="کم کردن تعداد">−</button><b>' . esc_html(molavi_digits((string) $qty)) . '</b><button type="button" data-molavi-step="1" aria-label="افزودن یکی">+</button></div><button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' . molavi_basket_trash_svg() . '</button></div>';
    } else {
        echo '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن حداقل ' . esc_attr(molavi_digits((string) $min)) . ' عدد ' . esc_attr($product->get_name()) . ' به سبد"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"></path><circle cx="10" cy="19" r="1.2"></circle><circle cx="17" cy="19" r="1.2"></circle></svg></button>';
    }
    echo '</div>';
}

function molavi_render_storefront_basket(array $quote): void
{
    $map = molavi_catalog_map();
    $qtys = is_array($quote['qtys'] ?? null) ? $quote['qtys'] : [];
    $units = is_array($quote['units'] ?? null) ? $quote['units'] : [];
    ?>
    <aside class="molavi-basket molavi-store-basket" id="molavi-basket-dialog" data-molavi-basket role="dialog" aria-modal="false" aria-labelledby="molavi-basket-title" tabindex="-1">
        <header class="molavi-store-basket-head">
            <div><p class="molavi-eyebrow">جمع‌بندی</p><h2 id="molavi-basket-title">سبد خرید <span data-molavi-basket-count><?php echo esc_html(molavi_digits((string) ($quote['kinds'] ?? 0))); ?></span></h2></div>
            <button type="button" class="molavi-close-basket" data-molavi-close-basket aria-label="بستن سبد">×</button>
        </header>
        <div class="molavi-basket-lines" data-molavi-basket-lines>
            <?php foreach ($qtys as $id => $qty): ?>
                <?php $row = $map[(string) $id] ?? null; if (!$row || (int) $qty < 1) { continue; } $unit = (int) ($units[(string) $id] ?? molavi_unit_price($row, (float) ($quote['progress'] ?? 0))); $product = wc_get_product((int) $id); ?>
                <article class="molavi-bline" data-product="<?php echo esc_attr((string) $id); ?>" data-min="<?php echo esc_attr((string) $row['min']); ?>" data-qty="<?php echo esc_attr((string) $qty); ?>">
                    <div class="molavi-bline-image"><?php if ($product): ?><?php echo wp_kses_post($product->get_image('woocommerce_thumbnail', ['alt' => (string) $row['name'], 'loading' => 'lazy'])); ?><?php endif; ?></div>
                    <div class="molavi-bline-copy"><h3><?php echo esc_html((string) $row['name']); ?></h3><p><strong><?php echo esc_html(molavi_toman($unit)); ?></strong></p></div>
                    <div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-1" aria-label="کم کردن تعداد">−</button><b><?php echo esc_html(molavi_digits((string) $qty)); ?></b><button type="button" data-molavi-step="1" aria-label="افزودن یکی">+</button></div><button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد"><?php echo molavi_basket_trash_svg(); ?></button></div>
                </article>
            <?php endforeach; ?>
            <?php if ($qtys === []): ?><p class="molavi-basket-empty" data-molavi-empty-basket>سبد خالی است. برای شروع، یک محصول انتخاب کنید.</p><?php endif; ?>
        </div>
        <section class="molavi-progress-module" aria-label="پیشرفت تخفیف">
            <div class="molavi-progress-copy"><strong data-molavi-progress-title><?php echo !empty($quote['progress']) && $quote['progress'] >= 1 ? 'بیشترین تخفیف فعال است' : 'پیشرفت برای تخفیف بیشتر'; ?></strong><span data-molavi-remaining><?php echo !empty($quote['progress']) && $quote['progress'] >= 1 ? 'به سقف تخفیف رسیده‌اید.' : 'مانده تا سقف تخفیف: ' . esc_html(molavi_toman((int) ($quote['remaining'] ?? $quote['unlock'] ?? 0))); ?></span></div>
            <div class="molavi-progress-track" role="progressbar" aria-label="پیشرفت سبد برای تخفیف" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>" data-molavi-progressbar><span data-molavi-progress-fill style="width:<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>%"></span></div>
            <p class="molavi-progress-help">مبنای پیشرفت، قیمت مصرف کالاهای مشمول تخفیف است.</p>
            <details><summary>جزئیات محاسبه</summary><p>قیمت فعلی هر کالای مشمول با پیشرفت سبد تغییر می‌کند. کالاهای با قیمت ثابت در این پیشرفت محاسبه نمی‌شوند و صرفه‌جویی، جداگانه از قیمت مصرف هر کالا حساب می‌شود.</p></details>
        </section>
        <dl class="molavi-basket-summary">
            <div><dt>جمع قیمت مصرف</dt><dd data-molavi-reference><?php echo esc_html(molavi_toman((int) ($quote['reference_total'] ?? 0))); ?></dd></div>
            <div class="molavi-summary-saving"><dt>صرفه‌جویی فعلی <span data-molavi-savings-rate><?php echo esc_html(molavi_percent((float) ($quote['savings_rate'] ?? 0))); ?></span></dt><dd data-molavi-savings><?php echo esc_html(molavi_toman((int) ($quote['savings'] ?? 0))); ?></dd></div>
            <div class="molavi-summary-total"><dt>جمع محصولات پس از تخفیف</dt><dd data-molavi-subtotal><?php echo esc_html(molavi_toman((int) ($quote['subtotal'] ?? 0))); ?></dd></div>
        </dl>
        <p class="molavi-quote-note">مبلغ نهایی محصولات هنگام ادامه سفارش از سبد ووکامرس محاسبه می‌شود.</p>
        <a class="molavi-checkout" data-molavi-checkout href="<?php echo esc_url(wc_get_checkout_url()); ?>"<?php echo !empty($quote['count']) ? '' : ' hidden'; ?>>ادامه سفارش</a>
    </aside>
    <?php
}

function molavi_storefront_shortcode(): string
{
    ob_start();
    molavi_render_storefront();
    return (string) ob_get_clean();
}
