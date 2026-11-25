<?php
/** Plugin Name: Molavi Native Supplier Ingestion (Slice 3) */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Slice 3 — message intake in WordPress: the Telegram + Bale webhook
 * receivers, the inbound classifier, the durable inbox queue and the
 * batcher. Ports app.py handle_inbound_update / inbound_payload /
 * extract_direct_data / extract_forward_data / extract_bale_forward_data /
 * stamp_inbound (catalog/media.py) and Handoffs.receive / .batch
 * (catalog/durable.py) with byte-identical receipt keys, so a message
 * received by Railway and re-received by WordPress dedupes to one inbox
 * row: the receipt key is sha256 of the canonical (sorted, spaces)
 * JSON of the source identity, exactly as durable.encode does it.
 *
 * What stays on Railway (plan section 8 + the transport rule):
 * - Operator bot commands (/store, /orders, /products, /help), inline
 *   button callbacks and price-prompt replies. WordPress RELAYS those
 *   updates to Railway's webhook untouched (same secret header) and
 *   returns Railway's answer verbatim; a relay failure is a 503 so
 *   Telegram retries the delivery instead of the update being dropped.
 * - Notice delivery: WordPress queues 'notice' jobs (same receipt-key
 *   scheme as Railway's fixed notice(), content digest included) and
 *   Railway's worker sends them — Railway claims every kind except
 *   the wordpress-hosted ones, so notices keep flowing with no bot
 *   token in WordPress.
 * - The model call, as ever (Slice 2 transport).
 *
 * Secrets (OWNER ACTION — not in this file, never logged): the bot
 * secrets live only in Railway today and are NOT in WordPress config.
 * Until the owner provisions them as wp-config.php / runtime-config.php
 * constants — TELEGRAM_WEBHOOK_SECRET, BALE_WEBHOOK_SECRET,
 * TELEGRAM_BOT_TOKEN, BALE_BOT_TOKEN, TELEGRAM_OPERATOR_CHAT_IDS,
 * TELEGRAM_ADDITIONAL_OPERATOR_CHAT_IDS, BALE_OPERATOR_CHAT_IDS —
 * every webhook call fails closed with 401, receipts cannot send and
 * the webhook cannot be re-pointed. The receivers are still fully
 * testable below that gate (classification, queue, batching).
 *
 * Flip (only once the secrets exist): call
 * molavi_domain_ingest_register_webhook('telegram') — setWebhook to the
 * WP REST URL — and set MOLAVI_WEBHOOK_OWNER=wordpress on Railway, so
 * Railway's ensure_telegram_webhook() stops re-pointing Telegram at
 * itself on every wake (it runs on every Railway request, so without
 * that guard the flip would silently revert). Bale has no setWebhook
 * call in code; its registration is an owner action. Drain the Railway
 * queue before flipping: Telegram allows one webhook URL.
 *
 * Behaviour change in this slice: the receivers exist and the tick
 * batches unbatched inbox rows when the batch executor is wordpress.
 * No webhook URL is re-pointed here and no secret is read from
 * anywhere but the constants above.
 */

/** Bot secrets + operator allowlists from constants only. Missing values read as '' / []. Never logged. */
function molavi_domain_ingest_secrets(): array {
    $names = ['telegram_secret' => 'TELEGRAM_WEBHOOK_SECRET', 'bale_secret' => 'BALE_WEBHOOK_SECRET',
        'telegram_token' => 'TELEGRAM_BOT_TOKEN', 'bale_token' => 'BALE_BOT_TOKEN'];
    $out = [];
    foreach ($names as $key => $const) {
        $value = defined($const) ? (string) constant($const) : '';
        $out[$key] = $value;
    }
    $lists = [];
    foreach (['telegram_operators' => ['TELEGRAM_OPERATOR_CHAT_IDS', 'TELEGRAM_ADDITIONAL_OPERATOR_CHAT_IDS'],
        'bale_operators' => ['BALE_OPERATOR_CHAT_IDS']] as $key => $consts) {
        $ids = [];
        foreach ($consts as $const) {
            if (!defined($const)) continue;
            foreach (explode(',', (string) constant($const)) as $part) {
                $part = trim($part);
                if ($part !== '') $ids[] = $part;
            }
        }
        $lists[$key] = array_values(array_unique($ids));
    }
    return $out + $lists;
}

/** Owner-approved Telegram operator chat ids (the enrollment table), as strings. */
function molavi_domain_ingest_approved_telegram_ids(): array {
    try {
        return molavi_domain_read('pipeline', static fn(SQLite3 $db) => array_map(
            static fn($row) => (string) $row['chat_id'],
            molavi_domain_rows($db, "SELECT chat_id FROM pending_telegram_chats WHERE state='approved' ORDER BY chat_id")));
    } catch (Throwable $e) {
        return [];
    }
}

function molavi_domain_ingest_secret_ok(string $channel, string $sent): bool {
    $secrets = molavi_domain_ingest_secrets();
    $expected = $channel === 'bale' ? $secrets['bale_secret'] : $secrets['telegram_secret'];
    return $expected !== '' && hash_equals($expected, $sent);
}

/* ------------------------------------------------------------------ */
/* Extractors — app.py inbound_payload family, ported verbatim.        */
/* ------------------------------------------------------------------ */

function molavi_domain_ingest_media_type(array $message): ?string {
    foreach (['photo' => 'photo', 'document' => 'document', 'video' => 'video', 'audio' => 'audio', 'voice' => 'voice'] as $key => $type)
        if (!empty($message[$key])) return $type;
    return null;
}

function molavi_domain_ingest_safe_file_id($value): string {
    $text = (string) ($value ?? '');
    return preg_match('/^[A-Za-z0-9_-]{8,300}$/D', $text) ? $text : '';
}

/** catalog/media.py largest_photo: biggest photo, ties keep the later one; image documents fall back. */
function molavi_domain_ingest_largest_photo(array $message): ?array {
    $best = null;
    $bestSize = -1;
    foreach (is_array($message['photo'] ?? null) ? $message['photo'] : [] as $photo) {
        if (!is_array($photo) || empty($photo['file_id'])) continue;
        $size = (int) ($photo['file_size'] ?? 0);
        if ($size === 0) $size = (int) ($photo['width'] ?? 0) * (int) ($photo['height'] ?? 0);
        if ($size >= $bestSize) {
            $best = $photo;
            $bestSize = $size;
        }
    }
    if ($best !== null) return ['file_id' => (string) ($best['file_id'] ?? ''), 'file_unique_id' => (string) ($best['file_unique_id'] ?? '')];
    $document = is_array($message['document'] ?? null) ? $message['document'] : [];
    if (str_starts_with((string) ($document['mime_type'] ?? ''), 'image/') && !empty($document['file_id']))
        return ['file_id' => (string) $document['file_id'], 'file_unique_id' => (string) ($document['file_unique_id'] ?? '')];
    return null;
}

/** catalog/media.py stamp_inbound: copy the post photo and the chat kind onto the sheet payload. */
function molavi_domain_ingest_stamp(array &$data, array $message): void {
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    if ($chat) {
        $type = (string) ($chat['type'] ?? '');
        $data['chat_type'] = $type !== '' ? $type : ((string) ($data['chat_type'] ?? '') !== '' ? (string) $data['chat_type'] : '');
        if (empty($data['chat_id'])) $data['chat_id'] = $chat['id'] ?? null;
    }
    if (!empty($message['media_group_id'])) $data['media_group_id'] = (string) $message['media_group_id'];
    $photo = molavi_domain_ingest_largest_photo($message);
    if ($photo !== null && molavi_domain_ingest_safe_file_id($photo['file_id']) !== '') {
        $data['image_file_id'] = $photo['file_id'];
        $data['image_file_unique_id'] = $photo['file_unique_id'];
        $data['has_media'] = true;
        if (empty($data['media_type'])) $data['media_type'] = 'photo';
    }
}

function molavi_domain_ingest_text(array $message): string {
    $text = (string) ($message['text'] ?? '');
    if ($text === '') $text = (string) ($message['caption'] ?? '');
    return $text;
}

function molavi_domain_ingest_direct(array $message, string $channel): array {
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $isChannelPost = (string) ($chat['type'] ?? '') === 'channel';
    $channelUsername = trim((string) ($chat['username'] ?? ''));
    $postId = (string) ($message['message_id'] ?? '');
    $sourceCreatedAt = $isChannelPost ? ($message['date'] ?? '') : '';
    $sourceUpdateAt = $isChannelPost ? ($message['edit_date'] ?? '') : '';
    // Python builds a t.me URL for any channel post, Bale included; ported as-is.
    $sourceUrl = ($isChannelPost && $channelUsername !== '' && $postId !== '') ? 'https://t.me/' . $channelUsername . '/' . $postId : '';
    $author = $isChannelPost
        ? ((string) ($chat['title'] ?? '') !== '' ? (string) $chat['title'] : ($channelUsername !== '' ? $channelUsername : 'Channel'))
        : ((string) ((is_array($message['from'] ?? null) ? $message['from'] : [])['username'] ?? '') !== '' ? (string) $message['from']['username'] : 'User');
    $fromUsername = (string) ((is_array($message['from'] ?? null) ? $message['from'] : [])['username'] ?? '');
    return [
        'id' => $postId,
        'content' => molavi_domain_ingest_text($message),
        'has_media' => !empty($message['photo']) || !empty($message['document']) || !empty($message['video']),
        'media_type' => molavi_domain_ingest_media_type($message),
        'forwarded_by' => $fromUsername !== '' ? $fromUsername : $author,
        'forwarded_at' => $message['date'] ?? '',
        'channel' => $channel,
        'channel_username' => $isChannelPost ? ($channelUsername !== '' ? '@' . $channelUsername : ((string) ($chat['title'] ?? '') !== '' ? (string) $chat['title'] : 'Channel')) : 'DirectMessage',
        'author' => $author,
        'timestamp' => $isChannelPost ? ($sourceUpdateAt !== '' ? $sourceUpdateAt : $sourceCreatedAt) : ($message['date'] ?? null),
        'source_created_at' => $sourceCreatedAt,
        'source_update_at' => $sourceUpdateAt,
        'url' => $sourceUrl,
        'chat_id' => $chat['id'] ?? null,
        'chat_type' => (string) ($chat['type'] ?? ''),
    ];
}

function molavi_domain_ingest_forward(array $message): array {
    $origin = is_array($message['forward_origin'] ?? null) ? $message['forward_origin'] : [];
    $fromUsername = (string) ((is_array($message['from'] ?? null) ? $message['from'] : [])['username'] ?? '');
    $data = [
        'id' => (string) ($message['message_id'] ?? ''),
        'content' => molavi_domain_ingest_text($message),
        'has_media' => !empty($message['photo']) || !empty($message['document']) || !empty($message['video']),
        'media_type' => molavi_domain_ingest_media_type($message),
        'forwarded_by' => $fromUsername !== '' ? $fromUsername : 'Unknown',
        'forwarded_at' => $message['date'] ?? '',
        'channel' => 'telegram',
        'channel_username' => 'Unknown',
        'author' => 'Unknown',
        'timestamp' => null,
        'source_created_at' => null,
        'source_update_at' => null,
        'url' => '',
        'chat_id' => (is_array($message['chat'] ?? null) ? $message['chat'] : [])['id'] ?? null,
    ];
    $type = (string) ($origin['type'] ?? '');
    if ($type === 'channel') {
        $chat = is_array($origin['chat'] ?? null) ? $origin['chat'] : [];
        $username = (string) ($chat['username'] ?? '');
        $data['channel_username'] = $username !== '' ? '@' . $username : ((string) ($chat['title'] ?? '') !== '' ? (string) $chat['title'] : 'Channel');
        $data['author'] = (string) ($chat['title'] ?? '') !== '' ? (string) $chat['title'] : 'Channel';
        $data['timestamp'] = $origin['date'] ?? '';
        $data['source_created_at'] = $origin['date'] ?? '';
        if ($username !== '') $data['url'] = 'https://t.me/' . $username . '/' . ($origin['message_id'] ?? '');
    } elseif ($type === 'user') {
        $sender = is_array($origin['sender_user'] ?? null) ? $origin['sender_user'] : [];
        $name = trim(((string) ($sender['first_name'] ?? '')) . ' ' . ((string) ($sender['last_name'] ?? '')));
        $data['author'] = $name !== '' ? $name : ((string) ($sender['username'] ?? '') !== '' ? (string) $sender['username'] : 'User');
        $data['timestamp'] = $origin['date'] ?? '';
    }
    return $data;
}

function molavi_domain_ingest_bale_forward(array $message): array {
    $forwardChat = is_array($message['forward_from_chat'] ?? null) ? $message['forward_from_chat'] : [];
    $forwardFrom = is_array($message['forward_from'] ?? null) ? $message['forward_from'] : [];
    $fromUsername = (string) ((is_array($message['from'] ?? null) ? $message['from'] : [])['username'] ?? '');
    $data = [
        'id' => (string) ($message['message_id'] ?? ''),
        'content' => molavi_domain_ingest_text($message),
        'has_media' => !empty($message['photo']) || !empty($message['document']) || !empty($message['video']),
        'media_type' => molavi_domain_ingest_media_type($message),
        'forwarded_by' => $fromUsername !== '' ? $fromUsername : 'Unknown',
        'forwarded_at' => $message['date'] ?? '',
        'channel' => 'bale',
        'channel_username' => 'Unknown',
        'author' => 'Unknown',
        'timestamp' => null,
        'url' => '',
        'chat_id' => (is_array($message['chat'] ?? null) ? $message['chat'] : [])['id'] ?? null,
    ];
    if ($forwardChat) {
        $username = (string) ($forwardChat['username'] ?? '');
        $data['channel_username'] = $username !== '' ? '@' . $username : ((string) ($forwardChat['title'] ?? '') !== '' ? (string) $forwardChat['title'] : 'Channel');
        $data['author'] = (string) ($forwardChat['title'] ?? '') !== '' ? (string) $forwardChat['title'] : 'Channel';
        $data['timestamp'] = $message['date'] ?? null;
        if ($username !== '') $data['url'] = 'https://bale.ai/' . $username . '/' . ($message['message_id'] ?? '');
    } elseif ($forwardFrom) {
        $name = trim(((string) ($forwardFrom['first_name'] ?? '')) . ' ' . ((string) ($forwardFrom['last_name'] ?? '')));
        $data['author'] = $name !== '' ? $name : ((string) ($forwardFrom['username'] ?? '') !== '' ? (string) $forwardFrom['username'] : 'User');
        $data['timestamp'] = $message['date'] ?? null;
    }
    return $data;
}

/** app.py inbound_payload: build the sheet payload for one Telegram or Bale update. Returns [data|null, source]. */
function molavi_domain_ingest_payload(array $message, string $channel): array {
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    if ($channel === 'telegram' && (string) ($chat['type'] ?? '') === 'channel') {
        $data = molavi_domain_ingest_direct($message, $channel);
        $source = 'channel';
    } elseif ($channel === 'telegram' && array_key_exists('forward_origin', $message)) {
        $data = molavi_domain_ingest_forward($message);
        $source = 'forward';
    } elseif ($channel === 'bale' && (array_key_exists('forward_from_chat', $message) || array_key_exists('forward_from', $message))) {
        $data = molavi_domain_ingest_bale_forward($message);
        $source = 'forward';
    } elseif ((string) ($message['text'] ?? '') !== '' || (string) ($message['caption'] ?? '') !== '' || molavi_domain_ingest_media_type($message) !== null) {
        $data = molavi_domain_ingest_direct($message, $channel);
        $source = 'direct';
    } else {
        return [null, 'ignored'];
    }
    // A private message proves when the owner sent it, not when the supplier
    // updated its offer — same distinction as Railway, so owner-submitted
    // text cannot silently reset supplier-price freshness.
    if ($source === 'channel') {
        $data['source_timestamp_basis'] = 'supplier_channel_update';
    } elseif ($source === 'forward' && $channel === 'telegram') {
        $origin = is_array($message['forward_origin'] ?? null) ? $message['forward_origin'] : [];
        $data['source_timestamp_basis'] = (string) ($origin['type'] ?? '') === 'channel' ? 'supplier_channel_forward' : 'owner_forward';
    } elseif ($source === 'forward') {
        $data['source_timestamp_basis'] = 'forward_receipt';
    } elseif (molavi_domain_ingest_private($data)) {
        $data['source_timestamp_basis'] = 'owner_submission';
    } else {
        $data['source_timestamp_basis'] = 'unverified_direct';
    }
    if (empty($data['chat_id'])) $data['chat_id'] = $chat['id'] ?? null;
    molavi_domain_ingest_stamp($data, $message);
    return [$data, $source];
}

/** catalog/status.py private_chat: a private chat is anything but a channel/group with an id. */
function molavi_domain_ingest_private(array $data): bool {
    $kind = (string) ($data['chat_type'] ?? '');
    return !in_array($kind, ['channel', 'group', 'supergroup'], true) && !empty($data['chat_id']);
}

/** catalog/status.py _fold: strip ZWNJ (char and literal text), fold Arabic letters. */
function molavi_domain_ingest_fold(string $text): string {
    return trim(str_replace(['\\u200c', 'ي', 'ك', "\u{200C}"], ['', 'ی', 'ک', ''], $text));
}

/** catalog/status.py command_name: a store command, or null when the text is a supplier post. */
function molavi_domain_ingest_command(string $text): ?string {
    $commands = ['/store' => 'store', '/status' => 'store', '/shop' => 'store', '/orders' => 'orders', '/order' => 'orders',
        '/products' => 'products', '/product' => 'products', '/start' => 'help', '/help' => 'help',
        '/فروشگاه' => 'store', '/سفارش' => 'orders', '/سفارشها' => 'orders', '/کالا' => 'products', '/کالاها' => 'products'];
    $phrases = ['وضعیت فروشگاه' => 'store', 'وضعیت سفارش' => 'orders', 'وضعیت سفارشها' => 'orders',
        'وضعیت کالا' => 'products', 'وضعیت کالاها' => 'products'];
    $raw = trim($text);
    if ($raw === '') return null;
    if (str_starts_with($raw, '/')) {
        $token = function_exists('mb_strtolower') ? mb_strtolower(strtok($raw, " \t\n\r\0\x0B"), 'UTF-8') : strtolower(strtok($raw, " \t\n\r\0\x0B"));
        $token = explode('@', $token, 2)[0];
        $token = molavi_domain_ingest_fold($token);
        return $commands[$token] ?? $commands[str_replace("\u{200C}", '', $token)] ?? null;
    }
    return $phrases[molavi_domain_ingest_fold($raw)] ?? null;
}

/** catalog/sync.py parse_prompt_answer (boolean form): accept, skip, or a unit price. */
function molavi_domain_ingest_prompt_answer(string $text): bool {
    $accept = ['ok', 'okay', 'yes', 'y', 'باشه', 'بله', 'درسته', 'درست', 'تایید', 'تأیید', 'قبول'];
    $skip = ['skip', 'no', 'n', 'رد', 'نه', 'بیخیال', 'لغو'];
    $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', molavi_domain_digits($text));
    $cleaned = trim((string) preg_replace('/\s+/u', ' ', (string) $cleaned));
    $cleaned = function_exists('mb_strtolower') ? mb_strtolower($cleaned, 'UTF-8') : strtolower($cleaned);
    if (in_array($cleaned, $accept, true) || in_array($cleaned, $skip, true)) return true;
    $without = trim((string) preg_replace('/(تومان|تومن|ریال|\bت\b|toman|irr|irt)/iu', ' ', $cleaned));
    if (!preg_match('~^[\d\s,./٫\x{066B}]+$~u', $without)) return false;
    $price = (int) preg_replace('/\D/', '', str_replace(['/', ',', '٫', "\u{066B}", '.'], '', molavi_domain_digits($without)));
    return $price > 0;
}

/* ------------------------------------------------------------------ */
/* Durable queue — catalog/durable.py receive / batch, ported.         */
/* ------------------------------------------------------------------ */

/** Handoffs.receive: idempotent inbox receipt, keyed by the source identity hash. Returns [key, inserted]. */
function molavi_domain_ingest_receive(array $message): array {
    $identity = [
        'channel' => $message['channel'] ?? null,
        'channel_username' => $message['channel_username'] ?? null,
        'id' => $message['id'] ?? null,
        'content' => $message['content'] ?? null,
        'image_file_id' => $message['image_file_id'] ?? null,
        'media_group_id' => $message['media_group_id'] ?? null,
        'source_update_at' => $message['source_update_at'] ?? null,
    ];
    if (!empty($message['url'])) $identity['id'] = $message['url'];
    if (empty($identity['id']) || in_array($identity['channel_username'], [null, '', 'DirectMessage'], true))
        $identity['chat_id'] = $message['chat_id'] ?? null;
    if (empty($identity['id'])) molavi_domain_fail('ingest_identity_required', 400);
    $key = hash('sha256', molavi_domain_json($identity, true));
    $encoded = molavi_domain_json($message, true);
    $inserted = molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($key, $encoded): bool {
        $row = molavi_domain_sql($db, 'INSERT OR IGNORE INTO inbox(id,payload,batch_id,received_at) VALUES (?,?,NULL,?)',
            [$key, $encoded, microtime(true)]);
        return $row['changes'] === 1;
    });
    return ['key' => $key, 'inserted' => $inserted];
}

