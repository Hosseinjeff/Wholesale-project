<?php
/** Plugin Name: Molavi AI Client */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * WordPress-side AI client. The model itself runs on Railway: the staging
 * host's egress IP is blocked by Google, so WordPress asks the Railway
 * executor for one judgement per pending decision via POST /pipeline/verdict,
 * and for one raw extraction per supplier message via POST /pipeline/extract.
 * WordPress keeps the rules, the holds, the verdict records, the extraction
 * prompt, the parsing and the post-processing; Railway only runs the model.
 *
 * Only two callers exist today: the autopilot post-decision verdict
 * (molavi_domain_autopilot_run_verdicts) and the extraction batch job
 * (molavi_domain_batch_job). The model verifies evidence or
 * extracts listings; it never decides, never sets a price, never acts.
 */

/** The model call failed in a way the caller must treat as an extraction failure. */
if (!class_exists('MolaviExtractError')) {
    final class MolaviExtractError extends RuntimeException {}
}

/** Railway deferred the model call for quota; the caller must roll back and retry later. */
if (!class_exists('MolaviExtractQuotaDeferred')) {
    final class MolaviExtractQuotaDeferred extends RuntimeException {
        public float $retryAt = 0.0;
    }
}

/**
 * The staging safety MU-plugin blocks all outbound HTTP except to the site
 * itself. This filter allows only this client's POST to the configured
 * bridge host (MOLAVI_REFERENCE_SERVER_URL), mirroring the owner proxy's
 * exception: it is added at call time (so it runs after the safety filter)
 * and removed right after the request.
 */
function molavi_domain_railway_http_exception(string $path): callable {
    return static function($pre, $parsedArgs, $requestUrl) use ($path) {
        if (!is_wp_error($pre) || $pre->get_error_code() !== 'molavi_staging_external_blocked')
            return $pre;
        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $requestHost = strtolower((string) wp_parse_url((string) $requestUrl, PHP_URL_HOST));
        $bridgeHost = strtolower((string) wp_parse_url(defined('MOLAVI_REFERENCE_SERVER_URL') ? (string) MOLAVI_REFERENCE_SERVER_URL : '', PHP_URL_HOST));
        $requestPath = (string) wp_parse_url((string) $requestUrl, PHP_URL_PATH);
        $requestScheme = strtolower((string) wp_parse_url((string) $requestUrl, PHP_URL_SCHEME));
        $requestMethod = strtoupper((string) ($parsedArgs['method'] ?? ''));
        if ($homeHost === 'staging.molavisale.ir'
            && $bridgeHost !== '' && $requestHost === $bridgeHost
            && $requestScheme === 'https'
            && $requestMethod === 'POST'
            && $requestPath === $path)
            return false;
        return $pre;
    };
}

/** Bridge base URL + token, or null when the bridge is not configured. */
function molavi_domain_railway_bridge(): ?array {
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($base) || !is_string($token) || strlen($token) < 32
        || wp_parse_url($base, PHP_URL_SCHEME) !== 'https') return null;
    return [$base, $token];
}

/** Server-to-server actor, mirroring molavi-runtime-host.php: the first administrator. */
function molavi_domain_railway_actor(): string {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
    return 'wp:' . (int) ($admins[0] ?? 1);
}

/**
 * Ask Railway for a structured JSON verdict. Returns the decoded verdict
 * array ['verdict'=>'ok'|'flag','reason'=>string] on success, null on ANY
 * failure (unconfigured bridge, transport error, non-200, invalid JSON,
 * Railway model failure, verdict outside the enum). Never throws, never
 * logs the bridge token.
 */
