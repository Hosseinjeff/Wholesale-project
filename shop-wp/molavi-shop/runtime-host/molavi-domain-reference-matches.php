<?php
/** Plugin Name: Molavi Native Kept Reference Matches */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Persistent catalog/market matches (slice 1 of the match model).
 *
 * One row per (product_id, source) in reference_product_matches is the KEPT
 * match: it survives snapshot refreshes and is only replaced, flagged or
 * retired by an explicit later action, never silently by a re-scan.
 * Candidates are NOT persisted.
 *
 * The ONE writer is molavi_domain_reference_match_record(). Every creator
 * calls it; no creator writes reference_product_matches directly. Callers
 * invoke it inside the publication lock, immediately next to the decision
 * write and the sheet projection, so no other writer can interleave between
 * the decision and its kept match. (The decision itself is committed by
 * molavi_domain_reference_decide() in its own transaction just before, and
 * the sheet projection is a Sheets call, not SQLite, so "same
 * transaction/lock" here means the same publication-lock window, not one
 * SQLite transaction spanning all three stores.)
 *
 * Rules enforced by the writer:
 *  - at most one active match per (product, source);
 *  - a needs_review row blocks creation of a replacement for the same
 *    source until resolved (same reference_id may still be reaffirmed);
 *  - a retired row is terminal for system writers: backfill, extraction
 *    and market auto-picks fail `match_retired` and never re-create it.
 *    An OWNER decision (basis owner_pick / owner_assign_ai / owner_url
 *    with a real decision_id) may replace a retired row — that is how a
 *    replacement is picked after an auto-retire (Q7); the replacement is
 *    recorded as `replaced` with code `owner_pick_after_retired`.
 *  - a match row whose decision_id has no reference_item_decisions row is
 *    untrusted: see molavi_domain_reference_match_is_trusted(). Auto-verify
 *    ignores untrusted rows (that change belongs to a later slice).
 *
 * Removal clock (Q3): when a catalog entry is first observed missing from
 * a snapshot, reconcile stamps `removed_at` on the match row (Unix
 * seconds, the first-observation time — never the deploy time). While
 * the entry stays missing for under 30 days the row stays needs_review;
 * at/after 30 days missing the row auto-retires (`retired` /
 * `auto_retired` event, old price preserved, sheet reference fields
 * cleared exactly as the manual retire does). A needs_review row that
 * predates this slice has no `removed_at` yet: the first refresh after
 * deploy stamps it when the sheet still says `reference_missing` and
 * the entry is still absent, and the 30 days run from that stamp. When
 * the entry returns, the clock is cleared and the row stays owner-held.
 *
 * Match events go to reference_match_events, mirroring
 * market_source_events field-for-field plus the match-specific
 * source/old_reference_id/new_reference_id/new_price/change_ratio columns.
 * Kinds produced in this slice: created, replaced, price_changed,
 * identity_changed, reaffirmed. price_updated, reference_removed and
 * retired have no producer yet (retirement/removal APIs are later slices).
 * A price_changed event's code names the path: within_margin (ledger),
 * outside_margin (owner-held, pre-Q2), outside_margin_auto_adopted (Q2:
 * a clean >5% move adopted by the system) or sale_not_below_regular
 * (Q2 anomaly: the new sale price is at/above the new regular price,
 * owner-held).
 *
 * Timestamps are integer Unix seconds, matching the market/link-check
 * tables. next_at defaults to created_at + 14 days (the regular-price
 * verification clock) for catalog matches.
 */

const MOLAVI_REFERENCE_MATCH_NEXT_SECONDS = 1209600; // 14 days
// Q3: a catalog entry missing for at least this long auto-retires its kept
// match (30 days). The clock anchors on the first-observation stamp in
// removed_at, never on deploy time.
const MOLAVI_REFERENCE_MATCH_REMOVED_AUTO_RETIRE_SECONDS = 2592000; // 30 days
// Owner bases: the only writers that may replace a retired row (Q7), and
// only with a real decision_id. System bases (auto_*, backfill) never can.
const MOLAVI_REFERENCE_MATCH_OWNER_BASES = ['owner_pick', 'owner_assign_ai', 'owner_url'];
// Market-only bases (choose_source): owner_url = owner pasted a listing URL, auto_discovery = system pick (autopilot G4).
const MOLAVI_REFERENCE_MATCH_BASES = ['auto_exact', 'auto_price_agreed', 'auto_exact_name', 'owner_pick', 'owner_assign_ai', 'backfill', 'owner_url', 'auto_discovery'];
const MOLAVI_REFERENCE_MATCH_STATUSES = ['active', 'needs_review', 'retired'];
// The 5% rule shared with molavi_domain_reference_product_changes() and
// MOLAVI_REFERENCE_BACKFILL_MARGIN: a reference price move at or under
// this margin is ledger-only; above it the match is auto-adopted (Q2,
// owner decision 2026-11-27) unless the new entry is anomalous, in which
// case it is owner-held. Products WITHOUT a kept match still go through
// molavi_domain_reference_product_changes(), which owner-holds any >5%
// move — that path is out of Q2 scope.
const MOLAVI_REFERENCE_MATCH_MARGIN = 0.05;
// Near-exact name bar for a code-change relink, the same bar as
// MOLAVI_REFERENCE_BACKFILL_EXACT_NAME_SIMILARITY.
const MOLAVI_REFERENCE_MATCH_RELINK_NAME_SIMILARITY = 0.98;
// Persian copy for the identity-drift approval state, shown on the card.
const MOLAVI_REFERENCE_MATCH_IDENTITY_CHANGED_REASON = 'مشخصات مرجع تغییر کرده؛ تطابق را بررسی کنید';
const MOLAVI_REFERENCE_MATCH_REMOVED_REASON = 'مرجع کالا در نسخه جدید پیدا نشد؛ اطلاعات پیام اصلی را بررسی کنید.';
const MOLAVI_REFERENCE_MATCH_PRICE_UNAVAILABLE_REASON = 'قیمت مرجع قبلی برای سنجش تغییر در دسترس نیست؛ اطلاعات پیام اصلی را بررسی کنید.';
// Q2 anomaly hold: the new catalog entry's own sale price is at or above
// its regular price, so the listing is broken — the move is owner-held
// with the old prices preserved, never auto-adopted.
const MOLAVI_REFERENCE_MATCH_SALE_NOT_BELOW_REGULAR_REASON = 'قیمت فروش مرجع برابر یا بیشتر از قیمت عادی است؛ قیمت تازه را بررسی کنید.';

/** The snapshot row for a reference_id, with its parallel source_product_id. */
function molavi_domain_reference_match_row_from_snapshot(array $snapshot, string $referenceId): ?array {
    $ids = $snapshot['source_product_ids'] ?? null;
    foreach ($snapshot['rows'] ?? [] as $index => $row) {
        // Schema v1 rows are 21 columns, v2 rows 26 (two-price columns [21]-[25]).
        if (!is_array($row) || !in_array(count($row), [21, 26], true)) continue;
        if ((string) molavi_domain_py_value($row[16], $row[2]) !== $referenceId) continue;
        return ['row' => $row, 'index' => $index,
            'source_product_id' => is_array($ids) ? (string) ($ids[$index] ?? '') : ''];
    }
    return null;
}

