<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Storefront extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-storefront';
    }

    public function get_title(): string
    {
        return 'فروشگاه مولوی‌سل';
    }

    public function get_icon(): string
    {
        return 'eicon-products';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['فروشگاه', 'کالا', 'سبد', 'storefront', 'molavi'];
    }

    protected function render(): void
    {
        molavi_render_storefront();
    }
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
        'orderby' => 'title',
        'order' => 'ASC',
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
    $title = 'خرید عمده برای خانه و فروشگاه';
    ?>
    <section class="molavi-storefront" data-molavi-storefront aria-label="فروشگاه مولوی‌سل">
        <header class="molavi-store-intro">
            <div>
                <p class="molavi-eyebrow">مولوی‌سل</p>
                <h1><?php echo esc_html($title); ?></h1>
                <p>محصول‌ها را ببینید، قیمت سبد را بررسی کنید و در صورت نیاز مقدار خرید را تغییر دهید.</p>
            </div>
            <div class="molavi-intro-note">
                <span class="molavi-intro-mark" aria-hidden="true">٪</span>
                <span>قیمت فعلی با تغییر ترکیب سبد به‌روز می‌شود.</span>
            </div>
        </header>

        <div class="molavi-store-tools">
            <label class="molavi-search">
                <span class="screen-reader-text">جستجوی محصولات</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"></circle><path d="m16 16 4 4"></path></svg>
                <input type="search" data-molavi-search placeholder="نام محصول را جستجو کنید" autocomplete="off">
            </label>
            <label class="molavi-sort">
                <span>مرتب‌سازی</span>
                <select data-molavi-sort aria-label="مرتب‌سازی محصولات">
                    <option value="name">نام محصول</option>
                    <option value="cheap">قیمت: کم به زیاد</option>
                    <option value="dear">قیمت: زیاد به کم</option>
                </select>
            </label>
        </div>

        <?php if ($categories !== []): ?>
            <nav class="molavi-category-list" aria-label="دسته‌بندی محصولات">
                <button type="button" class="is-active" data-molavi-category="">همه محصولات</button>
                <?php foreach ($categories as $category): ?>
                    <button type="button" data-molavi-category="<?php echo esc_attr($category); ?>"><?php echo esc_html($category); ?></button>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="molavi-store-layout">
            <div class="molavi-product-area">
                <div class="molavi-product-heading">
                    <h2>محصولات</h2>
                    <span data-molavi-result-count><?php echo esc_html(molavi_digits((string) count($products))); ?> کالا</span>
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
    ?>
    <article class="molavi-product-card e-loop-item" data-molavi-card data-name="<?php echo esc_attr($product->get_name()); ?>" data-category="<?php echo esc_attr($category); ?>" data-search="<?php echo esc_attr($search); ?>" data-price="<?php echo esc_attr((string) $unit); ?>">
        <div class="molavi-card-photo">
            <?php echo wp_kses_post($image); ?>
            <?php if ($max > 0): ?>
                <span class="molavi-deal" aria-label="حداکثر <?php echo esc_attr(molavi_percent($max)); ?> تخفیف">تا <?php echo esc_html(molavi_percent($max)); ?> تخفیف</span>
            <?php else: ?>
                <span class="molavi-deal molavi-deal-fixed">قیمت ثابت</span>
            <?php endif; ?>
        </div>
        <div class="molavi-card-body">
            <h3><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
            <div class="molavi-card-facts">
                <?php if ($packaging !== ''): ?><span><?php echo esc_html($packaging); ?></span><?php endif; ?>
                <?php if ($supplier !== ''): ?><span><?php echo esc_html($supplier); ?></span><?php endif; ?>
                <span>حداقل خرید <?php echo esc_html(molavi_digits((string) $min)); ?> عدد</span>
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
        echo '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن ' . esc_attr($product->get_name()) . ' به سبد">افزودن ' . esc_html(molavi_digits((string) $min)) . '<span aria-hidden="true">＋</span></button>';
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
                <?php $row = $map[(string) $id] ?? null; if (!$row || (int) $qty < 1) { continue; } $unit = (int) ($units[(string) $id] ?? molavi_unit_price($row, (float) ($quote['progress'] ?? 0))); ?>
                <article class="molavi-bline" data-product="<?php echo esc_attr((string) $id); ?>" data-min="<?php echo esc_attr((string) $row['min']); ?>" data-qty="<?php echo esc_attr((string) $qty); ?>">
                    <div class="molavi-bline-copy"><h3><?php echo esc_html((string) $row['name']); ?></h3><p><strong><?php echo esc_html(molavi_toman($unit)); ?></strong> × <?php echo esc_html(molavi_digits((string) $qty)); ?></p></div>
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
