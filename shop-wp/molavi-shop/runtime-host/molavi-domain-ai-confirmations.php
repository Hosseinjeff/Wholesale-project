<?php
/** Plugin Name: Molavi AI Confirmations (Slice 1 custody) */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Slice 1 — AI-confirmation review fully native (extraction migration).
 *
 * Custody (verified on staging 2026-11-24):
 * - Native in WordPress: case building (molavi_domain_ai_case /
 *   molavi_domain_ai_review_cases), the decision ledger
 *   (ai_confirmation_decisions in the `pipeline` SQLite store), the
 *   derived-offer write (molavi_domain_ai_apply_decision /
 *   molavi_domain_confirmed_offer through the WP Sheets client),
 *   MessageData status mirroring (molavi_domain_ai_refresh_message) and
 *   the product-status snapshot (molavi_domain_status_capture). The native
 *   route GET/POST /pipeline/ai-confirmations is in molavi_domain_routes()
 *   and in the molavi_native_routes_v1 option, and the استخراج tab is
 *   served by it — the Railway path is the fallback, not the live path.
 * - Stays on Railway: only the raw model call (Google 403s the staging
 *   host's IP) and, until this slice is proven, /pipeline/ai-confirmations
 *   as a relay/fallback. The Offers-sheet shape is unchanged.
 * - Identity is byte-identical across the two sides so old decisions stay
 *   valid: case_id = 'ai:' + sha256(offer_id); decision_id = sha256 of the
 *   payload JSON (sorted keys, ensure_ascii=False, separators (',', ':')).
 *   Recomputed in PHP for all 32 stored decisions on 2026-11-24: 32/32
 *   match. Derived-offer construction parity was proven the same day
 *   (WP molavi_domain_confirmed_offer vs the real Python apply_decision:
 *   62 fields compared, 0 diffs).
 *
 * Case-diff findings (WP vs Railway, 2026-11-24; see
 * molavi_domain_ai_confirmations_case_diff() — the live endpoint):
 * - 177 cases common to both sides, 0 field diffs. Reference candidates
 *   identical (same 12, same order, same ids).
 * - 9 cases only on Railway: all reopened cases (reopened=true). Two
 *   causes, both outside the WP case builder: (a) the deployed Railway
 *   code re-keys reopened cases to a case_id that is NOT
 *   sha256(offer_id) — that re-keying exists only on Railway, not in the
 *   WP model; (b) Railway's GoogleSheetStore.read() caches the Offers
 *   sheet in process memory (its own writes patch the cache, WP's direct
 *   Sheets writes do not), so Railway's view of rows WP has since
 *   resolved stays stale until its process restarts or read_fresh runs.
 *   WP's fresh read shows those rows already imported, so WP correctly
 *   does not list them. The native (WP) view is authoritative.
 * - 23 cases only in WP: all reextract rows. The superseded computation
 *   is identical code on both sides; the inputs differ — the
 *   ai_source_reextractions ledgers diverged (WP holds 157 receipts;
 *   Railway's separate store holds a different set). The two ledgers
 *   were never synced. A decision made on one side is invisible on the
 *   other until the ledgers are reconciled (a Slice 2 concern, not a
 *   case-builder bug).
 * - 3 decisions in both ledgers carry a case_id that does not equal
 *   sha256(offer_id) (orphaned/stale case_ids from an earlier state).
 *   Present identically on both sides; harmless for new decisions, which
 *   are keyed by the case the workbench displays.
 * - A stale-source decision fails closed: a decide with a wrong
 *   signature on a real open case throws MolaviWorkflowError
 *   'stale_case' (409) and writes nothing (ledger 32 -> 32, verified
 *   2026-11-24). Revert: removing GET/POST /pipeline/ai-confirmations
 *   from molavi_native_routes_v1 makes the workbench proxy to Railway
 *   again; ledger rows written by WP stay valid (same id scheme).
 *
 * Behaviour change in this file: none to the review path. It only adds
 * the case-diff diagnostic endpoint below. Rollback: deleting this file
 * removes the endpoint; the native review path is untouched.
 */

/** Custody status for the AI-confirmation review slice. Read-only. */
function molavi_domain_ai_confirmations_custody(): array {
    $routes = molavi_domain_routes();
    $active = get_option('molavi_native_routes_v1', []);
    $counts = molavi_domain_read('pipeline', static fn(SQLite3 $db) => [
        'decisions' => (int) molavi_domain_row($db, 'SELECT COUNT(*) c FROM ai_confirmation_decisions')['c'],
        'reextractions' => (int) molavi_domain_row($db, 'SELECT COUNT(*) c FROM ai_source_reextractions')['c'],
        'dismissals' => (int) molavi_domain_row($db, 'SELECT COUNT(*) c FROM ai_message_dismissals')['c'],
    ]);
    return [
        'slice' => 'slice-1-ai-confirmations-native',
        'extraction_host' => molavi_domain_extraction_host(),
        'route_defined' => isset($routes['/pipeline/ai-confirmations']),
        'route_native_get' => is_array($active) && in_array('GET /pipeline/ai-confirmations', $active, true),
        'route_native_post' => is_array($active) && in_array('POST /pipeline/ai-confirmations', $active, true),
        'ledger' => $counts,
    ];
}

/** Fetch the open cases + recent decisions from the Railway fallback, via raw curl (bypasses the WP HTTP intercept). Read-only. */
function molavi_domain_ai_confirmations_fetch_railway(): array {
    if (!defined('MOLAVI_REFERENCE_SERVER_URL') || !defined('MOLAVI_REFERENCE_BRIDGE_TOKEN')
        || trim((string) MOLAVI_REFERENCE_SERVER_URL) === '' || trim((string) MOLAVI_REFERENCE_BRIDGE_TOKEN) === '') {
        molavi_domain_fail('railway_bridge_unconfigured', 503);
    }
    $ch = curl_init(rtrim((string) MOLAVI_REFERENCE_SERVER_URL, '/') . '/pipeline/ai-confirmations');
    if ($ch === false) molavi_domain_fail('railway_unavailable', 503);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['X-Molavi-Reference-Token: ' . MOLAVI_REFERENCE_BRIDGE_TOKEN, 'X-Molavi-Actor: wp:1', 'Accept: application/json']]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $body === '' || $code !== 200) molavi_domain_fail('railway_unavailable', 503, ['http_code' => $code]);
    $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !isset($decoded['cases']) || !is_array($decoded['cases'])) molavi_domain_fail('railway_bad_response', 503);
    return $decoded;
}