/** Handoffs.record_pending_telegram_chat: keep only the identity/timing of an unapproved private sender. */
function molavi_domain_ingest_record_pending($chatId): bool {
    $value = trim((string) ($chatId ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/D', $value)) return false;
    $stamp = microtime(true);
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($value, $stamp): void {
        molavi_domain_sql($db, "DELETE FROM pending_telegram_chats WHERE state!='approved' AND last_seen_at<?", [$stamp - 30 * 86400]);
        molavi_domain_sql($db, "INSERT INTO pending_telegram_chats (chat_id,first_seen_at,last_seen_at,seen_count,revision,state) VALUES (?,?,?,?,1,'pending')
            ON CONFLICT(chat_id) DO UPDATE SET last_seen_at=excluded.last_seen_at,
                seen_count=pending_telegram_chats.seen_count+1, revision=pending_telegram_chats.revision+1
            WHERE pending_telegram_chats.state='pending'", [$value, $stamp, $stamp, 1]);
        molavi_domain_sql($db, 'DELETE FROM pending_telegram_chats WHERE chat_id IN (
            SELECT chat_id FROM pending_telegram_chats WHERE state=\'pending\' ORDER BY last_seen_at DESC,chat_id DESC LIMIT -1 OFFSET 500)');
    });
    return true;
}

/** Handoffs.batch: group unbatched inbox rows (100 max, 5 s quiet floor) into one batch job. Null when nothing is due. */
function molavi_domain_batch_inbox(int $limit = 100): ?string {
    if (molavi_domain_job_host('batch') !== 'wordpress') return null;
    $limit = max(1, min(100, $limit));
    return molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($limit): ?string {
        $rows = molavi_domain_rows($db, 'SELECT id,payload FROM inbox WHERE batch_id IS NULL AND received_at<=? ORDER BY rowid LIMIT ?',
            [microtime(true) - 5.0, $limit]);
        if (!$rows) return null;
        $batchId = bin2hex(random_bytes(16));
        $jobId = 'batch:' . $batchId;
        $messages = [];
        foreach ($rows as $row) $messages[] = molavi_domain_decode($row['payload']);
        $payload = molavi_domain_json(['batch_id' => $batchId, 'messages' => $messages,
            'expected_count' => count($rows), 'transmission_complete' => true, 'mode' => 'batch_ingest'], true);
        molavi_domain_sql($db, "INSERT INTO jobs (id,kind,payload,state,available) VALUES (?,?,?,'queued',?)",
            [$jobId, 'batch', $payload, microtime(true)]);
        foreach ($rows as $row) molavi_domain_sql($db, 'UPDATE inbox SET batch_id=? WHERE id=?', [$jobId, $row['id']]);
        return $jobId;
    });
}

/* ------------------------------------------------------------------ */
/* Outbound: relay to Railway, receipts, webhook registration.         */
/* The staging safety plugin blocks all outbound HTTP except to the   */
/* site itself; each call below carries its own scoped exception,     */
/* added at call time and removed right after, exactly like the        */
/* verdict/extract client in molavi-domain-ai.php. Never log a URL     */
/* that carries a token.                                               */
/* ------------------------------------------------------------------ */

function molavi_domain_ingest_http_exception(array $allowed): callable {
    return static function ($pre, array $args, string $url) use ($allowed) {
        if (!is_wp_error($pre) || $pre->get_error_code() !== 'molavi_staging_external_blocked') return $pre;
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $method = strtoupper((string) ($args['method'] ?? ''));
        if (strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) !== 'staging.molavisale.ir') return $pre;
        foreach ($allowed as $rule) {
            if ($scheme !== 'https' || $method !== $rule['method'] || $host !== $rule['host']) continue;
            if (isset($rule['path']) && $path !== $rule['path']) continue;
            if (isset($rule['path_pattern']) && !preg_match($rule['path_pattern'], $path)) continue;
            return false;
        }
        return $pre;
    };
}

