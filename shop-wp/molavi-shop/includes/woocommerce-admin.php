<?php
/** Native WooCommerce product and order context for MolaviSale operations. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_admin_supplier_state_label(string $state): string
{
    return [
        'current' => 'قیمت تأمین‌کننده تازه',
        'warning' => 'قیمت به‌زودی نیازمند بررسی است',
        'blocked' => 'قیمت منقضی؛ فروش متوقف است',
        'needs_review' => 'قیمت نیازمند بررسی',
        '' => 'هنوز تأیید نشده',
    ][$state] ?? 'وضعیت نامشخص';
}

function molavi_admin_order_handoff_label($order): string
{
    if (!$order instanceof WC_Order) { return 'سفارش پیدا نشد'; }
    $paidStatus = (string) $order->get_meta('_molavi_paid_handoff_status', true);
    $noticeStatus = (string) $order->get_meta('_molavi_handoff_status', true);
    $status = $paidStatus !== '' ? $paidStatus : $noticeStatus;
    if ($status === 'written' || $order->get_meta('_molavi_paid_handoff_complete', true) !== ''
        || $order->get_meta('_molavi_published', true) === '1') {
        return 'به سامانه مولوی‌سل ارسال شد';
    }
    if ($status === 'failed') { return 'ارسال ناموفق؛ نیازمند بررسی'; }
    if ($status === 'pending') { return 'ارسال در صف تلاش دوباره'; }
    if ($status === 'superseded') { return 'ارسال قبلی با تغییر وضعیت جایگزین شد'; }
    return $order->is_paid() ? 'پرداخت ثبت شده؛ وضعیت ارسال در انتظار بررسی' : 'پس از پرداخت ارسال می‌شود';
}

function molavi_admin_product_columns(array $columns): array
{
    if (!molavi_reference_owner()) { return $columns; }
    $result = [];
    foreach ($columns as $key => $label) {
        $result[$key] = $label;
        if ($key === 'name') {
            $result['molavi_supplier'] = 'تأمین‌کننده';
            $result['molavi_supplier_state'] = 'وضعیت قیمت مولوی‌سل';
        }
    }
    if (!isset($result['molavi_supplier'])) {
        $result['molavi_supplier'] = 'تأمین‌کننده';
        $result['molavi_supplier_state'] = 'وضعیت قیمت مولوی‌سل';
    }
    return $result;
}
add_filter('manage_edit-product_columns', 'molavi_admin_product_columns', 30);

function molavi_admin_product_column(string $column, int $postId): void
{
    if (!molavi_reference_owner() || !in_array($column, ['molavi_supplier', 'molavi_supplier_state'], true)) {
        return;
    }
    $product = wc_get_product($postId);
    if (!$product instanceof WC_Product) { echo '—'; return; }
    if ($column === 'molavi_supplier') {
        $supplier = trim((string) $product->get_meta('_molavi_supplier', true));
        echo esc_html($supplier !== '' ? $supplier : 'ثبت نشده');
        return;
    }
    $state = (string) $product->get_meta('_molavi_supplier_price_state', true);
    echo '<span>' . esc_html(molavi_admin_supplier_state_label($state)) . '</span>';
    $verified = (string) $product->get_meta('_molavi_supplier_price_verified_at', true);
    if ($verified !== '' && strtotime($verified) !== false) {
        echo '<br><small>' . esc_html(wp_date('Y-m-d H:i', strtotime($verified))) . '</small>';
    }
}
add_action('manage_product_posts_custom_column', 'molavi_admin_product_column', 10, 2);

function molavi_admin_product_state_filter(string $postType, string $which): void
{
    if ($postType !== 'product' || $which !== 'top' || !molavi_reference_owner()) { return; }
    $selected = isset($_GET['molavi_supplier_state']) && is_string($_GET['molavi_supplier_state'])
        ? sanitize_key(wp_unslash($_GET['molavi_supplier_state'])) : '';
    echo '<label class="screen-reader-text" for="molavi-supplier-state-filter">'
        . esc_html('فیلتر وضعیت قیمت مولوی‌سل') . '</label>';
    echo '<select id="molavi-supplier-state-filter" name="molavi_supplier_state">';
    echo '<option value="">' . esc_html('همه وضعیت‌های قیمت مولوی‌سل') . '</option>';
    foreach (['current','warning','blocked','needs_review'] as $state) {
        echo '<option value="' . esc_attr($state) . '"' . selected($selected, $state, false) . '>'
            . esc_html(molavi_admin_supplier_state_label($state)) . '</option>';
    }
    echo '</select>';
}
add_action('restrict_manage_posts', 'molavi_admin_product_state_filter', 20, 2);

add_action('pre_get_posts', static function ($query): void {
    if (!is_admin() || !$query->is_main_query() || !molavi_reference_owner()
        || $query->get('post_type') !== 'product') { return; }
    $state = isset($_GET['molavi_supplier_state']) && is_string($_GET['molavi_supplier_state'])
        ? sanitize_key(wp_unslash($_GET['molavi_supplier_state'])) : '';
    if (!in_array($state, ['current','warning','blocked','needs_review'], true)) { return; }
    $metaQuery = (array) $query->get('meta_query');
    $metaQuery[] = ['key' => '_molavi_supplier_price_state', 'value' => $state, 'compare' => '='];
    $query->set('meta_query', $metaQuery);
});

add_filter('woocommerce_product_data_tabs', static function (array $tabs): array {
    if (!molavi_reference_owner()) { return $tabs; }
    $tabs['molavi_owner_data'] = [
        'label' => 'مولوی‌سل', 'target' => 'molavi_owner_data', 'class' => [], 'priority' => 75,
    ];
    return $tabs;
});

add_action('woocommerce_product_data_panels', static function (): void {
    if (!molavi_reference_owner()) { return; }
    global $post;
    $product = isset($post->ID) ? wc_get_product((int) $post->ID) : false;
    if (!$product instanceof WC_Product) { return; }
    $supplier = (string) $product->get_meta('_molavi_supplier', true);
    $offerId = (string) $product->get_meta('_molavi_supplier_offer_id', true);
    $sourceProductId = (string) $product->get_meta('_molavi_source_id', true);
    $state = (string) $product->get_meta('_molavi_supplier_price_state', true);
    $verified = (string) $product->get_meta('_molavi_supplier_price_verified_at', true);
    $shelf = $product->get_meta('_molavi_market_shelf_toman', true);
    $supplierCost = $product->get_meta('_molavi_supplier_cost_toman', true);
    $minimum = (int) $product->get_meta('_molavi_min', true);
    $profile = molavi_product_profile($product);
    $sourcePostUrl = molavi_valid_source_post_url((string) $product->get_meta('_molavi_source_post_url', true));
    $referenceApprovalState = (string) $product->get_meta('_molavi_reference_approval_state', true);
    $referenceApprovalLabel = [
        'owner_approved' => 'تأیید مالک',
        'approved_compatible' => 'تطبیق خودکار سازگار',
        'approved_within_margin' => 'تأیید حفظ شد؛ تغییر مرجع حداکثر ۵٪',
        'outside_margin' => 'تغییر مرجع بیش از ۵٪؛ نیازمند بررسی',
        'reference_missing' => 'کالا در نسخه مرجع جدید نیست',
        'price_unavailable' => 'قیمت مرجع برای سنجش موجود نیست',
        'needs_review' => 'نیازمند بررسی مرجع',
    ][$referenceApprovalState] ?? $referenceApprovalState;
    $referenceChangePercent = (float) $product->get_meta('_molavi_reference_price_change_percent', true);
    $referenceReviewReason = (string) $product->get_meta('_molavi_reference_review_reason', true);
    $referenceUrl = admin_url('admin.php?page=molavi-reference-items');
    if ($offerId !== '') { $referenceUrl = add_query_arg('offer_id', $offerId, $referenceUrl); }
    $supplierReviewUrl = add_query_arg([
        'page' => 'molavi-supplier-prices',
        'product_id' => $sourceProductId !== '' ? $sourceProductId : (string) $product->get_id(),
    ], admin_url('admin.php'));
    ?>
    <div id="molavi_owner_data" class="panel woocommerce_options_panel hidden">
        <div class="options_group">
            <?php wp_nonce_field('molavi_product_profile_settings', 'molavi_product_profile_nonce'); ?>
            <p class="form-field"><strong><?php echo esc_html('اطلاعات منبع کالا'); ?></strong></p>
            <p class="form-field"><label><?php echo esc_html('تأمین‌کننده'); ?></label>
                <span><?php echo esc_html($supplier !== '' ? $supplier : 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('بسته‌بندی'); ?></label>
                <span><?php echo esc_html((string) $product->get_meta('_molavi_packaging', true) ?: 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('دسته منبع'); ?></label>
                <span><?php echo esc_html((string) $product->get_meta('_molavi_category', true) ?: 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('حداقل خرید'); ?></label>
                <span><?php echo esc_html($minimum > 0 ? number_format_i18n($minimum) : 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('واحد سفارش فعلی'); ?></label>
                <span><?php echo esc_html('خانوار: ' . number_format_i18n($profile['household_unit'])
                    . ' · فروشگاه: ' . number_format_i18n($profile['shop_unit'])
                    . ' · اندازه بسته استخراج‌شده: ' . number_format_i18n($profile['pack_size'])); ?></span></p>
            <p class="form-field"><label for="_molavi_tuna_threshold_anchor">
                <input type="checkbox" id="_molavi_tuna_threshold_anchor" name="_molavi_tuna_threshold_anchor" value="1" <?php checked($profile['tuna_threshold_anchor']); ?> />
                <?php echo esc_html('استفاده از قیمت همین قوطی تن‌ماهی برای محاسبه آستانه تخفیف'); ?></label>
                <span class="description">اگر چند محصول انتخاب شوند، قیمت میانه آن‌ها مبناست. قیمت مرجع مشتری در این محصول همیشه در دسترس است.</span></p>
            <p class="form-field"><label for="_molavi_household_order_unit">واحد سفارش خانوار (اختیاری)</label>
                <input type="number" min="1" max="999" step="1" id="_molavi_household_order_unit" name="_molavi_household_order_unit" value="<?php echo esc_attr($profile['order_units_defaulted'] ? '' : (string) $product->get_meta('_molavi_household_order_unit', true)); ?>" placeholder="<?php echo esc_attr((string) $profile['household_unit']); ?>" />
                <span class="description">خالی بماند تا یک‌هشتم تعداد بسته مبنا باشد؛ بسته ۲۴تایی برای خانوار از ۳ عدد شروع می‌شود.</span></p>
            <p class="form-field"><label for="_molavi_shop_order_unit">واحد سفارش فروشگاه (اختیاری)</label>
                <input type="number" min="1" max="999" step="1" id="_molavi_shop_order_unit" name="_molavi_shop_order_unit" value="<?php echo esc_attr($profile['order_units_defaulted'] ? '' : (string) $product->get_meta('_molavi_shop_order_unit', true)); ?>" placeholder="<?php echo esc_attr((string) $profile['shop_unit']); ?>" />
                <span class="description">خالی بماند تا یک‌چهارم تعداد بسته مبنا باشد؛ بسته ۲۴تایی برای فروشگاه از ۶ عدد شروع می‌شود.</span></p>
            <p class="form-field"><label for="_molavi_household_discount_cap_percent">سقف خانوار از حداکثر تخفیف کالا ٪</label>
                <input type="number" min="0" max="100" step="0.1" id="_molavi_household_discount_cap_percent" name="_molavi_household_discount_cap_percent" value="<?php echo esc_attr($product->get_meta('_molavi_household_discount_cap_ratio', true) === '' ? '' : (string) ((float) $product->get_meta('_molavi_household_discount_cap_ratio', true) * 100)); ?>" placeholder="<?php echo esc_attr((string) round($profile['household_cap_ratio'] * 100, 1)); ?>" /></p>
            <p class="form-field"><label for="_molavi_shop_discount_cap_percent">سقف فروشگاه از حداکثر تخفیف کالا ٪</label>
                <input type="number" min="0" max="100" step="0.1" id="_molavi_shop_discount_cap_percent" name="_molavi_shop_discount_cap_percent" value="<?php echo esc_attr($product->get_meta('_molavi_shop_discount_cap_ratio', true) === '' ? '' : (string) ((float) $product->get_meta('_molavi_shop_discount_cap_ratio', true) * 100)); ?>" placeholder="<?php echo esc_attr((string) round($profile['shop_cap_ratio'] * 100, 1)); ?>" /></p>
            <p class="form-field"><label><?php echo esc_html('قیمت مرجع مشتری'); ?></label>
                <span><?php echo esc_html(is_numeric($shelf) && (int) $shelf > 0
                    ? number_format_i18n((int) $shelf) . ' تومان' : 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('بهای ثبت‌شده تأمین‌کننده'); ?></label>
                <span><?php echo esc_html(is_numeric($supplierCost) && (int) $supplierCost > 0
                    ? number_format_i18n((int) $supplierCost) . ' تومان' : 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('تازگی قیمت تأمین‌کننده'); ?></label>
                <span><?php echo esc_html(molavi_admin_supplier_state_label($state)); ?>
                    <?php if ($verified !== '' && strtotime($verified) !== false) : ?>
                        · <?php echo esc_html(wp_date('Y-m-d H:i', strtotime($verified))); ?>
                    <?php endif; ?>
                </span></p>
            <?php if ($referenceApprovalState !== '') : ?>
                <p class="form-field"><label><?php echo esc_html('وضعیت تطبیق و تأیید مرجع'); ?></label>
                    <span><?php echo esc_html($referenceApprovalLabel . ($referenceChangePercent > 0
                        ? ' · تغییر قیمت مرجع ' . number_format_i18n($referenceChangePercent, 2) . '٪' : '')); ?></span>
                    <?php if ($referenceReviewReason !== '') : ?><br><small><?php echo esc_html($referenceReviewReason); ?></small><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($offerId !== '') : ?>
                <p class="form-field"><label><?php echo esc_html('شناسه پیشنهاد منبع'); ?></label>
                    <code><?php echo esc_html($offerId); ?></code></p>
            <?php endif; ?>
            <?php if ($sourcePostUrl !== '') : ?>
                <p class="form-field"><label><?php echo esc_html('پیام اصلی استخراج'); ?></label>
                    <a href="<?php echo esc_url($sourcePostUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html('باز کردن پیام اصلی برای بررسی و به‌روزرسانی'); ?></a></p>
            <?php endif; ?>
            <?php if ($sourceProductId !== '') : ?>
                <p class="form-field"><label><?php echo esc_html('شناسه کالا در منبع'); ?></label>
                    <code><?php echo esc_html($sourceProductId); ?></code></p>
            <?php endif; ?>
            <p class="form-field"><span><?php echo esc_html('این اطلاعات از سابقه منبع و همگام‌سازی مولوی‌سل می‌آید. برای تغییر مشخصات یا تطبیق، از مسیر بررسی استفاده کنید.'); ?></span></p>
            <p class="form-field">
                <a class="button" href="<?php echo esc_url($referenceUrl); ?>"><?php echo esc_html('بررسی تطبیق مرجع'); ?></a>
                <a class="button" href="<?php echo esc_url($supplierReviewUrl); ?>"><?php echo esc_html('بررسی قیمت تأمین‌کننده همین کالا'); ?></a>
            </p>
        </div>
    </div>
    <?php
});

add_action('woocommerce_process_product_meta', static function (int $postId): void {
    if (!molavi_reference_owner() || !current_user_can('edit_post', $postId)
        || !isset($_POST['molavi_product_profile_nonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['molavi_product_profile_nonce'])), 'molavi_product_profile_settings')) {
        return;
    }
    $integerFields = [
        '_molavi_household_order_unit' => [1, 999],
        '_molavi_shop_order_unit' => [1, 999],
        '_molavi_household_discount_threshold_units' => [1, 100],
        '_molavi_shop_discount_threshold_units' => [1, 100],
    ];
    foreach ($integerFields as $key => [$minimumValue, $maximumValue]) {
        $raw = isset($_POST[$key]) ? trim(wp_unslash((string) $_POST[$key])) : '';
        if ($raw === '') { delete_post_meta($postId, $key); continue; }
        if (!ctype_digit($raw)) { continue; }
        update_post_meta($postId, $key, max($minimumValue, min($maximumValue, (int) $raw)));
    }
    foreach (['_molavi_household_discount_cap_percent' => '_molavi_household_discount_cap_ratio',
              '_molavi_shop_discount_cap_percent' => '_molavi_shop_discount_cap_ratio'] as $input => $key) {
        $raw = isset($_POST[$input]) ? trim(wp_unslash((string) $_POST[$input])) : '';
        if ($raw === '') { delete_post_meta($postId, $key); continue; }
        if (!is_numeric($raw)) { continue; }
        update_post_meta($postId, $key, max(0.0, min(1.0, (float) $raw / 100)));
    }
    if (isset($_POST['_molavi_tuna_threshold_anchor'])
        && wp_unslash((string) $_POST['_molavi_tuna_threshold_anchor']) === '1') {
        update_post_meta($postId, '_molavi_tuna_threshold_anchor', '1');
    } else {
        delete_post_meta($postId, '_molavi_tuna_threshold_anchor');
    }
    molavi_forget_catalog();
}, 40);

function molavi_admin_order_columns(array $columns): array
{
    if (!molavi_reference_owner() || isset($columns['molavi_handoff'])) { return $columns; }
    $result = [];
    foreach ($columns as $key => $label) {
        $result[$key] = $label;
        if (in_array($key, ['order_status','order_number'], true)) {
            $result['molavi_handoff'] = 'پیگیری مولوی‌سل';
        }
    }
    if (!isset($result['molavi_handoff'])) { $result['molavi_handoff'] = 'پیگیری مولوی‌سل'; }
    return $result;
}
add_filter('manage_edit-shop_order_columns', 'molavi_admin_order_columns', 30);
add_filter('manage_woocommerce_page_wc-orders_columns', 'molavi_admin_order_columns', 30);

function molavi_admin_order_from_value($value) {
    if ($value instanceof WC_Order) { return $value; }
    if (is_object($value) && method_exists($value, 'get_id')) {
        return wc_get_order((int) $value->get_id());
    }
    return is_numeric($value) ? wc_get_order((int) $value) : false;
}

function molavi_admin_render_order_column(string $column, $value): void
{
    if ($column !== 'molavi_handoff' || !molavi_reference_owner()) { return; }
    $order = molavi_admin_order_from_value($value);
    if (!$order instanceof WC_Order) { echo '—'; return; }
    $status = (string) $order->get_meta('_molavi_paid_handoff_status', true);
    if ($status === '') { $status = (string) $order->get_meta('_molavi_handoff_status', true); }
    $class = in_array($status, ['failed'], true) ? 'attention'
        : (in_array($status, ['pending'], true) ? 'pending'
            : ($status === 'written' ? 'done' : ''));
    $editUrl = method_exists($order, 'get_edit_order_url')
        ? $order->get_edit_order_url()
        : admin_url('post.php?post=' . (int) $order->get_id() . '&action=edit');
    $prepUrl = add_query_arg([
        'page' => 'molavi-paid-orders',
        'order_id' => (int) $order->get_id(),
    ], admin_url('admin.php'));
    echo '<span class="molavi-wc-handoff-status ' . esc_attr($class) . '">'
        . esc_html(molavi_admin_order_handoff_label($order)) . '</span><br>';
    echo '<a href="' . esc_url($editUrl) . '">' . esc_html('جزئیات ووکامرس') . '</a>'
        . '<br><a href="' . esc_url($prepUrl) . '">' . esc_html('پیگیری مولوی‌سل') . '</a>';
}
add_action('manage_shop_order_posts_custom_column', static function (string $column, $postId): void {
    molavi_admin_render_order_column($column, $postId);
}, 30, 2);
add_action('manage_woocommerce_page_wc-orders_custom_column', 'molavi_admin_render_order_column', 30, 2);

add_action('add_meta_boxes', static function (): void {
    if (!molavi_reference_owner()) { return; }
    $screens = ['shop_order'];
    if (function_exists('wc_get_page_screen_id')) { $screens[] = wc_get_page_screen_id('shop-order'); }
    foreach (array_unique(array_filter($screens)) as $screen) {
        add_meta_box('molavi_order_context', 'پیگیری مولوی‌سل', 'molavi_admin_order_meta_box',
            $screen, 'side', 'high');
    }
}, 40);

function molavi_admin_order_meta_box($postOrOrder): void
{
    if (!molavi_reference_owner()) { return; }
    $order = $postOrOrder instanceof WC_Order ? $postOrOrder : false;
    if (!$order instanceof WC_Order && $postOrOrder instanceof WP_Post) {
        $order = wc_get_order((int) $postOrOrder->ID);
    }
    if (!$order instanceof WC_Order && isset($_GET['id']) && is_scalar($_GET['id'])) {
        $order = wc_get_order(absint(wp_unslash($_GET['id'])));
    }
    if (!$order instanceof WC_Order) { echo esc_html('اطلاعات سفارش در دسترس نیست.'); return; }
    $orderId = (int) $order->get_id();
    $editUrl = method_exists($order, 'get_edit_order_url')
        ? $order->get_edit_order_url()
        : admin_url('post.php?post=' . $orderId . '&action=edit');
    $prepUrl = add_query_arg(['page' => 'molavi-paid-orders', 'order_id' => $orderId], admin_url('admin.php'));
    echo '<p><strong>' . esc_html(molavi_admin_order_handoff_label($order)) . '</strong></p>';
    echo '<p>' . esc_html('وضعیت سفارش ووکامرس: ' . wc_get_order_status_name($order->get_status())) . '</p>';
    $buyerType = (string) $order->get_meta('_molavi_buyer_type', true);
    $buyerLabel = $buyerType === 'shop' ? 'صاحب فروشگاه' : ($buyerType === 'household' ? 'خریدار خانوار' : 'ثبت نشده');
    echo '<p>' . esc_html('نوع خریدار: ' . $buyerLabel) . '</p>';
    if ($order->is_paid()) {
        echo '<p><a class="button button-primary" href="' . esc_url($prepUrl) . '">'
            . esc_html('پیگیری تأمین و تکمیل همین سفارش') . '</a></p>';
    } else {
        echo '<p>' . esc_html('آماده‌سازی این سفارش پس از ثبت پرداخت در ووکامرس در دسترس است.') . '</p>';
    }
    echo '<p><a href="' . esc_url($editUrl) . '">' . esc_html('بازگشت به جزئیات سفارش ووکامرس') . '</a></p>';
}