/** Catalog prices from a 21-column snapshot row (package=3, unit=4, consumer=5). */
function molavi_domain_reference_match_prices(array $row): array {
    $price = static fn($value): ?int => molavi_domain_positive_price($value) ?: null;
    return ['catalog_package_price' => $price($row[3] ?? null),
        'catalog_unit_price' => $price($row[4] ?? null),
        'catalog_consumer_price' => $price($row[5] ?? null),
        'catalog_supplier_price' => null]; // RESERVED: parser builder fills it later.
}

/** [score, name_similarity] for one reference_id among live candidates, or nulls. */
function molavi_domain_reference_match_candidate_scores(array $attributes, array $rows, string $referenceId): array {
    // Candidate matching reads 21-column rows; v2 snapshots carry 26.
    $rows21 = array_map(static fn($row) => is_array($row) ? array_slice($row, 0, 21) : $row, $rows);
    foreach (molavi_domain_reference_candidates($attributes, $rows21, 20) as $candidate) {
        if ((string) ($candidate['reference_id'] ?? '') === $referenceId) {
            return ['score' => $candidate['score'] ?? null, 'name_similarity' => $candidate['name_similarity'] ?? null];
        }
    }
    return ['score' => null, 'name_similarity' => null];
}

/**
 * Fill catalog prices / source_product_id / scores into a match array from
 * the snapshot row for its reference_id. Returns null when the reference
 * is not in the snapshot (caller must not record the match then).
 * $match may carry 'attributes' (offer attributes) for score lookup.
 */
function molavi_domain_reference_match_from_snapshot(array $match, array $snapshot): ?array {
    $found = molavi_domain_reference_match_row_from_snapshot($snapshot, (string) ($match['reference_id'] ?? ''));
    if ($found === null) return null;
    $match += molavi_domain_reference_match_prices($found['row']);
    if (($match['source_product_id'] ?? '') === '') $match['source_product_id'] = $found['source_product_id'];
    if (!array_key_exists('score', $match) || $match['score'] === null) {
        $scores = molavi_domain_reference_match_candidate_scores(
            $match['attributes'] ?? [], $snapshot['rows'] ?? [], (string) $match['reference_id']);
        $match['score'] = $scores['score'];
        $match['name_similarity'] = $scores['name_similarity'];
    }
    unset($match['attributes']);
    return $match;
}

/** The kept match for a product/source, or null. */
function molavi_domain_reference_match_get(string $productId, string $source = 'catalog'): ?array {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
        'SELECT * FROM reference_product_matches WHERE product_id=? AND source=?', [$productId, $source]));
}

/** True only when the row links a real reference_item_decisions row. */
function molavi_domain_reference_match_is_trusted(?array $row): bool {
    if (!$row) return false;
    $decisionId = $row['decision_id'] ?? null;
    if ($decisionId === null || $decisionId === '') return false;
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
        'SELECT decision_id FROM reference_item_decisions WHERE decision_id=?', [$decisionId])) !== null;
}

/** Insert one reference_match_events row with fully explicit fields. */
function molavi_domain_reference_match_record_event_row(SQLite3 $db, string $productId, string $offerId, string $title, string $kind, string $source, ?string $oldReferenceId, ?string $newReferenceId, $oldPrice, $newPrice, $changeRatio, ?string $code, int $now): void {
    molavi_domain_sql($db, 'INSERT INTO reference_match_events
        (id,product_id,offer_id,title,kind,source,old_reference_id,new_reference_id,old_price,new_price,change_ratio,code,occurred_at,publish_pending)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [bin2hex(random_bytes(16)), $productId, $offerId, $title, $kind,
            $source, $oldReferenceId, $newReferenceId, $oldPrice, $newPrice, $changeRatio, $code, $now, 0]);
}

function molavi_domain_reference_match_record_event(SQLite3 $db, array $match, string $kind, ?string $oldReferenceId, $oldPrice, ?string $code, int $now): void {
    $newPrice = $match['source'] === 'market' ? ($match['regular_price'] ?? null) : ($match['catalog_consumer_price'] ?? null);
    $ratio = is_int($oldPrice) && $oldPrice > 0 && is_int($newPrice) && $newPrice !== $oldPrice
        ? round(($newPrice - $oldPrice) / $oldPrice, 4, PHP_ROUND_HALF_EVEN) : null;
    molavi_domain_reference_match_record_event_row($db, $match['product_id'], $match['offer_id'] ?? '',
        $match['title'] ?? '', $kind, $match['source'], $oldReferenceId, $match['reference_id'],
        $oldPrice, $newPrice, $ratio, $code, $now);
}

/**
 * THE writer. Upserts the kept match for ($match['product_id'], $match['source']).
 *
 * $match keys — required: product_id, source ('catalog'|'market'),
 * reference_id, match_basis (one of MOLAVI_REFERENCE_MATCH_BASES).
 * Optional: offer_id, signature, snapshot_id, price_role ('unit'|'package'),
 * source_product_id, catalog_*_price, sheet_consumer_price, score,
 * name_similarity, decision_id (null = untrusted, e.g. extraction exact),
 * listing_id/url/provider/merchant/quote_json/regular_price/pack_count/
 * identity_json (market-only), title (for events), status, next_at,
 * target (market_product_sources key, for the market mirror columns).
 * $actor is recorded as actor_id (and matched_by for market rows).
 * Returns the stored match row. Fails 'match_needs_review' /
 * 'match_retired' when the kept row blocks a replacement.
 */
