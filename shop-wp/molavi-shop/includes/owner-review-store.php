<?php
/** WordPress-side context snapshots and audit trail for owner dashboard decisions. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_owner_review_store_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'molavi_owner_review_events';
}

function molavi_workflow_decisions_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'molavi_workflow_decisions';
}

function molavi_workflow_outbox_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'molavi_workflow_outbox';
}

function molavi_owner_review_store_ensure_schema(): bool {
    global $wpdb;
    static $ready = null;
    if ($ready === true) { return true; }
    if (!isset($wpdb) || !is_object($wpdb)) { return false; }
    $table = molavi_owner_review_store_table();
    $decisionTable = molavi_workflow_decisions_table();
    $outboxTable = molavi_workflow_outbox_table();
    if ((string)get_option('molavi_owner_review_schema_version', '') === '2') {
        $foundTables = [
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)),
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $decisionTable)),
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $outboxTable)),
        ];
        if ($foundTables === [$table, $decisionTable, $outboxTable]) { $ready = true; return true; }
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
    $decisionSql = "CREATE TABLE {$decisionTable} (
        decision_id char(64) NOT NULL,
        event_id varchar(191) NOT NULL,
        domain varchar(40) NOT NULL,
        subject_id varchar(191) NOT NULL,
        expected_revision varchar(191) NOT NULL,
        choice varchar(40) NOT NULL,
        state varchar(24) NOT NULL,
        context_json longtext NOT NULL,
        decision_json longtext NOT NULL,
        receipt_json longtext NOT NULL,
        actor_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (decision_id),
        KEY subject_revision (domain,subject_id(100),expected_revision(100)),
        KEY decision_state (domain,state)
    ) ENGINE=InnoDB {$collate};";
    dbDelta($decisionSql);
    $outboxSql = "CREATE TABLE {$outboxTable} (
        command_id char(64) NOT NULL,
        decision_id char(64) NOT NULL,
        command_type varchar(40) NOT NULL,
        payload_json longtext NOT NULL,
        state varchar(24) NOT NULL,
        attempts int(10) unsigned NOT NULL DEFAULT 0,
        available_at datetime NOT NULL,
        last_error varchar(500) NOT NULL DEFAULT '',
        receipt_json longtext NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (command_id),
        KEY ready_commands (state,available_at),
        KEY decision_lookup (decision_id)
    ) ENGINE=InnoDB {$collate};";
    dbDelta($outboxSql);
    $foundTables = [
        $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)),
        $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $decisionTable)),
        $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $outboxTable)),
    ];
    if ($foundTables !== [$table, $decisionTable, $outboxTable]) { $ready = false; return false; }
    update_option('molavi_owner_review_schema_version', '2', false);
    $ready = true;
    return true;
}
add_action('admin_init', 'molavi_owner_review_store_ensure_schema', 1);

function molavi_owner_review_store_write_workflow_decision(array $record): bool {
    global $wpdb;
    $type = (string)($record['review_type'] ?? '');
    if (!in_array($type, ['sheet_conflict','supplier_price'], true)
        || !in_array(($record['state'] ?? ''), ['pending','accepted','failed'], true)) { return true; }
    $reviewId = (string)($record['review_id'] ?? '');
    $context = is_array($record['context'] ?? null) ? $record['context'] : [];
    $decision = is_array($record['decision'] ?? null) ? $record['decision'] : [];
    $receipt = is_array($record['receipt'] ?? null) ? $record['receipt'] : [];
    $domain = $type;
    $choice = $type === 'supplier_price'
        ? (($decision['action'] ?? '') === 'verify_current_supplier_price' ? 'verified' : '')
        : (string)($decision['choice'] ?? '');
    $revision = (string)($context['source_revision'] ?? '');
    $note = (string)($decision['note'] ?? '');
    $validReviewId = $type === 'sheet_conflict'
        ? preg_match('/^sheet:[A-Za-z0-9:_-]{1,74}$/', $reviewId)
        : preg_match('/^[A-Za-z0-9:_-]{1,191}$/', $reviewId);
    $validChoice = $type === 'sheet_conflict'
        ? in_array($choice, ['server','sheet','preserve'], true)
        : $choice === 'verified';
    if (!$validReviewId || !preg_match('/^[A-Za-z0-9:_-]{1,191}$/', $revision)
        || !$validChoice || strlen($note) > 1000) { return false; }

    $eventId = (string)($record['event_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9:_-]{1,191}$/', $eventId)) { return false; }
    $decisionId = hash('sha256', $domain . "\0" . $reviewId . "\0" . $revision);
    $commandId = hash('sha256', $domain . "\0" . $decisionId);
    if ($type === 'sheet_conflict') {
        $payload = [
            'conflict_id' => $reviewId,
            'choice' => $choice,
            'note' => $note,
            'expected_revision' => $revision,
        ];
        $contextData = [
            'product_id'=>(string)($context['product_id'] ?? ''),
            'product_name'=>(string)($context['product_name'] ?? ''),
            'channel'=>(string)($context['channel'] ?? ''),
            'field'=>(string)($context['field'] ?? ''),
            'base'=>$context['base'] ?? null,
            'server'=>$context['server'] ?? null,
            'sheet'=>$context['sheet'] ?? null,
        ];
    } else {
        $payload = [
            'product_id'=>$reviewId,
            'expected_revision'=>$revision,
            'note'=>$note,
            'command_id'=>$commandId,
        ];
        $contextData = [
            'product_id'=>$reviewId,
            'product_name'=>(string)($context['name'] ?? $context['product_name'] ?? ''),
            'channel'=>(string)($context['channel'] ?? ''),
            'sale_price'=>$context['sale_price'] ?? '',
            'source_timestamp'=>$context['source_timestamp'] ?? null,
            'source_revision'=>$revision,
        ];
    }
    $payloadJson = wp_json_encode($payload);
    $decisionData = ['choice'=>$choice,'note'=>$note];
    if ($type === 'supplier_price') { $decisionData['action'] = 'verify_current_supplier_price'; }
    $decisionJson = wp_json_encode($decisionData);
    $receiptJson = wp_json_encode($receipt);
    $contextJson = wp_json_encode($contextData);
    if (!is_string($payloadJson) || !is_string($decisionJson)
        || !is_string($receiptJson) || !is_string($contextJson)) { return false; }

    $decisionsTable = molavi_workflow_decisions_table();
    $outboxTable = molavi_workflow_outbox_table();
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT choice,state FROM {$decisionsTable} WHERE decision_id=%s", $decisionId), ARRAY_A);
    if ($wpdb->last_error !== '') { return false; }
    if (is_array($existing) && (string)$existing['choice'] !== $choice) { return false; }
    if (is_array($existing) && $existing['state'] === 'accepted'
        && $record['state'] !== 'accepted') { return false; }

    $now = current_time('mysql', true);
    $state = (string)$record['state'];
    $decisionRow = [
        'decision_id'=>$decisionId,'event_id'=>$eventId,'domain'=>$domain,
        'subject_id'=>$reviewId,'expected_revision'=>$revision,'choice'=>$choice,
        'state'=>$state,'context_json'=>$contextJson,'decision_json'=>$decisionJson,'receipt_json'=>$receiptJson,
        'actor_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,
    ];
    if (is_array($existing)) {
        unset($decisionRow['created_at']);
        $written = $wpdb->update($decisionsTable, $decisionRow, ['decision_id'=>$decisionId]);
    } else {
        $written = $wpdb->insert($decisionsTable, $decisionRow);
    }
    if ($written === false) { return false; }

    $outbox = $wpdb->get_row($wpdb->prepare(
        "SELECT state,attempts FROM {$outboxTable} WHERE command_id=%s", $commandId), ARRAY_A);
    if ($wpdb->last_error !== '') { return false; }
    $targetState = $state === 'accepted' ? 'delivered' : ($state === 'failed' ? 'failed' : 'queued');
    if (is_array($outbox)) {
        $outboxState = (string)$outbox['state'];
        if ($outboxState === 'delivered' && $targetState !== 'delivered') { return false; }
        // A fresh owner attempt may retry a terminal rejection or stale-command
        // failure. Keep the command ID stable so Railway can safely deduplicate it.
        $retryFailed = $outboxState === 'failed' && $targetState === 'queued';
        $delay = $state === 'pending' && $outboxState === 'queued'
            ? min(3600, 30 * (2 ** min(7, max(0, (int)$outbox['attempts'])))) : 0;
        $availableAt = $delay > 0 ? gmdate('Y-m-d H:i:s', time() + $delay) : $now;
        $outboxRow = [
            'payload_json'=>$payloadJson,'state'=>$targetState,
            'attempts'=>$retryFailed ? 0 : (int)$outbox['attempts'],
            'available_at'=>$availableAt,'last_error'=>$state === 'failed'
                ? substr((string)($receipt['error'] ?? 'remote_rejected'), 0, 500) : '',
            'receipt_json'=>$receiptJson,'updated_at'=>$now,
        ];
        $written = $wpdb->update($outboxTable, $outboxRow, ['command_id'=>$commandId]);
    } else {
        $written = $wpdb->insert($outboxTable, [
            'command_id'=>$commandId,'decision_id'=>$decisionId,
            'command_type'=>$domain,'payload_json'=>$payloadJson,
            'state'=>$targetState,'attempts'=>0,'available_at'=>$now,
            'last_error'=>$state === 'failed'
                ? substr((string)($receipt['error'] ?? 'remote_rejected'), 0, 500) : '',
            'receipt_json'=>$receiptJson,'created_at'=>$now,'updated_at'=>$now,
        ]);
    }
    return $written !== false;
}

function molavi_owner_review_store_write(array $record): bool {
    global $wpdb;
    if (!molavi_owner_review_store_ensure_schema()) { return false; }
    $types = ['publication_hold','sheet_conflict','ai_confirmation','ai_reextraction','ai_media_review','reference_item',
        'supplier_price','publication_backlog','telegram_operator'];
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
    if ($wpdb->query($sql) === false) { return false; }
    return molavi_owner_review_store_write_workflow_decision($record);
}

function molavi_owner_review_store_pending_commands(int $limit = 20, array $domains = ['sheet_conflict','supplier_price']): ?array {
    global $wpdb;
    if (!molavi_owner_review_store_ensure_schema()) { return null; }
    $limit = max(1, min(50, $limit));
    $domains = array_values(array_intersect($domains, ['sheet_conflict','supplier_price']));
    if (!$domains) { return []; }
    $decisionsTable = molavi_workflow_decisions_table();
    $outboxTable = molavi_workflow_outbox_table();
    $now = current_time('mysql', true);
    if ($wpdb->query('START TRANSACTION') === false) { return null; }
    $domainPlaceholders = implode(',', array_fill(0, count($domains), '%s'));
    $sql = "SELECT d.event_id,d.subject_id,d.domain,d.context_json,d.decision_json,
                o.command_id,o.command_type,o.payload_json,o.attempts
         FROM {$outboxTable} o INNER JOIN {$decisionsTable} d ON d.decision_id=o.decision_id
         WHERE o.command_type IN ({$domainPlaceholders}) AND o.state=%s
            AND d.domain=o.command_type AND o.available_at<=%s
         ORDER BY o.created_at ASC LIMIT %d FOR UPDATE";
    $queryArgs = array_merge($domains, ['queued',$now,$limit]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$queryArgs), ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) {
        $wpdb->query('ROLLBACK');
        return null;
    }
    $commands = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        $decision = json_decode((string)($row['decision_json'] ?? ''), true);
        $context = json_decode((string)($row['context_json'] ?? ''), true);
        if (!is_array($payload) || !is_array($decision) || !is_array($context)) { continue; }
        $attempts = max(0, (int)($row['attempts'] ?? 0));
        $delay = min(3600, 60 * (2 ** min(6, $attempts)));
        $availableAt = gmdate('Y-m-d H:i:s', time() + $delay);
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$outboxTable} SET attempts=attempts+1,available_at=%s,updated_at=%s
             WHERE command_id=%s AND state=%s AND available_at<=%s",
            $availableAt, $now, (string)$row['command_id'], 'queued', $now));
        if ($claimed === false) {
            $wpdb->query('ROLLBACK');
            return null;
        }
        if ($claimed !== 1) { continue; }
        $commands[] = [
            'event_id'=>(string)$row['event_id'],
            'command_type'=>(string)$row['command_type'],
            'review_type'=>(string)$row['domain'],
            'review_id'=>(string)$row['subject_id'],
            'context'=>$context,
            'decision'=>$decision,
            'payload'=>$payload,
            'command_id'=>(string)$row['command_id'],
        ];
    }
    if ($wpdb->query('COMMIT') === false) {
        $wpdb->query('ROLLBACK');
        return null;
    }
    return $commands;
}

function molavi_owner_review_store_pending_sheet_conflicts(int $limit = 20): ?array {
    return molavi_owner_review_store_pending_commands($limit, ['sheet_conflict']);
}

function molavi_owner_review_outbox_service_permission($request) {
    $secret = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    $sent = is_object($request) && method_exists($request, 'get_header')
        ? (string)$request->get_header('x-molavi-outbox-token') : '';
    if (!is_string($secret) || strlen($secret) < 32 || $sent === '') {
        return new WP_Error('molavi_outbox_forbidden', 'دسترسی مجاز نیست.', ['status'=>403]);
    }
    $expected = hash_hmac('sha256', 'molavi-wordpress-outbox-v1', $secret);
    if (!hash_equals($expected, $sent)) {
        return new WP_Error('molavi_outbox_forbidden', 'دسترسی مجاز نیست.', ['status'=>403]);
    }
    return true;
}

function molavi_owner_review_outbox_rest_pending($request) {
    $limit = is_object($request) && method_exists($request, 'get_param')
        ? (int)$request->get_param('limit') : 5;
    $commands = molavi_owner_review_store_pending_commands(max(1, min(10, $limit ?: 5)));
    if (!is_array($commands)) {
        return new WP_Error('molavi_outbox_unavailable', 'صف تصمیم‌ها در دسترس نیست.', ['status'=>503]);
    }
    return rest_ensure_response(['ok'=>true,'commands'=>$commands]);
}

function molavi_owner_review_outbox_store_receipt(array $input): bool {
    global $wpdb;
    if (!molavi_owner_review_store_ensure_schema()) { return false; }
    $commandId = (string)($input['command_id'] ?? '');
    $state = (string)($input['state'] ?? '');
    $receipt = is_array($input['receipt'] ?? null) ? $input['receipt'] : [];
    if (!preg_match('/^[a-f0-9]{64}$/', $commandId)
        || !in_array($state, ['delivered','queued','failed'], true)) { return false; }
    $outboxTable = molavi_workflow_outbox_table();
    $decisionsTable = molavi_workflow_decisions_table();
    $eventsTable = molavi_owner_review_store_table();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT o.state AS outbox_state,o.command_type,o.payload_json,
                d.decision_id,d.event_id,d.subject_id,d.choice
         FROM {$outboxTable} o INNER JOIN {$decisionsTable} d ON d.decision_id=o.decision_id
         WHERE o.command_id=%s",
        $commandId), ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($row)) { return false; }
    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) { return false; }
    if ($state === 'delivered') {
        if (($row['command_type'] ?? '') === 'sheet_conflict'
            && (($receipt['ok'] ?? true) === false
                || (string)($receipt['conflict_id'] ?? '') !== (string)($payload['conflict_id'] ?? '')
                || (string)($receipt['choice'] ?? '') !== (string)($payload['choice'] ?? '')
                || (($receipt['server_owned'] ?? false) !== true
                    && ($receipt['sheet_preserved'] ?? false) !== true
                    && empty($receipt['publish_job'])))) { return false; }
        if (($row['command_type'] ?? '') === 'supplier_price'
            && (($receipt['ok'] ?? true) === false
                || (string)($receipt['product_id'] ?? '') !== (string)($payload['product_id'] ?? '')
                || (string)($receipt['source_revision'] ?? '') !== (string)($payload['expected_revision'] ?? '')
                || (string)($receipt['command_id'] ?? '') !== $commandId
                || empty($receipt['verified_at']) || empty($receipt['publish_job']))) { return false; }
        if (!in_array(($row['command_type'] ?? ''), ['sheet_conflict','supplier_price'], true)) { return false; }
    }
    if ($state !== 'delivered') {
        $error = sanitize_text_field((string)($receipt['error'] ?? 'remote_unavailable'));
        $receipt = ['error'=>substr($error ?: 'remote_unavailable', 0, 500)];
    }
    $outboxState = (string)$row['outbox_state'];
    if ($outboxState === 'delivered') { return true; }
    if ($outboxState === 'failed' && $state !== 'delivered') { return $state === 'failed'; }

    $now = current_time('mysql', true);
    $decisionState = $state === 'delivered' ? 'accepted' : ($state === 'failed' ? 'failed' : 'pending');
    $receiptJson = wp_json_encode($receipt);
    if (!is_string($receiptJson)) { return false; }
    $attempts = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT attempts FROM {$outboxTable} WHERE command_id=%s", $commandId));
    $delay = $state === 'queued' ? min(3600, 60 * (2 ** min(6, max(0, $attempts)))) : 0;
    $availableAt = $delay ? gmdate('Y-m-d H:i:s', time() + $delay) : $now;
    $wpdb->query('START TRANSACTION');
    $written = $wpdb->update($outboxTable, [
        'state'=>$state,'available_at'=>$availableAt,
        'last_error'=>$state === 'delivered' ? '' : (string)($receipt['error'] ?? 'remote_unavailable'),
        'receipt_json'=>$receiptJson,'updated_at'=>$now,
    ], ['command_id'=>$commandId]);
    if ($written === false) { $wpdb->query('ROLLBACK'); return false; }
    $written = $wpdb->update($decisionsTable, [
        'state'=>$decisionState,'receipt_json'=>$receiptJson,'updated_at'=>$now,
    ], ['decision_id'=>(string)$row['decision_id']]);
    if ($written === false) { $wpdb->query('ROLLBACK'); return false; }
    $written = $wpdb->update($eventsTable, [
        'state'=>$decisionState,'receipt_json'=>$receiptJson,'updated_at'=>$now,
    ], ['event_id'=>(string)$row['event_id']]);
    if ($written === false) { $wpdb->query('ROLLBACK'); return false; }
    if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return false; }
    return true;
}

function molavi_owner_review_outbox_rest_receipt($request) {
    $input = is_object($request) && method_exists($request, 'get_json_params')
        ? $request->get_json_params() : null;
    if (!is_array($input) || !molavi_owner_review_outbox_store_receipt($input)) {
        return new WP_Error('molavi_outbox_receipt_rejected', 'رسید صف پذیرفته نشد.', ['status'=>409]);
    }
    return rest_ensure_response(['ok'=>true,'command_id'=>(string)$input['command_id'],
        'state'=>(string)$input['state']]);
}

add_action('rest_api_init', static function (): void {
    $pendingRoute = [
        'methods'=>'GET','permission_callback'=>'molavi_owner_review_outbox_service_permission',
        'callback'=>'molavi_owner_review_outbox_rest_pending',
    ];
    $receiptRoute = [
        'methods'=>'POST','permission_callback'=>'molavi_owner_review_outbox_service_permission',
        'callback'=>'molavi_owner_review_outbox_rest_receipt',
    ];
    register_rest_route('molavi/v1', '/outbox/commands', $pendingRoute);
    register_rest_route('molavi/v1', '/outbox/commands/receipt', $receiptRoute);
    // Keep the first production endpoint stable while the Railway rollout is updated.
    register_rest_route('molavi/v1', '/outbox/sheet-conflicts', $pendingRoute);
    register_rest_route('molavi/v1', '/outbox/sheet-conflicts/receipt', $receiptRoute);
});

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
    $commands = [];
    $decisionsTable = molavi_workflow_decisions_table();
    $outboxTable = molavi_workflow_outbox_table();
    foreach ($records as $record) {
        if (!is_array($record) || !in_array(($record['review_type'] ?? ''), ['sheet_conflict','supplier_price'], true)
            || !in_array(($record['state'] ?? ''), ['pending','accepted','failed'], true)) { continue; }
        $eventId = (string)($record['event_id'] ?? '');
        if ($eventId === '') { continue; }
        $commandId = $wpdb->get_var($wpdb->prepare(
            "SELECT o.command_id FROM {$outboxTable} o INNER JOIN {$decisionsTable} d ON d.decision_id=o.decision_id
             WHERE d.event_id=%s LIMIT 1", $eventId));
        if (is_string($commandId) && preg_match('/^[a-f0-9]{64}$/', $commandId)) {
            $commands[$eventId] = $commandId;
        }
    }
    wp_send_json_success(['stored'=>count($records),'commands'=>$commands]);
}
add_action('wp_ajax_molavi_owner_review_store', 'molavi_owner_review_store_ajax');

function molavi_owner_review_outbox_pending_ajax(): void {
    if (!current_user_can('manage_woocommerce') || !function_exists('molavi_reference_owner')
        || !molavi_reference_owner()) {
        wp_send_json_error(['code'=>'owner_required'], 403);
    }
    check_ajax_referer('molavi_owner_review_store', 'nonce');
    $commands = molavi_owner_review_store_pending_commands();
    if (!is_array($commands)) { wp_send_json_error(['code'=>'review_store_failed'], 503); }
    wp_send_json_success(['commands'=>$commands]);
}
add_action('wp_ajax_molavi_owner_review_outbox_pending', 'molavi_owner_review_outbox_pending_ajax');
