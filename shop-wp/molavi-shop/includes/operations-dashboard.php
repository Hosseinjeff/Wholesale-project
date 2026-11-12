<?php
/** Unified owner entry point for the MolaviSale product and operations pipeline. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_operations_dashboard_request() {
    return molavi_pipeline_request('/pipeline/operations-dashboard', 'GET');
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'میز کار مولوی‌سل', 'میز کار مولوی‌سل',
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
    echo '<div class="wrap"><h1>' . esc_html('میز کار مولوی‌سل') . '</h1>';
    echo '<p>' . esc_html('از رسید پیام در Railway تا استخراج، Offer، رکورد Products، مدرک قیمت و وضعیت واقعی Woo را مرحله‌به‌مرحله دنبال کنید. هر تصمیم اثر و رسید خودش را دارد؛ وضعیت نامعلوم موفق تلقی نمی‌شود.') . '</p>';
    molavi_owner_workflow_mount('در حال ساخت مسیر رسیدگی و بارگذاری صف‌ها…');
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
