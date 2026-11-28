<?php
/** Plugin Name: Molavi Native Reference Auto-Match Backfill */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Reference auto-match backfill.
 *
 * Most pipeline products are stuck at the regular-price step because they
 * were never matched to the reference catalog during extraction (no
 * Reference Match ID, or an unapproved match), so the catalog path in
 * molavi_domain_regular_price_evidence() can never make them current.
 * This backfill re-runs matching against the CURRENT active catalog
 * snapshot and auto-assigns a match only when it is unambiguous AND
 * price-agreed. It never sets a price from the catalog: a product with no
 * sheet Consumer Price is skipped.
 *
 * Loosened rule (all must hold within a tier):
 *  Tier 0 — full identity (iteration 1): brand agrees (supplier brand
 *  present and positively inferred from the title or the brand column)
 *  AND product tokens agree order-free (supplier-side containment
 *  strictly above 0.80, after de-gluing Rezvan's compact titles) AND
 *  variant / size / pack agree where provided. Identity matches are
 *  partitioned by the 5% price agreement first: exactly one
 *  price-agreed identity match auto-matches regardless of the string
 *  name_similarity. A unique identity match with a price outside 5%,
 *  or with no catalog consumer price at all (no `(Nف)` to scrape),
 *  is an owner candidate, not an auto-match; a stale (قدیم) row is
 *  never auto-matched and surfaces for human confirmation; more than
 *  one identity match means ambiguity and nothing auto-applies.
 *  Category never vetoes any tier.
 *  Tier 1 — high confidence, as before: exactly ONE candidate with
 *  name_similarity >= 0.92, no provided attribute conflict, and the
 *  catalog consumer_price within 5% of the sheet Consumer Price.
 *  Tier 2 — lower name similarity: no candidate at >= 0.92, but exactly
 *  ONE candidate with name_similarity >= 0.80, no provided attribute
 *  conflict, and the catalog consumer_price within 5% of the sheet
 *  Consumer Price. The 5% price agreement is the independent cross-check
 *  that lets the lower name bar auto-apply: a wrong-name match would also
 *  need a catalog price within 5% of the sheet price.
 *  Tier 3 — no sheet Consumer Price, so no price cross-check is possible:
 *  a near-exact unique name (name_similarity >= 0.98, exactly one
 *  candidate, no provided attribute conflict) auto-applies and is
 *  recorded as approved_compatible (the extraction path's state for an
 *  exact compatible identity). A unique close name (name_similarity
 *  >= 0.80, exactly one candidate, no conflict) does NOT auto-apply; it
 *  is reported as a candidate for the owner to pick from the card's
 *  reference-selection step, which already lists live candidates.
 *  In every tier, more than one candidate in the band means the name is
 *  ambiguous and nothing is matched or suggested. A product with no
 *  catalog twin stays unmatched; the backfill never writes a price.
 *
 * The 0.80 floor is the matching code's own 'close' band: the candidate
 * comparisons mark a name 'close' at >= 0.72 and 'conflict' below it, so
 * 0.80 sits inside 'close' with margin above the conflict line. The 0.98
 * bar is near-exact: for the normalized Indel ratio it means the names
 * are identical or differ by a single character in a long name — the name
 * alone is effectively certain, which is what a match without a price
 * cross-check needs.
 *
 * A match is recorded the same way an owner match is: a
 * reference_item_decisions row (choice 'match', price_role 'unit' — the
 * compared price is the catalog Consumer Unit Price — with the score and
 * price ratio in the note, so the card's Reference Decision ID links to
 * the full evidence), then the product row is rebuilt through
 * molavi_domain_refresh_conflict_product(), the existing projection path
 * used by owner reference decisions. The projection records the decision
 * as owner_approved; the backfill then restates the approval as
 * approved_within_margin with the measured change percent (tiers 1–2)
 * or approved_compatible with no change percent (tier 3, no sheet price
 * to compare against), using the same sheet-write + receipt-verification
 * pattern as reference reconciliation (molavi_domain_reference_complete()).
 *
 * Products that already carry any reference decision (owner or prior
 * run) are never re-decided; they are reported as skipped so the normal
 * review/projection path can handle them. Candidates are never written:
 * a re-scan surfaces the same candidate again, so the owner-review list
 * is idempotent and a later write run cannot duplicate it.
 *
 * A retired kept match (Q7) never auto-applies: the scan surfaces a
 * candidate for the owner instead, and apply() refuses a retired
 * product outright. Only an owner pick — via the reviews API, through
 * the writer's owner-basis carve-out — writes a new match for it.
 *
 * Exposed as action 'backfill_reference_matches' on the existing
 * POST /reference/snapshots route (molavi_domain_reference_api). No new
 * native route is needed. dry_run defaults to true; the write runs only
 * when dry_run is explicitly false.
 */

const MOLAVI_REFERENCE_BACKFILL_MIN_NAME_SIMILARITY = 0.92;
const MOLAVI_REFERENCE_BACKFILL_REVIEW_SIMILARITY = 0.80;
const MOLAVI_REFERENCE_BACKFILL_EXACT_NAME_SIMILARITY = 0.98;
const MOLAVI_REFERENCE_BACKFILL_MARGIN = 0.05;

/** Pick the single candidate at or above a similarity floor, or say why there is none. */
function molavi_domain_reference_backfill_pick(array $candidates, float $min): array {
    $in = array_values(array_filter($candidates,
        static fn($candidate) => ($candidate['name_similarity'] ?? 0) >= $min));
    if (!$in) return ['candidate' => null, 'reason' => 'no_candidate'];
    if (count($in) > 1) return ['candidate' => null, 'reason' => 'multiple_candidates', 'candidate_count' => count($in)];
    return ['candidate' => $in[0], 'reason' => ''];
}

/** The key of a provided attribute comparison in 'conflict' state, or ''.
 * Category NEVER vetoes (iteration 1): supplier categories are free
 * text from extraction, catalog categories are Rezvan's taxonomy, and
 * an exact-string mismatch between them (general vs تن ماهی) says
 * nothing about identity. The comparison is still reported for display. */
function molavi_domain_reference_backfill_conflict(array $candidate): string {
    foreach ($candidate['comparisons'] ?? [] as $comparison) {
        if (($comparison['key'] ?? '') === 'category') continue;
        if (($comparison['state'] ?? '') === 'conflict') return (string) ($comparison['key'] ?? '');
    }
    return '';
}

/** The tier-0 identity conflict check: like backfill_conflict, but the
 * name is also excluded — tier 0 judges identity, not the LCS string,
 * so a low name_similarity is the reason tier 0 exists, not a veto. */
function molavi_domain_reference_backfill_identity_conflict(array $candidate): string {
    foreach ($candidate['comparisons'] ?? [] as $comparison) {
        if (in_array($comparison['key'] ?? '', ['category', 'name'], true)) continue;
        if (($comparison['state'] ?? '') === 'conflict') return (string) ($comparison['key'] ?? '');
    }
    return '';
}

/** The match detail shared by every tier. */
function molavi_domain_reference_backfill_detail(array $candidate): array {
    return ['reference_id' => (string) $candidate['reference_id'],
        'reference_name' => (string) ($candidate['name'] ?? ''), 'score' => $candidate['score'],
        'name_similarity' => $candidate['name_similarity']];
}

/** Price-checked tier: conflict check, catalog price, 5% margin. */
function molavi_domain_reference_backfill_price_checked(array $candidate, int|float $sheet, string $tier): array {
    $conflict = molavi_domain_reference_backfill_conflict($candidate);
    if ($conflict !== '')
        return ['matched' => false, 'reason' => 'attribute_conflict', 'conflict_key' => $conflict, 'candidate_count' => 1];
    $catalog = molavi_domain_match_number($candidate['consumer_price'] ?? null);
    if (!$catalog) return ['matched' => false, 'reason' => 'reference_price_unavailable', 'candidate_count' => 1];
    $ratio = abs($catalog - $sheet) / $sheet;
    if ($ratio > MOLAVI_REFERENCE_BACKFILL_MARGIN)
        return ['matched' => false, 'reason' => 'price_outside_margin', 'price_ratio' => round($ratio, 4, PHP_ROUND_HALF_EVEN), 'candidate_count' => 1];
    $reason = $tier === 'high' ? 'unique_price_agreed_candidate' : 'unique_lower_similarity_price_agreed_candidate';
    return ['matched' => true, 'reason' => $reason, 'tier' => $tier, 'sheet_price' => $sheet, 'catalog_price' => $catalog,
        'price_ratio' => round($ratio, 4, PHP_ROUND_HALF_EVEN)] + molavi_domain_reference_backfill_detail($candidate);
}

/** Pure decision: does this product earn an automatic reference match, a candidate for the owner, or neither? */
function molavi_domain_reference_backfill_decide(array $record, array $candidates): array {
    $sheet = molavi_domain_positive_price($record['Consumer Price'] ?? null);
    if (!$sheet) {
        // Tier 0 (full identity) without a sheet price cannot auto-apply:
        // the price leg has nothing to agree with. A unique identity
        // match is still surfaced as an owner candidate — never silently
        // matched, and a stale (قدیم) row is flagged for confirmation.
        $identity = array_values(array_filter($candidates, static fn($candidate) => !empty($candidate['identity_match'])));
        if (count($identity) > 1)
            return ['matched' => false, 'reason' => 'multiple_identity_candidates', 'candidate_count' => count($identity)];
        if (count($identity) === 1) {
            $candidate = $identity[0];
            if (!empty($candidate['stale_packaging']))
                return ['matched' => false, 'candidate' => true, 'reason' => 'identity_match_stale_packaging', 'tier' => 'identity_stale',
                    'stale_packaging' => true, 'catalog_price' => molavi_domain_match_number($candidate['consumer_price'] ?? null)]
                    + molavi_domain_reference_backfill_detail($candidate);
            $conflict = molavi_domain_reference_backfill_identity_conflict($candidate);
            if ($conflict !== '')
                return ['matched' => false, 'reason' => 'attribute_conflict', 'conflict_key' => $conflict, 'candidate_count' => 1];
            $catalog = molavi_domain_match_number($candidate['consumer_price'] ?? null);
            return ['matched' => false, 'candidate' => true, 'reason' => 'identity_match_no_sheet_price', 'tier' => 'identity_candidate',
                'catalog_price' => $catalog] + molavi_domain_reference_backfill_detail($candidate);
        }
        // No sheet price to cross-check: only a near-exact unique name auto-applies.
        $exact = molavi_domain_reference_backfill_pick($candidates, MOLAVI_REFERENCE_BACKFILL_EXACT_NAME_SIMILARITY);
        if ($exact['candidate'] !== null) {
            $conflict = molavi_domain_reference_backfill_conflict($exact['candidate']);
            if ($conflict !== '')
                return ['matched' => false, 'reason' => 'attribute_conflict', 'conflict_key' => $conflict, 'candidate_count' => 1];
            $catalog = molavi_domain_match_number($exact['candidate']['consumer_price'] ?? null);
            if (!$catalog) return ['matched' => false, 'reason' => 'reference_price_unavailable', 'candidate_count' => 1];
            return ['matched' => true, 'reason' => 'unique_exact_name_no_sheet_price', 'tier' => 'exact_name',
                'catalog_price' => $catalog] + molavi_domain_reference_backfill_detail($exact['candidate']);
        }
        if ($exact['reason'] === 'multiple_candidates')
            return ['matched' => false, 'reason' => 'multiple_candidates', 'candidate_count' => $exact['candidate_count']];
        // No near-exact candidate: a unique close name is a candidate for the owner, not an auto-match.
        $close = molavi_domain_reference_backfill_pick($candidates, MOLAVI_REFERENCE_BACKFILL_REVIEW_SIMILARITY);
        if ($close['candidate'] !== null) {
            $conflict = molavi_domain_reference_backfill_conflict($close['candidate']);
            if ($conflict !== '')
                return ['matched' => false, 'reason' => 'attribute_conflict', 'conflict_key' => $conflict, 'candidate_count' => 1];
            $catalog = molavi_domain_match_number($close['candidate']['consumer_price'] ?? null);
            if (!$catalog) return ['matched' => false, 'reason' => 'reference_price_unavailable', 'candidate_count' => 1];
            return ['matched' => false, 'candidate' => true, 'reason' => 'unique_name_candidate_no_sheet_price', 'tier' => 'name_candidate',
                'catalog_price' => $catalog] + molavi_domain_reference_backfill_detail($close['candidate']);
        }
        if ($close['reason'] === 'multiple_candidates')
            return ['matched' => false, 'reason' => 'multiple_candidates', 'candidate_count' => $close['candidate_count']];
        return ['matched' => false, 'reason' => 'no_candidate'];
    }
    // Sheet price exists: the 5% price agreement is the cross-check, so a lower name bar can auto-apply.
    // Tier 0 — full identity (iteration 1): brand + product tokens +
    // variant + size + pack all agree, regardless of the string
    // name_similarity. Identity matches are partitioned by the 5% price
    // agreement FIRST: exactly one price-agreed identity match takes
    // the tier-0 path (a stale (قدیم) row becomes an owner candidate,
    // never an auto-match); zero price-agreed + exactly one identity
    // match becomes an owner candidate — `identity_match_no_catalog_price`
    // when the catalog carries no consumer price at all (تیمز rows,
    // بلک ولف: no `(Nف)` to scrape), else
    // `identity_match_price_outside_margin`. Multiple identity matches
    // mean ambiguity and nothing auto-applies.
    $identity = array_values(array_filter($candidates, static fn($candidate) => !empty($candidate['identity_match'])));
    if ($identity) {
        $agreed = [];
        foreach ($identity as $candidate) {
            $catalogPrice = molavi_domain_match_number($candidate['consumer_price'] ?? null);
            if ($catalogPrice && abs($catalogPrice - $sheet) / $sheet <= MOLAVI_REFERENCE_BACKFILL_MARGIN) $agreed[] = $candidate;
        }
        if (count($agreed) > 1)
            return ['matched' => false, 'reason' => 'multiple_identity_candidates', 'candidate_count' => count($agreed)];
        if (count($agreed) === 1) {
            $candidate = $agreed[0];
            if (!empty($candidate['stale_packaging']))
                return ['matched' => false, 'candidate' => true, 'reason' => 'identity_match_stale_packaging', 'tier' => 'identity_stale',
                    'stale_packaging' => true, 'catalog_price' => molavi_domain_match_number($candidate['consumer_price'] ?? null)]
                    + molavi_domain_reference_backfill_detail($candidate);
            $conflict = molavi_domain_reference_backfill_identity_conflict($candidate);
            if ($conflict !== '')
                return ['matched' => false, 'reason' => 'attribute_conflict', 'conflict_key' => $conflict, 'candidate_count' => 1];
            $catalog = molavi_domain_match_number($candidate['consumer_price'] ?? null);
            return ['matched' => true, 'reason' => 'unique_full_identity_price_agreed', 'tier' => 'full_identity',
                'sheet_price' => $sheet, 'catalog_price' => $catalog,
                'price_ratio' => round(abs($catalog - $sheet) / $sheet, 4, PHP_ROUND_HALF_EVEN)] + molavi_domain_reference_backfill_detail($candidate);
        }
        if (count($identity) === 1) {
            $candidate = $identity[0];
            if (!empty($candidate['stale_packaging']))
                return ['matched' => false, 'candidate' => true, 'reason' => 'identity_match_stale_packaging', 'tier' => 'identity_stale',
                    'stale_packaging' => true, 'catalog_price' => molavi_domain_match_number($candidate['consumer_price'] ?? null)]
                    + molavi_domain_reference_backfill_detail($candidate);
            $conflict = molavi_domain_reference_backfill_identity_conflict($candidate);
            if ($conflict !== '')
                return ['matched' => false, 'reason' => 'attribute_conflict', 'conflict_key' => $conflict, 'candidate_count' => 1];
            $catalog = molavi_domain_match_number($candidate['consumer_price'] ?? null);
            if (!$catalog)
                return ['matched' => false, 'candidate' => true, 'reason' => 'identity_match_no_catalog_price', 'tier' => 'identity_candidate',
                    'catalog_price' => 0] + molavi_domain_reference_backfill_detail($candidate);
            $ratio = abs($catalog - $sheet) / $sheet;
            return ['matched' => false, 'candidate' => true, 'reason' => 'identity_match_price_outside_margin', 'tier' => 'identity_candidate',
                'sheet_price' => $sheet, 'catalog_price' => $catalog,
                'price_ratio' => round($ratio, 4, PHP_ROUND_HALF_EVEN)] + molavi_domain_reference_backfill_detail($candidate);
        }
        return ['matched' => false, 'reason' => 'multiple_identity_candidates', 'candidate_count' => count($identity)];
    }
    // A tier-1 candidate that fails on conflict or price is a real disagreement — never fall through
    // to a weaker candidate; only an empty tier-1 band opens tier 2.
    $high = molavi_domain_reference_backfill_pick($candidates, MOLAVI_REFERENCE_BACKFILL_MIN_NAME_SIMILARITY);
    if ($high['candidate'] !== null) return molavi_domain_reference_backfill_price_checked($high['candidate'], $sheet, 'high');
    if ($high['reason'] === 'multiple_candidates')
        return ['matched' => false, 'reason' => 'multiple_high_candidates', 'candidate_count' => $high['candidate_count']];
    $lower = molavi_domain_reference_backfill_pick($candidates, MOLAVI_REFERENCE_BACKFILL_REVIEW_SIMILARITY);
    if ($lower['candidate'] !== null) return molavi_domain_reference_backfill_price_checked($lower['candidate'], $sheet, 'lower_similarity');
    if ($lower['reason'] === 'multiple_candidates')
        return ['matched' => false, 'reason' => 'multiple_candidates', 'candidate_count' => $lower['candidate_count']];
    return ['matched' => false, 'reason' => 'no_candidate'];
}

/** Scan every Products row against the active snapshot. No writes. */
function molavi_domain_reference_backfill_scan(array $tables, array $snapshot): array {
    $rows = $snapshot['rows'] ?? [];
    $snapshotId = (string) ($snapshot['snapshot_id'] ?? '');
    $products = molavi_domain_records($tables['Products'] ?? []);
    $offers = molavi_domain_records($tables['Offers'] ?? []);
    $byOffer = [];
    foreach ($offers as $offer) {
        $id = (string) molavi_domain_py_value($offer['Offer ID'] ?? null);
        if ($id !== '') $byOffer[$id] = $offer;
    }
    $outcomes = [];
    $scanned = 0;
    foreach ($products as $index => $record) {
        $productId = (string) molavi_domain_py_value($record['Product ID'] ?? null);
        $base = ['product_id' => $productId, 'product_name' => (string) molavi_domain_py_value($record['Product Name'] ?? null),
            'row' => $index + 2, 'snapshot_id' => $snapshotId];
        // Kept-match-first: the kept row is the record of the match, so it
        // decides before any re-scan. An active row means the product is
        // already matched (never re-decide, never rewrite); a needs_review
        // row belongs to the owner. A retired row (Q7) does NOT block a
        // re-scan: the backfill may propose a new candidate for it, but
        // must never auto-apply one (see the downgrade after decide()
        // below and the guard in apply()).
        $kept = function_exists('molavi_domain_reference_match_get')
            ? molavi_domain_reference_match_get($productId, 'catalog') : null;
        $retiredKept = $kept !== null && ($kept['status'] ?? '') === 'retired';
        if ($kept !== null && !$retiredKept && in_array($kept['status'] ?? '', ['active', 'needs_review'], true)) {
            $outcomes[] = $base + ['status' => 'skipped', 'reason' => [
                'active' => 'already_matched', 'needs_review' => 'match_needs_review',
            ][$kept['status']]];
            continue;
        }
        $matchId = (string) molavi_domain_py_value($record['Reference Match ID'] ?? null);
        $approval = (string) molavi_domain_py_value($record['Reference Approval State'] ?? null);
        // A sheet that still echoes the RETIRED reference is stale, not a
        // live match: the retired kept row wins and the product is
        // re-scanned for a candidate (Q7). A sheet match id naming a
        // different reference is a live sheet match and still skips.
        $staleRetiredSheet = $retiredKept && $matchId !== '' && $matchId === (string) ($kept['reference_id'] ?? '');
        if ($matchId !== '' && $approval !== 'needs_review' && !$staleRetiredSheet) {
            $outcomes[] = $base + ['status' => 'skipped', 'reason' => 'already_matched'];
            continue;
        }
        if ((string) molavi_domain_py_value($record['Reference Decision ID'] ?? null) !== '') {
            $adoptOffer = (string) molavi_domain_py_value($record['Latest Offer ID'] ?? null);
            $outcomes[] = $base + ['status' => 'skipped', 'reason' => 'reference_decision_exists',
                'offer_id' => $adoptOffer,
                'case_id' => $adoptOffer !== '' ? molavi_domain_reference_case_id($adoptOffer, $snapshotId) : ''];
            continue;
        }
        $status = strtolower(trim((string) molavi_domain_py_value($record['Status'] ?? null)));
        if (!in_array($status, ['imported', 'approved', 'needs_review'], true)) {
            $outcomes[] = $base + ['status' => 'skipped', 'reason' => 'status_not_eligible'];
            continue;
        }
        $offerId = (string) molavi_domain_py_value($record['Latest Offer ID'] ?? null);
        $offer = $offerId !== '' ? ($byOffer[$offerId] ?? null) : null;
        if (!$offer) {
            $outcomes[] = $base + ['status' => 'skipped', 'reason' => 'offer_missing'];
            continue;
        }
        $case = molavi_domain_reference_case_id($offerId, $snapshotId);
        if (molavi_domain_reference_latest($case)) {
            $outcomes[] = $base + ['status' => 'skipped', 'reason' => 'reference_decision_exists',
                'offer_id' => $offerId, 'case_id' => $case];
            continue;
        }
        $scanned++;
        $attrs = molavi_domain_offer_attributes($offer);
        // The signature is computed from the plain offer attributes, exactly
        // as the reference review cases do; supplier_price only feeds the
        // candidate role hint and must not change it.
        $signature = molavi_domain_reference_signature($attrs);
        $attrs['supplier_price'] = molavi_domain_py_value($offer['Price'] ?? null, molavi_domain_py_value($offer['Sale Price'] ?? null));
        $decision = molavi_domain_reference_backfill_decide($record, molavi_domain_reference_candidates($attrs, $rows, 20));
        if ($retiredKept && !empty($decision['matched'])) {
            // Q7 carve-out: a retired match makes the product
            // candidate-only. The auto-apply tier is downgraded to a
            // candidate for the owner to pick; nothing is written until
            // the owner picks (apply() only writes 'matched' outcomes,
            // and refuses retired products outright).
            $decision = array_merge($decision, ['matched' => false, 'candidate' => true,
                'reason' => 'retired_match_candidate_only']);
        }
        $status = !empty($decision['matched']) ? 'matched' : (!empty($decision['candidate']) ? 'candidate' : 'skipped');
        $outcomes[] = $base + ['status' => $status, 'reason' => $decision['reason'],
            'offer_id' => $offerId, 'case_id' => $case,
            'signature' => $signature] + $decision;
    }
    return ['snapshot_id' => $snapshotId, 'scanned' => $scanned, 'outcomes' => $outcomes];
}

/** Write the scanned matches: decision row, projection, approval restatement. */
function molavi_domain_reference_backfill_apply(array $scan, string $actor, ?string $clock = null): array {
    $now = $clock ?? molavi_domain_now();
    $results = [];
    foreach ($scan['outcomes'] as $outcome) {
        if ($outcome['status'] !== 'matched') continue;
        $productId = $outcome['product_id'];
        try {
            $written = molavi_domain_lock('publication', static function () use ($outcome, $actor, $now): array {
                $productId = $outcome['product_id'];
                // Re-read fresh tables inside the lock; the scanned row may be stale.
                $tables = molavi_domain_sheets(['Products', 'Offers'], true);
                $headers = array_map('strval', $tables['Products'][0] ?? []);
                $records = molavi_domain_records($tables['Products']);
                $index = molavi_domain_find_product($records, $productId);
                if ($index === null) molavi_domain_fail('product_changed', 409);
                $record = $records[$index];
                if ((string) molavi_domain_py_value($record['Reference Match ID'] ?? null) !== ''
                    && (string) molavi_domain_py_value($record['Reference Approval State'] ?? null) !== 'needs_review')
                    molavi_domain_fail('already_matched', 409);
                // Q7 carve-out (defense in depth: scan() already
                // downgrades retired products to candidates, so a
                // 'matched' outcome for one should never arrive here):
                // a retired kept match is candidate-only until the owner
                // picks. Refusing here keeps the decision row unwritten
                // too — the product stays a candidate, not a match.
                $keptNow = function_exists('molavi_domain_reference_match_get')
                    ? molavi_domain_reference_match_get($productId, 'catalog') : null;
                if ($keptNow !== null && ($keptNow['status'] ?? '') === 'retired') molavi_domain_fail('match_retired', 409);
                $offers = molavi_domain_records($tables['Offers']);
                $offer = null;
                foreach ($offers as $row)
                    if ((string) molavi_domain_py_value($row['Offer ID'] ?? null) === $outcome['offer_id']) {
                        $offer = $row;
                        break;
                    }
                if (!$offer) molavi_domain_fail('offer_missing', 409);
                if (molavi_domain_reference_latest($outcome['case_id'])) molavi_domain_fail('review_conflict', 409);
                $name = (string) molavi_domain_py_value($outcome['reference_name'], $outcome['reference_id']);
                $short = function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
                $tier = (string) ($outcome['tier'] ?? 'high');
                if ($tier === 'exact_name') {
                    $note = sprintf('Auto-match backfill (exact name, no sheet price): single catalog candidate %s (%s), name similarity %.4f, all provided attributes match, no sheet consumer price to cross-check; catalog consumer price %d recorded as the regular price.',
                        $short, $outcome['reference_id'], $outcome['name_similarity'], $outcome['catalog_price']);
                } elseif ($tier === 'lower_similarity') {
                    $note = sprintf('Auto-match backfill (lower name similarity): single catalog candidate %s (%s), name similarity %.4f, all provided attributes match, catalog consumer price %d within %.2f%% of sheet consumer price %d.',
                        $short, $outcome['reference_id'], $outcome['name_similarity'], $outcome['catalog_price'], $outcome['price_ratio'] * 100, $outcome['sheet_price']);
                } elseif ($tier === 'full_identity') {
                    $note = sprintf('Auto-match backfill (full identity): single catalog candidate %s (%s), brand, product tokens, variant, size and pack all agree (name similarity %.4f), catalog consumer price %d within %.2f%% of sheet consumer price %d.',
                        $short, $outcome['reference_id'], $outcome['name_similarity'], $outcome['catalog_price'], $outcome['price_ratio'] * 100, $outcome['sheet_price']);
                } else {
                    $note = sprintf('Auto-match backfill: single catalog candidate %s (%s), name similarity %.4f, all provided attributes match, catalog consumer price %d within %.2f%% of sheet consumer price %d.',
                        $short, $outcome['reference_id'], $outcome['name_similarity'], $outcome['catalog_price'], $outcome['price_ratio'] * 100, $outcome['sheet_price']);
                }
                $decision = molavi_domain_reference_decide([
                    'case_id' => $outcome['case_id'], 'offer_id' => $outcome['offer_id'], 'snapshot_id' => $outcome['snapshot_id'],
                    'signature' => $outcome['signature'], 'choice' => 'match', 'reference_id' => $outcome['reference_id'],
                    'price_role' => 'unit', 'actor_id' => $actor,
                    'note' => $note,
                ], null, $now);
                $matching = molavi_domain_offers_for_product($offers,
                    (string) molavi_domain_py_value($record['Product Name'] ?? null),
                    (string) molavi_domain_py_value($record['Channel Username'] ?? null),
                    molavi_domain_offer_identity_fields($record));
                if (!$matching) molavi_domain_fail('offer_missing', 409);
                molavi_domain_refresh_conflict_product(['product_id' => $productId,
                    'product_name' => (string) molavi_domain_py_value($record['Product Name'] ?? null),
                    'channel' => (string) molavi_domain_py_value($record['Channel Username'] ?? null)],
                    $record, $headers, $matching, $now);
                // Kept match, same publication lock as the decision + the
                // projection above. A recording failure must not undo the
                // auto-match: adoption rebuilds it from the decision row on
                // the next run, so log and continue.
                if(function_exists('molavi_domain_reference_match_record'))try{
                    $snapshot = molavi_domain_reference_active();
                    $match = $snapshot ? molavi_domain_reference_match_from_snapshot([
                        'product_id' => $productId, 'source' => 'catalog', 'offer_id' => $outcome['offer_id'],
                        'signature' => $outcome['signature'], 'reference_id' => $outcome['reference_id'],
                        'price_role' => 'unit', 'snapshot_id' => $outcome['snapshot_id'],
                        'decision_id' => $decision['decision_id'], 'match_basis' => 'backfill',
                        'score' => $outcome['score'] ?? null, 'name_similarity' => $outcome['name_similarity'] ?? null,
                        'sheet_consumer_price' => isset($outcome['sheet_price']) ? (int) $outcome['sheet_price'] : null,
                        'title' => (string) molavi_domain_py_value($record['Product Name'] ?? null),
                    ], $snapshot) : null;
                    if ($match !== null) molavi_domain_reference_match_record($match, $actor);
                }catch(Throwable $e){error_log('[molavi-native] reference_match_record_failed product='.$productId);}
                // The projection records the decision as owner_approved; the
                // backfill's basis is the 5% reference margin for the
                // price-checked tiers, so restate it as approved_within_margin
                // with the measured change percent, as reference reconciliation
                // does. The exact-name tier has no sheet price to compare
                // against, so it restates as approved_compatible — the
                // extraction path's state for an exact compatible identity —
                // with no change percent (empty, the codebase's "not measured").
                $fresh = molavi_domain_records(molavi_domain_sheets(['Products'], true, true)['Products']);
                $freshIndex = molavi_domain_find_product($fresh, $productId);
                if ($freshIndex === null) molavi_domain_fail('reference_product_receipt_mismatch', 503);
                $freshRecord = $fresh[$freshIndex];
                if ((string) molavi_domain_py_value($freshRecord['Reference Decision ID'] ?? null) !== $decision['decision_id']
                    || (string) molavi_domain_py_value($freshRecord['Reference Match ID'] ?? null) !== $outcome['reference_id'])
                    molavi_domain_fail('reference_product_receipt_mismatch', 503);
                $fields = $tier === 'exact_name'
                    ? ['Reference Approval State' => 'approved_compatible',
                        'Reference Price Change Percent' => '',
                        'Reference Price Checked At' => $now, 'Reference Review Reason' => '']
                    : ['Reference Approval State' => 'approved_within_margin',
                        'Reference Price Change Percent' => round($outcome['price_ratio'] * 100, 4, PHP_ROUND_HALF_EVEN),
                        'Reference Price Checked At' => $now, 'Reference Review Reason' => ''];
                $cells = [];
                foreach ($fields as $field => $value) {
                    $column = array_search($field, $headers, true);
                    if ($column === false) molavi_domain_fail('sheet_header_missing', 409, ['field' => $field]);
                    $cells[] = ['range' => molavi_domain_sheet_column($column + 1) . ($freshIndex + 2), 'values' => [[$value]]];
                }
                molavi_domain_sheet_cells('Products', $cells);
                $verified = molavi_domain_records(molavi_domain_sheets(['Products'], true, true)['Products']);
                $check = $verified[$freshIndex] ?? [];
                foreach ($fields as $field => $value) {
                    $actual = $check[$field] ?? '';
                    $matches = is_int($value) || is_float($value)
                        ? (is_numeric($actual) && (float) $actual === (float) $value)
                        : ((string) $actual === (string) $value);
                    if (!$matches) molavi_domain_fail('reference_product_receipt_mismatch', 503);
                }
                return ['decision_id' => $decision['decision_id']];
            }, 'pipeline');
            $results[] = $outcome + ['written' => true, 'decision_id' => $written['decision_id']];
        } catch (MolaviWorkflowError $e) {
            $results[] = $outcome + ['written' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[molavi-native] reference_backfill_failed product=' . $productId . ' code=' . get_class($e));
            $results[] = $outcome + ['written' => false, 'error' => 'backfill_failed'];
        }
    }
    return $results;
}

/** One-time adoption: a reference_item_decisions row exists for the current
 * case but no kept match row. Create the match from the decision (reference
 * id, price role, snapshot id, catalog prices from that snapshot, decision
 * id; basis inferred from the decision note as backfill when the note is a
 * backfill note, else owner_pick). Decisions with choice 'no_match' or
 * 'proceed_without_reference' adopt as NO match row. A retired kept row is
 * never re-created by adoption (the writer fails match_retired for basis
 * backfill, and adoption skips when any row exists); an owner pick records
 * its match directly through the reviews API (writer carve-out for owner
 * bases), so adoption never needs to revive a retired row. A needs_review
 * row blocks a replacement — both are skipped, not adopted. In dry-run mode
 * nothing is written; the return value is the count that WOULD adopt. */
function molavi_domain_reference_backfill_adopt(array $scan, string $actor, bool $dryRun): int {
    $snapshot = molavi_domain_reference_active();
    if (!$snapshot) return 0;
    $products = [];
    try {
        foreach (molavi_domain_records(molavi_domain_sheets(['Products'], true)['Products'] ?? []) as $record)
            $products[(string) molavi_domain_py_value($record['Product ID'] ?? null)] = $record;
    } catch (Throwable $e) {
        // Without the sheet, sheet consumer prices stay null; adoption still proceeds.
    }
    $adopted = 0;
    foreach ($scan['outcomes'] as $outcome) {
        if (($outcome['status'] ?? '') !== 'skipped' || ($outcome['reason'] ?? '') !== 'reference_decision_exists') continue;
        $case = (string) ($outcome['case_id'] ?? '');
        if ($case === '') continue;
        $decision = molavi_domain_reference_latest($case);
        if (!$decision || ($decision['choice'] ?? '') !== 'match' || ($decision['reference_id'] ?? '') === '') continue;
        if (!in_array($decision['price_role'] ?? '', ['unit', 'package'], true)) continue;
        $existing = molavi_domain_reference_match_get((string) $outcome['product_id'], 'catalog');
        if ($existing !== null) continue;
        $record = $products[(string) $outcome['product_id']] ?? null;
        $match = molavi_domain_reference_match_from_snapshot([
            'product_id' => (string) $outcome['product_id'], 'source' => 'catalog',
            'offer_id' => (string) ($decision['offer_id'] ?? ($outcome['offer_id'] ?? '')),
            'signature' => (string) ($decision['signature'] ?? ''),
            'reference_id' => (string) $decision['reference_id'],
            'price_role' => (string) $decision['price_role'],
            'snapshot_id' => (string) ($decision['snapshot_id'] ?? ($scan['snapshot_id'] ?? '')),
            'decision_id' => (string) $decision['decision_id'],
            'match_basis' => str_contains((string) ($decision['note'] ?? ''), 'Auto-match backfill') ? 'backfill' : 'owner_pick',
            'sheet_consumer_price' => $record ? (molavi_domain_positive_price($record['Consumer Price'] ?? null) ?: null) : null,
            'title' => (string) ($outcome['product_name'] ?? ''),
        ], $snapshot);
        if ($match === null) continue;
        if ($dryRun) {
            $adopted++;
            continue;
        }
        try {
            molavi_domain_reference_match_record($match, $actor);
            $adopted++;
        } catch (MolaviWorkflowError $e) {
            error_log('[molavi-native] reference_match_adopt_failed product=' . (string) $outcome['product_id'] . ' code=' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('[molavi-native] reference_match_adopt_failed product=' . (string) $outcome['product_id']);
        }
    }
    return $adopted;
}

/** POST /reference/snapshots action 'backfill_reference_matches'. Dry-run by default. */
function molavi_domain_reference_backfill_api(array $body, string $actor): array {
    $keys = array_keys($body);
    sort($keys);
    if ($keys !== ['action'] && $keys !== ['action', 'dry_run']) molavi_domain_fail('invalid_body', 400);
    $dryRun = true;
    if (array_key_exists('dry_run', $body)) {
        if (!is_bool($body['dry_run'])) molavi_domain_fail('invalid_fields', 400);
        $dryRun = $body['dry_run'];
    }
    if (!molavi_domain_owner_actor($actor)) molavi_domain_fail('owner_required', 403);
    $eligibility = molavi_domain_reference_use();
    $snapshot = molavi_domain_reference_active();
    if (!$eligibility['allow_automatic'] || !$snapshot || ($snapshot['snapshot_id'] ?? null) !== ($eligibility['snapshot_id'] ?? null))
        molavi_domain_fail('reference_snapshot_unavailable', 409, ['reference_state' => $eligibility['state'] ?? null]);
    $snapshotId = (string) $snapshot['snapshot_id'];
    $scan = molavi_domain_reference_backfill_scan(molavi_domain_sheets(['Products', 'Offers'], true), $snapshot);
    $public = static function (array $outcome): array {
        $row = ['product_id' => $outcome['product_id'], 'product_name' => $outcome['product_name'],
            'status' => $outcome['status'], 'reason' => $outcome['reason']];
        if (in_array($outcome['status'], ['matched', 'candidate'], true)) {
            $row += ['reference_id' => $outcome['reference_id'], 'reference_name' => $outcome['reference_name'],
                'score' => $outcome['score'], 'name_similarity' => $outcome['name_similarity'],
                'tier' => $outcome['tier'] ?? null, 'catalog_price' => $outcome['catalog_price']];
            if (isset($outcome['stale_packaging'])) $row += ['stale_packaging' => $outcome['stale_packaging']];
            if (isset($outcome['sheet_price'])) $row += ['sheet_price' => $outcome['sheet_price'], 'price_ratio' => $outcome['price_ratio']];
        }
        if (isset($outcome['decision_id'])) $row['decision_id'] = $outcome['decision_id'];
        if (isset($outcome['error'])) $row['error'] = $outcome['error'];
        return $row;
    };
    $skipped = [];
    foreach ($scan['outcomes'] as $outcome)
        if ($outcome['status'] === 'skipped') $skipped[$outcome['reason']] = ($skipped[$outcome['reason']] ?? 0) + 1;
    $matched = array_values(array_filter($scan['outcomes'], static fn($outcome) => $outcome['status'] === 'matched'));
    $candidates = array_values(array_filter($scan['outcomes'], static fn($outcome) => $outcome['status'] === 'candidate'));
    $result = ['ok' => true, 'action' => 'backfill_reference_matches', 'dry_run' => $dryRun, 'snapshot_id' => $snapshotId,
        'scanned' => $scan['scanned'], 'matched' => count($matched), 'candidates' => count($candidates),
        'written' => 0, 'adopted' => 0, 'skipped' => $skipped, 'matches' => [], 'candidate_list' => []];
    if ($dryRun) {
        $result['adopted'] = function_exists('molavi_domain_reference_match_record')
            ? molavi_domain_reference_backfill_adopt($scan, $actor, true) : 0;
        $result['matches'] = array_map($public, $matched);
        $result['candidate_list'] = array_map($public, $candidates);
        return $result;
    }
    $applied = molavi_domain_reference_backfill_apply($scan, $actor);
    $result['adopted'] = function_exists('molavi_domain_reference_match_record')
        ? molavi_domain_reference_backfill_adopt($scan, $actor, false) : 0;
    $written = 0;
    $failures = [];
    foreach ($applied as $outcome) {
        if (!empty($outcome['written'])) $written++;
        else $failures[] = $public($outcome);
    }
    $result['written'] = $written;
    $result['matches'] = array_map($public, $applied);
    $result['candidate_list'] = array_map($public, $candidates);
    $result['failures'] = $failures;
    if ($written) {
        try {
            $fresh = molavi_domain_sheets(['Products', 'Offers', 'Shop'], true);
            $result['publish_job'] = molavi_domain_observe_projection(molavi_domain_projection_digest($fresh));
            try {
                $result['product_status_snapshot'] = molavi_domain_status_capture($fresh, 'reference_backfill:' . $snapshotId);
            } catch (Throwable $e) {
                error_log('[molavi-native] reference_backfill_snapshot_failed');
            }
        } catch (Throwable $e) {
            error_log('[molavi-native] reference_backfill_observe_failed');
            $result['publish_job'] = null;
            $result['projection_observed'] = false;
        }
    }
    return $result;
}