function molavi_domain_reference_match_record(array $match, string $actor): array {
    $productId = trim((string) ($match['product_id'] ?? ''));
    $source = (string) ($match['source'] ?? '');
    $referenceId = trim((string) ($match['reference_id'] ?? ''));
    $basis = (string) ($match['match_basis'] ?? '');
    if ($productId === '' || !in_array($source, ['catalog', 'market'], true)
        || $referenceId === '' || !in_array($basis, MOLAVI_REFERENCE_MATCH_BASES, true)
        || trim($actor) === '') molavi_domain_fail('invalid_match', 400);
    $priceRole = (string) ($match['price_role'] ?? '');
    if ($priceRole !== '' && !in_array($priceRole, ['unit', 'package'], true)) molavi_domain_fail('invalid_match', 400);
    $status = (string) ($match['status'] ?? 'active');
    if (!in_array($status, MOLAVI_REFERENCE_MATCH_STATUSES, true)) molavi_domain_fail('invalid_match', 400);
    $now = time();
    $match = array_merge($match, ['product_id' => $productId, 'source' => $source, 'reference_id' => $referenceId,
        'match_basis' => $basis, 'price_role' => $priceRole, 'actor_id' => $actor,
        'offer_id' => (string) ($match['offer_id'] ?? ''), 'signature' => (string) ($match['signature'] ?? ''),
        'snapshot_id' => (string) ($match['snapshot_id'] ?? ''),
        'source_product_id' => (string) ($match['source_product_id'] ?? ''),
        'decision_id' => ($match['decision_id'] ?? null) === '' ? null : ($match['decision_id'] ?? null)]);
    $int = static fn($key) => isset($match[$key]) && $match[$key] !== '' && $match[$key] !== null ? (int) $match[$key] : null;
    $real = static fn($key) => isset($match[$key]) && $match[$key] !== '' && $match[$key] !== null ? (float) $match[$key] : null;
    $text = static fn($key) => isset($match[$key]) && $match[$key] !== null ? (string) $match[$key] : null;
    $row = [
        $productId, $source, $match['offer_id'], $match['signature'], $referenceId, $match['source_product_id'],
        $match['snapshot_id'], $priceRole,
        $int('catalog_package_price'), $int('catalog_unit_price'), $int('catalog_consumer_price'),
        $int('catalog_supplier_price'), $int('sheet_consumer_price'),
        $real('score'), $real('name_similarity'), $basis, $match['decision_id'],
        $text('listing_id'), $text('url'), $text('provider'), $text('merchant'), $text('quote_json'),
        $int('regular_price'), $int('pack_count'), $text('identity_json'), $actor,
    ];
    $columns = 'product_id,source,offer_id,signature,reference_id,source_product_id,snapshot_id,price_role,'
        . 'catalog_package_price,catalog_unit_price,catalog_consumer_price,catalog_supplier_price,sheet_consumer_price,'
        . 'score,name_similarity,match_basis,decision_id,listing_id,url,provider,merchant,quote_json,'
        . 'regular_price,pack_count,identity_json,actor_id';
    return molavi_domain_tx('reference', static function (SQLite3 $db) use ($match, $row, $columns, $status, $now): array {
        $existing = molavi_domain_row($db, 'SELECT * FROM reference_product_matches WHERE product_id=? AND source=?',
            [$match['product_id'], $match['source']]);
        $newPrice = $match['source'] === 'market' ? ($match['regular_price'] ?? null) : ($match['catalog_consumer_price'] ?? null);
        if ($existing) {
            if ($existing['status'] === 'retired') {
                // Retired is terminal for system writers, but an owner
                // decision (Q7) may replace the retired match: the owner
                // picked a replacement, so the write proceeds through the
                // normal replace/reaffirm paths below. Anything without
                // an owner basis AND a real decision_id still fails.
                // (Market payloads carry decision_id null, so a market
                // auto-pick can never take this path.)
                $ownerReplace = in_array($match['match_basis'], MOLAVI_REFERENCE_MATCH_OWNER_BASES, true)
                    && ($match['decision_id'] ?? null) !== null && ($match['decision_id'] ?? '') !== '';
                if (!$ownerReplace) molavi_domain_fail('match_retired', 409);
            }
            if ($existing['status'] === 'needs_review' && $existing['reference_id'] !== $match['reference_id'])
                molavi_domain_fail('match_needs_review', 409);
            $oldPrice = $existing['catalog_consumer_price'] ?? $existing['regular_price'];
            $oldPrice = $oldPrice !== null ? (int) $oldPrice : null;
            if ($existing['reference_id'] !== $match['reference_id']) {
                // A replacement retires the old removal clock: the new
                // reference is a fresh match, so a later removal must
                // start its own 30 days (a stale removed_at would retire
                // it at once). Only when the replacement is active.
                $clearRemoved = $status === 'active' ? ',removed_at=NULL' : '';
                molavi_domain_sql($db, 'UPDATE reference_product_matches SET offer_id=?,signature=?,reference_id=?,'
                    . 'source_product_id=?,snapshot_id=?,price_role=?,catalog_package_price=?,catalog_unit_price=?,'
                    . 'catalog_consumer_price=?,catalog_supplier_price=?,sheet_consumer_price=?,score=?,name_similarity=?,'
                    . 'match_basis=?,decision_id=?,listing_id=?,url=?,provider=?,merchant=?,quote_json=?,regular_price=?,'
                    . 'pack_count=?,identity_json=?,actor_id=?,status=?,created_at=?,checked_at=?,verified_at=NULL,next_at=?'
                    . $clearRemoved . ' WHERE product_id=? AND source=?',
                    array_merge(array_slice($row, 2), [$status, $now, $now, $match['next_at'] ?? $now + MOLAVI_REFERENCE_MATCH_NEXT_SECONDS,
                        $match['product_id'], $match['source']]));
                $replaceCode = ($existing['status'] ?? '') === 'retired' ? 'owner_pick_after_retired' : null;
                molavi_domain_reference_match_record_event($db, $match, 'replaced', $existing['reference_id'], $oldPrice, $replaceCode, $now);
            } else {
                $decisionChanged = ($match['decision_id'] ?? null) !== null
                    && ($existing['decision_id'] ?? null) !== $match['decision_id'];
                $priceChanged = is_int($newPrice) && $oldPrice !== null && $newPrice !== $oldPrice;
                $identityChanged = ($match['signature'] ?? '') !== '' && ($existing['signature'] ?? '') !== $match['signature'];
                // An owner decision reaffirming the same reference (the
                // reference-review accept path for a >5% move) returns the
                // row to active. Any other re-record keeps the current
                // status: a needs_review row is only cleared by a new
                // decision, never by a silent re-record.
                $newStatus = $decisionChanged ? 'active' : $existing['status'];
                // An owner reaffirm that returns the row to active also
                // clears the removal clock (same stale-clock reason as a
                // replacement). A silent re-record keeps it.
                $clearRemoved = $newStatus === 'active' ? ',removed_at=NULL' : '';
                molavi_domain_sql($db, 'UPDATE reference_product_matches SET offer_id=?,signature=?,'
                    . 'source_product_id=?,snapshot_id=?,price_role=?,catalog_package_price=?,catalog_unit_price=?,'
                    . 'catalog_consumer_price=?,catalog_supplier_price=?,sheet_consumer_price=?,score=?,name_similarity=?,'
                    . 'match_basis=?,decision_id=?,listing_id=?,url=?,provider=?,merchant=?,quote_json=?,regular_price=?,'
                    . 'pack_count=?,identity_json=?,actor_id=?,status=?,checked_at=?,next_at=?'
                    . $clearRemoved . ' WHERE product_id=? AND source=?',
                    array_merge([$row[2], $row[3]], array_slice($row, 5, 21),
                        [$newStatus, $now, $match['next_at'] ?? $now + MOLAVI_REFERENCE_MATCH_NEXT_SECONDS,
                            $match['product_id'], $match['source']]));
                if ($decisionChanged) {
                    molavi_domain_reference_match_record_event($db, $match, 'reaffirmed', $existing['reference_id'], $oldPrice, null, $now);
                } elseif ($priceChanged) {
                    molavi_domain_reference_match_record_event($db, $match, 'price_changed', $existing['reference_id'], $oldPrice, null, $now);
                } elseif ($identityChanged) {
                    molavi_domain_reference_match_record_event($db, $match, 'identity_changed', $existing['reference_id'], $oldPrice, null, $now);
                }
            }
        } else {
            molavi_domain_sql($db, 'INSERT INTO reference_product_matches (' . $columns
                . ',status,created_at,checked_at,verified_at,next_at) VALUES ('
                . implode(',', array_fill(0, count($row), '?')) . ',?,?,?,NULL,?)',
                array_merge($row, [$status, $now, $now, $match['next_at'] ?? $now + MOLAVI_REFERENCE_MATCH_NEXT_SECONDS]));
            molavi_domain_reference_match_record_event($db, $match, 'created', null, null, null, $now);
        }
        if ($match['source'] === 'market' && ($match['target'] ?? '') !== '') {
            molavi_domain_sql($db, 'UPDATE market_product_sources SET match_basis=?,matched_by=?,matched_at=?,match_status=? WHERE target=?',
                [$match['match_basis'], $match['actor_id'], $now, $status, $match['target']]);
        }
        return molavi_domain_row($db, 'SELECT * FROM reference_product_matches WHERE product_id=? AND source=?',
            [$match['product_id'], $match['source']]);
    });
}

