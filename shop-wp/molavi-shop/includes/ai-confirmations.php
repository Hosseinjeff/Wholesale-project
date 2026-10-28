<?php
/** Private owner review for uncertain supplier extraction. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_ai_confirmation_request(string $method, ?array $payload = null) {
    if (!molavi_reference_owner()) { return new WP_Error('owner', 'دسترسی مجاز نیست.'); }
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($base) || !is_string($token) || strlen($token) < 32
        || wp_parse_url($base, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('config', 'اتصال بررسی هوشمند کالا تنظیم نشده است.');
    }
    $args = ['method'=>$method, 'timeout'=>20, 'redirection'=>0,
        'headers'=>['X-Molavi-Reference-Token'=>$token,
            'X-Molavi-Actor'=>'wp:' . get_current_user_id(), 'Content-Type'=>'application/json']];
    if ($payload !== null) { $args['body']=wp_json_encode($payload); }
    $response=wp_safe_remote_request(rtrim($base,'/') . '/pipeline/ai-confirmations', $args);
    if (is_wp_error($response)) { return new WP_Error('offline', 'ارتباط با سرور برقرار نشد.'); }
    $body=json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response)!==200 || !is_array($body) || ($body['ok'] ?? false)!==true) {
        return new WP_Error('failed', 'بررسی ثبت نشد. صفحه را تازه کنید و دوباره بررسی کنید.');
    }
    if ($method==='GET' && (!is_array($body['cases'] ?? null) || !is_array($body['recent_decisions'] ?? null)
        || !is_bool($body['publication_held'] ?? null))) {
        return new WP_Error('receipt', 'فهرست بررسی معتبر نیست.');
    }
    if ($method==='POST' && ($payload===null
        || ($body['case_id'] ?? null)!==$payload['case_id']
        || ($body['offer_id'] ?? null)!==$payload['offer_id']
        || ($body['signature'] ?? null)!==$payload['signature']
        || ($body['choice'] ?? null)!==$payload['choice']
        || !preg_match('/^[a-f0-9]{64}$/', (string)($body['decision_id'] ?? ''))
        || !in_array($body['projection_state'] ?? '', ['imported','needs_review','not_product'], true))) {
        return new WP_Error('receipt', 'تأیید سرور با بررسی ارسالی مطابقت ندارد.');
    }
    return $body;
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'تأیید استخراج کالا', 'تأیید استخراج کالا',
        'manage_woocommerce', 'molavi-ai-confirmations', 'molavi_ai_confirmations_screen');
});

function molavi_ai_confirmation_form(array $case): void {
    $caseId=(string)($case['case_id'] ?? '');
    if (!preg_match('/^ai:[a-f0-9]{64}$/', $caseId)
        || !is_string($case['offer_id'] ?? null) || !is_string($case['signature'] ?? null)) { return; }
    $decision=is_array($case['decision'] ?? null) ? $case['decision'] : null;
    $decisionFields=is_array($decision['fields'] ?? null) ? $decision['fields'] : [];
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('molavi_ai_confirmation:' . $caseId);
    echo '<input type="hidden" name="action" value="molavi_ai_confirmation_choice">';
    foreach (['case_id'=>$caseId,'offer_id'=>(string)$case['offer_id'],
              'signature'=>(string)$case['signature'],
              'expected_decision_id'=>(string)($case['previous_decision_id'] ?? '')] as $key=>$value) {
        echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
    }
    $role=strtolower((string)($decisionFields['price_role'] ?? $case['price_type'] ?? ''));
    $sourcePrice=$decisionFields['sale_price'] ?? (
        in_array($role,['pack','package','carton'],true) && !empty($case['package_price'])
            ? $case['package_price'] : ($case['sale_price'] ?? $case['detected_price'] ?? ''));
    if ($sourcePrice==='' && !empty($case['detected_price'])) { $sourcePrice=$case['detected_price']; }
    if (!array_key_exists('sale_price',$decisionFields) && in_array($role,['pack','package','carton'],true)
        && !empty($case['package_price'])) { $sourcePrice=$case['package_price']; }
    echo '<p><label>' . esc_html('نقش قیمت را صریح انتخاب کنید') . ' <select name="price_role" required>';
    echo '<option value="">' . esc_html('انتخاب نقش قیمت') . '</option>';
    echo '<option value="unit"' . ($role==='unit'?' selected':'') . '>' . esc_html('قیمت هر واحد') . '</option>';
    echo '<option value="package"' . (in_array($role,['pack','package','carton'],true)?' selected':'') . '>' . esc_html('قیمت کل بسته / کارتن') . '</option>';
    echo '</select></label> ' . '<label>' . esc_html('قیمت تأییدشده به تومان')
        . ' <input name="sale_price" inputmode="numeric" type="number" min="1" step="1" required value="'
        . esc_attr((string)$sourcePrice) . '"></label></p>';
    echo '<p><label>' . esc_html('قیمت مصرف‌کننده اختیاری') . ' <input name="actual_price" inputmode="numeric" type="number" min="1" step="1" value="'
        . esc_attr((string)($decisionFields['actual_price'] ?? $case['actual_price'] ?? '')) . '"></label> ';
    echo '<label>' . esc_html('تعداد داخل بسته') . ' <input name="package_count" inputmode="numeric" type="number" min="1" step="1" value="'
        . esc_attr((string)($decisionFields['package_count'] ?? $case['package_count'] ?? '')) . '"></label> ';
    echo '<label>' . esc_html('حداقل خرید') . ' <input name="min_qty" inputmode="numeric" type="number" min="1" step="1" value="'
        . esc_attr((string)($decisionFields['min_qty'] ?? $case['min_qty'] ?? '')) . '"></label></p>';
    $stock=(string)($decisionFields['stock_status'] ?? $case['stock_status'] ?? 'Unknown');
    echo '<p><label>' . esc_html('وضعیت موجودی تأمین‌کننده') . ' <select name="stock_status">';
    foreach (['Available'=>'موجود','Out of Stock'=>'ناموجود','Unknown'=>'نامشخص'] as $value=>$label) {
        echo '<option value="' . esc_attr($value) . '"' . ($stock===$value?' selected':'') . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>' . esc_html('یادداشت مالک') . ' <textarea name="note" required maxlength="1000">'
        . esc_textarea((string)($decision['note'] ?? '')) . '</textarea></label></p>';
    echo '<button class="button button-primary" name="choice" value="confirm">'
        . esc_html($decision ? 'ثبت اصلاح و تأیید دوباره' : 'تأیید مشخصات استخراج‌شده') . '</button> ';
    echo '<button class="button" name="choice" value="not_product" formnovalidate>' . esc_html('این مورد کالا نیست') . '</button>';
    echo '</form>';
}

function molavi_ai_confirmations_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('تأیید استخراج هوشمند کالا') . '</h1>';
    echo '<p>' . esc_html('کالاهای نامطمئن تا تصمیم مالک از فروشگاه پنهان می‌مانند. تصمیم تأیید، پیشنهاد مشتق‌شده‌ای به سابقه Offers اضافه می‌کند و پیام تأمین‌کننده را تغییر نمی‌دهد.') . '</p>';
    if (($_GET['saved'] ?? '')==='1') { echo '<div class="notice notice-success"><p>' . esc_html('تصمیم ثبت شد؛ وضعیت انتشار در سرور صف شد.') . '</p></div>'; }
    $result=molavi_ai_confirmation_request('GET');
    if (is_wp_error($result)) { echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div></div>'; return; }
    $cases=$result['cases'];
    if (!empty($result['publication_held'])) {
        echo '<div class="notice notice-warning"><p>' . esc_html('تا زمان بررسی توقف ایمنی خط پردازش، تصمیم‌های استخراج ثبت نمی‌شوند.') . '</p></div>';
    }
    $caseRows=[];
    foreach (array_merge($cases,$result['recent_decisions']) as $case) {
        if (is_array($case) && !empty($case['case_id'])) { $caseRows[(string)$case['case_id']]=$case; }
    }
    if ($cases===[]) { echo '<p>' . esc_html('مورد استخراج در انتظار تصمیم وجود ندارد.') . '</p>'; }
    foreach ($caseRows as $case) {
        if (!is_array($case) || !is_string($case['case_id'] ?? null)) { continue; }
        echo '<div class="card"><h2>' . esc_html((string)($case['product_name'] ?? 'کالای بدون نام')) . '</h2>';
        echo '<p>' . esc_html('تأمین‌کننده: ' . (string)($case['channel'] ?? '') . ' · قیمت استخراج‌شده: '
            . (string)($case['detected_price'] ?? $case['sale_price'] ?? '') . ' · نقش: ' . (string)($case['price_type'] ?? 'نامشخص')) . '</p>';
        echo '<p>' . esc_html('دلیل: ' . (string)($case['reason'] ?? '') . ' · حداقل خرید: ' . (string)($case['min_qty'] ?? '')) . '</p>';
        if (!empty($case['original_message'])) {
            echo '<details><summary>' . esc_html('نمایش پیام اصلی تأمین‌کننده') . '</summary><pre>'
                . esc_html((string)$case['original_message']) . '</pre></details>';
        }
        $decision=$case['decision'] ?? null;
        if (is_array($decision)) {
            echo '<p>' . esc_html('تصمیم قبلی: ' . (string)($decision['choice'] ?? '') . ' · '
                . (string)($decision['note'] ?? '')) . '</p>';
        }
        molavi_ai_confirmation_form($case);
        echo '</div>';
    }
    echo '</div>';
}

add_action('admin_post_molavi_ai_confirmation_choice', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    $textFields=['case_id','offer_id','signature','expected_decision_id','choice','price_role','sale_price',
        'actual_price','package_count','min_qty','stock_status','note'];
    foreach ($textFields as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $caseId=wp_unslash($_POST['case_id']);
    if (!preg_match('/^ai:[a-f0-9]{64}$/',$caseId)) { wp_die('شناسه نامعتبر است.', '', ['response'=>400]); }
    check_admin_referer('molavi_ai_confirmation:' . $caseId);
    $choice=wp_unslash($_POST['choice']);
    if (!in_array($choice,['confirm','not_product'],true)) { wp_die('انتخاب نامعتبر است.', '', ['response'=>400]); }
    $payload=['case_id'=>$caseId,'offer_id'=>wp_unslash($_POST['offer_id']),
        'signature'=>wp_unslash($_POST['signature']),'choice'=>$choice,
        'fields'=>[],'note'=>wp_unslash($_POST['note']),
        'expected_decision_id'=>wp_unslash($_POST['expected_decision_id']) ?: null];
    if ($choice==='confirm') {
        foreach (['sale_price','actual_price','package_count','min_qty'] as $field) {
            $value=trim(wp_unslash($_POST[$field]));
            if ($value!=='' && !ctype_digit($value)) { wp_die('قیمت یا تعداد نامعتبر است.', '', ['response'=>400]); }
            $payload['fields'][$field]=$value==='' ? null : (int)$value;
        }
        $payload['fields']['price_role']=wp_unslash($_POST['price_role']);
        $payload['fields']['stock_status']=wp_unslash($_POST['stock_status']);
        if ($payload['fields']['actual_price']===null) { $payload['fields']['actual_price']=''; }
        if ($payload['fields']['package_count']===null) { $payload['fields']['package_count']=''; }
        if ($payload['fields']['min_qty']===null) { $payload['fields']['min_qty']=''; }
    }
    $result=molavi_ai_confirmation_request('POST',$payload);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-ai-confirmations&saved=1'));
    exit;
});