/** Relay one raw update to Railway's webhook untouched (bot commands, callbacks, price replies stay on Railway). */
function molavi_domain_ingest_relay(array $update, string $channel, string $rawBody = ''): array {
    $secrets = molavi_domain_ingest_secrets();
    $secret = $channel === 'bale' ? $secrets['bale_secret'] : $secrets['telegram_secret'];
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? (string) MOLAVI_REFERENCE_SERVER_URL : '';
    if ($secret === '' || $base === '' || wp_parse_url($base, PHP_URL_SCHEME) !== 'https')
        return ['ok' => false, 'error' => 'relay_unavailable'];
    $path = $channel === 'bale' ? '/bale/webhook' : '/webhook';
    $host = strtolower((string) wp_parse_url($base, PHP_URL_HOST));
    $exception = molavi_domain_ingest_http_exception([['host' => $host, 'path' => $path, 'method' => 'POST']]);
    add_filter('pre_http_request', $exception, PHP_INT_MAX, 3);
    try {
        $response = wp_safe_remote_post(rtrim($base, '/') . $path, [
            'timeout' => 60, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json',
                $channel === 'bale' ? 'X-Bale-Webhook-Secret' : 'X-Telegram-Bot-Api-Secret-Token' => $secret],
            'body' => $rawBody !== '' ? $rawBody : (string) wp_json_encode($update),
        ]);
    } finally {
        remove_filter('pre_http_request', $exception, PHP_INT_MAX);
    }
    if (is_wp_error($response)) return ['ok' => false, 'error' => 'relay_unavailable'];
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    return ['ok' => true, 'status' => (int) wp_remote_retrieve_response_code($response),
        'body' => is_array($body) ? $body : ['status' => 'relay_unparsable']];
}

