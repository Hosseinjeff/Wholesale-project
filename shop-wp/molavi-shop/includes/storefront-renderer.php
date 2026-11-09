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
    $featuredProducts = molavi_storefront_featured_products($products, molavi_buyer_type());
    $categories = [];
    foreach ($products as $product) {
        $category = molavi_storefront_product_category($product);
        if ($category !== '') {
            $categories[$category] = true;
        }
    }
    $categories = array_keys($categories);
    sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
    ?>
    <section class="molavi-storefront" data-molavi-storefront aria-label="فروشگاه مولوی‌سل">
        <h1 class="screen-reader-text">فروشگاه مولوی‌سل</h1>
        <div class="molavi-store-layout">
            <div class="molavi-product-area">
                <?php molavi_render_buyer_profile_selector('shop'); ?>
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
                <section class="molavi-recommendation-carousel" data-molavi-recommendations aria-label="پیشنهادهای محصول با تخفیف بیشتر">
                    <div class="molavi-recommendation-head">
                        <div><p class="molavi-eyebrow">پیشنهاد محصول</p><h2>محصول با بیشترین تخفیف</h2></div>
                        <div class="molavi-recommendation-controls" role="group" aria-label="پیشنهادها">
                            <button type="button" data-molavi-recommendation-prev aria-label="پیشنهاد قبلی">‹</button>
                            <span data-molavi-recommendation-count aria-live="polite">۱ از <?php echo esc_html(molavi_digits((string) max(1, count($featuredProducts)))); ?></span>
                            <button type="button" data-molavi-recommendation-next aria-label="پیشنهاد بعدی">›</button>
                        </div>
                    </div>
                    <div class="molavi-recommendation-slide" data-molavi-recommendation-slide aria-live="polite">
                        <?php if ($featuredProducts !== []): ?>
                            <?php molavi_render_featured_storefront_product($featuredProducts[0]['product'], $featuredProducts[0]['discount'], $quote); ?>
                        <?php else: ?>
                            <p class="molavi-featured-empty">در حال حاضر کالای تخفیف‌دار موجود نیست.</p>
                        <?php endif; ?>
                    </div>
                    <div class="molavi-recommendation-dots" data-molavi-recommendation-dots role="group" aria-label="انتخاب پیشنهاد"></div>
                </section>
                <div class="molavi-product-grid" data-molavi-grid>
                    <?php foreach ($products as $product): ?>
                        <?php molavi_render_storefront_card($product, $quote); ?>
                    <?php endforeach; ?>
                </div>
                <p class="molavi-store-empty" data-molavi-empty hidden>محصولی با این مشخصات پیدا نشد.</p>
            </div>

            <div class="molavi-basket-shell" data-molavi-basket-shell>
                <button type="button" class="molavi-mobile-basket" data-molavi-open-basket aria-controls="molavi-basket-dialog" aria-expanded="false"<?php echo empty($quote['count']) ? ' hidden' : ''; ?>>
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
                <span class="molavi-deal" data-molavi-profile-deal data-product="<?php echo esc_attr((string) $id); ?>" aria-label="حداکثر <?php echo esc_attr(molavi_percent($max)); ?> تخفیف">تا <?php echo esc_html(molavi_percent($max)); ?> تخفیف</span>
            <?php else: ?>
                <span class="molavi-deal molavi-deal-fixed" data-molavi-profile-deal data-product="<?php echo esc_attr((string) $id); ?>">قیمت ثابت</span>
            <?php endif; ?>
            <button type="button" class="molavi-favorite" data-molavi-favorite="<?php echo esc_attr((string) $id); ?>" aria-label="افزودن <?php echo esc_attr($displayName); ?> به علاقه‌مندی‌ها" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg></button>
        </div>
        <div class="molavi-card-body">
            <h3><a href="<?php echo esc_url($product->get_permalink()); ?>" title="<?php echo esc_attr($displayName); ?>"><?php echo esc_html($displayName); ?></a></h3>
            <div class="molavi-card-facts">
                <span class="molavi-order-units" data-molavi-order-units data-household-unit="<?php echo esc_attr((string) $profile['household_unit']); ?>" data-shop-unit="<?php echo esc_attr((string) $profile['shop_unit']); ?>" title="خانوار <?php echo esc_attr(molavi_digits((string) $profile['household_unit'])); ?> عدد؛ فروشگاه <?php echo esc_attr(molavi_digits((string) $profile['shop_unit'])); ?> عدد">گام سفارش: <?php echo esc_html(molavi_digits((string) $profile[$buyerType . '_unit'])); ?> عدد</span>
                <?php if ($category !== ''): ?><span class="molavi-card-category"><?php echo esc_html($category); ?></span><?php endif; ?>
                <span class="screen-reader-text">حداقل خرید <?php echo esc_html(molavi_digits((string) $min)); ?> عدد</span>
            </div>
            <div class="molavi-card-price" data-molavi-price data-product="<?php echo esc_attr((string) $id); ?>" data-name="<?php echo esc_attr($displayName); ?>" data-min="<?php echo esc_attr((string) $min); ?>" data-household-min="<?php echo esc_attr((string) $profile['household_unit']); ?>" data-shop-min="<?php echo esc_attr((string) $profile['shop_unit']); ?>" data-household-max="<?php echo esc_attr((string) $householdCurve['max']); ?>" data-shop-max="<?php echo esc_attr((string) $shopCurve['max']); ?>" data-discountable="<?php echo $curve['discountable'] ? '1' : '0'; ?>" data-shelf="<?php echo esc_attr((string) $shelf); ?>" data-fixed-price="<?php echo esc_attr((string) ($shelf > 0 ? 0 : $unit)); ?>" data-unit="<?php echo esc_attr((string) $unit); ?>">
                <?php if ($shelf > 0): ?><span class="molavi-reference-label">قیمت مصرف <s class="molavi-was" title="<?php echo esc_attr(molavi_toman($shelf)); ?>"<?php echo $shelf > $unit ? '' : ' hidden'; ?>><?php echo esc_html(molavi_toman($shelf)); ?></s></span><?php endif; ?>
                <div class="molavi-current-price">
                    <span class="molavi-amount" title="<?php echo esc_attr(molavi_toman($unit)); ?>"><?php echo esc_html(molavi_toman($unit)); ?></span>
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
        echo '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-' . esc_attr((string) $min) . '" aria-label="کم کردن ' . esc_attr(molavi_digits((string) $min)) . ' عدد">−</button><b>' . esc_html(molavi_digits((string) $qty)) . '</b><button type="button" data-molavi-step="' . esc_attr((string) $min) . '" aria-label="افزودن ' . esc_attr(molavi_digits((string) $min)) . ' عدد">+</button></div><button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' . molavi_basket_trash_svg() . '</button></div>';
    } else {
        $supplier = trim((string) $product->get_meta('_molavi_supplier'));
        $displayName = molavi_storefront_card_product_name($product, $supplier);
        echo '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن حداقل ' . esc_attr(molavi_digits((string) $min)) . ' عدد ' . esc_attr($displayName) . ' به سبد"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"></path><circle cx="10" cy="19" r="1.2"></circle><circle cx="17" cy="19" r="1.2"></circle></svg><span>افزودن</span></button>';
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
        <?php molavi_render_buyer_profile_selector('basket'); ?>
        <div class="molavi-basket-lines" id="molavi-basket-lines" data-molavi-basket-lines>
            <?php foreach ($qtys as $id => $qty): ?>
                <?php $row = $map[(string) $id] ?? null; if (!$row || (int) $qty < 1) { continue; } $unit = (int) ($units[(string) $id] ?? molavi_catalog_product_price($row, (int) $qty, molavi_buyer_type(), (float) ($quote['progress'] ?? 0))['price']); $product = wc_get_product((int) $id); $step = max(1, (int) ($row[molavi_buyer_type() . '_unit'] ?? $row['min'])); ?>
                <article class="molavi-bline" data-product="<?php echo esc_attr((string) $id); ?>" data-min="<?php echo esc_attr((string) $row['min']); ?>" data-qty="<?php echo esc_attr((string) $qty); ?>">
                    <div class="molavi-bline-image"><?php if ($product): ?><?php echo wp_kses_post($product->get_image('woocommerce_thumbnail', ['alt' => (string) $row['name'], 'loading' => 'lazy'])); ?><?php endif; ?></div>
                    <div class="molavi-bline-copy"><h3><?php echo esc_html((string) $row['name']); ?></h3><p><strong><?php echo esc_html(molavi_toman($unit)); ?></strong> × <?php echo esc_html(molavi_digits((string) $qty)); ?></p><small class="molavi-order-step">گام سفارش: <?php echo esc_html(molavi_digits((string) $step)); ?> عدد</small></div>
                    <div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-<?php echo esc_attr((string) $step); ?>" aria-label="کم کردن <?php echo esc_attr(molavi_digits((string) $step)); ?> عدد">−</button><b><?php echo esc_html(molavi_digits((string) $qty)); ?></b><button type="button" data-molavi-step="<?php echo esc_attr((string) $step); ?>" aria-label="افزودن <?php echo esc_attr(molavi_digits((string) $step)); ?> عدد">+</button></div><button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد"><?php echo molavi_basket_trash_svg(); ?></button></div>
                </article>
            <?php endforeach; ?>
            <?php if ($qtys === []): ?><p class="molavi-basket-empty" data-molavi-empty-basket>سبد خالی است. برای شروع، یک محصول انتخاب کنید.</p><?php endif; ?>
        </div>
        <section class="molavi-progress-module" aria-label="پیشرفت تخفیف">
            <div class="molavi-progress-copy"><strong data-molavi-progress-title><?php echo !empty($quote['progress']) && $quote['progress'] >= 1 ? 'به سقف تخفیف این نوع خرید رسیدید' : 'با افزایش مبلغ سبد، تخفیف بیشتر می‌شود'; ?></strong><span data-molavi-remaining><?php echo !empty($quote['remaining']) ? 'مانده تا سقف: ' . esc_html(molavi_toman((int) $quote['remaining'])) : 'سقف تخفیف این نوع خرید فعال است.'; ?></span></div>
            <div class="molavi-progress-track" role="progressbar" aria-label="پیشرفت سبد برای تخفیف" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>" data-molavi-progressbar><span data-molavi-progress-fill style="width:<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>%"></span></div>
            <details><summary>نحوه محاسبه</summary><p>تخفیف بر اساس جمع قیمت مرجع کالاهای مشمول محاسبه می‌شود. آستانه خانوار <?php echo esc_html(molavi_toman((int) ($quote['household_unlock'] ?? 5000000))); ?> و آستانه فروشگاه <?php echo esc_html(molavi_toman((int) ($quote['shop_unlock'] ?? 20000000))); ?> است. هزینه ارسال جداگانه محاسبه می‌شود.</p></details>
        </section>
        <dl class="molavi-basket-summary">
            <div><dt>جمع قیمت مصرف</dt><dd data-molavi-reference><?php echo esc_html(molavi_toman((int) ($quote['reference_total'] ?? 0))); ?></dd></div>
            <div class="molavi-summary-saving"><dt>صرفه‌جویی فعلی <span data-molavi-savings-rate><?php echo esc_html(molavi_percent((float) ($quote['savings_rate'] ?? 0))); ?></span></dt><dd data-molavi-savings><?php echo esc_html(molavi_toman((int) ($quote['savings'] ?? 0))); ?></dd></div>
            <div class="molavi-summary-total"><dt>جمع محصولات پس از تخفیف</dt><dd data-molavi-subtotal><?php echo esc_html(molavi_toman((int) ($quote['subtotal'] ?? 0))); ?></dd></div>
        </dl>
        <p class="molavi-quote-note">مبلغ نهایی محصولات هنگام ادامه سفارش از سبد ووکامرس محاسبه می‌شود.</p>
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
