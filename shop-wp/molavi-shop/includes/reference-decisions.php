<?php
/** Draft include for the existing MolaviShop plugin. Not installed. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_reference_owner(): bool {
    $owners = defined('MOLAVI_REFERENCE_OWNER_IDS') ? MOLAVI_REFERENCE_OWNER_IDS : [];
    return is_array($owners) && current_user_can('manage_woocommerce')
        && in_array(get_current_user_id(), $owners, true);
}

function molavi_reference_bridge(string $method, ?array $payload = null) {
    if (!molavi_reference_owner()) {
        return new WP_Error('owner', 'دسترسی به تصمیم‌های مرجع مجاز نیست.');
    }
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($base) || !is_string($token) || strlen($token) < 32
        || wp_parse_url($base, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('config', 'اتصال به سرور تصمیم‌ها تنظیم نشده است.');
    }
    $args = ['method' => $method, 'timeout' => 12, 'redirection' => 0,
        'headers' => ['X-Molavi-Reference-Token' => $token,
            'X-Molavi-Actor' => 'wp:' . get_current_user_id(), 'Content-Type' => 'application/json']];
    if ($payload !== null) { $args['body'] = wp_json_encode($payload); }
    $response = wp_safe_remote_request(rtrim($base, '/') . '/reference/decisions', $args);
    if (is_wp_error($response)) {
        return new WP_Error('offline', 'ارتباط با سرور برقرار نشد؛ تصمیم تأیید نشده است.');
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response) !== 200
        || !is_array($body) || ($body['ok'] ?? false) !== true) {
        return new WP_Error('rejected', 'تصمیم تأیید نشد. صفحه را تازه کنید و دوباره بررسی کنید.');
    }
    if ($method === 'POST' && ($payload === null
        || ($body['event_id'] ?? null) !== $payload['event_id']
        || ($body['choice'] ?? null) !== $payload['choice']
        || !array_key_exists('snapshot_id', $body) || $body['snapshot_id'] !== $payload['snapshot_id'])) {
        return new WP_Error('receipt', 'تأیید سرور با تصمیم ارسال‌شده مطابقت ندارد.');
    }
    return $body;
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'تصمیم‌های مولوی‌سل', 'تصمیم‌های مولوی‌سل',
        'manage_woocommerce', 'molavi-reference-decisions', 'molavi_reference_screen');
});

function molavi_reference_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response' => 403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('تصمیم‌های مولوی‌سل') . '</h1>';
    echo '<p>' . esc_html('قیمت‌های مرجع پس از ۲۴ ساعت نیاز به تصمیم شما دارند. قیمت تأمین‌کننده و قیمت فروش مشتری جدا باقی می‌مانند.') . '</p>';
    if (($_GET['saved'] ?? '') === '1') {
        echo '<div class="notice notice-success"><p>' . esc_html('تصمیم در سرور ثبت شد.') . '</p></div>';
    }
    $result = molavi_reference_bridge('GET');
    if (is_wp_error($result) || !isset($result['events']) || !is_array($result['events'])) {
        echo '<div class="notice notice-error"><p>' . esc_html(is_wp_error($result)
            ? $result->get_error_message() : 'پاسخ سرور معتبر نیست.') . '</p></div></div>';
        return;
    }
    if ($result['events'] === []) { echo '<p>' . esc_html('تصمیم در انتظار بررسی وجود ندارد.') . '</p>'; }
    $labels = ['stale' => 'قیمت مرجع بیش از ۲۴ ساعت قدمت دارد',
        'refresh_failed' => 'دریافت قیمت مرجع ناموفق بود', 'missing' => 'قیمت مرجع قبلی موجود نیست'];
    foreach ($result['events'] as $event) {
        if (!is_array($event) || !is_string($event['event_id'] ?? null)) { continue; }
        $id = $event['event_id'];
        $snapshot = $event['snapshot_id'] ?? null;
        echo '<div class="card"><h2>' . esc_html($labels[$event['reason'] ?? ''] ?? 'نیاز به بررسی') . '</h2>';
        echo '<p>' . esc_html('شناسه رخداد: ' . $id) . '</p><p>'
            . esc_html('زمان ثبت (UTC): ' . ($event['created_at'] ?? '')) . '</p>';
        echo '<p>' . esc_html('نسخه مرجع: ' . ($snapshot ?? 'موجود نیست')) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('molavi_reference_choice:' . $id);
        echo '<input type="hidden" name="action" value="molavi_reference_choice">';
        echo '<input type="hidden" name="event_id" value="' . esc_attr($id) . '">';
        echo '<input type="hidden" name="snapshot_id" value="' . esc_attr($snapshot ?? '') . '">';
        echo '<button class="button" name="choice" value="pause_review">' . esc_html('توقف و بررسی موارد مرتبط') . '</button> ';
        if (is_string($snapshot) && $snapshot !== '') {
            echo '<button class="button button-primary" name="choice" value="use_last_snapshot">'
                . esc_html('ادامه با نسخه قبلی و ثبت هشدار') . '</button>';
        }
        echo '</form></div>';
    }
    echo '</div>';
}

add_action('admin_post_molavi_reference_choice', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response' => 403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('درخواست نامعتبر است.', '', ['response' => 405]); }
    foreach (['event_id', 'snapshot_id', 'choice'] as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
            wp_die('درخواست نامعتبر است.', '', ['response' => 400]);
        }
    }
    $id = wp_unslash($_POST['event_id']);
    $snapshot = wp_unslash($_POST['snapshot_id']);
    $choice = wp_unslash($_POST['choice']);
    check_admin_referer('molavi_reference_choice:' . $id);
    if (!in_array($choice, ['pause_review', 'use_last_snapshot'], true)) {
        wp_die('انتخاب نامعتبر است.', '', ['response' => 400]);
    }
    $result = molavi_reference_bridge('POST', ['event_id' => $id,
        'snapshot_id' => $snapshot === '' ? null : $snapshot, 'choice' => $choice]);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response' => 503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-reference-decisions&saved=1'));
    exit;
});
