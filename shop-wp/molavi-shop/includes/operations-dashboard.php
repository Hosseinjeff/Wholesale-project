<?php
/** Unified owner landing page for held and failed pipeline work. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_operations_dashboard_request() {
    return molavi_pipeline_request('/pipeline/operations-dashboard', 'GET');
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'مرکز عملیات مولوی‌سل', 'مرکز عملیات مولوی‌سل',
        'manage_woocommerce', 'molavi-operations', 'molavi_operations_dashboard_screen');
});

function molavi_operations_dashboard_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('مرکز عملیات مولوی‌سل') . '</h1>';
    echo '<p>' . esc_html('کارهای متوقف‌شده و خطادار را از اینجا ببینید و برای بررسی یا ادامه به صفحه مربوط بروید. این صفحه فقط‌خواندنی است.') . '</p>';
    $result=molavi_operations_dashboard_request();
    if (is_wp_error($result) || !is_array($result['operations'] ?? null)) {
        echo '<div class="notice notice-error"><p>' . esc_html(is_wp_error($result)
            ? $result->get_error_message() : 'گزارش عملیات معتبر نیست.') . '</p></div></div>';
        return;
    }
    $ops=$result['operations'];
    $counts=is_array($ops['by_state'] ?? null) ? $ops['by_state'] : [];
    $cards=[
        ['title'=>'کارهای صف پردازش','count'=>$ops['actionable_count'] ?? 0,'url'=>'molavi-publication-holds'],
        ['title'=>'موارد نیازمند بررسی','count'=>$ops['attention_count'] ?? 0,'url'=>'molavi-publication-holds'],
        ['title'=>'توقف‌های انتشار','count'=>$ops['publication_holds'] ?? 0,'url'=>'molavi-publication-holds'],
        ['title'=>'تعارض‌های باز شیت','count'=>$ops['sheet_conflicts'] ?? 0,'url'=>'molavi-sheet-conflicts'],
    ];
    echo '<div class="card"><div style="display:flex;flex-wrap:wrap;gap:1rem">';
    foreach ($cards as $card) {
        echo '<a class="card" style="min-width:11rem" href="' . esc_url(admin_url('admin.php?page=' . $card['url'])) . '">'
            . '<strong>' . esc_html((string)$card['title']) . '</strong><br><span>'
            . esc_html((string)$card['count']) . '</span></a>';
    }
    echo '</div><p>' . esc_html('وضعیت‌ها: صف: ' . (string)($counts['queued'] ?? 0)
        . ' · در حال اجرا: ' . (string)($counts['running'] ?? 0)
        . ' · انتظار تصمیم مرجع: ' . (string)($counts['waiting_reference'] ?? 0)
        . ' · خطا: ' . (string)($counts['failed'] ?? 0)
        . ' · نیازمند بررسی: ' . (string)($counts['needs_review'] ?? 0)) . '</p></div>';

    $items=is_array($ops['items'] ?? null) ? $ops['items'] : [];
    echo '<h2>' . esc_html('رسیدهای فعال پردازش') . '</h2>';
    if ($items===[]) {
        echo '<p>' . esc_html('رسید فعال یا متوقف‌شده‌ای در صف وجود ندارد.') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>نوع</th><th>وضعیت</th><th>تلاش</th><th>جزئیات خطا</th></tr></thead><tbody>';
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $label=(string)($item['state'] ?? 'unknown');
            if (!empty($item['lease_expired'])) { $label .= ' · lease expired'; }
            echo '<tr><td>' . esc_html((string)($item['id'] ?? '')) . '</td><td>'
                . esc_html((string)($item['kind'] ?? '')) . '</td><td>' . esc_html($label)
                . '</td><td>' . esc_html((string)($item['attempts'] ?? '')) . '</td><td>'
                . esc_html((string)($item['error'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<h2>' . esc_html('صفحه‌های کار روزانه') . '</h2><ul>';
    $links=[
        'بررسی توقف انتشار'=>'molavi-publication-holds',
        'تعارض ویرایش‌های شیت'=>'molavi-sheet-conflicts',
        'تازگی قیمت تأمین‌کنندگان'=>'molavi-supplier-prices',
        'تصمیم‌های قیمت مرجع'=>'molavi-reference-decisions',
        'تطبیق کالاهای تأمین‌کننده'=>'molavi-reference-items',
        'نسخه‌های PDF و قیمت مرجع'=>'molavi-reference-snapshots',
        'تأیید استخراج هوشمند'=>'molavi-ai-confirmations',
        'پیشنهاد قیمت حراج موجودی'=>'molavi-clearance-pricing',
        'اصلاح و نگهداری موجودی'=>'molavi-inventory-management',
        'سفارش و موجودی'=>'molavi-paid-orders',
    ];
    foreach ($links as $label=>$slug) {
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($label) . '</a></li>';
    }
    echo '</ul></div>';
}
