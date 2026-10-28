<?php
/** Per-offer reference correction surface in the existing private Woo dashboard. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_reference_item_review_request(string $method, ?array $payload = null) {
    if (!molavi_reference_owner()) { return new WP_Error('owner', 'دسترسی مجاز نیست.'); }
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($base) || !is_string($token) || strlen($token) < 32
        || wp_parse_url($base, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('config', 'اتصال به سرور بررسی کالا تنظیم نشده است.');
    }
    $args = ['method'=>$method, 'timeout'=>20, 'redirection'=>0,
        'headers'=>['X-Molavi-Reference-Token'=>$token,
            'X-Molavi-Actor'=>'wp:' . get_current_user_id(), 'Content-Type'=>'application/json']];
    if ($payload !== null) { $args['body']=wp_json_encode($payload); }
    $response=wp_safe_remote_request(rtrim($base,'/') . '/reference/item-reviews', $args);
    if (is_wp_error($response)) { return new WP_Error('offline', 'ارتباط با سرور برقرار نشد.'); }
    $body=json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response)!==200 || !is_array($body) || ($body['ok'] ?? false)!==true) {
        return new WP_Error('failed', 'بررسی ثبت نشد. صفحه را تازه کنید و دوباره بررسی کنید.');
    }
    if ($method==='GET' && (!is_array($body['cases'] ?? null) || !is_array($body['recent_decisions'] ?? null)
        || !is_array($body['reference_state'] ?? null))) {
        return new WP_Error('receipt', 'فهرست بررسی معتبر نیست.');
    }
    if ($method==='POST') {
        if ($payload===null || ($body['case_id'] ?? null)!==$payload['case_id']
            || ($body['offer_id'] ?? null)!==$payload['offer_id']
            || ($body['snapshot_id'] ?? null)!==$payload['snapshot_id']
            || ($body['choice'] ?? null)!==$payload['choice']
            || !preg_match('/^[a-f0-9]{64}$/', (string)($body['decision_id'] ?? ''))
            || !in_array($body['projection_state'] ?? '', ['imported','needs_review','held_for_review'], true)) {
            return new WP_Error('receipt', 'تأیید سرور با بررسی ارسالی مطابقت ندارد.');
        }
        if ($payload['choice']==='match' && $body['projection_state']==='held_for_review') {
            return new WP_Error('receipt', 'وضعیت انتشار کالا تأیید نشد.');
        }
        if ($payload['choice']==='no_match' && $body['projection_state']!=='held_for_review') {
            return new WP_Error('receipt', 'کالای بدون مرجع باید در بررسی بماند.');
        }
    }
    return $body;
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'تطبیق کالاهای تأمین‌کننده', 'تطبیق کالاهای تأمین‌کننده',
        'manage_woocommerce', 'molavi-reference-items', 'molavi_reference_item_reviews_screen');
});

function molavi_reference_item_reviews_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('بررسی تطبیق کالای تأمین‌کننده') . '</h1>';
    echo '<p>' . esc_html('هر تطبیق و نقش قیمت باید دستی تأیید شود. قیمت و پیام اصلی تأمین‌کننده دست‌نخورده می‌ماند. کالای بدون تطبیق در فروشگاه متوقف می‌ماند.') . '</p>';
    if (($_GET['saved'] ?? '')==='1') {
        echo '<div class="notice notice-success"><p>' . esc_html('تصمیم ثبت و رسید سرور بررسی شد.') . '</p></div>';
    }
    $result=molavi_reference_item_review_request('GET');
    if (is_wp_error($result)) {
        echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div></div>';
        return;
    }
    $referenceState=$result['reference_state'];
    if (empty($referenceState['allow_automatic'])) {
        echo '<div class="notice notice-warning"><p>' . esc_html('نسخه قیمت مرجع هنوز اجازه استفاده ندارد؛ ابتدا تصمیم تازگی مرجع را در صفحه تصمیم‌های مولوی‌سل بررسی کنید.') . '</p></div>';
    }
    $cases=$result['cases'];
    if ($cases===[]) { echo '<p>' . esc_html('مورد تطبیق در انتظار بررسی وجود ندارد.') . '</p>'; }
    $recent=$result['recent_decisions'];
    if ($recent!==[]) { echo '<h2>' . esc_html('تصمیم‌های اخیر؛ برای اصلاح دوباره انتخاب کنید') . '</h2>'; }
    $caseRows=array_merge($cases,$recent);
    foreach ($caseRows as $case) {
        if (!is_array($case) || !is_string($case['case_id'] ?? null)
            || !is_string($case['offer_id'] ?? null) || !is_string($case['snapshot_id'] ?? null)) { continue; }
        $id=$case['case_id'];
        echo '<div class="card"><h2>' . esc_html((string)($case['product_name'] ?? 'کالای بدون نام')) . '</h2>';
        echo '<p>' . esc_html('تأمین‌کننده: ' . (string)($case['channel'] ?? '')) . ' · '
            . esc_html('قیمت ثبت‌شده: ' . (string)($case['supplier_price'] ?? '')) . '</p>';
        echo '<p>' . esc_html('دلیل بررسی: ' . (string)($case['reason'] ?? '')) . ' · '
            . esc_html('نقش پیشنهادی قیمت: ' . (string)($case['price_role_hint'] ?? 'نیازمند بررسی')) . '</p>';
        echo '<p>' . esc_html('شناسه پیشنهاد: ' . $case['offer_id']) . '</p>';
        $decision=$case['decision'] ?? null;
        if (is_array($decision)) {
            echo '<p>' . esc_html('تصمیم فعلی: ' . (string)($decision['choice'] ?? '') . ' · مرجع '
                . (string)($decision['reference_id'] ?? 'ندارد') . ' · نقش '
                . (string)($decision['price_role'] ?? '') . ' · یادداشت '
                . (string)($decision['note'] ?? '')) . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('molavi_reference_item:' . $id);
        echo '<input type="hidden" name="action" value="molavi_reference_item_choice">';
        echo '<input type="hidden" name="case_id" value="' . esc_attr($id) . '">';
        echo '<input type="hidden" name="offer_id" value="' . esc_attr($case['offer_id']) . '">';
        echo '<input type="hidden" name="snapshot_id" value="' . esc_attr($case['snapshot_id']) . '">';
        echo '<input type="hidden" name="expected_decision_id" value="' . esc_attr(is_array($decision) ? (string)($decision['decision_id'] ?? '') : '') . '">';
        echo '<p><label>' . esc_html('نامزدهای پیشنهادی') . ' <select name="candidate_reference_id"><option value="">' . esc_html('انتخاب مرجع') . '</option>';
        $currentReference=is_array($decision) ? (string)($decision['reference_id'] ?? '') : '';
        if ($currentReference!=='') {
            echo '<option value="' . esc_attr($currentReference) . '" selected>' . esc_html('انتخاب فعلی: ' . $currentReference) . '</option>';
        }
        foreach (($case['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate) || !is_string($candidate['reference_id'] ?? null)) { continue; }
            $label=(string)($candidate['name'] ?? '') . ' · ' . (string)($candidate['score'] ?? '')
                . ' · تکی ' . (string)($candidate['unit_price'] ?? '') . ' · بسته ' . (string)($candidate['package_price'] ?? '');
            $selected=$currentReference!=='' && $currentReference===$candidate['reference_id'] ? ' selected' : '';
            echo '<option value="' . esc_attr($candidate['reference_id']) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ' . '<label>' . esc_html('یا شناسه دقیق مرجع')
            . ' <input name="reference_id" maxlength="300"></label></p>';
        $currentRole=is_array($decision) ? (string)($decision['price_role'] ?? '') : '';
        echo '<p><label>' . esc_html('نقش قیمت') . ' <select name="price_role"><option value="unit"' . ($currentRole==='unit'?' selected':'') . '>' . esc_html('قیمت واحد')
            . '</option><option value="package"' . ($currentRole==='package'?' selected':'') . '>' . esc_html('قیمت بسته / کارتن') . '</option></select></label></p>';
        echo '<p><label>' . esc_html('یادداشت مالک') . ' <textarea name="note" required maxlength="1000">'
            . esc_textarea(is_array($decision) ? (string)($decision['note'] ?? '') : '') . '</textarea></label></p>';
        echo '<button class="button button-primary" name="choice" value="match">' . esc_html(is_array($decision) ? 'اصلاح تطبیق و نقش قیمت' : 'ثبت تطبیق و نقش قیمت') . '</button> ';
        echo '<button class="button" name="choice" value="no_match">' . esc_html('تأیید نبود تطبیق و نگه داشتن کالا در بررسی') . '</button>';
        echo '</form></div>';
    }
    echo '</div>';
}

add_action('admin_post_molavi_reference_item_choice', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    foreach (['case_id','offer_id','snapshot_id','choice','reference_id','candidate_reference_id',
              'price_role','note','expected_decision_id'] as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
            wp_die('درخواست نامعتبر است.', '', ['response'=>400]);
        }
    }
    $id=wp_unslash($_POST['case_id']);
    check_admin_referer('molavi_reference_item:' . $id);
    $choice=wp_unslash($_POST['choice']);
    if (!in_array($choice,['match','no_match'],true)) { wp_die('انتخاب نامعتبر است.', '', ['response'=>400]); }
    $referenceId=trim(wp_unslash($_POST['reference_id']));
    if ($referenceId==='') { $referenceId=trim(wp_unslash($_POST['candidate_reference_id'])); }
    $role=wp_unslash($_POST['price_role']);
    if ($choice==='no_match') { $referenceId=''; $role='unresolved'; }
    if (($choice==='match' && ($referenceId==='' || !in_array($role,['unit','package'],true)))
        || ($choice==='no_match' && $referenceId!=='')) {
        wp_die('شناسه مرجع و نقش قیمت را بررسی کنید.', '', ['response'=>400]);
    }
    $expected=wp_unslash($_POST['expected_decision_id']);
    $payload=['case_id'=>$id,'offer_id'=>wp_unslash($_POST['offer_id']),
        'snapshot_id'=>wp_unslash($_POST['snapshot_id']),'choice'=>$choice,
        'reference_id'=>$referenceId,'price_role'=>$role,
        'note'=>wp_unslash($_POST['note']),'expected_decision_id'=>$expected===''?null:$expected];
    $result=molavi_reference_item_review_request('POST',$payload);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-reference-items&saved=1'));
    exit;
});
