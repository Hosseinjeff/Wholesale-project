<?php
/** Private owner history for product approval and freshness state. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'سوابق وضعیت کالا', 'سوابق وضعیت کالا',
        'manage_woocommerce', 'molavi-product-status-snapshots', 'molavi_product_status_snapshots_screen');
});

function molavi_product_status_snapshots_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('سوابق وضعیت کالا') . '</h1>';
    echo '<p>' . esc_html('تاریخچه تأیید، تطبیق مرجع، تازگی قیمت و نیاز به به‌روزرسانی کالاها را ببینید.') . '</p>';
    molavi_owner_workflow_mount('در حال دریافت snapshotهای وضعیت کالا…');
    echo '</div>';
}
