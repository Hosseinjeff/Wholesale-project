<?php
/** Owner-reviewed batch clearance calculator. It never writes Woo prices. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_clearance_proposals_request(string $method, ?array $payload = null) {
    return molavi_pipeline_request('/pipeline/clearance-proposals', $method, $payload);
}

function molavi_stock_purchase_date_request(array $payload) {
    return molavi_pipeline_request('/pipeline/stock-lots/purchase-date', 'POST', $payload);
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'پیشنهاد قیمت حراج موجودی', 'قیمت حراج موجودی',
        'manage_woocommerce', 'molavi-clearance-pricing', 'molavi_clearance_pricing_screen');
});

function molavi_clearance_pricing_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('پیشنهاد قیمت حراج موجودی') . '</h1>';
    echo '<p>' . esc_html('این محاسبه فقط پیشنهاد ثبت‌شده برای بررسی مالک است و قیمت محصول را تغییر نمی‌دهد. قیمت عادی باید برای همان سبد و قیمت بازار باید برای کالای هم‌واحد و امروز باشد.') . '</p>';
    $result=molavi_clearance_proposals_request('GET');
    if (is_wp_error($result) || !is_array($result['lots'] ?? null) || !is_array($result['proposals'] ?? null)) {
        echo '<div class="notice notice-error"><p>' . esc_html(is_wp_error($result)
            ? $result->get_error_message() : 'پاسخ قیمت‌گذاری معتبر نیست.') . '</p></div></div>';
        return;
    }
    $today=(string)($result['today'] ?? current_time('Y-m-d'));
    $threshold=$result['near_cost_alert_threshold_toman'] ?? null;
    if ($threshold===null || $threshold==='') {
        echo '<div class="notice notice-warning"><p>' . esc_html('آستانه هشدار نزدیک‌شدن به بهای خرید هنوز تنظیم نشده است؛ سامانه این مقدار را حدس نمی‌زند. پیشنهادها تا تعیین آستانه هشدار خودکار نزدیک‌به‌بهای خرید ندارند.') . '</p></div>';
    } else {
        echo '<p>' . esc_html('آستانه هشدار نزدیک بهای خرید: ' . (string)$threshold . ' تومان') . '</p>';
    }
    echo '<h2>' . esc_html('بچ‌های موجود') . '</h2>';
    $eligible=0;
    foreach ($result['lots'] as $lot) {
        if (!is_array($lot)) { continue; }
        $available=(int)($lot['available_units'] ?? 0);
        $canPropose=($lot['state'] ?? '')==='ready' && $available>0
            && is_string($lot['receipt_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/',(string)$lot['receipt_hash']);
        echo '<div class="card"><h3>' . esc_html((string)($lot['sku'] ?? '') . ' · ' . (string)($lot['lot_id'] ?? '')) . '</h3>';
        echo '<p>' . esc_html('تأمین‌کننده: ' . (string)($lot['supplier'] ?? '')
            . ' · قابل فروش: ' . (string)$available
            . ' · تاریخ خرید: ' . (string)($lot['purchase_date'] ?? 'ثبت نشده')
            . ' · تولید: ' . (string)($lot['production_date'] ?? 'نامشخص')
            . ' · انقضا: ' . (string)($lot['expiry_date'] ?? 'نامشخص')
            . ' · بهای هر واحد: ' . (string)($lot['unit_cost'] ?? '') . ' تومان') . '</p>';
        if (empty($lot['purchase_date']) && is_string($lot['receipt_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/',(string)$lot['receipt_hash'])) {
            echo '<p>' . esc_html('تاریخ خرید این رسید ثبت نشده است. تاریخ واقعی را با تأیید رسید موجودی تکمیل کنید.') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('molavi_correct_lot_purchase_date:' . (string)$lot['lot_id']);
            echo '<input type="hidden" name="action" value="molavi_correct_lot_purchase_date">';
            echo '<input type="hidden" name="lot_id" value="' . esc_attr((string)$lot['lot_id']) . '">';
            echo '<input type="hidden" name="expected_receipt_hash" value="' . esc_attr((string)$lot['receipt_hash']) . '">';
            echo '<p><label>' . esc_html('تاریخ واقعی خرید') . ' <input name="purchase_date" type="date" required></label> ';
            echo '<label>' . esc_html('دلیل تکمیل رسید') . ' <input name="note" maxlength="1000" required></label></p>';
            echo '<button class="button">' . esc_html('ثبت تاریخ خرید') . '</button></form></div>';
            continue;
        }
        if (!$canPropose) {
            echo '<p>' . esc_html('برای این بچ پیشنهاد محاسبه نمی‌شود؛ موجودی باید قابل فروش باشد و رسید معتبر داشته باشد.') . '</p></div>';
            continue;
        }
        $eligible++;
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('molavi_clearance_proposal:' . (string)$lot['lot_id']);
        echo '<input type="hidden" name="action" value="molavi_clearance_proposal">';
        echo '<input type="hidden" name="lot_id" value="' . esc_attr((string)$lot['lot_id']) . '">';
        echo '<input type="hidden" name="receipt_hash" value="' . esc_attr((string)$lot['receipt_hash']) . '">';
        echo '<p><label>' . esc_html('قیمت عادی همین واحد و همین سبد (تومان)') . ' <input name="normal_price_toman" type="number" min="1" max="1000000000000" required></label> ';
        echo '<label>' . esc_html('قیمت بازار امروز برای کالای هم‌واحد (تومان)') . ' <input name="market_price_toman" type="number" min="1" max="1000000000000" required></label></p>';
        echo '<p><label>' . esc_html('منبع قیمت بازار') . ' <input name="market_source" maxlength="200" required></label> ';
        echo '<label>' . esc_html('تاریخ مشاهده') . ' <input name="market_observed_date" type="date" required value="' . esc_attr($today) . '"></label></p>';
        echo '<p><label>' . esc_html('یادداشت و مبنای تطبیق') . ' <textarea name="note" required maxlength="1000"></textarea></label></p>';
        echo '<button class="button button-primary">' . esc_html('محاسبه و ثبت پیشنهاد') . '</button></form></div>';
    }
    if ($eligible===0) { echo '<p>' . esc_html('بچ آماده و دارای موجودی قابل فروش برای محاسبه وجود ندارد.') . '</p>'; }

    echo '<h2>' . esc_html('محاسبه‌های اخیر') . '</h2>';
    if ($result['proposals']===[]) { echo '<p>' . esc_html('هنوز پیشنهادی ثبت نشده است.') . '</p>'; }
    else {
        echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>بچ</th><th>وضعیت</th><th>قیمت پیشنهادی (تومان)</th><th>هشدار بهای خرید</th><th>یادداشت</th></tr></thead><tbody>';
        foreach ($result['proposals'] as $proposal) {
            if (!is_array($proposal) || !is_array($proposal['result'] ?? null)) { continue; }
            $calculation=$proposal['result'];
            $alert=!empty($calculation['near_cost_alert']) ? 'هشدار نزدیک بهای خرید'
                : (($calculation['state'] ?? '')==='alert_threshold_required' ? 'آستانه هشدار تنظیم نشده' : '—');
            echo '<tr><td>' . esc_html((string)($proposal['created_at'] ?? '')) . '</td><td>'
                . esc_html((string)($proposal['lot_id'] ?? '') . ' · ' . (string)($proposal['sku'] ?? '')) . '</td><td>'
                . esc_html((string)($calculation['state'] ?? 'needs_review')) . '</td><td>'
                . esc_html(isset($calculation['suggested_price_toman']) ? (string)$calculation['suggested_price_toman'] : '—') . '</td><td>'
                . esc_html($alert) . '</td><td>' . esc_html((string)($proposal['note'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

add_action('admin_post_molavi_clearance_proposal', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    $keys=['lot_id','receipt_hash','normal_price_toman','market_price_toman','market_source','market_observed_date','note'];
    foreach ($keys as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $lotId=trim(sanitize_text_field(wp_unslash($_POST['lot_id'])));
    check_admin_referer('molavi_clearance_proposal:' . $lotId);
    $hash=wp_unslash($_POST['receipt_hash']);
    $source=trim(sanitize_text_field(wp_unslash($_POST['market_source'])));
    $observed=wp_unslash($_POST['market_observed_date']);
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    $prices=[];
    foreach (['normal_price_toman','market_price_toman'] as $field) {
        $raw=wp_unslash($_POST[$field]);
        if (!ctype_digit($raw) || (int)$raw<1 || (float)$raw>1000000000000) { wp_die('قیمت نامعتبر است.', '', ['response'=>400]); }
        $prices[$field]=(int)$raw;
    }
    if ($lotId==='' || strlen($lotId)>120 || !preg_match('/^[a-f0-9]{64}$/',$hash)
        || $source==='' || mb_strlen($source)>200 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$observed)
        || $note==='' || mb_strlen($note)>1000) { wp_die('اطلاعات پیشنهاد نامعتبر است.', '', ['response'=>400]); }
    $payload=['lot_id'=>$lotId,'receipt_hash'=>$hash] + $prices + [
        'market_source'=>$source,'market_observed_date'=>$observed,'note'=>$note];
    $saved=molavi_clearance_proposals_request('POST',$payload);
    if (is_wp_error($saved)) { wp_die(esc_html($saved->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-clearance-pricing&proposal_saved=1'));
    exit;
});

add_action('admin_post_molavi_correct_lot_purchase_date', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    foreach (['lot_id','expected_receipt_hash','purchase_date','note'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $lotId=trim(sanitize_text_field(wp_unslash($_POST['lot_id'])));
    check_admin_referer('molavi_correct_lot_purchase_date:' . $lotId);
    $hash=wp_unslash($_POST['expected_receipt_hash']);
    $purchase=wp_unslash($_POST['purchase_date']);
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    if ($lotId==='' || strlen($lotId)>120 || !preg_match('/^[a-f0-9]{64}$/',$hash)
        || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$purchase,$parts)
        || !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])
        || $note==='' || mb_strlen($note)>1000) { wp_die('اطلاعات رسید نامعتبر است.', '', ['response'=>400]); }
    $saved=molavi_stock_purchase_date_request(['lot_id'=>$lotId,'expected_receipt_hash'=>$hash,
        'purchase_date'=>$purchase,'note'=>$note]);
    if (is_wp_error($saved)) { wp_die(esc_html($saved->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-clearance-pricing&purchase_date_saved=1'));
    exit;
});