/**
 * Extraction exact-compatible identity (choke point hook). Records the kept
 * match for a product whose latest offer already carries an
 * exact_compatible_identity match and no decision. The row has decision_id
 * NULL, so it is untrusted until an owner/backfill decision links it.
 * Never throws: match recording must not break the projection.
 */
function molavi_domain_reference_match_maybe_record_exact(string $productId, array $product, array $offer, ?string $clock = null): void {
    try {
        if ((string) ($offer['Reference Match State'] ?? '') !== 'exact_compatible_identity') return;
        $referenceId = (string) molavi_domain_py_value($product['reference_match_id'] ?? null, '');
        if ($referenceId === '' || (string) molavi_domain_py_value($product['reference_decision_id'] ?? null, '') !== '') return;
        $snapshot = molavi_domain_reference_active();
        if (!$snapshot) return;
        $attrs = molavi_domain_offer_attributes($offer);
        $found = molavi_domain_reference_candidate_match($attrs, $snapshot['rows'] ?? []);
        if (($found['state'] ?? '') !== 'candidate' || ($found['reason'] ?? '') !== 'exact_compatible_identity') return;
        $candidate = $found['candidates'][0] ?? null;
        if (!$candidate || (string) ($candidate['reference_id'] ?? '') !== $referenceId) return;
        $role = (string) molavi_domain_py_value($offer['Reference Price Role Hint'] ?? null, '');
        if (!in_array($role, ['unit', 'package'], true)) $role = 'unit';
        $scores = molavi_domain_reference_match_candidate_scores($attrs, $snapshot['rows'] ?? [], $referenceId);
        $match = molavi_domain_reference_match_from_snapshot([
            'product_id' => $productId, 'source' => 'catalog', 'offer_id' => (string) molavi_domain_py_value($offer['Offer ID'] ?? null, ''),
            'signature' => molavi_domain_reference_signature($attrs), 'reference_id' => $referenceId,
            'price_role' => $role, 'snapshot_id' => (string) ($snapshot['snapshot_id'] ?? ''),
            'decision_id' => null, 'match_basis' => 'auto_exact',
            'sheet_consumer_price' => molavi_domain_positive_price($product['consumer_price'] ?? null) ?: null,
            'title' => (string) molavi_domain_py_value($product['name'] ?? null, ''),
        ] + $scores, $snapshot);
        if ($match === null) return;
        molavi_domain_reference_match_record($match, 'system:extraction');
    } catch (Throwable $e) {
        error_log('[molavi-native] reference_match_exact_failed product=' . $productId);
    }
}

/**
 * Refresh reconcile (slice 2).
 *
 * Runs on every reference refresh, inside the publication lock in
 * molavi_domain_reference_complete(), before
 * molavi_domain_reference_product_changes() reads the Products sheet (so
 * a reconciled product is never re-decided by the sheet pass in the same
 * run). It examines ONLY kept catalog matches whose reference_id appears
 * in the new snapshot's change_summary (changed / removed /
 * reconcile_reference_ids) plus rows whose stored snapshot_id is not the
 * new active snapshot. Retired rows are terminal and untouched.
 * needs_review rows are owner-owned until resolved and are left alone —
 * except removal-clock rows (removed_at set, or a pre-existing
 * reference_missing hold with no clock yet): those are re-examined on
 * every refresh so the 30-day auto-retire clock can run (Q3).
 *
 * Outcomes (the automatic status changes are active -> needs_review,
 * for anomalies and removals; and, after 30 days missing, needs_review
 * -> retired; auto-adopt keeps the match active):
 *  - entry present, price (per price_role) moved <= 5%: stays active;
 *    stored prices + snapshot_id + checked_at/verified_at updated. Sheet
 *    writes are exactly what product_changes() writes for the same case.
 *    Event price_updated / within_margin. Recorded in the ledger, never
 *    silently overwritten, but no card alert and no digest line.
 *  - entry present, price moved > 5% (Q2 auto-adopt, owner decision
 *    2026-11-27 — the same rule as market prices, round-eight decision
 *    3): stays active and ADOPTS the new price — stored prices +
 *    snapshot_id + checked_at/verified_at updated, sheet Reference
 *    Approval State 'approved_auto_adopted' with the change percent.
 *    Event price_changed / outside_margin_auto_adopted with old/new +
 *    signed ratio. The adopted price flows onward exactly like a manual
 *    accept: the regular-price sync treats approved_auto_adopted as an
 *    approved state, so the next sync tick writes the new catalogue
 *    price to the sheet Consumer Price (and WooCommerce) — reconcile
 *    itself writes no Consumer Price cell. No separate write step.
 *  - entry present, price moved > 5% BUT the new entry is anomalous:
 *    the new price is missing (handled below as price_unavailable),
 *    the new sale price is at/above the new regular price, or the
 *    identity/signature drifted (handled above as identity_changed):
 *    needs_review; stored prices NOT overwritten (the old price stays
 *    as the "before" value). Event price_changed /
 *    sale_not_below_regular with old/new + signed ratio, sheet
 *    Approval State stays outside_margin (so the review case, the card
 *    path forward and the product-state due flags all keep working)
 *    with a reason naming the anomaly. The sale>=regular check reads
 *    the new row's own two-price model: the parser's
 *    sale_not_below_regular anomaly flag (v2 column [25]), or a stored
 *    regular price at/below the role's sale price (v2 columns
 *    [21]-[24]). A sale exactly equal to regular leaves no trace in a
 *    real parser-built row (the parser treats it as "no sale" and
 *    stores no regular price), so only sale>regular is flaggable there;
 *    the numeric check covers rows that do carry a regular price.
 *  - entry absent from the new snapshot: needs_review; row + prices kept.
 *    Event reference_removed / reference_missing. The first observation
 *    stamps removed_at (the 30-day clock starts there). A Product Code
 *    change that fails the relink conditions below lands here, and the new code
 *    surfaces as a fresh candidate, never as an auto-match.
 *  - entry still absent and removed_at is 30+ days old: retired. The
 *    reference link — and only the reference link — is dropped: the
 *    product row and its prices are untouched. Event retired /
 *    auto_retired with the old price preserved; the sheet's Reference
 *    Match ID and Reference Approval State are cleared exactly as the
 *    manual retire does, so the digest (which lists auto_retired) and
 *    the card's reference-selection step can offer a replacement (Q7).
 *  - entry still absent and removed_at is under 30 days old: stays
 *    needs_review; no new event, no sheet write (both already say
 *    reference_missing). Outcome `removed_waiting`.
 *  - entry absent, needs_review, no removed_at yet (a hold that predates
 *    the removal clock): the first refresh after deploy stamps
 *    removed_at when the sheet still says reference_missing, and the 30
 *    days run from that stamp. Outcome `removed_waiting`, no event.
 *  - entry returned after being missing: the clock is cleared
 *    (removed_at NULL) and the row stays needs_review for the owner.
 *    Outcome `removed_returned`, no event, no sheet write.
 *  - entry present but the old price is unavailable for comparison (or
 *    the new price is missing): needs_review. Event price_changed /
 *    reference_price_unavailable.
 *  - entry present but name/brand/variant/volume/weight changed so
 *    molavi_domain_reference_candidate_match() compatibility fails for
 *    the stored signature: needs_review, approval state
 *    reference_identity_changed. Event identity_changed / identity_drift.
 *  - entry's Product Code changed but source_product_id identical, name
 *    similarity >= 0.98 and price within 5%: auto-relink, reference_id
 *    updated, stays active. Event replaced / code_changed_relinked.
 *  - nothing changed: checked_at/verified_at (+ snapshot_id) refreshed,
 *    no event, no sheet write.
 *
 * INVARIANT: every stored-price change is applied in the same SQLite
 * transaction as its event insert, so a change can never be projected
 * without being recorded (and vice versa). Sheet writes happen first,
 * with the same write + receipt-verification pattern as
 * molavi_domain_reference_complete(); the SQLite transaction commits
 * only after the receipt verifies, so a sheet failure leaves the match
 * rows untouched and the next refresh recomputes the same outcomes.
 * Market-source matches are out of scope: they carry no snapshot.
 */