/** app.py send_chat_text (receipt only, plain text): Telegram or Bale sendMessage. Best-effort; never throws. */
function molavi_domain_ingest_send_text($chatId, string $text, string $channel): bool {
    if (empty($chatId) || $text === '') return false;
    $secrets = molavi_domain_ingest_secrets();
    $token = $channel === 'bale' ? $secrets['bale_token'] : $secrets['telegram_token'];
    if ($token === '') {
        error_log('[molavi-ingest] receipt skipped: bot token not configured');
        return false;
    }
    $host = $channel === 'bale' ? 'tapi.bale.ai' : 'api.telegram.org';
    $exception = molavi_domain_ingest_http_exception([['host' => $host, 'path_pattern' => '~^/bot[^/]+/sendMessage$~', 'method' => 'POST']]);
    add_filter('pre_http_request', $exception, PHP_INT_MAX, 3);
    try {
        $response = wp_safe_remote_post('https://' . $host . '/bot' . $token . '/sendMessage', [
            'timeout' => 10, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode(['chat_id' => $chatId, 'text' => $text]),
        ]);
    } finally {
        remove_filter('pre_http_request', $exception, PHP_INT_MAX);
    }
    if (is_wp_error($response)) return false;
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    return (int) wp_remote_retrieve_response_code($response) === 200 && is_array($data) && ($data['ok'] ?? false) === true;
}

