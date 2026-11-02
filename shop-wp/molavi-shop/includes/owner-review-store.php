<?php
/** WordPress-side context snapshots and audit trail for owner dashboard decisions. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_owner_review_store_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'molavi_owner_review_events';
}

function molavi_owner_review_store_ensure_schema(): bool {
    global $wpdb;
    static $ready = null;
    if ($ready === true) { return true; }
    if (!isset($wpdb) || !is_object($wpdb)) { return false; }
    $table = molavi_owner_review_store_table();
    if ((string)get_option('molavi_owner_review_schema_version', '') === '1') {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found === $table) { $ready = true; return true; }
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        event_id varchar(191) NOT NULL,
        review_type varchar(40) NOT NULL,
        review_id varchar(191) NOT NULL,
        state varchar(24) NOT NULL,
        context_json longtext NOT NULL,
        decision_json longtext NOT NULL,
        receipt_json longtext NOT NULL,
        actor_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (event_id),
        KEY review_lookup (review_type,review_id(120)),
        KEY state_lookup (review_type,state)
    ) ENGINE=InnoDB {$collate};";
    dbDelta($sql);
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($found !== $table) { $ready = false; return false; }
    update_option('molavi_owner_review_schema_version', '1', false);
    $ready = true;
    return true;
}
add_action('admin_init', 'molavi_owner_review_store_ensure_schema', 1);

function molavi_owner_review_store_write(array $record): bool {
    global $wpdb;
    if (!molavi_owner_review_store_ensure_schema()) { return false; }
    $types = ['publication_hold','sheet_conflict','ai_confirmation','reference_item','publication_backlog'];
    $states = ['observed','pending','accepted','failed'];
    $eventId = (string)($record['event_id'] ?? '');
    $type = (string)($record['review_type'] ?? '');
    $reviewId = (string)($record['review_id'] ?? '');
    $state = (string)($record['state'] ?? '');
    if (!preg_match('/^[A-Za-z0-9:_-]{1,191}$/', $eventId)
        || !in_array($type, $types, true) || !in_array($state, $states, true)
        || $reviewId === '' || strlen($reviewId) > 191) { return false; }
    $encode = static function ($value): string {
        $json = wp_json_encode(is_array($value) ? $value : []);
        return is_string($json) ? $json : '';
    };
    $context = $encode($record['context'] ?? []);
    $decision = $encode($record['decision'] ?? []);
    $receipt = $encode($record['receipt'] ?? []);
    if ($context === '' || $decision === '' || $receipt === '') { return false; }
    if (strlen($context) > 700000 || strlen($decision) > 50000 || strlen($receipt) > 50000) { return false; }
    $now = current_time('mysql', true);
    $table = molavi_owner_review_store_table();
    $sql = $wpdb->prepare(
        "INSERT INTO {$table} (event_id,review_type,review_id,state,context_json,decision_json,receipt_json,actor_id,created_at,updated_at)
         VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%s,%s)
         ON DUPLICATE KEY UPDATE state=VALUES(state),context_json=VALUES(context_json),
         decision_json=VALUES(decision_json),receipt_json=VALUES(receipt_json),actor_id=VALUES(actor_id),updated_at=VALUES(updated_at)",
        $eventId, $type, $reviewId, $state, $context, $decision, $receipt,
        get_current_user_id(), $now, $now
    );
    return $wpdb->query($sql) !== false;
}

function molavi_owner_review_store_ajax(): void {
    global $wpdb;
    if (!current_user_can('manage_woocommerce') || !function_exists('molavi_reference_owner')
        || !molavi_reference_owner()) {
        wp_send_json_error(['code'=>'owner_required'], 403);
    }
    check_ajax_referer('molavi_owner_review_store', 'nonce');
    $raw = isset($_POST['records']) && is_string($_POST['records'])
        ? wp_unslash($_POST['records']) : '';
    if ($raw === '' || strlen($raw) > 2000000) {
        wp_send_json_error(['code'=>'invalid_records'], 400);
    }
    $records = json_decode($raw, true);
    if (!is_array($records) || !$records || count($records) > 100) {
        wp_send_json_error(['code'=>'invalid_records'], 400);
    }
    if ($wpdb->query('START TRANSACTION') === false) {
        wp_send_json_error(['code'=>'review_store_failed'], 503);
    }
    foreach ($records as $record) {
        if (!is_array($record) || !molavi_owner_review_store_write($record)) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['code'=>'review_store_failed'], 503);
        }
    }
    if ($wpdb->query('COMMIT') === false) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['code'=>'review_store_failed'], 503);
    }
    wp_send_json_success(['stored'=>count($records)]);
}
add_action('wp_ajax_molavi_owner_review_store', 'molavi_owner_review_store_ajax');
