<?php
/** Unified owner entry point for the MolaviSale product and operations pipeline. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_operations_dashboard_request() {
    return molavi_pipeline_request('/pipeline/operations-dashboard', 'GET');
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'خط لوله مولوی‌سل', 'خط لوله مولوی‌سل',
        'manage_woocommerce', 'molavi-operations', 'molavi_operations_dashboard_screen');
}, 20);

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    $pages = [
        'molavi-reference-decisions', 'molavi-reference-snapshots', 'molavi-reference-items',
        'molavi-ai-confirmations', 'molavi-publication-holds', 'molavi-supplier-prices',
        'molavi-sheet-conflicts', 'molavi-paid-orders', 'molavi-clearance-pricing',
        'molavi-inventory-management',
    ];
    foreach ($pages as $page) { remove_submenu_page('woocommerce', $page); }
}, 999);

function molavi_operations_dashboard_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('خط لوله مولوی‌سل') . '</h1>';
    echo '<p>' . esc_html('مراحل آماده‌سازی کالا را از پیام تأمین‌کننده تا تطبیق، انتشار و موجودی به‌ترتیب پیگیری کنید.') . '</p>';
    molavi_owner_workflow_mount('در حال دریافت وضعیت خط لوله…');
    echo '<noscript><div class="notice notice-warning"><p>' . esc_html('برای نمایش وضعیت زنده، جاوااسکریپت را فعال کنید.') . '</p>';
    echo '<ul>';
    foreach (molavi_owner_workflow_page_urls() as $slug=>$url) {
        echo '<li><a href="' . esc_url($url) . '">' . esc_html($slug) . '</a></li>';
    }
    echo '</ul></div></noscript></div>';
}