function molavi_domain_reference_match_reconcile(array $active, ?array $previous, ?string $clock = null): array {
    $newId = (string) ($active['snapshot_id'] ?? '');
    if ($newId === '') molavi_domain_fail('invalid_snapshot', 400);
    $nowIso = $clock ?? molavi_domain_now();
    $now = (int) (molavi_domain_timestamp($nowIso) ?? time());
    $summary = $active['change_summary'] ?? [];
    $changeIds = [];
    foreach ((array) ($summary['reconcile_reference_ids'] ?? []) as $code) if ((string) $code !== '') $changeIds[(string) $code] = true;
    $changedByOldCode = [];
    foreach ((array) ($summary['changed'] ?? []) as $change) {
        foreach (['old_product_code', 'product_code'] as $key) if ((string) ($change[$key] ?? '') !== '') $changeIds[(string) $change[$key]] = true;
        $old = (string) ($change['old_product_code'] ?? '');
        if ($old !== '' && (string) ($change['product_code'] ?? '') !== '' && $old !== (string) $change['product_code']) $changedByOldCode[$old] = $change;
    }
    foreach ((array) ($summary['removed'] ?? []) as $removed) if ((string) ($removed['product_code'] ?? '') !== '') $changeIds[(string) $removed['product_code']] = true;
    $indexSnapshot = static function (?array $snapshot): array {
        $byCode = [];
        foreach (($snapshot['rows'] ?? []) as $index => $row) {
            if (!is_array($row) || !in_array(count($row), [21, 26], true)) continue;
            $code = (string) molavi_domain_py_value($row[16] ?? null, $row[2] ?? null);
            if ($code === '' || isset($byCode[$code])) continue;
            $ids = $snapshot['source_product_ids'] ?? null;
            $byCode[$code] = ['row' => $row, 'source_product_id' => is_array($ids) ? (string) ($ids[$index] ?? '') : ''];
        }
        return $byCode;
    };
    $newByCode = $indexSnapshot($active);
    $oldByCode = $indexSnapshot($previous);
    $newPrices = molavi_domain_reference_price_index($active);
    $matches = molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db,
        "SELECT * FROM reference_product_matches WHERE source='catalog' AND status<>'retired' ORDER BY product_id"));
    $table = molavi_domain_sheets(['Products'], true)['Products'] ?? [];
    $headers = $table[0] ?? molavi_domain_product_headers();
    $products = [];
    foreach (molavi_domain_records($table) as $index => $record) {
        $id = (string) molavi_domain_py_value($record['Product ID'] ?? null);
        if ($id !== '') $products[$id] = ['record' => $record, 'row' => $index + 2];
    }
    $counts = ['checked' => 0, 'unchanged' => 0, 'retained' => 0, 'relinked' => 0, 'needs_review' => 0,
        'removed' => 0, 'auto_retired' => 0, 'removed_waiting' => 0, 'removed_returned' => 0,
        'identity_changed' => 0, 'price_unavailable' => 0, 'outside_margin' => 0,
        'auto_adopted' => 0,
        'skipped_needs_review' => 0, 'already_current' => 0, 'sheet_skipped' => 0];
    $outcomes = [];
    $rolePrice = static function (array $match, string $role): ?int {
        $value = $role === 'package' ? ($match['catalog_package_price'] ?? null)
            : ($role === 'unit' ? ($match['catalog_unit_price'] ?? null) : ($match['catalog_consumer_price'] ?? null));
        return $value !== null && (int) $value > 0 ? (int) $value : null;
    };
    $newRolePrice = static function (array $priceIndex, array $newRow, string $referenceId, string $role): ?int {
        if ($role === 'unit' || $role === 'package') {
            $value = $priceIndex[$referenceId][$role] ?? null;
            return $value !== null && (int) $value > 0 ? (int) $value : null;
        }
        return molavi_domain_positive_price($newRow[5] ?? null) ?: null;
    };
    foreach ($matches as $match) {
        $productId = (string) ($match['product_id'] ?? '');
        $referenceId = (string) ($match['reference_id'] ?? '');
        $role = in_array($match['price_role'] ?? '', ['unit', 'package'], true) ? (string) $match['price_role'] : '';
        $product = $products[$productId] ?? null;
        $title = (string) molavi_domain_py_value($product['record']['Product Name'] ?? null,
            $oldByCode[$referenceId]['row'][0] ?? '');
        $oldRolePrice = $rolePrice($match, $role);
        $base = ['product_id' => $productId, 'reference_id' => $referenceId, 'offer_id' => (string) ($match['offer_id'] ?? ''),
            'title' => $title, 'sheet' => null, 'event' => null,
            'db_status' => 'active', 'db_reference_id' => null, 'db_prices' => null, 'db_snapshot' => null, 'db_verified' => false];
        if (($match['status'] ?? '') === 'needs_review') {
            if (!empty($match['removed_at'])) {
                // Removal-clock row: falls through to the removed/returned
                // handling below so the 30-day auto-retire can run. Every
                // other needs_review row stays owner-held.
            } elseif ((string) molavi_domain_py_value($product['record']['Reference Approval State'] ?? null) === 'reference_missing'
                && !isset($newByCode[$referenceId])) {
                // Hold that predates the removal clock: stamp removed_at
                // on the first refresh after deploy; the 30 days run from
                // this stamp, not from deploy time or the original removal.
                $counts['checked']++;
                $counts['removed_waiting']++;
                $outcomes[] = array_merge($base, ['outcome' => 'removed_waiting', 'db_status' => 'needs_review', 'db_removed_at' => $now]);
                continue;
            } else { $counts['skipped_needs_review']++; continue; }
        }
        if (!isset($changeIds[$referenceId]) && (string) ($match['snapshot_id'] ?? '') === $newId) { $counts['already_current']++; continue; }
        $counts['checked']++;
        $needsReview = static function (string $outcome, array $sheet, ?array $event) use ($base): array {
            return array_merge($base, ['outcome' => $outcome, 'sheet' => $sheet, 'event' => $event,
                'db_status' => 'needs_review', 'db_verified' => false]);
        };
        $entry = $newByCode[$referenceId] ?? null;
        if ($entry === null) {
            if (($match['status'] ?? '') === 'needs_review') {
                // Removal-clock row, entry still missing: the age check
                // runs here, on every refresh, so the retire lands on the
                // first refresh at/after 30 days missing. No relink for
                // an owner-held row. The >= comparison also tolerates a
                // removed_at slightly ahead of the clock (never retires
                // early on skew).
                $removedAt = (int) ($match['removed_at'] ?? 0);
                if ($removedAt > 0 && $now >= $removedAt + MOLAVI_REFERENCE_MATCH_REMOVED_AUTO_RETIRE_SECONDS) {
                    $outcomes[] = array_merge($base, ['outcome' => 'auto_retired',
                        'sheet' => $product === null ? null : ['Reference Approval State' => '', 'Reference Match ID' => ''],
                        'event' => ['kind' => 'retired', 'code' => 'auto_retired', 'old_reference_id' => $referenceId,
                            'new_reference_id' => null, 'old_price' => $oldRolePrice, 'new_price' => null, 'change_ratio' => null],
                        'db_status' => 'retired', 'db_verified' => false]);
                    $counts['auto_retired']++;
                    if ($product === null) $counts['sheet_skipped']++;
                } else {
                    $outcomes[] = array_merge($base, ['outcome' => 'removed_waiting', 'db_status' => 'needs_review']);
                    $counts['removed_waiting']++;
                }
                continue;
            }
            // Relink: the code changed but the Woo product is the same.
            $change = $changedByOldCode[$referenceId] ?? null;
            $relink = null;
            if ($change !== null) {
                $newCode = (string) ($change['product_code'] ?? '');
                $candidate = $newByCode[$newCode] ?? null;
                $oldRow = $oldByCode[$referenceId]['row'] ?? null;
                $oldSourceId = (string) ($match['source_product_id'] ?? '') !== ''
                    ? (string) $match['source_product_id']
                    : (string) ($oldByCode[$referenceId]['source_product_id'] ?? '');
                if ($candidate !== null && $oldRow !== null && $oldSourceId !== ''
                    && (string) ($candidate['source_product_id'] ?? '') === $oldSourceId
                    && (string) ($candidate['source_product_id'] ?? '') === (string) ($change['source_product_id'] ?? ($candidate['source_product_id'] ?? ''))) {
                    $similarity = molavi_domain_match_ratio(
                        molavi_domain_match_name((string) molavi_domain_py_value($oldRow[0] ?? null)),
                        molavi_domain_match_name((string) molavi_domain_py_value($candidate['row'][0] ?? null)));
                    $newPrice = $newRolePrice($newPrices, $candidate['row'], $newCode, $role);
                    if ($similarity >= MOLAVI_REFERENCE_MATCH_RELINK_NAME_SIMILARITY && $oldRolePrice !== null && $newPrice !== null
                        && abs($newPrice - $oldRolePrice) / $oldRolePrice <= MOLAVI_REFERENCE_MATCH_MARGIN) {
                        $relink = ['code' => $newCode, 'row' => $candidate['row'], 'price' => $newPrice, 'similarity' => $similarity];
                    }
                }
            }
            if ($relink !== null) {
                $ratio = round(($relink['price'] - $oldRolePrice) / $oldRolePrice, 4, PHP_ROUND_HALF_EVEN);
                $outcomes[] = array_merge($base, ['outcome' => 'relinked',
                    'sheet' => $product === null ? null : ['Reference Match ID' => $relink['code'],
                        'Reference Snapshot ID' => $newId, 'Reference Approval State' => 'approved_within_margin',
                        'Reference Price Change Percent' => round(abs($relink['price'] - $oldRolePrice) / $oldRolePrice * 100, 4, PHP_ROUND_HALF_EVEN),
                        'Reference Price Checked At' => $nowIso, 'Reference Review Reason' => ''],
                    'event' => ['kind' => 'replaced', 'code' => 'code_changed_relinked', 'old_reference_id' => $referenceId,
                        'new_reference_id' => $relink['code'], 'old_price' => $oldRolePrice, 'new_price' => $relink['price'], 'change_ratio' => $ratio],
                    'db_reference_id' => $relink['code'], 'db_prices' => molavi_domain_reference_match_prices($relink['row']),
                    'db_snapshot' => $newId, 'db_verified' => true]);
                $counts['relinked']++;
                if ($product === null) $counts['sheet_skipped']++;
                continue;
            }
            $firstMissing = $needsReview('removed',
                $product === null ? null : ['Status' => 'needs_review', 'Reference Approval State' => 'reference_missing',
                    'Reference Price Change Percent' => '', 'Reference Price Checked At' => $nowIso,
                    'Reference Review Reason' => MOLAVI_REFERENCE_MATCH_REMOVED_REASON],
                ['kind' => 'reference_removed', 'code' => 'reference_missing', 'old_reference_id' => $referenceId,
                    'new_reference_id' => null, 'old_price' => $oldRolePrice, 'new_price' => null, 'change_ratio' => null]);
            // First observation of the removal: stamp the clock. The 30
            // days run from here, never from deploy time.
            $firstMissing['db_removed_at'] = $now;
            $outcomes[] = $firstMissing;
            $counts['needs_review']++; $counts['removed']++;
            if ($product === null) $counts['sheet_skipped']++;
            continue;
        }
        if (($match['status'] ?? '') === 'needs_review') {
            // Removal-clock row whose entry returned: clear the clock and
            // stay owner-held. The price/identity branches below must
            // never auto-clear an owner-held row.
            $outcomes[] = array_merge($base, ['outcome' => 'removed_returned', 'db_status' => 'needs_review', 'db_removed_at' => null]);
            $counts['removed_returned']++;
            continue;
        }
        $newRow = $entry['row'];
        // Identity: the stored signature must still be compatible with the
        // new entry. Product attributes first; the old catalog row's own
        // attributes as fallback when the product row is missing.
        $compatible = null;
        $attrs = $product === null ? null : [
            'name' => (string) molavi_domain_py_value($product['record']['Product Name'] ?? null),
            'brand' => (string) molavi_domain_py_value($product['record']['Brand'] ?? null),
            'category' => (string) molavi_domain_py_value($product['record']['Category'] ?? null),
            'variant' => (string) molavi_domain_py_value($product['record']['Variation Type'] ?? null),
            'volume' => (string) molavi_domain_py_value($product['record']['Volume'] ?? null),
            'weight' => (string) molavi_domain_py_value($product['record']['Weight'] ?? null),
            'package_count' => (string) molavi_domain_py_value($product['record']['Package Count'] ?? null)];
        $identityCheck = static function (array $attributes, array $row, string $referenceId): bool {
            $check = molavi_domain_reference_candidate_match($attributes, [array_slice($row, 0, 21)]);
            return ($check['state'] ?? '') === 'candidate' && count($check['candidates'] ?? []) === 1
                && (string) ($check['candidates'][0]['reference_id'] ?? '') === $referenceId;
        };
        if ($attrs !== null) $compatible = $identityCheck($attrs, $newRow, $referenceId);
        if ($compatible === false && isset($oldByCode[$referenceId])) {
            $oldRow = $oldByCode[$referenceId]['row'];
            $compatible = $identityCheck([
                'name' => (string) molavi_domain_py_value($oldRow[0] ?? null),
                'brand' => (string) molavi_domain_py_value($oldRow[12] ?? null),
                'category' => (string) molavi_domain_py_value($oldRow[13] ?? null),
                'variant' => (string) molavi_domain_py_value($oldRow[11] ?? null),
                'volume' => (string) molavi_domain_py_value($oldRow[8] ?? null),
                'weight' => (string) molavi_domain_py_value($oldRow[9] ?? null),
                'package_count' => (string) molavi_domain_py_value($oldRow[6] ?? null)], $newRow, $referenceId);
        }
        $newRole = $newRolePrice($newPrices, $newRow, $referenceId, $role);
        if ($compatible === false) {
            $ratio = $oldRolePrice !== null && $newRole !== null && $oldRolePrice > 0 && $newRole !== $oldRolePrice
                ? round(($newRole - $oldRolePrice) / $oldRolePrice, 4, PHP_ROUND_HALF_EVEN) : null;
            $outcomes[] = $needsReview('identity_changed',
                $product === null ? null : ['Status' => 'needs_review', 'Reference Approval State' => 'reference_identity_changed',
                    'Reference Price Change Percent' => '', 'Reference Price Checked At' => $nowIso,
                    'Reference Review Reason' => MOLAVI_REFERENCE_MATCH_IDENTITY_CHANGED_REASON],
                ['kind' => 'identity_changed', 'code' => 'identity_drift', 'old_reference_id' => $referenceId,
                    'new_reference_id' => $referenceId, 'old_price' => $oldRolePrice, 'new_price' => $newRole, 'change_ratio' => $ratio]);
            $counts['needs_review']++; $counts['identity_changed']++;
            if ($product === null) $counts['sheet_skipped']++;
            continue;
        }
        if ($oldRolePrice === null || $newRole === null) {
            $outcomes[] = $needsReview('price_unavailable',
                $product === null ? null : ['Status' => 'needs_review', 'Reference Approval State' => 'price_unavailable',
                    'Reference Price Change Percent' => '', 'Reference Price Checked At' => $nowIso,
                    'Reference Review Reason' => MOLAVI_REFERENCE_MATCH_PRICE_UNAVAILABLE_REASON],
                ['kind' => 'price_changed', 'code' => 'reference_price_unavailable', 'old_reference_id' => $referenceId,
                    'new_reference_id' => $referenceId, 'old_price' => $oldRolePrice, 'new_price' => $newRole, 'change_ratio' => null]);
            $counts['needs_review']++; $counts['price_unavailable']++;
            if ($product === null) $counts['sheet_skipped']++;
            continue;
        }
        $newPricesRow = molavi_domain_reference_match_prices($newRow);
        $storedPrices = ['catalog_package_price' => $match['catalog_package_price'] ?? null,
            'catalog_unit_price' => $match['catalog_unit_price'] ?? null,
            'catalog_consumer_price' => $match['catalog_consumer_price'] ?? null,
            'catalog_supplier_price' => $match['catalog_supplier_price'] ?? null];
        if ($newPricesRow === $storedPrices && $oldRolePrice === $newRole) {
            $outcomes[] = array_merge($base, ['outcome' => 'unchanged', 'db_snapshot' => $newId, 'db_verified' => true]);
            $counts['unchanged']++;
            continue;
        }
        $absRatio = abs($newRole - $oldRolePrice) / $oldRolePrice;
        $signedRatio = round(($newRole - $oldRolePrice) / $oldRolePrice, 4, PHP_ROUND_HALF_EVEN);
        if ($absRatio <= MOLAVI_REFERENCE_MATCH_MARGIN) {
            $outcomes[] = array_merge($base, ['outcome' => 'within_margin',
                'sheet' => $product === null ? null : ['Reference Snapshot ID' => $newId,
                    'Reference Approval State' => 'approved_within_margin',
                    'Reference Price Change Percent' => round($absRatio * 100, 4, PHP_ROUND_HALF_EVEN),
                    'Reference Price Checked At' => $nowIso, 'Reference Review Reason' => ''],
                'event' => ['kind' => 'price_updated', 'code' => 'within_margin', 'old_reference_id' => $referenceId,
                    'new_reference_id' => $referenceId, 'old_price' => $oldRolePrice, 'new_price' => $newRole, 'change_ratio' => $signedRatio],
                'db_prices' => $newPricesRow, 'db_snapshot' => $newId, 'db_verified' => true]);
            $counts['retained']++;
            if ($product === null) $counts['sheet_skipped']++;
            continue;
        }
        // Q2: a >5% move auto-adopts — UNLESS the new entry is anomalous.
        // The new sale price (the role price) must sit below the new
        // regular price on the same basis; the parser stores that model
        // in the v2 columns [21]-[24] (supply/regular pack/unit) and its
        // own sale_not_below_regular flag in [25]. v1 rows carry no
        // regular price, so no anomaly is detectable there and the move
        // adopts as normal. For the consumer basis (role '') there is no
        // same-basis regular price, so only the parser flag applies.
        $newRegular = null;
        if (count($newRow) === 26) {
            $regularColumn = $role === 'package' ? 23 : ($role === 'unit' ? 24 : null);
            if ($regularColumn !== null) $newRegular = molavi_domain_positive_price($newRow[$regularColumn] ?? null) ?: null;
        }
        $saleNotBelowRegular = (count($newRow) === 26 && (string) ($newRow[25] ?? '') === 'sale_not_below_regular')
            || ($newRegular !== null && $newRole >= $newRegular);
        if ($saleNotBelowRegular) {
            $outcomes[] = $needsReview('outside_margin',
                $product === null ? null : ['Status' => 'needs_review', 'Reference Approval State' => 'outside_margin',
                    'Reference Price Change Percent' => round($absRatio * 100, 4, PHP_ROUND_HALF_EVEN),
                    'Reference Price Checked At' => $nowIso,
                    'Reference Review Reason' => MOLAVI_REFERENCE_MATCH_SALE_NOT_BELOW_REGULAR_REASON],
                ['kind' => 'price_changed', 'code' => 'sale_not_below_regular', 'old_reference_id' => $referenceId,
                    'new_reference_id' => $referenceId, 'old_price' => $oldRolePrice, 'new_price' => $newRole, 'change_ratio' => $signedRatio]);
            $counts['needs_review']++; $counts['outside_margin']++;
            if ($product === null) $counts['sheet_skipped']++;
            continue;
        }
        $outcomes[] = array_merge($base, ['outcome' => 'auto_adopted',
            'sheet' => $product === null ? null : ['Reference Snapshot ID' => $newId,
                'Reference Approval State' => 'approved_auto_adopted',
                'Reference Price Change Percent' => round($absRatio * 100, 4, PHP_ROUND_HALF_EVEN),
                'Reference Price Checked At' => $nowIso, 'Reference Review Reason' => ''],
            'event' => ['kind' => 'price_changed', 'code' => 'outside_margin_auto_adopted', 'old_reference_id' => $referenceId,
                'new_reference_id' => $referenceId, 'old_price' => $oldRolePrice, 'new_price' => $newRole, 'change_ratio' => $signedRatio],
            'db_prices' => $newPricesRow, 'db_snapshot' => $newId, 'db_verified' => true]);
        $counts['auto_adopted']++;
        if ($product === null) $counts['sheet_skipped']++;
    }
    // Sheet writes first, with the receipt pattern from reference_complete:
    // a sheet failure leaves the match rows untouched and the next refresh
    // recomputes the same outcomes.
    $cells = [];
    $updates = [];
    foreach ($outcomes as $outcome) {
        if ($outcome['sheet'] === null) continue;
        $product = $products[$outcome['product_id']] ?? null;
        if ($product === null) continue;
        $updates[] = ['row' => $product['row'], 'product_id' => $outcome['product_id'], 'fields' => $outcome['sheet']];
    }
    foreach ($updates as $update) {
        if ($cells && count($cells) + count($update['fields']) > 500) { molavi_domain_sheet_cells('Products', $cells); $cells = []; }
        foreach ($update['fields'] as $field => $value) {
            $column = array_search($field, $headers, true);
            if ($column === false) molavi_domain_fail('sheet_header_missing', 409, ['field' => $field]);
            $cells[] = ['range' => molavi_domain_sheet_column($column + 1) . $update['row'], 'values' => [[$value]]];
        }
    }
    if ($cells) molavi_domain_sheet_cells('Products', $cells);
    if ($updates) {
        $verified = molavi_domain_records(molavi_domain_sheets(['Products'], true, true)['Products']);
        foreach ($updates as $update) {
            $row = $verified[$update['row'] - 2] ?? [];
            if ((string) ($row['Product ID'] ?? '') !== $update['product_id']) molavi_domain_fail('reference_product_receipt_mismatch', 503);
            foreach ($update['fields'] as $key => $value) {
                $actual = $row[$key] ?? '';
                $matches = is_int($value) || is_float($value)
                    ? (is_numeric($actual) && (float) $actual === (float) $value)
                    : ((string) $actual === (string) $value);
                if (!$matches) molavi_domain_fail('reference_product_receipt_mismatch', 503);
            }
        }
    }
    // One transaction: every stored-price change lands with its event.
    molavi_domain_tx('reference', static function (SQLite3 $db) use ($outcomes, $now): void {
        foreach ($outcomes as $outcome) {
            $sets = ['checked_at=?'];
            $params = [$now];
            if (!empty($outcome['db_verified'])) { $sets[] = 'verified_at=?'; $params[] = $now; }
            if (!empty($outcome['db_snapshot'])) { $sets[] = 'snapshot_id=?'; $params[] = $outcome['db_snapshot']; }
            if (!empty($outcome['db_reference_id'])) { $sets[] = 'reference_id=?'; $params[] = $outcome['db_reference_id']; }
            if (!empty($outcome['db_prices'])) {
                $sets[] = 'catalog_package_price=?'; $params[] = $outcome['db_prices']['catalog_package_price'];
                $sets[] = 'catalog_unit_price=?'; $params[] = $outcome['db_prices']['catalog_unit_price'];
                $sets[] = 'catalog_consumer_price=?'; $params[] = $outcome['db_prices']['catalog_consumer_price'];
            }
            // Removal clock: present means stamp (int) or clear (null);
            // absent means leave the stored value alone.
            if (array_key_exists('db_removed_at', $outcome)) { $sets[] = 'removed_at=?'; $params[] = $outcome['db_removed_at']; }
            $sets[] = 'status=?'; $params[] = $outcome['db_status'];
            molavi_domain_sql($db, 'UPDATE reference_product_matches SET ' . implode(',', $sets) . ' WHERE product_id=? AND source=?',
                array_merge($params, [$outcome['product_id'], 'catalog']));
            if ($outcome['event'] !== null) {
                $event = $outcome['event'];
                molavi_domain_reference_match_record_event_row($db, $outcome['product_id'], $outcome['offer_id'],
                    $outcome['title'], $event['kind'], 'catalog', $event['old_reference_id'], $event['new_reference_id'],
                    $event['old_price'], $event['new_price'], $event['change_ratio'], $event['code'], $now);
            }
        }
    });
    $counts['outcomes'] = array_map(static fn($outcome) => [
        'product_id' => $outcome['product_id'], 'reference_id' => $outcome['reference_id'],
        'new_reference_id' => $outcome['db_reference_id'] ?? $outcome['reference_id'],
        'outcome' => $outcome['outcome'],
        'event_kind' => $outcome['event']['kind'] ?? null, 'event_code' => $outcome['event']['code'] ?? null], $outcomes);
    return $counts;
}

