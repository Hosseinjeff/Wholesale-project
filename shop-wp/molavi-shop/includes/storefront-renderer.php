<?php

declare(strict_types=1);

namespace Molavi\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

function molavi_basket_trash_svg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M4.4 6.15h15.2c.5 0 .9.4.9.9s-.4.9-.9.9H4.4c-.5 0-.9-.4-.9-.9s.4-.9.9-.9z"/><path fill="currentColor" stroke="none" d="M10 3.3h4c.55 0 1 .4 1 .95V6.1h-6V4.25c0-.55.45-.95 1-.95z"/><path fill="currentColor" stroke="none" d="M6.35 9.2h11.3c.25 0 .45.22.43.48l-.78 10.05c-.08.85-.8 1.5-1.65 1.5H8.35c-.85 0-1.57-.65-1.65-1.5L5.92 9.68c-.02-.26.18-.48.43-.48z"/></svg>';
}

function molavi_storefront_is_default_category_label(string $category): bool
{
    $category = strtolower(trim(wp_strip_all_tags($category)));
    $normalized = preg_replace('/[\p{Z}\p{Cf}\s_\-]+/u', '', $category);
    $normalized = is_string($normalized) ? $normalized : $category;
    return in_array($normalized, ['uncategorized', 'دستهبندینشده'], true);
}

function molavi_storefront_product_category(\WC_Product $product): string
{
    $category = trim((string) $product->get_meta('_molavi_category'));
    if ($category !== '' && !molavi_storefront_is_default_category_label($category)) {
        return $category;
    }

    $terms = get_the_terms($product->get_id(), 'product_cat');
    if (!is_array($terms) || $terms === []) {
        return '';
    }

    $defaultCategoryId = (int) get_option('default_product_cat', 0);
    foreach ($terms as $term) {
        if (!($term instanceof \WP_Term)
            || $term->slug === 'uncategorized'
            || molavi_storefront_is_default_category_label((string) $term->name)
            || ($defaultCategoryId > 0 && (int) $term->term_id === $defaultCategoryId)) {
            continue;
        }
        return trim((string) $term->name);
    }

    return '';
}

