<?php
/** Owner review and activation of scheduled PDF/Gemini reference candidates. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_reference_snapshots_request(string $method, ?array $payload = null) {
    if (!molavi_reference_owner()) { return new WP_Error('owner', 'دسترسی مجاز نیست.'); }
    $base=defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token=defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($base) || !is_string($token) || strlen($token)<32
        || wp_parse_url($base,PHP_URL_SCHEME)!=='https') {
        return new WP_Error('config','اتصال تازه‌سازی فهرست مرجع تنظیم نشده است.');
    }
    $timeout=$method==='POST' && ($payload['action'] ?? '')==='refresh' ? 150 : 30;
    $args=['method'=>$method,'timeout'=>$timeout,'redirection'=>0,
        'headers'=>['X-Molavi-Reference-Token'=>$token,
            'X-Molavi-Actor'=>'wp:' . get_current_user_id(),'Content-Type'=>'application/json']];
    if ($payload!==null) { $args['body']=wp_json_encode($payload); }
    $response=wp_safe_remote_request(rtrim($base,'/') . '/reference/snapshots',$args);
    if (is_wp_error($response)) {
        $diagnostic=sanitize_text_field($response->get_error_message());
        error_log('[Molavi reference snapshots] ' . $response->get_error_code() . ': ' . substr($diagnostic,0,300));
        return new WP_Error('offline','ارتباط با سرور برقرار نشد.');
    }
    $body=json_decode(wp_remote_retrieve_body($response),true);
    if (wp_remote_retrieve_response_code($response)!==200 || !is_array($body) || ($body['ok'] ?? false)!==true) {
        return new WP_Error('failed','عملیات فهرست مرجع تأیید نشد؛ صفحه را تازه کنید.');
    }
    if ($method==='GET' && (!is_array($body['snapshots'] ?? null)
        || !array_key_exists('active_snapshot_id',$body)
        || !(is_null($body['refresh_state'] ?? null) || is_array($body['refresh_state'])))) {
        return new WP_Error('receipt','وضعیت فهرست مرجع معتبر نیست.');
    }
    if ($method==='POST' && ($payload['action'] ?? '')==='refresh') {
        if (($body['action'] ?? null)!=='refresh'
            || !in_array($body['state'] ?? '',['staged','failed'],true)
            || !is_string($body['last_attempt_at'] ?? null)) {
            return new WP_Error('receipt','رسید تلاش تازه‌سازی معتبر نیست.');
        }
    } elseif ($method==='POST' && ($payload===null
        || ($body['action'] ?? null)!==$payload['action']
        || ($body['snapshot_id'] ?? null)!==$payload['snapshot_id'])) {
        return new WP_Error('receipt','رسید سرور با نسخه انتخاب‌شده مطابقت ندارد.');
    }
    if ($method==='POST' && $payload['action']==='activate'
        && (($body['active_snapshot_id'] ?? null)!==$payload['snapshot_id']
            || ($body['projection_state'] ?? null)!=='written'
            || (int)($body['row_count'] ?? 0)<1)) {
        return new WP_Error('receipt','نسخه فعال شد اما رسید نوشتن ReferencePrices تأیید نشد.');
    }
    return $body;
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce','نسخه‌های قیمت مرجع','نسخه‌های قیمت مرجع',
        'manage_woocommerce','molavi-reference-snapshots','molavi_reference_snapshots_screen');
});

function molavi_reference_snapshots_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.','',['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('تازه‌سازی و نسخه‌های قیمت مرجع') . '</h1>';
    echo '<p>' . esc_html('کارگر سرور به‌صورت روزانه PDF رزوان را با Gemini استخراج می‌کند. نسخه تازه ابتدا برای بررسی مالک ذخیره می‌شود؛ فقط پس از بررسی و فعال‌سازی به ReferencePrices نوشته می‌شود.') . '</p>';
    if (($_GET['saved'] ?? '')==='1') { echo '<div class="notice notice-success"><p>' . esc_html('رسید بررسی یا همگام‌سازی ثبت شد.') . '</p></div>'; }
    $result=molavi_reference_snapshots_request('GET');
    if (is_wp_error($result)) { echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div></div>'; return; }
    $refresh=$result['refresh_state'];
    if (is_array($refresh)) {
        echo '<p>' . esc_html('وضعیت آخرین تلاش: ' . (string)($refresh['state'] ?? 'نامشخص') . ' · '
            . (string)($refresh['last_attempt_at'] ?? 'هنوز اجرا نشده')
            . (!empty($refresh['error_code']) ? ' · کد خطا: ' . (string)$refresh['error_code'] : '')) . '</p>';
        if (($refresh['state'] ?? '')==='failed') {
            molavi_reference_snapshot_refresh_form((string)($refresh['last_attempt_at'] ?? ''));
        }
    }
    $snapshots=$result['snapshots'];
    if ($snapshots===[]) { echo '<p>' . esc_html('نسخه استخراج‌شده‌ای در انتظار بررسی نیست.') . '</p>'; }
    foreach ($snapshots as $snapshot) {
        if (!is_array($snapshot) || !is_string($snapshot['snapshot_id'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/',$snapshot['snapshot_id'])) { continue; }
        $id=$snapshot['snapshot_id'];
        echo '<div class="card"><h2>' . esc_html('نسخه ' . substr($id,0,12)) . '</h2>';
        echo '<p>' . esc_html('استخراج: ' . (string)($snapshot['extracted_at'] ?? '') . ' · '
            . 'تعداد ردیف: ' . (string)($snapshot['row_count'] ?? '') . ' · '
            . 'مدل: ' . (string)($snapshot['model'] ?? '')) . '</p>';
        echo '<p>' . esc_html('هش PDF: ' . substr((string)($snapshot['pdf_sha256'] ?? ''),0,16)
            . ' · وضعیت: ' . (!empty($snapshot['active']) ? 'فعال' : (!empty($snapshot['reviewed_by']) ? 'بررسی‌شده' : 'در انتظار بررسی'))) . '</p>';
        $preview=is_array($snapshot['preview'] ?? null) ? array_slice($snapshot['preview'],0,8) : [];
        if ($preview!==[]) {
            echo '<h3>' . esc_html('نمونه ردیف‌های استخراج‌شده') . '</h3><ul>';
            foreach ($preview as $item) {
                if (!is_array($item)) { continue; }
                $line=(string)($item['product_name'] ?? '') . ' · '
                    . 'قیمت بسته: ' . (string)($item['package_price'] ?? '') . ' · '
                    . 'قیمت واحد: ' . (string)($item['unit_price'] ?? '') . ' · '
                    . 'تعداد بسته: ' . (string)($item['package_count'] ?? '') . ' '
                    . (string)($item['unit_label'] ?? '') . ' · '
                    . 'کد: ' . (string)($item['product_code'] ?? '') . ' · '
                    . 'ردیف PDF: ' . (string)($item['pdf_row_number'] ?? '');
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }
        if (!empty($snapshot['reviewed_by'])) {
            echo '<p>' . esc_html('بررسی مالک: ' . (string)$snapshot['reviewed_by'] . ' · '
                . (string)($snapshot['review_note'] ?? '')) . '</p>';
        }
        if (empty($snapshot['reviewed_by'])) {
            molavi_reference_snapshot_form($id,'review',$result['active_snapshot_id']);
        } else {
            molavi_reference_snapshot_form($id,'activate',$result['active_snapshot_id']);
        }
        echo '</div>';
    }
    echo '</div>';
}

function molavi_reference_snapshot_refresh_form(string $lastAttempt): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('molavi_reference_snapshot:refresh:' . $lastAttempt);
    echo '<input type="hidden" name="action" value="molavi_reference_snapshot_choice">';
    echo '<input type="hidden" name="choice" value="refresh">';
    echo '<input type="hidden" name="expected_last_attempt_at" value="' . esc_attr($lastAttempt) . '">';
    echo '<button class="button" type="submit">' . esc_html('تلاش دوباره برای دریافت و بررسی PDF') . '</button>';
    echo '</form>';
}

function molavi_reference_snapshot_form(string $snapshotId,string $choice,?string $activeId): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('molavi_reference_snapshot:' . $snapshotId . ':' . $choice);
    echo '<input type="hidden" name="action" value="molavi_reference_snapshot_choice">';
    echo '<input type="hidden" name="choice" value="' . esc_attr($choice) . '">';
    echo '<input type="hidden" name="snapshot_id" value="' . esc_attr($snapshotId) . '">';
    echo '<input type="hidden" name="expected_active_id" value="' . esc_attr($activeId ?? '') . '">';
    if ($choice==='review') {
        echo '<p><label>' . esc_html('یادداشت بررسی پوشش و قیمت‌ها') . ' <textarea name="note" required maxlength="1000"></textarea></label></p>';
        echo '<button class="button" name="submit_choice" value="review">' . esc_html('ثبت بررسی مالک') . '</button>';
    } else {
        echo '<button class="button button-primary" name="submit_choice" value="activate">'
            . esc_html($snapshotId===$activeId ? 'تلاش دوباره برای همگام‌سازی ReferencePrices' : 'فعال‌سازی و همگام‌سازی ReferencePrices') . '</button>';
    }
    echo '</form>';
}

add_action('admin_post_molavi_reference_snapshot_choice', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.','',['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.','',['response'=>405]); }
    $choice=isset($_POST['choice']) && is_string($_POST['choice']) ? wp_unslash($_POST['choice']) : '';
    if ($choice==='refresh') {
        if (!isset($_POST['expected_last_attempt_at']) || !is_string($_POST['expected_last_attempt_at'])) {
            wp_die('وضعیت تلاش نامعتبر است.','',['response'=>400]);
        }
        $expectedLast=wp_unslash($_POST['expected_last_attempt_at']);
        if (strlen($expectedLast)>80) { wp_die('وضعیت تلاش نامعتبر است.','',['response'=>400]); }
        check_admin_referer('molavi_reference_snapshot:refresh:' . $expectedLast);
        $result=molavi_reference_snapshots_request('POST',[
            'action'=>'refresh','expected_last_attempt_at'=>$expectedLast===''?null:$expectedLast]);
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()),'',['response'=>503]); }
        wp_safe_redirect(admin_url('admin.php?page=molavi-reference-snapshots&saved=1'));
        exit;
    }
    foreach (['choice','snapshot_id','expected_active_id'] as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) { wp_die('درخواست نامعتبر است.','',['response'=>400]); }
    }
    $snapshotId=wp_unslash($_POST['snapshot_id']);
    if (!in_array($choice,['review','activate'],true) || !preg_match('/^[a-f0-9]{64}$/',$snapshotId)) {
        wp_die('انتخاب نسخه نامعتبر است.','',['response'=>400]);
    }
    check_admin_referer('molavi_reference_snapshot:' . $snapshotId . ':' . $choice);
    $expected=wp_unslash($_POST['expected_active_id']);
    if ($expected!=='' && !preg_match('/^[a-f0-9]{64}$/',$expected)) { wp_die('نسخه فعال نامعتبر است.','',['response'=>400]); }
    $payload=['action'=>$choice,'snapshot_id'=>$snapshotId,'expected_active_id'=>$expected===''?null:$expected];
    if ($choice==='review') {
        if (!isset($_POST['note']) || !is_string($_POST['note'])) { wp_die('یادداشت بررسی لازم است.','',['response'=>400]); }
        $payload['note']=wp_unslash($_POST['note']);
    }
    $result=molavi_reference_snapshots_request('POST',$payload);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()),'',['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-reference-snapshots&saved=1'));
    exit;
});
