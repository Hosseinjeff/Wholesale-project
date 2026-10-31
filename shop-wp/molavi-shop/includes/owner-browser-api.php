<?php
/** Short-lived, route-scoped browser access for owner-only workflow screens. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_owner_workflow_pages(): array {
    return [
        'molavi-operations' => ['/pipeline/operations-dashboard', 'owner:/pipeline/operations-dashboard'],
        'molavi-ai-confirmations' => ['/pipeline/ai-confirmations', 'owner:/pipeline/ai-confirmations'],
        'molavi-reference-items' => ['/reference/item-reviews', 'owner:/reference/item-reviews'],
        'molavi-reference-decisions' => ['/reference/decisions', 'owner:/reference/decisions'],
    ];
}

function molavi_owner_workflow_page_urls(): array {
    $pages = [
        'molavi-operations', 'molavi-ai-confirmations', 'molavi-reference-items',
        'molavi-reference-decisions', 'molavi-reference-snapshots', 'molavi-publication-holds',
        'molavi-sheet-conflicts', 'molavi-supplier-prices', 'molavi-clearance-pricing',
        'molavi-inventory-management', 'molavi-paid-orders',
    ];
    $urls = [];
    foreach ($pages as $page) {
        $urls[$page] = admin_url('admin.php?page=' . $page);
    }
    return $urls;
}

function molavi_owner_workflow_token(string $scope): string {
    if (!molavi_reference_owner()) { return ''; }
    $allowed = array_column(molavi_owner_workflow_pages(), 1);
    if (!in_array($scope, $allowed, true)) { return ''; }
    $secret = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($secret) || strlen($secret) < 32) { return ''; }
    $issued = time();
    $claims = wp_json_encode([
        'iss' => 'molavi-wordpress',
        'aud' => 'molavi-owner-workflows',
        'scope' => $scope,
        'actor' => 'wp:' . get_current_user_id(),
        'iat' => $issued,
        'exp' => $issued + 600,
    ]);
    if (!is_string($claims)) { return ''; }
    $payload = rtrim(strtr(base64_encode($claims), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    return $payload . '.' . $signature;
}

add_action('admin_enqueue_scripts', static function (): void {
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $pages = molavi_owner_workflow_pages();
    if (!isset($pages[$page]) || !molavi_reference_owner()) { return; }
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $script = __DIR__ . '/owner-workflows.js';
    $token = molavi_owner_workflow_token($pages[$page][1]);
    if (!is_string($base) || wp_parse_url($base, PHP_URL_SCHEME) !== 'https'
        || $token === '' || !is_readable($script)) { return; }
    wp_enqueue_script('molavi-owner-workflows', plugins_url('owner-workflows.js', __FILE__), [],
        (string) filemtime($script), true);
    $style = __DIR__ . '/owner-workflows.css';
    if (is_readable($style)) {
        wp_enqueue_style('molavi-owner-workflows', plugins_url('owner-workflows.css', __FILE__), [],
            (string) filemtime($style));
    }
    wp_localize_script('molavi-owner-workflows', 'molaviOwnerWorkflow', [
        'page' => $page,
        'apiUrl' => rtrim($base, '/') . $pages[$page][0],
        'token' => $token,
        'pipelineUrl' => admin_url('admin.php?page=molavi-operations'),
        'pageUrls' => molavi_owner_workflow_page_urls(),
    ]);
});

function molavi_owner_workflow_mount(string $loading): void {
    if (function_exists('wp_script_is') && !wp_script_is('molavi-owner-workflows', 'enqueued')) {
        echo '<div class="notice notice-error"><p>'
            . esc_html('اتصال امن این صفحه آماده نشد. تنظیم سرور و دسترسی مالک را بررسی کنید و صفحه را تازه کنید.')
            . '</p></div>';
        return;
    }
    echo '<div id="molavi-owner-workflow" aria-live="polite"><p>' . esc_html($loading) . '</p></div>';
}