function molavi_render_storefront(): void
{
    if (!function_exists('wc_get_products') || !function_exists('WC')) {
        echo '<p class="molavi-store-message">برای نمایش فروشگاه، ووکامرس باید فعال باشد.</p>';
        return;
    }

    $page = $GLOBALS['molavi_page_catalog'] ?? molavi_storefront_catalog_page(wp_unslash($_GET));
    $products = $page['products'];
    $quote = $page['quote'];
    $shopUrl = function_exists('wc_get_page_permalink') ? (wc_get_page_permalink('shop') ?: home_url('/shop/')) : home_url('/shop/');
    $buyerType = molavi_buyer_type();
    $searchQuery = isset($_GET['molavi_q']) && is_scalar($_GET['molavi_q']) ? sanitize_text_field(wp_unslash((string)$_GET['molavi_q'])) : '';
    $categories = $page['categories'];
    ?>
    <section class="molavi-storefront" data-molavi-storefront data-catalog-page="<?php echo esc_attr((string)$page['page']); ?>" data-catalog-total="<?php echo esc_attr((string)$page['total']); ?>" aria-label="فروشگاه مولوی‌سل">
        <h1 class="screen-reader-text">فروشگاه مولوی‌سل</h1>
        <div class="molavi-purchase-intro"><p data-molavi-guide>کالاها را اضافه کنید؛ <strong>تخفیف فعلی با سبد شما به‌روز می‌شود.</strong></p><a href="#molavi-shopping-help" data-molavi-open-help>راهنمای خرید</a></div>
        <div class="molavi-store-layout">
            <div class="molavi-product-area">
                <div class="molavi-store-controls" data-molavi-store-controls>
                <?php molavi_render_buyer_profile_selector('shop'); ?>
                <div class="molavi-product-toolbar" id="molavi-products">
                    <div class="molavi-toolbar-actions">
                    <button class="molavi-buyer-compact" type="button" data-molavi-open-panel="buyer" aria-label="تغییر نوع خرید" aria-haspopup="dialog" aria-expanded="false" aria-controls="molavi-buyer-panel" title="نوع خرید">
                            <svg class="molavi-buyer-house-icon" data-molavi-buyer-icon-house viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1V10Z"></path></svg>
                            <svg class="molavi-buyer-store-icon" data-molavi-buyer-icon-store viewBox="0 0 24 24" aria-hidden="true" hidden><path d="M4 10v10h16V10M3 10l2-6h14l2 6"></path><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M9 20v-6h6v6"></path></svg>
                            <span data-molavi-buyer-current><?php echo $buyerType === 'shop' ? 'فروشگاه' : 'خانوار'; ?></span>
                            <svg class="molavi-buyer-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
                    </button>
                    <button class="molavi-toolbar-icon molavi-toolbar-search-trigger molavi-compact-search-trigger" type="button" data-molavi-search-toggle aria-label="جست‌وجوی محصولات" aria-expanded="false" aria-controls="molavi-toolbar-search" title="جست‌وجو">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 4.5 4.5"></path></svg>
                    </button>
                    <button class="molavi-toolbar-icon molavi-category-trigger" type="button" data-molavi-open-panel="category" aria-label="فیلتر دسته‌بندی محصولات" aria-haspopup="dialog" aria-expanded="false" aria-controls="molavi-category-panel" title="دسته‌بندی">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1V18l-3.6 2v-7.9L4 5Z"></path></svg><span class="molavi-toolbar-label">دسته‌بندی</span><span class="molavi-toolbar-value" data-molavi-category-current>همه</span>
                    </button>
                    <button class="molavi-toolbar-icon molavi-sort-trigger" type="button" data-molavi-open-panel="sort" aria-label="مرتب‌سازی محصولات" aria-haspopup="dialog" aria-expanded="false" aria-controls="molavi-sort-panel" title="مرتب‌سازی">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4v15m0 0-4-4m4 4 4-4M16 20V5m0 0 4 4m-4-4-4 4"></path></svg><span class="molavi-toolbar-label">مرتب‌سازی</span><span class="molavi-toolbar-value" data-molavi-sort-current>جدیدترین</span>
                    </button>
                    </div>
                    <div class="molavi-toolbar-meta">
                    <span class="molavi-result-count" data-molavi-result-count aria-label="<?php echo esc_attr(molavi_digits((string) $page['total']) . ' کالا'); ?>" aria-live="polite"><span data-molavi-result-value><?php echo esc_html(molavi_digits((string) $page['total'])); ?></span><span class="molavi-result-label"> کالا</span></span>
                    <div class="molavi-view-toggle" role="group" aria-label="نحوه نمایش محصولات">
                        <button type="button" class="is-active" data-molavi-view="grid" aria-label="نمایش شبکه‌ای" aria-pressed="true"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="2" y="2" width="6" height="6" rx="1"></rect><rect x="12" y="2" width="6" height="6" rx="1"></rect><rect x="2" y="12" width="6" height="6" rx="1"></rect><rect x="12" y="12" width="6" height="6" rx="1"></rect></svg></button>
                        <button type="button" data-molavi-view="list" aria-label="نمایش فهرستی" aria-pressed="false"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 4h11M7 10h11M7 16h11"></path><circle cx="3" cy="4" r="1"></circle><circle cx="3" cy="10" r="1"></circle><circle cx="3" cy="16" r="1"></circle></svg></button>
                    </div>
                    </div>
                </div>
                <form class="molavi-toolbar-search" id="molavi-toolbar-search" data-molavi-toolbar-search data-molavi-search-form role="search" action="<?php echo esc_url($shopUrl); ?>" method="get" hidden>
                    <label class="screen-reader-text" for="molavi-toolbar-search-input">جست‌وجوی محصولات</label>
                    <svg class="molavi-toolbar-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 4.5 4.5"></path></svg>
                    <input id="molavi-toolbar-search-input" type="search" name="molavi_q" data-molavi-search value="<?php echo esc_attr($searchQuery); ?>" placeholder="نام کالا را جست‌وجو کنید" autocomplete="off">
                    <button type="button" class="molavi-toolbar-search-close" data-molavi-search-close aria-label="بستن جست‌وجو" title="بستن جست‌وجو">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
                    </button>
                </form>
                </div>
                <div class="molavi-control-panels" data-molavi-control-panels>
                    <div class="molavi-control-panel" id="molavi-category-panel" data-molavi-control-panel="category" role="dialog" aria-modal="false" aria-labelledby="molavi-category-panel-title" hidden>
                        <div class="molavi-control-panel-heading"><strong id="molavi-category-panel-title">دسته‌بندی محصولات</strong><button type="button" class="molavi-control-panel-close" data-molavi-panel-close aria-label="بستن"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg></button></div>
                        <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-category-option="" aria-pressed="true"><span>همه دسته‌بندی‌ها</span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                        <?php foreach ($categories as $category): ?>
                            <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-category-option="<?php echo esc_attr($category); ?>" aria-pressed="false"><span><?php echo esc_html($category); ?></span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                        <?php endforeach; ?>
                        <?php if ($categories === []): ?>
                            <p class="molavi-control-panel-note">دسته‌بندی دیگری برای فیلتر فعلاً ثبت نشده است.</p>
                        <?php endif; ?>
                    </div>
                    <div class="molavi-control-panel" id="molavi-sort-panel" data-molavi-control-panel="sort" role="dialog" aria-modal="false" aria-labelledby="molavi-sort-panel-title" hidden>
                        <div class="molavi-control-panel-heading"><strong id="molavi-sort-panel-title">مرتب‌سازی</strong><button type="button" class="molavi-control-panel-close" data-molavi-panel-close aria-label="بستن"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg></button></div>
                        <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-sort-option="newest" aria-pressed="true"><span>جدیدترین</span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                        <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-sort-option="cheap" aria-pressed="false"><span>قیمت: کم به زیاد</span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                        <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-sort-option="dear" aria-pressed="false"><span>قیمت: زیاد به کم</span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                        <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-sort-option="name" aria-pressed="false"><span>نام کالا</span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                    </div>
                    <div class="molavi-control-panel" id="molavi-buyer-panel" data-molavi-control-panel="buyer" role="dialog" aria-modal="false" aria-labelledby="molavi-buyer-panel-title" aria-describedby="molavi-buyer-panel-note" hidden>
                        <div class="molavi-control-panel-heading"><strong id="molavi-buyer-panel-title">نوع خرید</strong><button type="button" class="molavi-control-panel-close" data-molavi-panel-close aria-label="بستن"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg></button></div>
                        <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-buyer="household" aria-pressed="<?php echo $buyerType === 'household' ? 'true' : 'false'; ?>"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1V10Z"></path></svg> خانوار</span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                        <button type="button" class="molavi-control-option" data-molavi-control-option data-molavi-buyer="shop" aria-pressed="<?php echo $buyerType === 'shop' ? 'true' : 'false'; ?>"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v10h16V10M3 10l2-6h14l2 6"></path><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M9 20v-6h6v6"></path></svg> فروشگاه</span><span class="molavi-control-check" aria-hidden="true">✓</span></button>
                        <p class="molavi-control-panel-note" id="molavi-buyer-panel-note">«خانوار» برای مصرف خانه و «فروشگاه» برای خرید مغازه است. هرکدام قیمت و گام سفارش خود را دارد. با تغییر نوع خرید، تعداد سبد حفظ می‌شود؛ اگر لازم باشد، دکمهٔ «اصلاح تعداد» نشان داده می‌شود.</p>
                    </div>
                    <div class="molavi-control-panel" id="molavi-help-panel" data-molavi-control-panel="help" role="dialog" aria-modal="false" aria-labelledby="molavi-help-panel-title" aria-describedby="molavi-help-panel-note" tabindex="-1" hidden>
                        <div class="molavi-control-panel-heading"><strong id="molavi-help-panel-title">راهنمای نوع خرید</strong><button type="button" class="molavi-control-panel-close" data-molavi-panel-close aria-label="بستن"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg></button></div>
                        <p class="molavi-control-panel-note" id="molavi-help-panel-note" data-molavi-buyer-help>«خانوار» برای مصرف خانه و «فروشگاه» برای خرید مغازه است. هرکدام قیمت و گام سفارش خود را دارد. با تغییر نوع خرید، تعداد سبد حفظ می‌شود؛ اگر لازم باشد، دکمهٔ «اصلاح تعداد» نشان داده می‌شود.</p>
                    </div>
                </div>
                <div class="molavi-product-grid" data-molavi-grid>
                    <?php foreach ($products as $product): ?>
                        <?php molavi_render_storefront_card($product, $quote); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="molavi-catalog-more" data-molavi-more<?php echo $page['has_more']?'':' hidden'; ?>>نمایش کالاهای بیشتر</button>
                <p class="molavi-store-empty" data-molavi-empty hidden>محصولی با این مشخصات پیدا نشد.</p>
            </div>

            <div class="molavi-basket-shell" data-molavi-basket-shell>
                <button type="button" class="molavi-mobile-basket" data-molavi-open-basket aria-label="باز کردن سبد خرید و علاقه‌مندی‌ها" aria-controls="molavi-basket-dialog" aria-expanded="false">
                    <span class="molavi-mobile-cart-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h2l2 11h9l2-8H8"></path><circle cx="10" cy="20" r="1"></circle><circle cx="17" cy="20" r="1"></circle></svg><span class="molavi-mobile-count" data-molavi-mobile-count aria-hidden="true"<?php echo empty($quote['count']) ? ' hidden' : ''; ?>><?php echo esc_html(molavi_digits((string) ($quote['count'] ?? 0))); ?></span></span>
                    <span class="molavi-mobile-copy"><strong><span data-molavi-mobile-rate><?php echo esc_html(molavi_percent((float) ($quote['savings_rate'] ?? 0))); ?></span> تخفیف فعلی</strong><small><span data-molavi-mobile-saving><?php echo esc_html(molavi_toman((int) ($quote['savings'] ?? 0))); ?></span> صرفه‌جویی</small></span>
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

function molavi_storefront_card_product_name(\WC_Product $product, string $supplier = ''): string
{
    $replace = static function (string $pattern, string $replacement, string $subject): string {
        $updated = preg_replace($pattern, $replacement, $subject);
        return is_string($updated) ? $updated : $subject;
    };
    $originalName = '';
    if (function_exists('get_post_field')) {
        $originalName = trim(wp_strip_all_tags((string) get_post_field('post_title', $product->get_id())));
    }
    if ($originalName === '') {
        $originalName = trim(wp_strip_all_tags($product->get_name()));
    }
    if ($originalName === '') {
        $path = parse_url($product->get_permalink(), PHP_URL_PATH);
        $slug = is_string($path) ? basename(rtrim($path, '/')) : '';
        if ($slug === '' && method_exists($product, 'get_slug')) {
            $slug = (string) $product->get_slug();
        }
        if ($slug === '' && function_exists('get_post_field')) {
            $slug = (string) get_post_field('post_name', $product->get_id());
        }
        $slug = rawurldecode($slug);
        $slug = $replace('/-[0-9]+$/', '', $slug);
        $originalName = trim(str_replace(['-', '_'], ' ', $slug));
    }
    $name = $originalName;
    $supplier = trim(wp_strip_all_tags($supplier));
    if ($supplier !== '') {
        $name = str_ireplace($supplier, ' ', $name);
    }
    $name = str_ireplace(['تعداد در کارتن', 'تعداد در بسته', 'تعداد در جعبه', 'تعداد'], ' ', $name);
    $name = $replace(
        '/(?:کارتن|بسته|جعبه)?\s*[0-9۰-۹٠-٩]{1,4}\s*(?:عددی|عدد|تایی|بسته|کارتن)/u',
        ' ',
        $name
    );
    $name = $replace(
        '/[0-9۰-۹٠-٩]+(?:[.,٫][0-9۰-۹٠-٩]+)?\s*(?:میلی[\s\x{200c}]*لیتر(?:ی)?|میلی[\s\x{200c}]*گرم(?:ی)?|کیلو[\s\x{200c}]*گرم(?:ی)?|لیتر(?:ی)?|گرم(?:ی)?|کیلو|kg|g|ml|cc|l)/iu',
        ' ',
        $name
    );
    $name = str_replace(['،', ',', '؛', ';'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    // PHP trim() treats its character list as bytes; Persian punctuation here
    // can strip the first byte from Arabic-script letters and make invalid UTF-8.
    $name = trim($name, " \t\n\r\0\x0B,:;-");
    return $name !== '' ? $name : ($originalName !== '' ? $originalName : 'کالا');
}

/** Return the five best currently eligible product discounts for the selected buyer type. */
function molavi_storefront_featured_products(array $products, string $buyerType): array
{
    $featured = [];
    foreach ($products as $product) {
        if (!$product instanceof \WC_Product) {
            continue;
        }
        $curve = molavi_curve($product);
        $profile = molavi_product_profile($product);
        $discount = (float) molavi_profile_curve($curve, $profile, $buyerType)['max'];
        if ($discount <= 0) {
            continue;
        }
        $featured[] = ['product' => $product, 'discount' => $discount];
    }
    usort($featured, static function (array $left, array $right): int {
        $rate = $right['discount'] <=> $left['discount'];
        if ($rate !== 0) {
            return $rate;
        }
        return strcasecmp($left['product']->get_name(), $right['product']->get_name());
    });
    return array_slice($featured, 0, 5);
}

function molavi_render_featured_storefront_product(\WC_Product $product, float $maxDiscount, array $quote): void
{
    $id = $product->get_id();
    $supplier = trim((string) $product->get_meta('_molavi_supplier'));
    $name = molavi_storefront_card_product_name($product, $supplier);
    $curve = molavi_curve($product);
    $shelf = (int) $curve['shelf'];
    $unit = (int) ($quote['units'][(string) $id] ?? molavi_price_for_product($product)['price']);
    $qty = molavi_cart_qty($id);
    $image = $product->get_image('woocommerce_thumbnail', [
        'alt' => $name,
        'loading' => 'eager',
    ]);
    $url = $product->get_permalink();
    ?>
    <article class="molavi-featured-product" data-molavi-featured-product="<?php echo esc_attr((string) $id); ?>">
        <a class="molavi-featured-image" href="<?php echo esc_url($url); ?>" tabindex="-1" aria-hidden="true"><?php echo wp_kses_post($image); ?></a>
        <div class="molavi-featured-copy">
            <span class="molavi-featured-discount">تا <?php echo esc_html(molavi_percent($maxDiscount)); ?> تخفیف</span>
            <h3><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($name); ?></a></h3>
            <p>بیشترین تخفیف قابل دریافت در فهرست است؛ نرخ نهایی با نوع خرید و مبلغ سبد محاسبه می‌شود.</p>
            <div class="molavi-featured-prices">
                <?php if ($shelf > $unit): ?><s><?php echo esc_html(molavi_toman($shelf)); ?></s><?php endif; ?>
                <strong><?php echo esc_html(molavi_toman($unit)); ?></strong>
                <span>قیمت فعلی</span>
            </div>
        </div>
        <button type="button" class="molavi-featured-action" data-molavi-recommendation-product="<?php echo esc_attr((string) $id); ?>">
            <?php echo esc_html($qty > 0 ? 'افزایش یک گام' : 'افزودن به سبد'); ?>
        </button>
    </article>
    <?php
}

function molavi_render_storefront_card(\WC_Product $product, array $quote): void
{
    $id = $product->get_id();
    $curve = molavi_curve($product);
    $buyerType = molavi_buyer_type();
    $profile = molavi_product_profile($product);
    $profileCurve = molavi_profile_curve($curve, $profile, $buyerType);
    $householdCurve = molavi_profile_curve($curve, $profile, 'household');
    $shopCurve = molavi_profile_curve($curve, $profile, 'shop');
    $unit = (int) ($quote['units'][(string) $id] ?? molavi_price_for_product($product)['price']);
    $shelf = (int) $curve['shelf'];
    $max = (float) $profileCurve['max'];
    $rate = $shelf > $unit ? ($shelf - $unit) / $shelf : 0.0;
    $qty = molavi_cart_qty($id);
    $min = molavi_product_min($product);
    $invalidStep = $qty > 0 && ($qty < $min || $qty % $min !== 0);
    $supplier = trim((string) $product->get_meta('_molavi_supplier'));
    $displayName = molavi_storefront_card_product_name($product, $supplier);
    $category = molavi_storefront_product_category($product);
    if ($supplier !== '') {
        $category = trim(str_ireplace($supplier, ' ', $category), " \t\n\r\0\x0B،,؛;:-");
    }
    $search = trim(implode(' ', array_filter([$displayName, $category])));
    $image = $product->get_image('woocommerce_thumbnail', [
        'alt' => $displayName,
        'loading' => 'lazy',
    ]);
    $created = $product->get_date_created();
    $createdAt = $created ? $created->getTimestamp() : 0;
    ?>
    <article class="molavi-product-card e-loop-item" data-molavi-card data-product="<?php echo esc_attr((string) $id); ?>" data-name="<?php echo esc_attr($displayName); ?>" data-category="<?php echo esc_attr($category); ?>" data-search="<?php echo esc_attr($search); ?>" data-price="<?php echo esc_attr((string) $unit); ?>" data-created="<?php echo esc_attr((string) $createdAt); ?>" data-household-max="<?php echo esc_attr((string) $householdCurve['max']); ?>" data-shop-max="<?php echo esc_attr((string) $shopCurve['max']); ?>" data-has-discount="<?php echo $shelf > $unit ? '1' : '0'; ?>">
        <div class="molavi-card-photo">
            <?php echo wp_kses_post($image); ?>
            <?php if ($max > 0): ?>
                <span class="molavi-deal" data-molavi-profile-deal data-product="<?php echo esc_attr((string) $id); ?>" data-short="تا <?php echo esc_attr(molavi_percent($max)); ?>" data-short-compact="<?php echo esc_attr(molavi_percent($max)); ?>" aria-label="حداکثر <?php echo esc_attr(molavi_percent($max)); ?> تخفیف"><span class="molavi-deal-copy">تا <?php echo esc_html(molavi_percent($max)); ?> تخفیف</span></span>
            <?php else: ?>
                <span class="molavi-deal molavi-deal-fixed" data-molavi-profile-deal data-product="<?php echo esc_attr((string) $id); ?>" data-short="ثابت" data-short-compact="ثابت">قیمت ثابت</span>
            <?php endif; ?>
            <button type="button" class="molavi-favorite" data-molavi-favorite="<?php echo esc_attr((string) $id); ?>" aria-label="افزودن <?php echo esc_attr($displayName); ?> به علاقه‌مندی‌ها" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg></button>
        </div>
        <div class="molavi-card-body">
            <h3><a href="<?php echo esc_url($product->get_permalink()); ?>" title="<?php echo esc_attr($displayName); ?>"><?php echo esc_html($displayName); ?></a></h3>
            <div class="molavi-card-price" data-molavi-price data-product="<?php echo esc_attr((string) $id); ?>" data-name="<?php echo esc_attr($displayName); ?>" data-min="<?php echo esc_attr((string) $min); ?>" data-household-min="<?php echo esc_attr((string) $profile['household_unit']); ?>" data-shop-min="<?php echo esc_attr((string) $profile['shop_unit']); ?>" data-household-max="<?php echo esc_attr((string) $householdCurve['max']); ?>" data-shop-max="<?php echo esc_attr((string) $shopCurve['max']); ?>" data-discountable="<?php echo $curve['discountable'] ? '1' : '0'; ?>" data-shelf="<?php echo esc_attr((string) $shelf); ?>" data-fixed-price="<?php echo esc_attr((string) ($shelf > 0 ? 0 : $unit)); ?>" data-unit="<?php echo esc_attr((string) $unit); ?>">
                <div class="molavi-current-price">
                    <span class="molavi-rate-label"<?php echo $rate > 0 ? '' : ' hidden'; ?>>تخفیف فعلی <b class="molavi-rate"><?php echo esc_html(molavi_percent($rate)); ?></b></span>
                    <span class="molavi-amount" title="<?php echo esc_attr(molavi_toman($unit)); ?>"><?php echo esc_html(molavi_toman($unit)); ?></span>
                </div>
                <div class="molavi-price-meta">
                    <?php if ($shelf > 0): ?><span class="molavi-reference-label"><s class="molavi-was" title="<?php echo esc_attr(molavi_toman($shelf)); ?>"<?php echo $shelf > $unit ? '' : ' hidden'; ?>><?php echo esc_html(molavi_toman($shelf)); ?></s></span><?php endif; ?>
                </div>
            </div>
            <div class="molavi-card-bottom">
                <div class="molavi-card-facts">
                    <span class="molavi-order-units" data-molavi-order-units<?php echo $invalidStep ? ' hidden' : ''; ?> data-household-unit="<?php echo esc_attr((string) $profile['household_unit']); ?>" data-shop-unit="<?php echo esc_attr((string) $profile['shop_unit']); ?>" title="خانوار <?php echo esc_attr(molavi_digits((string) $profile['household_unit'])); ?> عدد؛ فروشگاه <?php echo esc_attr(molavi_digits((string) $profile['shop_unit'])); ?> عدد">گام سفارش: <?php echo esc_html(molavi_digits((string) $profile[$buyerType . '_unit'])); ?> عدد</span>
                    <?php if ($category !== ''): ?><span class="molavi-card-category"<?php echo $invalidStep ? ' hidden' : ''; ?>><?php echo esc_html($category); ?></span><?php endif; ?>
                    <details class="molavi-card-step-help">
                        <summary aria-label="توضیح گام سفارش" title="توضیح درباره گام سفارش">؟</summary>
                        <div class="molavi-card-step-popover">گام سفارش تعداد هر بار افزودن یا کم‌کردن کالا را مشخص می‌کند. با تغییر نوع خرید، تعداد فعلی حفظ می‌شود؛ برای هماهنگ‌کردنش «اصلاح تعداد» را انتخاب کنید.</div>
                    </details>
                    <div class="molavi-card-step-warning" data-molavi-card-step-warning<?php echo $invalidStep ? '' : ' hidden'; ?> role="status" aria-live="polite">
                        <span data-molavi-card-step-warning-text><?php echo $invalidStep ? esc_html('تعداد ' . molavi_digits((string) $qty) . ' · گام ' . molavi_digits((string) $min)) : ''; ?></span>
                    </div>
                    <span class="screen-reader-text">حداقل خرید <?php echo esc_html(molavi_digits((string) $min)); ?> عدد</span>
                </div>
                <?php molavi_render_storefront_control($product, $qty, $min); ?>
            </div>
        </div>
    </article>
    <?php
}

function molavi_render_storefront_control(\WC_Product $product, int $qty, int $min): void
{
    echo '<div class="molavi-control' . ($qty > 0 ? ' is-in' : '') . '" data-molavi-cart data-product="' . esc_attr((string) $product->get_id()) . '" data-min="' . esc_attr((string) $min) . '" data-qty="' . esc_attr((string) $qty) . '">';
    if ($qty > 0 && ($qty < $min || $qty % $min !== 0)) {
        $next = max($min, (int) ceil($qty / $min) * $min);
        if ($next > 999) {
            $next = max($min, intdiv(999, $min) * $min);
        }
        echo '<button type="button" class="molavi-card-adjust" data-molavi-set-qty="' . esc_attr((string) $next) . '" aria-label="اصلاح تعداد از ' . esc_attr(molavi_digits((string) $qty)) . ' به ' . esc_attr(molavi_digits((string) $next)) . '"><span class="molavi-card-adjust-label" data-compact-label="اصلاح">اصلاح تعداد</span><span class="molavi-count-shift" aria-hidden="true"><b>' . esc_html(molavi_digits((string) $qty)) . '</b><i>→</i><b>' . esc_html(molavi_digits((string) $next)) . '</b></span></button>';
    } elseif ($qty > 0) {
        echo '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-' . esc_attr((string) $min) . '" aria-label="کم کردن ' . esc_attr(molavi_digits((string) $min)) . ' عدد">−</button><b>' . esc_html(molavi_digits((string) $qty)) . '</b><button type="button" data-molavi-step="' . esc_attr((string) $min) . '" aria-label="افزودن ' . esc_attr(molavi_digits((string) $min)) . ' عدد">+</button></div><button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' . molavi_basket_trash_svg() . '</button></div>';
    } else {
        $supplier = trim((string) $product->get_meta('_molavi_supplier'));
        $displayName = molavi_storefront_card_product_name($product, $supplier);
        echo '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن حداقل ' . esc_attr(molavi_digits((string) $min)) . ' عدد ' . esc_attr($displayName) . ' به سبد"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"></path><circle cx="10" cy="19" r="1.2"></circle><circle cx="17" cy="19" r="1.2"></circle></svg><span>افزودن ' . esc_html(molavi_digits((string)$min)) . ' عدد</span></button>';
    }
    echo '</div>';
}

function molavi_render_favorites_library(): void
{
    ?>
    <dialog id="molavi-favorites-dialog" class="molavi-favorites-dialog" data-molavi-lists aria-labelledby="molavi-favorites-title">
        <header class="molavi-library-head"><meta charset="utf-8"><div><span class="molavi-library-eyebrow">برای خریدهای بعدی</span><h2 id="molavi-favorites-title">علاقه‌مندی‌ها</h2></div><button type="button" class="molavi-library-close" data-molavi-close-library aria-label="بستن علاقه‌مندی‌ها">×</button></header>
        <div class="molavi-library-create"><button type="button" data-molavi-new-cart>+ سبد جدید</button><small>سبد فعلی پیش از شروع سبد جدید ذخیره می‌شود.</small></div>
        <div class="molavi-library-tabs" role="tablist" aria-label="نوع علاقه‌مندی">
            <button type="button" id="molavi-favorite-products-tab" role="tab" aria-selected="true" aria-controls="molavi-favorite-products" data-molavi-library-tab="products">کالاها <span data-molavi-favorites-count>۰</span></button>
            <button type="button" id="molavi-saved-carts-tab" role="tab" aria-selected="false" aria-controls="molavi-saved-carts" tabindex="-1" data-molavi-library-tab="carts">سبدهای ذخیره‌شده <span data-molavi-saved-count>۰</span></button>
        </div>
        <section id="molavi-favorite-products" class="molavi-library-panel" role="tabpanel" aria-labelledby="molavi-favorite-products-tab" data-molavi-library-panel="products">
            <div class="molavi-library-toolbar"><p>کالاهایی که با قلب انتخاب کرده‌اید.</p><button type="button" data-molavi-save-favorites>ذخیرهٔ همه به‌صورت سبد</button></div>
            <div class="molavi-library-products" data-molavi-favorite-products></div>
        </section>
        <section id="molavi-saved-carts" class="molavi-library-panel" role="tabpanel" aria-labelledby="molavi-saved-carts-tab" data-molavi-library-panel="carts" hidden>
            <div class="molavi-library-toolbar"><p>انتخاب‌های آماده برای خرید دوباره.</p><button type="button" data-molavi-save-list>ذخیرهٔ سبد خرید فعلی</button></div>
            <div class="molavi-library-carts" data-molavi-saved-carts></div>
        </section>
        <footer class="molavi-library-footer"><p data-molavi-lists-status role="status" aria-live="polite"></p><button type="button" class="molavi-library-retry" data-molavi-library-retry hidden>تلاش دوباره</button><small>روی همین مرورگر ذخیره می‌شود. قیمت و امکان خرید هنگام افزودن به سبد بررسی می‌شود.</small></footer>
    </dialog>
    <?php
}

function molavi_render_storefront_basket(array $quote): void
{
    $map = molavi_catalog_map();
    $qtys = is_array($quote['qtys'] ?? null) ? $quote['qtys'] : [];
    $units = is_array($quote['units'] ?? null) ? $quote['units'] : [];
    $invalidById = [];
    foreach (($quote['invalid_lines'] ?? []) as $invalidLine) {
        if (is_array($invalidLine) && isset($invalidLine['product_id'])) {
            $invalidById[(string) $invalidLine['product_id']] = $invalidLine;
        }
    }
    ?>
    <aside class="molavi-basket molavi-store-basket" id="molavi-basket-dialog" data-molavi-basket role="dialog" aria-modal="false" aria-labelledby="molavi-basket-title" tabindex="-1">
        <header class="molavi-store-basket-head">
            <div><p class="molavi-eyebrow">جمع‌بندی</p><h2 id="molavi-basket-title">سبد خرید <span data-molavi-basket-count><?php echo esc_html(molavi_digits((string) ($quote['kinds'] ?? 0))); ?></span></h2></div>
            <button type="button" class="molavi-close-basket" data-molavi-close-basket aria-label="بستن سبد">×</button>
        </header>
        <?php molavi_render_buyer_profile_selector('basket'); ?>
        <div class="molavi-basket-lines" id="molavi-basket-lines" data-molavi-basket-lines>
            <?php foreach ($qtys as $id => $qty): ?>
                <?php
                $row = $map[(string) $id] ?? null;
                if (!$row || (int) $qty < 1) {
                    continue;
                }
                $quantity = (int) $qty;
                $unit = (int) ($units[(string) $id] ?? molavi_catalog_product_price(
                    $row,
                    $quantity,
                    molavi_buyer_type(),
                    (float) ($quote['progress'] ?? 0)
                )['price']);
                $product = wc_get_product((int) $id);
                $step = max(1, (int) ($row[molavi_buyer_type() . '_unit'] ?? $row['min']));
                $invalidLine = $invalidById[(string) $id] ?? null;
                $minusDelta = -$step;
                $plusDelta = $step;
                $plusDisabled = false;
                if ($invalidLine) {
                    $previousValid = intdiv(max(0, $quantity - 1), $step) * $step;
                    $nextValid = min(999 - (999 % $step), max($step, (int) ceil($quantity / $step) * $step));
                    $minusDelta = $previousValid - $quantity;
                    $plusDelta = $nextValid - $quantity;
                    $plusDisabled = $nextValid <= $quantity;
                }
                $minusLabel = $invalidLine
                    ? 'تغییر تعداد به ' . molavi_digits((string) max(0, $quantity + $minusDelta)) . ' عدد'
                    : 'کم کردن ' . molavi_digits((string) $step) . ' عدد';
                $plusLabel = $invalidLine
                    ? 'تغییر تعداد به ' . molavi_digits((string) max(0, $quantity + $plusDelta)) . ' عدد'
                    : 'افزودن ' . molavi_digits((string) $step) . ' عدد';
                ?>
                <article class="molavi-bline<?php echo $invalidLine ? ' is-invalid' : ''; ?>" data-product="<?php echo esc_attr((string) $id); ?>" data-min="<?php echo esc_attr((string) $step); ?>" data-qty="<?php echo esc_attr((string) $qty); ?>" data-invalid="<?php echo $invalidLine ? '1' : '0'; ?>">
                    <div class="molavi-bline-image"><?php if ($product): ?><?php echo wp_kses_post($product->get_image('woocommerce_thumbnail', ['alt' => (string) $row['name'], 'loading' => 'lazy'])); ?><?php endif; ?></div>
                    <div class="molavi-bline-copy">
                        <h3><?php echo esc_html((string) $row['name']); ?></h3>
                        <p><strong><?php echo esc_html(molavi_toman($unit)); ?></strong> × <?php echo esc_html(molavi_digits((string) $qty)); ?></p>
                        <small class="molavi-order-step">گام سفارش: <?php echo esc_html(molavi_digits((string) $step)); ?> عدد</small>
                        <?php if ($invalidLine): ?>
                            <small class="molavi-buyer-line-warning">تعداد فعلی با این گام هماهنگ نیست.</small>
                            <button type="button" class="molavi-line-update" data-molavi-set-qty="<?php echo esc_attr((string) $invalidLine['needed_quantity']); ?>">
                                تغییر به <?php echo esc_html(molavi_digits((string) $invalidLine['needed_quantity'])); ?> عدد
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="molavi-actions">
                        <div class="molavi-step">
                            <button type="button" data-molavi-step="<?php echo esc_attr((string) $minusDelta); ?>" aria-label="<?php echo esc_attr($minusLabel); ?>">−</button>
                            <b><?php echo esc_html(molavi_digits((string) $qty)); ?></b>
                            <button type="button" data-molavi-step="<?php echo esc_attr((string) $plusDelta); ?>" aria-label="<?php echo esc_attr($plusLabel); ?>"<?php echo $plusDisabled ? ' disabled' : ''; ?>>+</button>
                        </div>
                        <button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد"><?php echo molavi_basket_trash_svg(); ?></button>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ($qtys === []): ?><p class="molavi-basket-empty" data-molavi-empty-basket>سبد خالی است. برای شروع، یک محصول انتخاب کنید.</p><?php endif; ?>
        </div>
        <details class="molavi-progress-module" aria-label="پیشرفت تخفیف">
            <summary class="molavi-progress-summary">
                <span class="molavi-progress-copy">
                    <strong data-molavi-progress-title><?php echo !empty($quote['progress']) && $quote['progress'] >= 1 ? 'سقف تخفیف تکمیل شده' : 'پیشرفت تخفیف'; ?></strong>
                    <span data-molavi-remaining><?php echo !empty($quote['progress']) && $quote['progress'] >= 1 ? 'سقف تخفیف ' . (molavi_buyer_type() === 'shop' ? 'فروشگاه' : 'خانوار') . ' فعال است.' : 'تا سقف ' . (molavi_buyer_type() === 'shop' ? 'فروشگاه: ' : 'خانوار: ') . esc_html(molavi_toman((int) ($quote['remaining'] ?? 0))); ?></span>
                </span>
                <span class="molavi-progress-track" role="progressbar" aria-label="پیشرفت سبد برای تخفیف" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>" data-molavi-progressbar>
                    <span data-molavi-progress-fill style="width:<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>%"></span>
                </span>
                <span class="molavi-disclosure-action">جزئیات</span>
            </summary>
            <div class="molavi-progress-details">
                <p>تخفیف بر اساس جمع قیمت مرجع کالاهای مشمول محاسبه می‌شود. آستانه خانوار <?php echo esc_html(molavi_toman((int) ($quote['household_unlock'] ?? 5000000))); ?> و آستانه فروشگاه <?php echo esc_html(molavi_toman((int) ($quote['shop_unlock'] ?? 20000000))); ?> است. هزینه ارسال جداگانه محاسبه می‌شود.</p>
            </div>
        </details>
        <button type="button" class="molavi-cart-favorites" data-molavi-cart-favorites<?php echo empty($quote['count']) ? ' hidden' : ''; ?>>انتقال کالاهای سبد به علاقه‌مندی‌ها</button>
        <dl class="molavi-basket-summary">
            <div><dt>جمع قیمت مصرف</dt><dd data-molavi-reference><?php echo esc_html(molavi_toman((int) ($quote['reference_total'] ?? 0))); ?></dd></div>
            <div class="molavi-summary-saving"><dt>صرفه‌جویی فعلی <span data-molavi-savings-rate><?php echo esc_html(molavi_percent((float) ($quote['savings_rate'] ?? 0))); ?></span></dt><dd data-molavi-savings><?php echo esc_html(molavi_toman((int) ($quote['savings'] ?? 0))); ?></dd></div>
            <div class="molavi-summary-total"><dt>جمع محصولات پس از تخفیف</dt><dd data-molavi-subtotal><?php echo esc_html(molavi_toman((int) ($quote['subtotal'] ?? 0))); ?></dd></div>
        </dl>
        <p class="molavi-quote-note">هزینهٔ ارسال در تسویه‌حساب مشخص می‌شود.</p>
        <?php $hasInvalidLines = !empty($quote['invalid_lines']); ?>
        <p class="molavi-checkout-help" data-molavi-checkout-help<?php echo $hasInvalidLines ? '' : ' hidden'; ?>>برای ادامه، تعداد کالاهای مشخص‌شده در سبد را اصلاح کنید.</p>
        <a class="molavi-checkout<?php echo $hasInvalidLines ? ' is-invalid' : ''; ?>" data-molavi-checkout href="<?php echo esc_url($hasInvalidLines ? '#molavi-basket-lines' : wc_get_checkout_url()); ?>"<?php echo !empty($quote['count']) ? '' : ' hidden'; ?><?php echo $hasInvalidLines ? ' aria-disabled="true"' : ''; ?>><?php echo $hasInvalidLines ? 'اصلاح تعداد برای ادامه' : 'ادامه سفارش'; ?></a>
    </aside>
    <?php
}

function molavi_storefront_shortcode(): string
{
    ob_start();
    molavi_render_storefront();
    return (string) ob_get_clean();
}