/** Point Telegram at the WordPress receiver. Call once, at flip time, after the secrets exist. Bale has no code path; owner action. */
function molavi_domain_ingest_register_webhook(string $channel): array {
    if ($channel !== 'telegram') return ['ok' => false, 'error' => 'bale_webhook_registration_unknown'];
    $secrets = molavi_domain_ingest_secrets();
    if ($secrets['telegram_token'] === '' || $secrets['telegram_secret'] === '')
        return ['ok' => false, 'error' => 'ingest_secrets_unconfigured'];
    $url = home_url('/wp-json/molavi-ingest/v1/telegram');
    $exception = molavi_domain_ingest_http_exception([['host' => 'api.telegram.org', 'path_pattern' => '~^/bot[^/]+/setWebhook$~', 'method' => 'POST']]);
    add_filter('pre_http_request', $exception, PHP_INT_MAX, 3);
    try {
        $response = wp_safe_remote_post('https://api.telegram.org/bot' . $secrets['telegram_token'] . '/setWebhook', [
            'timeout' => 15, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode(['url' => $url,
                'allowed_updates' => ['message', 'edited_message', 'channel_post', 'edited_channel_post', 'callback_query'],
                'drop_pending_updates' => false, 'secret_token' => $secrets['telegram_secret']]),
        ]);
    } finally {
        remove_filter('pre_http_request', $exception, PHP_INT_MAX);
    }
    if (is_wp_error($response)) return ['ok' => false, 'error' => 'webhook_registration_failed'];
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data) || ($data['ok'] ?? false) !== true)
        return ['ok' => false, 'error' => 'webhook_registration_failed', 'description' => (string) ($data['description'] ?? '')];
    return ['ok' => true, 'url' => $url, 'description' => (string) ($data['description'] ?? '')];
}

