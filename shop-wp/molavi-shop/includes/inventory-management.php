<?php
/** Owner-only stock count and hold workflow over audited server lots. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_stock_lot_actions_request(string $method, ?array $payload = null) {
    return molavi_pipeline_request('/pipeline/stock-lot-actions',$method,$payload);
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce','اصلاح و نگهداری موجودی','اصلاح موجودی',
        'manage_woocommerce','molavi-inventory-management','molavi_inventory_management_screen');
});

function molavi_inventory_management_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.','',['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('اصلاح و نگهداری موجودی') . '</h1>';
    echo '<p>' . esc_html('اصلاح شمارش، ثبت آسیب/انقضا و توقف/آزادسازی بچ‌ها با یادداشت مالک ثبت می‌شود. اصلاح موجودیِ رزروشده یا فروش‌رفته مجاز نیست؛ ثبت مرجوعی مشتری از مسیر جداگانه و پس از بررسی انجام می‌شود.') . '</p>';
    $stock=molavi_stock_lots_request('GET');
    if (is_wp_error($stock) || !is_array($stock['lots'] ?? null)) {
        echo '<div class="notice notice-error"><p>' . esc_html(is_wp_error($stock)
            ? $stock->get_error_message() : 'پاسخ موجودی معتبر نیست.') . '</p></div></div>';
        return;
    }
    foreach ($stock['lots'] as $lot) {
        if (!is_array($lot) || !is_string($lot['lot_id'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/',(string)($lot['revision'] ?? ''))) { continue; }
        $lotId=(string)$lot['lot_id'];$revision=(string)$lot['revision'];
        echo '<div class="card"><h2>' . esc_html((string)($lot['sku'] ?? '') . ' · ' . $lotId) . '</h2>';
        echo '<p>' . esc_html('وضعیت: ' . (string)($lot['state'] ?? '')
            . ' · موجودی فیزیکی: ' . (string)($lot['units'] ?? 0)
            . ' · رزرو/فروش ثبت‌شده: ' . (string)($lot['committed_units'] ?? 0)
            . ' · قابل فروش: ' . (string)($lot['available_units'] ?? 0)
            . ' · هزینه هر واحد: ' . (string)($lot['unit_cost'] ?? '') . ' تومان') . '</p>';
        echo '<p>' . esc_html('خرید: ' . (string)($lot['purchase_date'] ?? 'ثبت نشده')
            . ' · تولید: ' . (string)($lot['production_date'] ?? 'نامشخص')
            . ' · انقضا: ' . (string)($lot['expiry_date'] ?? 'نامشخص')) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('molavi_stock_lot_event:' . $lotId . ':adjust');
        echo '<input type="hidden" name="action" value="molavi_stock_lot_event">';
        echo '<input type="hidden" name="lot_id" value="' . esc_attr($lotId) . '">';
        echo '<input type="hidden" name="expected_revision" value="' . esc_attr($revision) . '">';
        echo '<input type="hidden" name="event_action" value="adjust">';
        echo '<p><label>' . esc_html('تغییر خالص شمارش (مثبت/منفی واحد)') . ' <input name="delta_units" type="number" min="-1000000" max="1000000" step="1" required></label> ';
        echo '<label>' . esc_html('دلیل') . ' <select name="reason" required>';
        foreach (['count_correction'=>'اصلاح شمارش','damage'=>'آسیب','expiry'=>'انقضا'] as $reason=>$label) {
            echo '<option value="' . esc_attr($reason) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label> <label>' . esc_html('یادداشت مالک') . ' <input name="note" maxlength="1000" required></label> ';
        echo '<button class="button">' . esc_html('ثبت اصلاح شمارش') . '</button></p></form>';
        if (($lot['state'] ?? '')!=='held') {
            molavi_stock_lot_state_form($lotId,$revision,'hold','توقف برای بررسی',[
                'quality'=>'کیفیت/سلامت','record_review'=>'بررسی اطلاعات','supplier_issue'=>'ایراد تأمین‌کننده']);
        } else {
            molavi_stock_lot_state_form($lotId,$revision,'release','آزادسازی پس از بررسی',[
                'inspection_passed'=>'بازرسی انجام شد']);
        }
        echo '</div>';
    }
    $events=molavi_stock_lot_actions_request('GET');
    echo '<h2>' . esc_html('تغییرات اخیر موجودی') . '</h2>';
    if (is_wp_error($events) || !is_array($events['events'] ?? null)) {
        echo '<div class="notice notice-error"><p>' . esc_html(is_wp_error($events)
            ? $events->get_error_message() : 'رویدادهای موجودی در دسترس نیست.') . '</p></div>';
    } elseif ($events['events']===[]) {
        echo '<p>' . esc_html('هنوز اصلاح یا توقفی ثبت نشده است.') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>بچ</th><th>عمل</th><th>تغییر واحد</th><th>دلیل</th><th>یادداشت</th></tr></thead><tbody>';
        foreach ($events['events'] as $event) {
            if (!is_array($event)) { continue; }
            echo '<tr><td>' . esc_html((string)($event['created_at'] ?? '')) . '</td><td>'
                . esc_html((string)($event['lot_id'] ?? '')) . '</td><td>'
                . esc_html((string)($event['action'] ?? '')) . '</td><td>'
                . esc_html((string)($event['delta_units'] ?? 0)) . '</td><td>'
                . esc_html((string)($event['reason'] ?? '')) . '</td><td>'
                . esc_html((string)($event['note'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

function molavi_stock_lot_state_form(string $lotId,string $revision,string $action,string $label,array $reasons): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('molavi_stock_lot_event:' . $lotId . ':' . $action);
    echo '<input type="hidden" name="action" value="molavi_stock_lot_event">';
    echo '<input type="hidden" name="lot_id" value="' . esc_attr($lotId) . '">';
    echo '<input type="hidden" name="expected_revision" value="' . esc_attr($revision) . '">';
    echo '<input type="hidden" name="event_action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="delta_units" value="0">';
    echo '<label>' . esc_html('دلیل') . ' <select name="reason" required>';
    foreach ($reasons as $reason=>$text) {
        echo '<option value="' . esc_attr((string)$reason) . '">' . esc_html((string)$text) . '</option>';
    }
    echo '</select></label> <label>' . esc_html('یادداشت مالک') . ' <input name="note" maxlength="1000" required></label> ';
    echo '<button class="button">' . esc_html($label) . '</button></form>';
}

add_action('admin_post_molavi_stock_lot_event', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.','',['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.','',['response'=>405]); }
    foreach (['lot_id','expected_revision','event_action','delta_units','reason','note'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.','',['response'=>400]); }
    }
    $lotId=trim(sanitize_text_field(wp_unslash($_POST['lot_id'])));
    $eventAction=sanitize_key(wp_unslash($_POST['event_action']));
    check_admin_referer('molavi_stock_lot_event:' . $lotId . ':' . $eventAction);
    $revision=wp_unslash($_POST['expected_revision']);$rawDelta=wp_unslash($_POST['delta_units']);
    $reason=sanitize_key(wp_unslash($_POST['reason']));
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    if ($lotId==='' || strlen($lotId)>120 || !preg_match('/^[a-f0-9]{64}$/',$revision)
        || !in_array($eventAction,['adjust','hold','release'],true)
        || !preg_match('/^-?\d+$/',$rawDelta) || (float)$rawDelta>1000000
        || (float)$rawDelta < -1000000 || $note==='' || mb_strlen($note)>1000) {
        wp_die('اطلاعات اصلاح موجودی نامعتبر است.','',['response'=>400]);
    }
    $saved=molavi_stock_lot_actions_request('POST',['lot_id'=>$lotId,'expected_revision'=>$revision,
        'action'=>$eventAction,'delta_units'=>(int)$rawDelta,'reason'=>$reason,'note'=>$note]);
    if (is_wp_error($saved)) { wp_die(esc_html($saved->get_error_message()),'',['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-inventory-management&saved=1'));
    exit;
});
