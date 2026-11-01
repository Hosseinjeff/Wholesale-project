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
    molavi_owner_workflow_mount('در حال دریافت پیشنهادهای تطبیق…');
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