/* ------------------------------------------------------------------ */
/* Batch notices — app.py run_durable_handoff finalize, ported.       */
/* After a WordPress batch succeeds, one notice job per supplier chat  */
/* (Railway's worker delivers them; WordPress never holds the token).  */
/* Receipt keys mirror Railway's fixed scheme exactly — batch:chat:    */
/* channel:sha256(payload)[:16] — so a WordPress-queued notice and a   */
/* Railway-queued one for the same inputs share one receipt.           */
/* ------------------------------------------------------------------ */

function molavi_domain_ingest_format_batch_reply(array $data): string {
    if (!empty($data['rollback'])) return 'این دسته ذخیره نشد. قیمت قبلی سر جایش ماند.';
    if (($data['status'] ?? '') === 'error') return 'این دسته ذخیره نشد.';
    $lines = [];
    foreach (array_slice(is_array($data['saved'] ?? null) ? $data['saved'] : [], 0, 20) as $item) {
        if (!$lines) $lines[] = 'ثبت شد:';
        $lines[] = (string) ($item['name'] ?? '') . ' — ' . ((string) ($item['sale'] ?? '') !== '' ? (string) $item['sale'] : 'بدون قیمت واحد');
    }
    $prompts = is_array($data['prompts'] ?? null) ? $data['prompts'] : [];
    if ($prompts) {
        if ($lines) $lines[] = '';
        $lines[] = (string) ($prompts[0]['question'] ?? '');
    }
    if (!$lines) {
        $found = (int) ($data['products_found'] ?? 0);
        if ($found > 0) return $found . ' پیشنهاد از پیام استخراج شد. وضعیت قیمت، بررسی و انتشار را در داشبورد ببینید.';
        return 'پردازش تمام شد؛ پیشنهادی برای ثبت پیدا نشد.';
    }
    return implode("\n", $lines);
}

