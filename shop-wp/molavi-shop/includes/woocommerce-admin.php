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
    $referenceUrl = admin_url('admin.php?page=molavi-reference-items');
    if ($offerId !== '') { $referenceUrl = add_query_arg('offer_id', $offerId, $referenceUrl); }
    $supplierReviewUrl = add_query_arg([
        'page' => 'molavi-supplier-prices',
        'product_id' => $sourceProductId !== '' ? $sourceProductId : (string) $product->get_id(),
    ], admin_url('admin.php'));
    ?>
    <div id="molavi_owner_data" class="panel woocommerce_options_panel hidden">
        <div class="options_group">
            <p class="form-field"><strong><?php echo esc_html('اطلاعات منبع کالا'); ?></strong></p>
            <p class="form-field"><label><?php echo esc_html('تأمین‌کننده'); ?></label>
                <span><?php echo esc_html($supplier !== '' ? $supplier : 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('بسته‌بندی'); ?></label>
                <span><?php echo esc_html((string) $product->get_meta('_molavi_packaging', true) ?: 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('دسته منبع'); ?></label>
                <span><?php echo esc_html((string) $product->get_meta('_molavi_category', true) ?: 'ثبت نشده'); ?></span></p>
            <p class="form-field"><label><?php echo esc_html('حداقل خرید'); ?></label>
                <span><?php echo esc_html($minimum > 0 ? number_format_i18n($minimum) : 'ثبت نشده'); ?></span></p>
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
            <?php if ($offerId !== '') : ?>
                <p class="form-field"><label><?php echo esc_html('شناسه پیشنهاد منبع'); ?></label>
                    <code><?php echo esc_html($offerId); ?></code></p>
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
    if ($order->is_paid()) {
        echo '<p><a class="button button-primary" href="' . esc_url($prepUrl) . '">'
            . esc_html('پیگیری تأمین و تکمیل همین سفارش') . '</a></p>';
    } else {
        echo '<p>' . esc_html('آماده‌سازی این سفارش پس از ثبت پرداخت در ووکامرس در دسترس است.') . '</p>';
    }
    echo '<p><a href="' . esc_url($editUrl) . '">' . esc_html('بازگشت به جزئیات سفارش ووکامرس') . '</a></p>';
}
