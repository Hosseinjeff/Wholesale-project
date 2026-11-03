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

function molavi_storefront_card_product_name(\WC_Product $product, string $supplier = ''): string
{
    $originalName = trim(wp_strip_all_tags($product->get_name()));
    if ($originalName === '') {
        $slug = method_exists($product, 'get_slug') ? (string) $product->get_slug() : '';
        if ($slug === '' && function_exists('get_post_field')) {
            $slug = (string) get_post_field('post_name', $product->get_id());
        }
        if ($slug === '') {
            $path = parse_url($product->get_permalink(), PHP_URL_PATH);
            $slug = is_string($path) ? basename(rtrim($path, '/')) : '';
        }
        $slug = rawurldecode($slug);
        $slug = (string) preg_replace('/-[0-9]+$/', '', $slug);
        $originalName = trim(preg_replace('/[-_]+/u', ' ', $slug) ?? '');
    }
    $name = $originalName;
    $supplier = trim(wp_strip_all_tags($supplier));
    if ($supplier !== '') {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($supplier, '/') . '(?![\p{L}\p{N}])/iu';
        $name = (string) preg_replace($pattern, ' ', $name);
    }
    $name = (string) preg_replace('/[\p{Z}\s\p{Cf}]+/u', ' ', $name);
    // A legacy supplier field can duplicate the entire product title. Keep that
    // title rather than rendering a blank link in the storefront.
    if (preg_match('/[\p{L}\p{N}]/u', $name) !== 1) {
        $name = $originalName;
    }
    $name = (string) preg_replace(
        '/(?<![\p{L}\p{N}])(?:(?:کارتن|بسته|جعبه)\s*)?[0-9۰-۹٠-٩]{1,4}\s*(?:عددی|عدد|تایی|بسته|کارتن)(?![\p{L}\p{N}])/u',
        ' ',
        $name
    );
    $name = (string) preg_replace(
        '/(?<![\p{L}\p{N}])[0-9۰-۹٠-٩]+(?:[.,٫][0-9۰-۹٠-٩]+)?\s*(?:میلی[\p{Z}\s\x{200c}]*لیتر(?:ی)?|میلی[\p{Z}\s\x{200c}]*گرم(?:ی)?|کیلو[\p{Z}\s\x{200c}]*گرم(?:ی)?|لیتر(?:ی)?|گرم(?:ی)?|کیلو|kg|g|ml|cc|l)(?![\p{L}\p{N}])/iu',
        ' ',
        $name
    );
    $name = (string) preg_replace('/(?<![\p{L}\p{N}])تعداد\s+در\s+(?:کارتن|بسته|جعبه)(?![\p{L}\p{N}])/u', ' ', $name);
    $name = (string) preg_replace('/(?<![\p{L}\p{N}])(?:کارتن|بسته|جعبه)(?![\p{L}\p{N}])/u', ' ', $name);
    $name = (string) preg_replace('/[،,؛;]+/u', ' ', $name);
    $name = (string) preg_replace('/[\p{Z}\s\p{Cf}]+/u', ' ', $name);
    $name = trim($name, " \t\n\r\0\x0B،,؛;:-");
    return preg_match('/[\p{L}\p{N}]/u', $name) === 1 ? $name : 'کالا';
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
    $category = trim((string) $product->get_meta('_molavi_category'));
    if ($category === '') {
        $terms = get_the_terms($id, 'product_cat');
        if (is_array($terms) && $terms !== []) {
            $category = (string) $terms[0]->name;
        }
    }
    if ($supplier !== '') {
        $supplierPattern = '/(?<![\p{L}\p{N}])' . preg_quote($supplier, '/') . '(?![\p{L}\p{N}])/iu';
        $category = trim((string) preg_replace($supplierPattern, ' ', $category), " \t\n\r\0\x0B،,؛;:-");
    }
    $search = trim(implode(' ', array_filter([$displayName, $category])));
    $image = $product->get_image('woocommerce_thumbnail', [
        'alt' => $displayName,
        'loading' => 'lazy',
    ]);
    $created = $product->get_date_created();
    $createdAt = $created ? $created->getTimestamp() : 0;
    ?>
    <article class="molavi-product-card e-loop-item" data-molavi-card data-product="<?php echo esc_attr((string) $id); ?>" data-name="<?php echo esc_attr($displayName); ?>" data-category="<?php echo esc_attr($category); ?>" data-search="<?php echo esc_attr($search); ?>" data-price="<?php echo esc_attr((string) $unit); ?>" data-created="<?php echo esc_attr((string) $createdAt); ?>" data-household-max="<?php echo esc_attr((string) $householdCurve['max']); ?>" data-shop-max="<?php echo esc_attr((string) $shopCurve['max']); ?>">
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
            <h3><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo esc_html($displayName); ?></a></h3>
            <div class="molavi-card-facts">
                <span class="molavi-order-units" data-molavi-order-units>خانوار <?php echo esc_html(molavi_digits((string) $profile['household_unit'])); ?> · فروشگاه <?php echo esc_html(molavi_digits((string) $profile['shop_unit'])); ?></span>
                <?php if ($category !== ''): ?><span><?php echo esc_html($category); ?></span><?php endif; ?>
                <span class="screen-reader-text">حداقل خرید <?php echo esc_html(molavi_digits((string) $min)); ?> عدد</span>
            </div>
            <div class="molavi-card-price" data-molavi-price data-product="<?php echo esc_attr((string) $id); ?>" data-name="<?php echo esc_attr($displayName); ?>" data-min="<?php echo esc_attr((string) $min); ?>" data-household-min="<?php echo esc_attr((string) $profile['household_unit']); ?>" data-shop-min="<?php echo esc_attr((string) $profile['shop_unit']); ?>" data-household-max="<?php echo esc_attr((string) $householdCurve['max']); ?>" data-shop-max="<?php echo esc_attr((string) $shopCurve['max']); ?>" data-discountable="<?php echo $curve['discountable'] ? '1' : '0'; ?>" data-shelf="<?php echo esc_attr((string) $shelf); ?>" data-fixed-price="<?php echo esc_attr((string) ($shelf > 0 ? 0 : $unit)); ?>" data-unit="<?php echo esc_attr((string) $unit); ?>">
                <?php if ($shelf > 0): ?><span class="molavi-reference-label">قیمت مصرف <s class="molavi-was"<?php echo $shelf > $unit ? '' : ' hidden'; ?>><?php echo esc_html(molavi_toman($shelf)); ?></s></span><?php endif; ?>
                <div class="molavi-current-price">
                    <span class="molavi-amount"><?php echo esc_html(molavi_toman($unit)); ?></span>
                    <span class="molavi-rate-label"<?php echo $rate > 0 ? '' : ' hidden'; ?>>تخفیف فعلی <b class="molavi-rate"><?php echo esc_html(molavi_percent($rate)); ?></b></span>
                    <?php if ($shelf <= 0): ?><span class="molavi-fixed-label">قیمت ثابت</span><?php endif; ?>
                </div>
            </div>
            <?php molavi_render_storefront_control($product, $qty, $min); ?>
            <div class="molavi-card-feedback" data-molavi-card-feedback data-product="<?php echo esc_attr((string) $id); ?>" role="status" aria-live="polite" hidden></div>
            <p class="molavi-card-status" data-molavi-card-status data-product="<?php echo esc_attr((string) $id); ?>" role="status" aria-live="polite" hidden></p>
        </div>
    </article>
    <?php
}