/** Queue one notice job per supplier chat for a successful batch. Returns the count queued. */
function molavi_domain_ingest_queue_batch_notices(array $payload, array $result): int {
    if (($result['status'] ?? '') !== 'success') return 0;
    $batchId = (string) ($payload['batch_id'] ?? '');
    if ($batchId === '') return 0;
    $targets = [];
    foreach (is_array($payload['messages'] ?? null) ? $payload['messages'] : [] as $message) {
        if (!is_array($message) || empty($message['chat_id'])) continue;
        $key = ((string) ($message['channel'] ?? '') !== '' ? (string) $message['channel'] : 'telegram') . ':' . (string) $message['chat_id'];
        $targets[$key] = ['chat_id' => $message['chat_id'], 'channel' => (string) ($message['channel'] ?? '') !== '' ? (string) $message['channel'] : 'telegram'];
    }
    $queued = 0;
    $senders = is_array($result['products_by_sender'] ?? null) ? $result['products_by_sender'] : [];
    foreach ($targets as $sender => $target) {
        $text = molavi_domain_ingest_format_batch_reply($result + ['prompts' => [], 'products_found' => (int) ($senders[$sender] ?? 0)]);
        $notice = ['chat_id' => $target['chat_id'], 'text' => $text, 'channel' => $target['channel']];
        $digest = substr(hash('sha256', molavi_domain_json($notice, true)), 0, 16);
        try {
            molavi_domain_queue('notice', $batchId . ':' . (string) $target['chat_id'] . ':' . $target['channel'] . ':' . $digest, $notice);
            $queued++;
        } catch (MolaviWorkflowError $e) {
            error_log('[molavi-ingest] batch notice already queued');
        }
    }
    return $queued;
}

/* ------------------------------------------------------------------ */
/* Entry point — app.py handle_inbound_update, ported.                 */
/* ------------------------------------------------------------------ */

