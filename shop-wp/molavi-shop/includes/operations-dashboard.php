<?php
/** Unified owner entry point for the MolaviSale product and operations pipeline. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_operations_dashboard_request() {
    return molavi_pipeline_request('/pipeline/operations-dashboard', 'GET');
}

/** Return counts only so the start screen can order owner work without exposing source records. */
function molavi_operations_dashboard_queue_summary() {
    if (!molavi_reference_owner()) { return new WP_Error('owner', 'دسترسی مجاز نیست.'); }
    $sources = [
        'reference_items' => ['/reference/item-reviews', 'cases'],
        'ai_confirmations' => ['/pipeline/ai-confirmations', 'cases'],
        'reference_decisions' => ['/reference/decisions', 'events'],
        'supplier_prices' => ['/pipeline/supplier-price-evidence', 'offers'],
    ];
    $summary = [];
    foreach ($sources as $key=>$source) {
        [$path, $rowsKey] = $source;
        $result = molavi_pipeline_request($path, 'GET');
        $rows = is_array($result) ? ($result[$rowsKey] ?? null) : null;
        $summary[$key] = is_array($rows)
            ? ['status'=>'ok', 'count'=>count($rows)]
            : ['status'=>'unavailable', 'count'=>null];
    }
    return $summary;
}

add_action('wp_ajax_molavi_operations_queue_summary', static function (): void {
    check_ajax_referer('molavi_operations_queue_summary', 'nonce');
    if (!molavi_reference_owner()) {
        wp_send_json_error(['code'=>'owner_required'], 403);
    }
    $summary = molavi_operations_dashboard_queue_summary();
    if (is_wp_error($summary)) {
        wp_send_json_error(['code'=>'queue_summary_unavailable'], 503);
    }
    wp_send_json_success(['queues'=>$summary]);
});

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'خط لوله مولوی‌سل', 'خط لوله مولوی‌سل',
        'manage_woocommerce', 'molavi-operations', 'molavi_operations_dashboard_screen');
}, 20);

add_action('admin_footer', static function (): void {
    if (!molavi_reference_owner()) { return; }
    $pages = [
        'molavi-reference-decisions', 'molavi-reference-snapshots', 'molavi-reference-items',
        'molavi-ai-confirmations', 'molavi-publication-holds', 'molavi-supplier-prices',
        'molavi-sheet-conflicts', 'molavi-paid-orders', 'molavi-clearance-pricing',
        'molavi-inventory-management', 'molavi-product-status-snapshots',
    ];
    echo '<script>(function(){var pages=' . wp_json_encode($pages)
        . ';var menu=document.querySelector("#toplevel_page_woocommerce .wp-submenu");'
        . 'if(!menu)return;menu.querySelectorAll("a[href]").forEach(function(link){'
        . 'var href=link.getAttribute("href")||"";'
        . 'if(pages.some(function(page){return href.indexOf("page="+page)!==-1;}))'
        . '{var item=link.closest("li");if(item)item.hidden=true;}'
        . '});})();</script>';
}, 1000);

function molavi_operations_dashboard_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('خط لوله مولوی‌سل') . '</h1>';
    echo '<p>' . esc_html('بخش‌های استخراج، بررسی کالا، انتشار، موجودی و سفارش را از تب‌های همین صفحه باز کنید.') . '</p>';
    molavi_owner_workflow_mount('در حال دریافت وضعیت خط لوله…');
    echo '<noscript><div class="notice notice-warning"><p>' . esc_html('برای نمایش وضعیت زنده، جاوااسکریپت را فعال کنید.') . '</p>';
    echo '<ul>';
    $labels = [
        'molavi-ai-confirmations' => 'استخراج کالا',
        'molavi-reference-snapshots' => 'فهرست مرجع',
        'molavi-reference-decisions' => 'تازگی مرجع',
        'molavi-reference-items' => 'تطبیق کالا',
        'molavi-supplier-prices' => 'قیمت تأمین‌کننده',
        'molavi-publication-holds' => 'انتشار',
        'molavi-sheet-conflicts' => 'تعارض شیت',
        'molavi-inventory-management' => 'موجودی',
        'molavi-clearance-pricing' => 'قیمت حراج',
        'molavi-paid-orders' => 'سفارش‌ها',
        'molavi-product-status-snapshots' => 'سوابق وضعیت کالا',
    ];
    $pageUrls = molavi_owner_workflow_page_urls();
    foreach ($labels as $slug=>$label) {
        $url = $pageUrls[$slug] ?? '';
        if ($url !== '') {
            echo '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
    }
    echo '</ul></div></noscript></div>';
}