function molavi_domain_gemini_generate(string $prompt, array $opts = []): ?array {
    try {
        if ($prompt === '') return null;
        $bridge = molavi_domain_railway_bridge();
        if ($bridge === null) return null;
        [$base, $token] = $bridge;
        $payload = ['prompt' => $prompt];
        $model = (string) ($opts['model'] ?? '');
        if ($model !== '' && preg_match('/^[A-Za-z0-9._-]+$/D', $model)) $payload['model'] = $model;
        $body = wp_json_encode($payload);
        if (!is_string($body)) return null;
        $url = rtrim($base, '/') . '/pipeline/verdict';
        $exception = molavi_domain_railway_http_exception('/pipeline/verdict');
        add_filter('pre_http_request', $exception, PHP_INT_MAX, 3);
        try {
            $response = wp_safe_remote_post($url, [
                'timeout' => 45,
                'redirection' => 0,
                'headers' => [
                    'X-Molavi-Reference-Token' => $token,
                    'X-Molavi-Actor' => molavi_domain_railway_actor(),
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]);
        } finally {
            remove_filter('pre_http_request', $exception, PHP_INT_MAX);
        }
        if (is_wp_error($response)) return null;
        if ((int) wp_remote_retrieve_response_code($response) !== 200) return null;
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) return null;
        $verdict = $data['verdict'] ?? null;
        if (!in_array($verdict, ['ok', 'flag'], true)) return null;
        $reason = $data['reason'] ?? '';
        if (!is_string($reason)) $reason = '';
        return ['verdict' => $verdict, 'reason' => $reason];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Ask Railway to fetch a t.me URL and extract the message text
 * (POST /pipeline/telegram-fetch). The staging host cannot reach Telegram
 * at all, so the fetch runs on Railway, which appends ?embed=1 itself
 * for post URLs — callers pass the CLEAN t.me URL, no suffix.
 * Returns ['ok'=>true, 'status'=>int, 'text'=>string|null] when Railway
 * SERVED the fetch (text is null when Railway fetched the page but found
 * no message text), or ['ok'=>false, 'transport'=>true, 'code'=>string]
 * when the FETCH SERVICE itself failed. The split matters: a per-link
 * result — including t.me's own 404, or a 200 with no message text —
 * always arrives inside ok:true, so anything else (bridge unconfigured,
 * WP_Error, a non-200 from Railway, invalid JSON, ok=false, bad shape)
 * is the transport, never the link. Callers must treat a transport
 * failure as a global pause, not as a per-link failure: Railway down
 * must not read as 144 broken links. Never throws, never logs the
 * bridge token.
 */
function molavi_domain_telegram_fetch_detailed(string $url): array {
    try {
        if ($url === '') return ['ok' => false, 'transport' => true, 'code' => 'empty_url'];
        $bridge = molavi_domain_railway_bridge();
        if ($bridge === null) return ['ok' => false, 'transport' => true, 'code' => 'bridge_unconfigured'];
        [$base, $token] = $bridge;
        $body = wp_json_encode(['url' => $url]);
        if (!is_string($body)) return ['ok' => false, 'transport' => true, 'code' => 'bad_request'];
        $requestUrl = rtrim($base, '/') . '/pipeline/telegram-fetch';
        $exception = molavi_domain_railway_http_exception('/pipeline/telegram-fetch');
        add_filter('pre_http_request', $exception, PHP_INT_MAX, 3);
        try {
            $response = wp_safe_remote_post($requestUrl, [
                'timeout' => 45,
                'redirection' => 0,
                'headers' => [
                    'X-Molavi-Reference-Token' => $token,
                    'X-Molavi-Actor' => molavi_domain_railway_actor(),
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]);
        } finally {
            remove_filter('pre_http_request', $exception, PHP_INT_MAX);
        }
        if (is_wp_error($response)) return ['ok' => false, 'transport' => true, 'code' => 'http_error'];
        if ((int) wp_remote_retrieve_response_code($response) !== 200) return ['ok' => false, 'transport' => true, 'code' => 'http_' . (int) wp_remote_retrieve_response_code($response)];
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) return ['ok' => false, 'transport' => true, 'code' => 'bad_response'];
        $status = $data['status'] ?? null;
        if (!is_int($status)) return ['ok' => false, 'transport' => true, 'code' => 'bad_response'];
        $text = $data['text'] ?? null;
        if ($text !== null && !is_string($text)) return ['ok' => false, 'transport' => true, 'code' => 'bad_response'];
        return ['ok' => true, 'transport' => false, 'status' => $status, 'text' => $text];
    } catch (Throwable $e) {
        return ['ok' => false, 'transport' => true, 'code' => 'exception'];
    }
}

/**
 * Ask Railway to fetch a t.me URL and extract the message text
 * (POST /pipeline/telegram-fetch). Thin wrapper over
 * molavi_domain_telegram_fetch_detailed: returns
 * ['status'=>int, 'text'=>string|null] on success (text is null when
 * Railway fetched the page but found no message text), null on ANY
 * failure (unconfigured bridge, transport error, non-200, invalid JSON,
 * ok=false, bad shape). Callers that need to tell a dead fetch service
 * apart from a dead link must use the detailed call instead. Never
 * throws, never logs the bridge token.
 */
function molavi_domain_telegram_fetch(string $url): ?array {
    $detailed = molavi_domain_telegram_fetch_detailed($url);
    if (!($detailed['ok'] ?? false)) return null;
    return ['status' => $detailed['status'], 'text' => $detailed['text']];
}

/**
 * Ask Railway for a raw extraction (POST /pipeline/extract). Prompt in,
 * model JSON text out — no prompt construction, no parsing, no state on
 * Railway. Returns the raw model response text on success.
 *
 * Throws MolaviExtractQuotaDeferred when Railway defers for quota (the
 * caller must roll the batch back and retry later — never a partial write),
 * and MolaviExtractError on any other failure (unconfigured bridge,
 * transport error, non-200, invalid JSON, route error). Never logs the
 * bridge token. Timeout is 120s: a single-message extraction with up to
 * 8192 output tokens can take well past the verdict's 45s.
 *
 * Transport failures (WP_Error code 'http_request_failed': cURL errors,
 * DNS resolve timeouts, connection failures) are retried exactly once
 * after a 5s backoff. The request never reached Railway in that case, so
 * no model call was made and no quota is burned — and /pipeline/extract
 * is stateless, so a retry cannot double-write. HTTP responses (any
 * status, including 503 and quota deferrals) and model errors are never
 * retried. The retry is extraction-only: the verdict path returns null on
 * any failure and its caller retries on the next tick, so it needs none.
 */
function molavi_domain_extract_generate(string $prompt, array $opts = []): string {
    if ($prompt === '') throw new MolaviExtractError('Empty extraction prompt');
    $bridge = molavi_domain_railway_bridge();
    if ($bridge === null) throw new MolaviExtractError('Railway bridge not configured');
    [$base, $token] = $bridge;
    $payload = ['prompt' => $prompt];
    $model = (string) ($opts['model'] ?? '');
    if ($model !== '' && preg_match('/^[A-Za-z0-9._-]+$/D', $model)) $payload['model'] = $model;
    $body = wp_json_encode($payload);
    if (!is_string($body)) throw new MolaviExtractError('Extraction request could not be encoded');
    $url = rtrim($base, '/') . '/pipeline/extract';
    $exception = molavi_domain_railway_http_exception('/pipeline/extract');
    $response = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        add_filter('pre_http_request', $exception, PHP_INT_MAX, 3);
        try {
            $response = wp_safe_remote_post($url, [
                'timeout' => 120,
                'redirection' => 0,
                'headers' => [
                    'X-Molavi-Reference-Token' => $token,
                    'X-Molavi-Actor' => molavi_domain_railway_actor(),
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]);
        } finally {
            remove_filter('pre_http_request', $exception, PHP_INT_MAX);
        }
        if (!is_wp_error($response) || $response->get_error_code() !== 'http_request_failed' || $attempt === 2) break;
        error_log('[molavi-native] extract_transport_retry attempt=' . $attempt . ' error=' . $response->get_error_message());
        sleep(5);
    }
    if (is_wp_error($response)) throw new MolaviExtractError('Extraction transport failed: ' . $response->get_error_message());
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data)) throw new MolaviExtractError('Extraction route returned invalid JSON');
    if (($data['ok'] ?? false) === true) {
        $text = $data['text'] ?? null;
        if (!is_string($text)) throw new MolaviExtractError('Extraction route returned no model text');
        return $text;
    }
    $error = (string) ($data['error'] ?? '');
    if ($error === 'extract_deferred') {
        $deferred = new MolaviExtractQuotaDeferred('Extraction deferred for quota');
        $retryAt = $data['retry_at'] ?? 0;
        $deferred->retryAt = is_numeric($retryAt) ? (float) $retryAt : 0.0;
        throw $deferred;
    }
    if ($code === 503) throw new MolaviExtractError('Extraction route unavailable: ' . (string) ($data['detail'] ?? $error));
    throw new MolaviExtractError('Extraction failed: ' . (string) ($data['detail'] ?? $error));
}
