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

function molavi_reference_snapshots_browser_token(): string {
    if (!molavi_reference_owner()) { return ''; }
    $secret=defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($secret) || strlen($secret)<32) { return ''; }
    $issued=time();
    $claims=wp_json_encode([
        'iss'=>'molavi-wordpress','aud'=>'reference-snapshots',
        'scope'=>'reference:snapshots','actor'=>'wp:' . get_current_user_id(),
        'iat'=>$issued,'exp'=>$issued+600,
    ]);
    if (!is_string($claims)) { return ''; }
    $payload=rtrim(strtr(base64_encode($claims),'+/','-_'),'=');
    $signature=rtrim(strtr(base64_encode(hash_hmac('sha256',$payload,$secret,true)),'+/','-_'),'=');
    return $payload . '.' . $signature;
}

add_action('admin_enqueue_scripts', static function (string $hook): void {
    $page=isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page!=='molavi-reference-snapshots' || !molavi_reference_owner()) { return; }
    $base=defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token=molavi_reference_snapshots_browser_token();
    $script=__DIR__ . '/reference-snapshots.js';
    if (!is_string($base) || wp_parse_url($base,PHP_URL_SCHEME)!=='https'
        || $token==='' || !is_readable($script)) { return; }
    wp_enqueue_script('molavi-reference-snapshots',plugins_url('reference-snapshots.js',__FILE__),[],
        (string)filemtime($script),true);
    wp_localize_script('molavi-reference-snapshots','molaviReferenceSnapshots',[
        'apiUrl'=>rtrim($base,'/') . '/reference/snapshots',
        'token'=>$token,
    ]);
});

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce','بررسی قیمت‌های مرجع','بررسی قیمت‌های مرجع',
        'manage_woocommerce','molavi-reference-snapshots','molavi_reference_snapshots_screen');
});

function molavi_reference_snapshots_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.','',['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('بررسی قیمت‌های مرجع') . '</h1>';
    echo '<p>' . esc_html('نسخه‌های استخراج‌شده از PDF رزوان را بررسی کنید. «ثبت بررسی» فقط یادداشت شما را ذخیره می‌کند. قیمت‌ها فقط با «فعال‌سازی و ثبت قیمت‌ها» وارد فهرست مرجع می‌شوند.') . '</p>';
    echo '<div id="molavi-reference-snapshots" aria-live="polite"><p>'
        . esc_html('در حال بارگذاری قیمت‌ها…') . '</p></div></div>';
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