function molavi_render_storefront_control(\WC_Product $product, int $qty, int $min): void
{
    echo '<div class="molavi-control' . ($qty > 0 ? ' is-in' : '') . '" data-molavi-cart data-product="' . esc_attr((string) $product->get_id()) . '" data-min="' . esc_attr((string) $min) . '" data-qty="' . esc_attr((string) $qty) . '">';
    if ($qty > 0) {
        echo '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-' . esc_attr((string) $min) . '" aria-label="کم کردن به اندازه واحد سفارش">−</button><b>' . esc_html(molavi_digits((string) $qty)) . '</b><button type="button" data-molavi-step="' . esc_attr((string) $min) . '" aria-label="افزودن به اندازه واحد سفارش">+</button></div><button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' . molavi_basket_trash_svg() . '</button></div>';
    } else {
        $supplier = trim((string) $product->get_meta('_molavi_supplier'));
        $displayName = molavi_storefront_card_product_name($product, $supplier);
        echo '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن حداقل ' . esc_attr(molavi_digits((string) $min)) . ' عدد ' . esc_attr($displayName) . ' به سبد"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"></path><circle cx="10" cy="19" r="1.2"></circle><circle cx="17" cy="19" r="1.2"></circle></svg></button>';
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
        <div class="molavi-basket-lines" data-molavi-basket-lines>
            <?php foreach ($qtys as $id => $qty): ?>
                <?php $row = $map[(string) $id] ?? null; if (!$row || (int) $qty < 1) { continue; } $unit = (int) ($units[(string) $id] ?? molavi_catalog_product_price($row, (int) $qty, molavi_buyer_type(), (float) ($quote['progress'] ?? 0))['price']); $product = wc_get_product((int) $id); $step = max(1, (int) ($row[molavi_buyer_type() . '_unit'] ?? $row['min'])); ?>
                <article class="molavi-bline" data-product="<?php echo esc_attr((string) $id); ?>" data-min="<?php echo esc_attr((string) $row['min']); ?>" data-qty="<?php echo esc_attr((string) $qty); ?>">
                    <div class="molavi-bline-image"><?php if ($product): ?><?php echo wp_kses_post($product->get_image('woocommerce_thumbnail', ['alt' => (string) $row['name'], 'loading' => 'lazy'])); ?><?php endif; ?></div>
                    <div class="molavi-bline-copy"><h3><?php echo esc_html((string) $row['name']); ?></h3><p><strong><?php echo esc_html(molavi_toman($unit)); ?></strong> × <?php echo esc_html(molavi_digits((string) $qty)); ?></p><small>گام سفارش: <?php echo esc_html(molavi_digits((string) $step)); ?> عدد</small></div>
                    <div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-<?php echo esc_attr((string) $step); ?>" aria-label="کم کردن به اندازه واحد سفارش">−</button><b><?php echo esc_html(molavi_digits((string) $qty)); ?></b><button type="button" data-molavi-step="<?php echo esc_attr((string) $step); ?>" aria-label="افزودن به اندازه واحد سفارش">+</button></div><button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد"><?php echo molavi_basket_trash_svg(); ?></button></div>
                </article>
            <?php endforeach; ?>
            <?php if ($qtys === []): ?><p class="molavi-basket-empty" data-molavi-empty-basket>سبد خالی است. برای شروع، یک محصول انتخاب کنید.</p><?php endif; ?>
        </div>
        <section class="molavi-progress-module" aria-label="پیشرفت تخفیف">
            <div class="molavi-progress-copy"><strong data-molavi-progress-title><?php echo !empty($quote['progress']) && $quote['progress'] >= 1 ? 'به سقف تخفیف این نوع خرید رسیدید' : 'با افزایش مبلغ سبد، تخفیف بیشتر می‌شود'; ?></strong><span data-molavi-remaining><?php echo !empty($quote['remaining']) ? 'مانده تا سقف: ' . esc_html(molavi_toman((int) $quote['remaining'])) : 'سقف تخفیف این نوع خرید فعال است.'; ?></span></div>
            <div class="molavi-progress-track" role="progressbar" aria-label="پیشرفت سبد برای تخفیف" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>" data-molavi-progressbar><span data-molavi-progress-fill style="width:<?php echo esc_attr((string) round((float) ($quote['progress'] ?? 0) * 100)); ?>%"></span></div>
            <p class="molavi-progress-help">آستانه بر اساس جمع قیمت مرجع کالاهای مشمول تخفیف محاسبه می‌شود؛ هزینه ارسال جداست.</p>
            <details><summary>جزئیات محاسبه</summary><p>آستانه خانوار ارزش <?php echo esc_html(molavi_digits((string) ($quote['tuna_shell_units'] ?? 24))); ?> قوطی تن‌ماهی (<?php echo esc_html(molavi_toman((int) ($quote['household_unlock'] ?? 5000000))); ?>) است و آستانه فروشگاه <?php echo esc_html(molavi_digits((string) ($quote['shop_unlock_multiplier'] ?? 4))); ?> برابر آن (<?php echo esc_html(molavi_toman((int) ($quote['shop_unlock'] ?? 20000000))); ?>). سقف تخفیف فروشگاه تا کف مجاز قیمت کالا پیش می‌رود؛ خانوار سقف پایین‌تری دارد.</p></details>
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