/** Classify + queue one update. Returns ['status','http',...] with Railway's status strings. */
function molavi_domain_ingest_update(array $update, string $channel, string $rawBody = ''): array {
    if ($channel === 'telegram' && is_array($update['callback_query'] ?? null))
        return molavi_domain_ingest_relay_result($update, $channel, $rawBody);
    $message = null;
    foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
        if (!empty($update[$key]) && is_array($update[$key])) {
            $message = $update[$key];
            break;
        }
    }
    if ($message === null) return ['status' => 'ignored', 'http' => 200];
    [$data, $source] = molavi_domain_ingest_payload($message, $channel);
    if ($data === null) return ['status' => 'ignored', 'http' => 200];
    $chatType = (string) ($data['chat_type'] ?? '');
    if (in_array($chatType, ['group', 'supergroup'], true)) return ['status' => 'ignored', 'http' => 200];
    if (molavi_domain_ingest_private($data)) {
        $secrets = molavi_domain_ingest_secrets();
        $allowlist = $channel === 'telegram'
            ? array_merge($secrets['telegram_operators'], molavi_domain_ingest_approved_telegram_ids())
            : $secrets['bale_operators'];
        if (!in_array((string) $data['chat_id'], array_map('strval', $allowlist), true)) {
            if ($channel === 'telegram') molavi_domain_ingest_record_pending($data['chat_id']);
            return ['status' => 'ignored', 'http' => 200];
        }
    }
    $command = $source === 'direct' ? molavi_domain_ingest_command((string) ($data['content'] ?? '')) : null;
    if ($command !== null) return molavi_domain_ingest_relay_result($update, $channel, $rawBody);
    $replyCandidate = $source === 'direct' && (!empty($message['reply_to_message'])
        || molavi_domain_ingest_prompt_answer((string) ($data['content'] ?? '')));
    if ($replyCandidate) return molavi_domain_ingest_relay_result($update, $channel, $rawBody);
    try {
        $receipt = molavi_domain_ingest_receive($data);
    } catch (Throwable $e) {
        return ['status' => 'error', 'http' => 503, 'error' => 'ingestion_failed'];
    }
    if ($receipt['inserted'] && molavi_domain_ingest_private($data))
        molavi_domain_ingest_send_text($data['chat_id'], 'پیام دریافت شد و در صف بررسی است.', $channel);
    return ['status' => 'success', 'http' => 200, 'source' => $source, 'inserted' => $receipt['inserted'], 'receipt_id' => $receipt['key']];
}

/** Map a relay outcome onto the webhook answer: Railway's reply verbatim, or 503 so Telegram retries. */
function molavi_domain_ingest_relay_result(array $update, string $channel, string $rawBody): array {
    $relayed = molavi_domain_ingest_relay($update, $channel, $rawBody);
    if (empty($relayed['ok'])) return ['status' => 'relay_unavailable', 'http' => 503, 'error' => 'relay_unavailable'];
    $body = is_array($relayed['body'] ?? null) ? $relayed['body'] : ['status' => 'relay_unparsable'];
    return ['status' => (string) ($body['status'] ?? 'relayed'), 'http' => (int) ($relayed['status'] ?? 200),
        'relayed' => true, 'body' => $body];
}

function molavi_domain_ingest_public_permission(): bool {
    // Authentication is the webhook secret header, checked inside the callback like Railway's routes.
    return true;
}

add_action('rest_api_init', static function (): void {
    foreach (['telegram' => 'X-Telegram-Bot-Api-Secret-Token', 'bale' => 'X-Bale-Webhook-Secret'] as $channel => $header) {
        register_rest_route('molavi-ingest/v1', '/' . $channel, ['methods' => 'POST',
            'permission_callback' => 'molavi_domain_ingest_public_permission',
            'callback' => static function ($request) use ($channel, $header) {
                $sent = (string) $request->get_header($header);
                if (!molavi_domain_ingest_secret_ok($channel, $sent))
                    return new WP_REST_Response(['status' => 'unauthorized'], 401);
                $update = $request->get_json_params();
                if (!is_array($update))
                    return new WP_REST_Response(['status' => 'error', 'message' => 'No JSON data'], 400);
                try {
                    $result = molavi_domain_ingest_update($update, $channel, (string) $request->get_body());
                } catch (Throwable $e) {
                    error_log('[molavi-ingest] webhook failed');
                    return new WP_REST_Response(['status' => 'error'], 500);
                }
                $http = (int) ($result['http'] ?? 200);
                $body = ['status' => (string) ($result['status'] ?? 'error')];
                foreach (['source', 'command', 'error'] as $key)
                    if (isset($result[$key])) $body[$key] = $result[$key];
                return new WP_REST_Response($body, $http);
            }]);
    }
    register_rest_route('molavi-ingest/v1', '/status', ['methods' => 'GET',
        'permission_callback' => 'molavi_runtime_permission',
        'callback' => static function () {
            $secrets = molavi_domain_ingest_secrets();
            $unbatched = 0;
            try {
                $unbatched = (int) (molavi_domain_read('pipeline', static fn(SQLite3 $db) =>
                    molavi_domain_row($db, 'SELECT COUNT(*) c FROM inbox WHERE batch_id IS NULL')['c'] ?? 0));
            } catch (Throwable $e) {
                $unbatched = -1;
            }
            return ['ok' => true,
                'telegram_secret_configured' => $secrets['telegram_secret'] !== '',
                'bale_secret_configured' => $secrets['bale_secret'] !== '',
                'telegram_token_configured' => $secrets['telegram_token'] !== '',
                'bale_token_configured' => $secrets['bale_token'] !== '',
                'telegram_operators' => count($secrets['telegram_operators']) + count(molavi_domain_ingest_approved_telegram_ids()),
                'bale_operators' => count($secrets['bale_operators']),
                'inbox_unbatched' => $unbatched,
                'batch_host' => function_exists('molavi_domain_job_host') ? molavi_domain_job_host('batch') : 'unknown',
                'extraction_host' => function_exists('molavi_domain_extraction_host') ? molavi_domain_extraction_host() : 'unknown'];
        }]);
});