/**
 * POST /reference/snapshots action 'retire_reference_match'.
 *
 * Owner-only, note required (same pattern as molavi_domain_reference_review()).
 * The match row becomes terminal 'retired' (the writer never re-creates
 * it), the Products sheet's Reference Approval State and Reference Match
 * ID are cleared with the same sheet-write + receipt pattern, and a
 * retired / owner_retired event is recorded. Retiring an already-retired
 * match is a no-op success. The note is an owner attestation: it is
 * validated, but reference_match_events has no note column, so it is not
 * persisted (a schema addition would be needed to keep it).
 */
function molavi_domain_reference_match_retire_api(array $body, string $actor): array {
    molavi_domain_body_keys($body, ['action', 'product_id', 'source', 'note']);
    if (!molavi_domain_owner_actor($actor)) molavi_domain_fail('owner_required', 403);
    if (trim((string) ($body['note'] ?? '')) === '') molavi_domain_fail('review_note_required', 400);
    $productId = trim((string) ($body['product_id'] ?? ''));
    $source = (string) ($body['source'] ?? '');
    if ($productId === '' || molavi_domain_strlen($productId) > 300 || !in_array($source, ['catalog', 'market'], true)) molavi_domain_fail('invalid_fields', 400);
    return molavi_domain_lock('publication', static function () use ($productId, $source, $actor): array {
        $row = molavi_domain_reference_match_get($productId, $source);
        if ($row === null) molavi_domain_fail('match_not_found', 404);
        if (($row['status'] ?? '') === 'retired') {
            return ['ok' => true, 'action' => 'retire_reference_match', 'product_id' => $productId,
                'source' => $source, 'reference_id' => (string) ($row['reference_id'] ?? ''), 'status' => 'retired', 'already_retired' => true];
        }
        $nowIso = molavi_domain_now();
        $now = time();
        $table = molavi_domain_sheets(['Products'], true)['Products'] ?? [];
        $headers = $table[0] ?? molavi_domain_product_headers();
        $index = molavi_domain_find_product(molavi_domain_records($table), $productId);
        if ($index !== null) {
            $fields = ['Reference Approval State' => '', 'Reference Match ID' => ''];
            $cells = [];
            foreach ($fields as $field => $value) {
                $column = array_search($field, $headers, true);
                if ($column === false) molavi_domain_fail('sheet_header_missing', 409, ['field' => $field]);
                $cells[] = ['range' => molavi_domain_sheet_column($column + 1) . ($index + 2), 'values' => [[$value]]];
            }
            molavi_domain_sheet_cells('Products', $cells);
            $verified = molavi_domain_records(molavi_domain_sheets(['Products'], true, true)['Products']);
            $check = $verified[$index] ?? [];
            foreach ($fields as $field => $value) {
                if ((string) ($check[$field] ?? '') !== (string) $value) molavi_domain_fail('reference_product_receipt_mismatch', 503);
            }
            if ((string) ($check['Product ID'] ?? '') !== $productId) molavi_domain_fail('reference_product_receipt_mismatch', 503);
        }
        $oldPrice = $source === 'market' ? ($row['regular_price'] ?? null) : ($row['catalog_consumer_price'] ?? null);
        $oldPrice = $oldPrice !== null ? (int) $oldPrice : null;
        molavi_domain_tx('reference', static function (SQLite3 $db) use ($row, $productId, $source, $oldPrice, $now): void {
            molavi_domain_sql($db, "UPDATE reference_product_matches SET status='retired',checked_at=? WHERE product_id=? AND source=?",
                [$now, $productId, $source]);
            molavi_domain_reference_match_record_event_row($db, $productId, (string) ($row['offer_id'] ?? ''),
                '', 'retired', $source, (string) ($row['reference_id'] ?? ''), null,
                $oldPrice, null, null, 'owner_retired', $now);
        });
        return ['ok' => true, 'action' => 'retire_reference_match', 'product_id' => $productId,
            'source' => $source, 'reference_id' => (string) ($row['reference_id'] ?? ''), 'status' => 'retired'];
    });
}