/** Normalise one case field value for comparison across the PHP/Python sides. */
function molavi_domain_ai_confirmations_norm(mixed $value): string {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_int($value) || is_float($value)) return (string) $value;
    if (is_string($value)) return $value;
    if (is_array($value)) return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    return (string) $value;
}

/** Classify a case that appears on only one side. */
function molavi_domain_ai_confirmations_classify(array $case): string {
    if (!empty($case['reopened'])) return 'reopened';
    if (str_starts_with((string) ($case['offer_id'] ?? ''), 'reextract:')) return 'reextract_superseded';
    if (str_starts_with((string) ($case['offer_id'] ?? ''), 'owner-confirm:')) return 'derived_row';
    return 'other';
}

/**
 * Diff the WP-native cases against the Railway fallback cases.
 * Returns counts, field diffs (capped at 10 examples per field) and the
 * classified one-sided sets. Read-only: no Sheet or ledger writes.
 */
function molavi_domain_ai_confirmations_case_diff(): array {
    $snapshot = null;
    try {
        $candidate = molavi_domain_reference_active();
        $state = molavi_domain_reference_use();
        if ($candidate && $state['allow_automatic'] && $candidate['snapshot_id'] === $state['snapshot_id']) $snapshot = $candidate;
    } catch (Throwable $e) {
    }
    $tables = molavi_domain_sheets(['Offers'], true);
    [$wpCases, $wpRecent] = molavi_domain_ai_review_cases($tables, $snapshot, true, 200);
    $rw = molavi_domain_ai_confirmations_fetch_railway();
    $rwCases = $rw['cases'];
    $rwRecent = $rw['recent_decisions'] ?? [];
    $byWp = [];
    foreach ($wpCases as $case) $byWp[(string) $case['case_id']] = $case;
    $byRw = [];
    foreach ($rwCases as $case) $byRw[(string) $case['case_id']] = $case;
    $skip = ['publication_held' => true, 'decision_history' => true, 'reference_candidates' => true];
    $fieldDiffs = [];
    $common = 0;
    foreach ($byWp as $caseId => $wpCase) {
        if (!isset($byRw[$caseId])) continue;
        $common++;
        $rwCase = $byRw[$caseId];
        $keys = array_unique(array_merge(array_keys($wpCase), array_keys($rwCase)));
        foreach ($keys as $key) {
            if (isset($skip[$key])) continue;
            if (molavi_domain_ai_confirmations_norm($wpCase[$key] ?? null) !== molavi_domain_ai_confirmations_norm($rwCase[$key] ?? null)) {
                $fieldDiffs[$key][] = $caseId;
            }
        }
        $wpCand = array_map(static fn($c) => (string) ($c['reference_id'] ?? $c['id'] ?? ''), $wpCase['reference_candidates'] ?? []);
        $rwCand = array_map(static fn($c) => (string) ($c['reference_id'] ?? $c['id'] ?? ''), $rwCase['reference_candidates'] ?? []);
        if ($wpCand !== $rwCand) $fieldDiffs['reference_candidates'][] = $caseId;
    }
    foreach ($fieldDiffs as $key => $ids) $fieldDiffs[$key] = ['count' => count($ids), 'cases' => array_slice($ids, 0, 10)];
    $onlyWp = [];
    foreach ($byWp as $caseId => $case) {
        if (!isset($byRw[$caseId])) $onlyWp[] = ['case_id' => $caseId, 'offer_id' => (string) ($case['offer_id'] ?? ''), 'class' => molavi_domain_ai_confirmations_classify($case)];
    }
    $onlyRw = [];
    foreach ($byRw as $caseId => $case) {
        if (!isset($byWp[$caseId])) $onlyRw[] = ['case_id' => $caseId, 'offer_id' => (string) ($case['offer_id'] ?? ''), 'class' => molavi_domain_ai_confirmations_classify($case)];
    }
    $wpRecentIds = [];
    foreach ($wpRecent as $entry) $wpRecentIds[(string) $entry['case_id']] = (string) (($entry['decision']['decision_id'] ?? ''));
    $rwRecentIds = [];
    foreach ($rwRecent as $entry) $rwRecentIds[(string) $entry['case_id']] = (string) (($entry['decision']['decision_id'] ?? ''));
    $recentDiff = [];
    foreach (array_unique(array_merge(array_keys($wpRecentIds), array_keys($rwRecentIds))) as $caseId) {
        if (($wpRecentIds[$caseId] ?? null) !== ($rwRecentIds[$caseId] ?? null)) $recentDiff[] = $caseId;
    }
    return [
        'ok' => true,
        'wp_cases' => count($byWp),
        'railway_cases' => count($byRw),
        'common_cases' => $common,
        'field_diffs' => $fieldDiffs,
        'only_wp' => ['count' => count($onlyWp), 'cases' => array_slice($onlyWp, 0, 10),
            'by_class' => array_count_values(array_column($onlyWp, 'class'))],
        'only_railway' => ['count' => count($onlyRw), 'cases' => array_slice($onlyRw, 0, 10),
            'by_class' => array_count_values(array_column($onlyRw, 'class'))],
        'recent' => ['wp' => count($wpRecentIds), 'railway' => count($rwRecentIds),
            'decision_mismatches' => ['count' => count($recentDiff), 'cases' => array_slice($recentDiff, 0, 10)]],
        'custody' => molavi_domain_ai_confirmations_custody(),
    ];
}

add_action('rest_api_init', static function(): void {
    register_rest_route('molavi-workflow/v1', '/ai-confirmations/case-diff', ['methods' => 'GET', 'permission_callback' => 'molavi_runtime_permission',
        'callback' => static function() {
            try {
                return molavi_domain_ai_confirmations_case_diff();
            } catch (MolaviWorkflowError $e) {
                return new WP_Error($e->getMessage(), 'AI-confirmations case diff failed', ['status' => $e->status]);
            } catch (Throwable $e) {
                return new WP_Error('ai_confirmations_case_diff_failed', 'AI-confirmations case diff failed', ['status' => 503]);
            }
        }]);
    register_rest_route('molavi-workflow/v1', '/ai-confirmations/custody', ['methods' => 'GET', 'permission_callback' => 'molavi_runtime_permission',
        'callback' => static function() {
            try {
                return ['ok' => true] + molavi_domain_ai_confirmations_custody();
            } catch (Throwable $e) {
                return new WP_Error('ai_confirmations_custody_failed', 'AI-confirmations custody read failed', ['status' => 503]);
            }
        }]);
});
