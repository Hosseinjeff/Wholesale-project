<?php
/** Plugin Name: Molavi Supplier Auto-Verify (owner-approved)
 *
 * The owner approved this on the 2026-11-26 voice call ("that's the whole
 * purpose of it"): when the evidence is already solid, the system writes
 * the supplier-price verification itself instead of waiting on the owner.
 *
 * Auto-verify ONLY when BOTH hold for a product:
 *   (a) the supplier link check proves the supplier price is unchanged
 *       from the live post (outcome 'unchanged', still in the confirm
 *       queue, and the row's stored sale price still equals the sheet
 *       Sale Price — the price has not changed since the check); AND
 *   (b) the regular price is established by ONE of four legs, tried in
 *       this order — market-first, catalog, link-message, market:
 *       FIRST, the DISPLAYED regular price (Q4, owner decision
 *       2026-11-27): when a fresh market source exists, the card shows
 *       the market price (molavi_domain_regular_price_evidence returns
 *       it immediately), so auto-verify judges the market leg against
 *       the price the card actually shows instead of the sheet Consumer
 *       Price. The leg needs the picked market source row (identity
 *       match, re-verified within 24h — the same window the display
 *       uses), the KEPT market match row (reference_product_matches,
 *       source 'market', status 'active', its identity proof matching
 *       the current identity and its URL the picked listing), and the
 *       display evidence itself confirming the market price is current
 *       at exactly per-unit regular x pack. It certifies the DISPLAYED
 *       price, which may differ from the sheet; OR the KEPT catalog
 *       match (reference_product_matches, source 'catalog', status
 *       'active', linking a real decision, matching the current offer's
 *       identity, with an approved price role, the active catalog
 *       snapshot holding that reference_id, the catalog row's
 *       explicitly-marked regular price — the v2 regular-pack column —
 *       EXACTLY equalling the sheet Consumer Price, the active
 *       snapshot's last_checked_at within 24h (fresh), and the catalog
 *       deadline in the future); OR the identical-link-verified message
 *       itself (FIX-1): the live post, re-read and proven identical to
 *       the stored message, states the regular price equal to the
 *       sheet Consumer Price (fresh 'identical' check, latest offer,
 *       stored sale price still equal — the same evidence as the
 *       regular-price renewal in molavi_domain_link_check_regular_deadline);
 *       OR the KEPT market source as a last resort: the owner-picked
 *       market listing, re-verified within 24h, whose per-unit regular
 *       price x pack exactly equals the sheet Consumer Price. When all
 *       legs fail, the reported reason stays the catalog leg's first
 *       failing gate, so existing dry-run reason counts are kept.
 *
 * Q8 (owner decision 2026-11-27): a lone Rezvan price is SUPPLY
 * evidence only. When the catalog row carries a single price — the
 * regular column empty — the catalog leg fails with
 * 'catalog_no_regular_price' and the product falls through to the
 * link-message / market legs; the lone supply price instead
 * corroborates the SUPPLIER leg (its pack price agreeing exactly with
 * the sheet Sale Price is recorded as supply_agrees in the evidence
 * and named in the note) and can never satisfy the regular leg. The
 * title figure (catalog column [5]) is not regular evidence: it is
 * scraped from the product title, not an explicitly-marked regular
 * price. The three legs stay independent: only one leg is ever
 * recorded in the evidence, the link leg never reads catalog prices,
 * and the catalog leg never reads the message price.
 *
 * Q4 market-wins (owner decision 2026-11-27): the market-first leg is
 * judged against the DISPLAYED regular price, not the sheet Consumer
 * Price, so auto-verify can no longer certify a different price than
 * the card displays (the old bug: card shows market 90 while
 * auto-verify certified sheet/catalog 100). "Fresh" is the existing
 * market-source window — verified_at within MOLAVI_MARKET_SOURCE_CHECK_SECONDS
 * (24h), the same window the display, the market tick and the old
 * market leg use; no new clock is invented. The kept market match row
 * is read for lifecycle + identity + listing agreement (active status,
 * identity proof, URL equals the picked listing); it is NOT asked for
 * a decision row — a market pick has none by construction
 * (molavi_domain_market_match_payload sets decision_id null), so
 * requiring is_trusted() would make the leg unreachable — and its
 * stored regular_price is NOT trusted either (it is only updated on
 * re-pick, not on refresh, so it can be stale); the price authority
 * is the freshly re-verified source row. When the market source is
 * stale or absent, or no kept market row agrees with it, the leg does
 * not fire and the order falls back to catalog -> link-message ->
 * market exactly as before. The catalog leg is still evaluated first
 * (for its supply corroboration and as the fallback), but its success
 * is only returned after the market-first leg has been tried.
 *
 * NEVER auto-verified: link-check outcome 'changed' (the supplier
 * actually changed the price — the 6 price_changed items stay with the
 * owner), outcome 'uncheckable', an expired regular price, or a stale
 * supplier price. Nothing here sets or invents a price: both prices
 * come from real sources (the live post + the catalog / market source).
 *
 * The write goes through the existing path: molavi_domain_verify_price
 * with method 'auto' (actor 'system:auto-verify'), the same
 * 'supplier-verification:...' publish job the manual path queues, and
 * the link check is then marked confirmed via
 * molavi_domain_supplier_link_check_confirm_receipt, so the item leaves
 * the confirm queue. The evidence history note names the evidence
 * (link-check 'unchanged' + catalog price match, check ref, snapshot
 * id) and the evidence method is 'auto', so the card shows it was
 * auto-verified, not manually verified.
 *
 * Idempotent (freshness-aware): a product verified for its current
 * revision within the 14-day window, or whose link check is already
 * confirmed, is left alone. Stale evidence for the current revision is
 * re-verifiable: the gate and the in-transaction race guard both apply
 * the same 14-day rule, so a dry-run 'eligible' and a live write agree.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

const MOLAVI_AUTO_VERIFY_ACTOR = 'system:auto-verify';
const MOLAVI_AUTO_VERIFY_PER_TICK = 5;
// Kept for callers that still read the sheet approval states; the catalog
// leg itself no longer consults them (it trusts the kept match row).
const MOLAVI_AUTO_VERIFY_REFERENCE_STATES = ['owner_approved', 'approved_compatible', 'approved_within_margin', 'approved_auto_adopted'];

/**
 * The latest Offers-sheet row for a product — the offer the Products
 * record's Latest Offer ID points at — or null when there is none.
 * Cached per request, so a tick over the whole confirm queue costs one
 * sheet read. The link-message leg needs the offer row itself (its
 * Consumer Price is what the identical live message states); the
 * freshness offer passed to the check carries no consumer price.
 */
function molavi_domain_auto_verify_latest_offer(array $record): ?array {
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        if (function_exists('molavi_domain_sheets') && function_exists('molavi_domain_records')) {
            $tables = molavi_domain_sheets(['Offers']);
            foreach (molavi_domain_records($tables['Offers'] ?? []) as $row) {
                $oid = trim((string)($row['Offer ID'] ?? ''));
                if ($oid !== '') $byId[$oid] = $row;
            }
        }
    }
    $latest = trim((string)($record['Latest Offer ID'] ?? ''));
    return $latest === '' ? null : ($byId[$latest] ?? null);
}

/**
 * Q8 two-price read (owner decision 2026-11-27): the catalog row's
 * explicit prices, pack basis. 'regular' is the v2 regular-pack column
 * [23] — null on v1 rows (21 columns carry no regular column) and null
 * when the listing carries a single price (a lone supply price has no
 * regular: supply with regular empty). 'supply' is the v2 supply-pack
 * column [21], falling back to [3] on v1 rows, where the single price
 * IS the supply price. The title figure [5] is deliberately NOT read
 * here: it is scraped from the product title, not an explicitly-marked
 * regular price. A single lone price can never satisfy both legs: the
 * regular leg needs 'regular' non-empty, and a non-empty regular
 * column implies a genuine sale (sale < regular per the parser), so
 * the supply and regular prices are distinct values.
 */
function molavi_domain_auto_verify_catalog_prices(array $row): array {
    $num = static fn($value): ?int => molavi_domain_positive_price($value) ?: null;
    if (count($row) === 26) return ['supply' => $num($row[21] ?? null), 'regular' => $num($row[23] ?? null)];
    return ['supply' => $num($row[3] ?? null), 'regular' => null];
}

/**
 * Gate check. Returns ['eligible'=>bool, 'reason'=>string, 'evidence'=>?array].
 * The reason is the first failing gate, so the dry-run report can say why
 * each confirm-queue item does NOT qualify. Leg order (Q4): the
 * market-first leg is tried before the catalog leg's success is returned
 * (it certifies the displayed market price); then, when it does not fire,
 * the catalog leg, the link-message leg, and the kept-market-source leg;
 * the reported reason stays the catalog leg's first failing gate, so existing
 * dry-run reason counts are kept. The evidence (check row, catalog price,
 * snapshot id) is returned only when eligible.
 */
function molavi_domain_auto_verify_check(array $record, array $offer, int $now): array {
    $no = static fn(string $reason): array => ['eligible' => false, 'reason' => $reason, 'evidence' => null];
    $id = (string)($offer['product_id'] ?? '');
    $revision = (string)($offer['source_revision'] ?? '');
    if ($id === '' || $revision === '') return $no('no_product_or_revision');
    $check = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_supplier_link_check_row($db, $id));
    if (!$check) return $no('no_link_check');
    // Exclusion: a changed live price is never auto-verified; an
    // uncheckable link is never auto-verified either.
    if ($check['outcome'] === 'changed') return $no('link_check_changed');
    if ($check['outcome'] !== 'unchanged') return $no('link_check_uncheckable');
    if ($check['confirmed_at'] !== null) return $no('link_check_confirmed');
    if ($check['lease'] !== null) return $no('link_check_leased');
    if ((string)($check['source_revision'] ?? '') !== $revision) return $no('revision_mismatch');
    if ((string)($check['checked_at'] ?? '') === '') return $no('link_check_unchecked');
    // The stored price must still equal the current sheet price: the
    // price has not changed since the check (the stale-price exclusion).
    $sheetSale = (string)molavi_domain_py_value($record['Sale Price'] ?? null);
    if ((string)($check['stored_sale_price'] ?? '') !== $sheetSale) return $no('sale_price_changed');
    // Idempotency at the gate, so the dry-run count equals the live write
    // count — but freshness-aware: evidence verified within the 14-day
    // window still counts as already verified, while stale evidence for
    // the current revision is re-verifiable (the 9 stale Oct 25–Nov 11
    // items whose link checks are fresh 'identical'). A verified_at that
    // cannot be parsed is not provably current. The in-transaction check
    // in molavi_domain_auto_verify_one applies the same rule, so the gate
    // and the write cannot disagree.
    $saved = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
        'SELECT * FROM supplier_price_evidence WHERE offer_id=?', [$id]));
    if ($saved && (string)$saved['source_revision'] === $revision) {
        $verifiedAt = molavi_domain_timestamp($saved['verified_at'] ?? null);
        if ($verifiedAt !== null && $verifiedAt + 14*86400 > $now) return $no('already_verified');
    }
    $consumer = molavi_domain_positive_price($record['Consumer Price'] ?? null);
    // Second leg, catalog OR identical-link message OR kept market source.
    // The catalog leg reads the KEPT match row, not the sheet's projected
    // Reference Match ID: the sheet fields alone were untrusted (any stale
    // projection could claim a match), so the row must link a real
    // decision, match the current offer's identity, and carry an approved
    // price role. The price itself is always re-read from the CURRENT
    // active snapshot at verify time — never trusted from the row — and
    // must EXACTLY equal the sheet Consumer Price. The leg returns its
    // first failing reason when it does not hold, so the dry-run report
    // keeps its existing reason counts.
    $catalogLeg = static function () use ($record, $id, $now, $consumer): array {
        $noSupply = ['ok' => false, 'supply_price' => null];
        if (!function_exists('molavi_domain_reference_match_get')
            || !function_exists('molavi_domain_reference_match_is_trusted'))
            return $noSupply + ['reason' => 'no_reference_match'];
        $match = molavi_domain_reference_match_get($id, 'catalog');
        // A needs_review or retired row is as if no match existed; the
        // product is ineligible on the catalog leg either way.
        if (!$match || ($match['status'] ?? '') !== 'active')
            return $noSupply + ['reason' => 'no_reference_match'];
        if (!molavi_domain_reference_match_is_trusted($match))
            return $noSupply + ['reason' => 'no_reference_match'];
        // Identity: the match was recorded for one offer identity, and
        // the current (latest) offer must be the same identity, else the
        // match is stale. The latest Offers row is the current offer;
        // when it is unavailable the Products record — the projection of
        // that offer — carries the same fields.
        $latestOffer = molavi_domain_auto_verify_latest_offer($record);
        $attrs = $latestOffer !== null
            ? molavi_domain_offer_attributes($latestOffer)
            : molavi_domain_offer_attributes($record);
        if ((string)($match['signature'] ?? '') !== molavi_domain_reference_signature($attrs))
            return $noSupply + ['reason' => 'no_reference_match'];
        if (!in_array($match['price_role'] ?? '', ['unit', 'package'], true))
            return $noSupply + ['reason' => 'reference_not_approved'];
        if ($consumer <= 0) return $noSupply + ['reason' => 'no_consumer_price'];
        $active = molavi_domain_reference_active();
        if (!$active) return $noSupply + ['reason' => 'no_active_snapshot'];
        $prices = null;
        foreach ($active['rows'] ?? [] as $row) {
            if (!is_array($row) || count($row) < 17) continue;
            $entry = molavi_domain_matching_reference($row);
            if ($entry['reference_id'] === (string)($match['reference_id'] ?? '')) { $prices = molavi_domain_auto_verify_catalog_prices($row); break; }
        }
        if ($prices === null) return $noSupply + ['reason' => 'reference_not_in_catalog'];
        $supply = $prices['supply'];
        $catalog = $prices['regular'];
        // Q8: a lone supply price is supply evidence only. The regular
        // leg trusts ONLY an explicitly-marked regular price — the v2
        // regular-pack column. A kept match whose regular is empty (a
        // single price, which is the supply price) must NOT satisfy the
        // regular leg, however well its supply price agrees. The supply
        // price is returned for the supplier leg below; it is never
        // passed to the regular comparison.
        if ($catalog === null) return ['ok' => false, 'reason' => 'catalog_no_regular_price', 'supply_price' => $supply];
        // EXACT equality, consistent with molavi_domain_reference_regular_deadline.
        if ($catalog !== $consumer) return ['ok' => false, 'reason' => 'catalog_price_mismatch', 'supply_price' => $supply];
        $checkedAt = molavi_domain_timestamp($active['last_checked_at'] ?? $active['extracted_at'] ?? null);
        if ($checkedAt === null || $checkedAt > $now || $checkedAt + MOLAVI_REFERENCE_REFRESH_SECONDS <= $now)
            return ['ok' => false, 'reason' => 'catalog_stale', 'supply_price' => $supply];
        // Authoritative expiry: the catalog deadline must be in the future.
        $deadline = molavi_domain_reference_regular_deadline($record, $consumer, $now);
        if ($deadline <= $now) return ['ok' => false, 'reason' => 'regular_price_expired', 'supply_price' => $supply];
        return ['ok' => true, 'reason' => '', 'catalog' => $catalog, 'consumer' => $consumer,
            'reference_id' => (string)$match['reference_id'], 'snapshot_id' => (string)($active['snapshot_id'] ?? ''),
            'match_id' => $id . ':catalog', 'decision_id' => (string)($match['decision_id'] ?? ''),
            'supply_price' => $supply];
    };
    $leg = $catalogLeg();
    // Q8 supplier corroboration: the kept match's supply price (pack
    // basis, from the same catalog row the leg just read) agreeing with
    // the sheet Sale Price counts toward supplier-price verification —
    // as corroboration of the link-check gate above, never as a
    // replacement for it and never as regular evidence. Exact equality:
    // the same standard the gate applies to stored vs sheet sale price.
    // There is no percentage tolerance in the auto-verify supplier leg
    // (the 2% supplier_price_tolerance is G3's change gate, not this
    // one); importing it here would let a changed price verify.
    $salePrice = molavi_domain_positive_price($sheetSale) ?: null;
    $supplyPrice = $leg['supply_price'] ?? null;
    $supplyAgrees = $salePrice !== null && $supplyPrice !== null && (int)$supplyPrice === $salePrice;
    // Q4 market-first: the catalog leg above is evaluated for its
    // supply corroboration and as the fallback, but its success is
    // only returned AFTER the market-first leg has been tried. When a
    // fresh market source exists, the card displays the market price,
    // so the market leg is judged against that displayed price — the
    // certified regular price is the displayed one, which may differ
    // from the sheet Consumer Price. When the leg does not fire
    // (stale/absent source, or no kept market row agreeing with it)
    // the order below is exactly the old catalog -> link -> market.
    $marketFirst = molavi_domain_auto_verify_market_first_leg($record, $offer, $id, $now);
    if ($marketFirst['ok']) {
        return ['eligible' => true, 'reason' => '', 'evidence' => [
            'product_id' => $id, 'source_revision' => $revision, 'sale_price' => $sheetSale,
            'consumer_price' => $marketFirst['regular_price'], 'catalog_price' => null,
            'reference_id' => $marketFirst['reference_id'],
            'snapshot_id' => '', 'leg' => 'market', 'market_first' => true,
            'displayed_price' => $marketFirst['displayed_price'],
            'match_id' => $marketFirst['match_id'],
            'market_reason' => 'kept_market_source_displayed',
            'market_url' => $marketFirst['url'], 'market_verified_at' => $marketFirst['verified_at'],
            'market_regular_price' => $marketFirst['regular_price'],
            'supply_price' => $supplyPrice, 'supply_agrees' => $supplyAgrees,
            'check_ref' => molavi_domain_supplier_link_check_ref($id, (int)$check['checked_at']),
            'checked_at' => (int)$check['checked_at'], 'check_url' => (string)($check['url'] ?? ''),
        ]];
    }
    if ($leg['ok']) {
        return ['eligible' => true, 'reason' => '', 'evidence' => [
            'product_id' => $id, 'source_revision' => $revision, 'sale_price' => $sheetSale,
            'consumer_price' => $leg['consumer'], 'catalog_price' => $leg['catalog'], 'reference_id' => $leg['reference_id'],
            'snapshot_id' => $leg['snapshot_id'], 'leg' => 'catalog',
            'match_id' => $leg['match_id'], 'decision_id' => $leg['decision_id'],
            'supply_price' => $supplyPrice, 'supply_agrees' => $supplyAgrees,
            'check_ref' => molavi_domain_supplier_link_check_ref($id, (int)$check['checked_at']),
            'checked_at' => (int)$check['checked_at'], 'check_url' => (string)($check['url'] ?? ''),
        ]];
    }
    // Link-message leg (FIX-1): the identical-link-verified message
    // itself is the second leg — the live post, re-read and proven
    // identical to the stored message, states the regular price equal to
    // the sheet Consumer Price. Same renewal evidence as
    // molavi_domain_link_check_regular_deadline: fresh 'identical' check,
    // stored sale == sheet sale (already gated above), latest offer,
    // offer Consumer Price == sheet Consumer Price.
    $linkDeadline = 0;
    if ($consumer > 0 && function_exists('molavi_domain_link_check_regular_deadline')) {
        $latestOffer = molavi_domain_auto_verify_latest_offer($record);
        if ($latestOffer !== null)
            $linkDeadline = molavi_domain_link_check_regular_deadline($record, $latestOffer, $now, $check);
    }
    if ($linkDeadline > $now) {
        return ['eligible' => true, 'reason' => '', 'evidence' => [
            'product_id' => $id, 'source_revision' => $revision, 'sale_price' => $sheetSale,
            'consumer_price' => $consumer, 'catalog_price' => null, 'reference_id' => '',
            'snapshot_id' => '', 'leg' => 'link_message', 'link_deadline' => $linkDeadline,
            'supply_price' => $supplyPrice, 'supply_agrees' => $supplyAgrees,
            'check_ref' => molavi_domain_supplier_link_check_ref($id, (int)$check['checked_at']),
            'checked_at' => (int)$check['checked_at'], 'check_url' => (string)($check['url'] ?? ''),
        ]];
    }
    // Third leg (kept market source, last resort): the owner-picked
    // market listing for this product, re-verified within 24h, whose
    // per-unit regular price x pack exactly equals the sheet Consumer
    // Price. Every step fails closed to 'no_kept_market_source', and
    // that reason lives in the evidence detail only: when all legs fail
    // the reported reason stays the catalog leg's first failing gate.
    $market = molavi_domain_auto_verify_market_leg($record, $id, $now, $consumer);
    if ($market['ok']) {
        return ['eligible' => true, 'reason' => '', 'evidence' => [
            'product_id' => $id, 'source_revision' => $revision, 'sale_price' => $sheetSale,
            'consumer_price' => $consumer, 'catalog_price' => null, 'reference_id' => '',
            'snapshot_id' => '', 'leg' => 'market', 'market_reason' => 'kept_market_source',
            'market_url' => $market['url'], 'market_verified_at' => $market['verified_at'],
            'market_regular_price' => $market['regular_price'],
            'supply_price' => $supplyPrice, 'supply_agrees' => $supplyAgrees,
            'check_ref' => molavi_domain_supplier_link_check_ref($id, (int)$check['checked_at']),
            'checked_at' => (int)$check['checked_at'], 'check_url' => (string)($check['url'] ?? ''),
        ]];
    }
    return $no($leg['reason']);
}

/**
 * Derived-From Offer ID chain, cached per request: the picked market
 * source may live under the latest offer or one of its ancestors.
 */
function molavi_domain_auto_verify_market_parents(): array {
    static $parents = null;
    if ($parents !== null) return $parents;
    $parents = [];
    if (function_exists('molavi_domain_sheets') && function_exists('molavi_domain_records')) {
        try {
            foreach (molavi_domain_records(molavi_domain_sheets(['Offers'])['Offers'] ?? []) as $row)
                $parents[(string)($row['Offer ID'] ?? '')] = (string)($row['Derived From Offer ID'] ?? '');
        } catch (Throwable $e) { $parents = []; }
    }
    return $parents;
}

/**
 * The picked market source row for this product, shared by the
 * market-first leg (Q4) and the last-resort market leg below: the
 * market_product_sources row whose stored identity still matches the
 * current product identity, found over the product:/offer: key chain.
 * Returns ['identity'=>?array, 'row'=>?array] with 'row' null when
 * there is no such source (including offer-only picks, which have no
 * product key). Read-only.
 */
function molavi_domain_auto_verify_market_source(array $record, string $id): array {
    $none = ['identity' => null, 'row' => null];
    if (!function_exists('molavi_domain_market_identity')
        || !function_exists('molavi_domain_market_source_rows')
        || !function_exists('molavi_domain_market_source_for')
        || !function_exists('molavi_domain_market_source_keys')) return $none;
    $identity = molavi_domain_market_identity($record);
    if (!$identity) return $none;
    $rows = molavi_domain_market_source_rows();
    if (!$rows) return $none;
    $found = molavi_domain_market_source_for(
        molavi_domain_market_source_keys($id, (string)($record['Latest Offer ID'] ?? ''),
            molavi_domain_auto_verify_market_parents()),
        $rows, $identity);
    $row = $found['price'] ?? null;
    if (!$row || empty($row['url'])) return $none;
    return ['identity' => $identity, 'row' => $row];
}

/**
 * Market-first leg (Q4, owner decision 2026-11-27). Returns
 * ['ok'=>bool, 'reason'=>string] plus the source detail when ok.
 * Unlike the last-resort market leg, this leg is judged against the
 * DISPLAYED regular price: when a fresh market source exists, the card
 * shows its price, so the price auto-verify certifies must be that
 * price, not the sheet Consumer Price. Conditions, all fail-closed:
 *   - a picked market source row for the current identity (see
 *     molavi_domain_auto_verify_market_source);
 *   - the KEPT market match row (reference_product_matches, source
 *     'market') exists, is 'active', its identity proof (signature,
 *     else identity_json hash) matches the current identity, and its
 *     URL is the picked listing — the kept record is the record of
 *     THIS listing for THIS product identity;
 *   - the source was re-verified within MOLAVI_MARKET_SOURCE_CHECK_SECONDS
 *     (24h, the display's own window — no new clock);
 *   - the display evidence itself (molavi_domain_regular_price_evidence)
 *     confirms the market price is current at exactly per-unit regular
 *     x pack — the exact-agreement bar, against the shown price.
 * The kept row's stored regular_price is deliberately NOT read: it is
 * only updated on re-pick, never on refresh, so it can be stale; the
 * freshly re-verified source row is the price authority. No decision
 * row is required: a market pick has none by construction.
 */
function molavi_domain_auto_verify_market_first_leg(array $record, array $offer, string $id, int $now): array {
    $no = ['ok' => false, 'reason' => 'no_fresh_market_display'];
    if (!function_exists('molavi_domain_reference_match_get')
        || !function_exists('molavi_domain_regular_price_evidence')) return $no;
    $found = molavi_domain_auto_verify_market_source($record, $id);
    $identity = $found['identity'];
    $row = $found['row'];
    if (!$identity || !$row) return $no;
    $match = molavi_domain_reference_match_get($id, 'market');
    if (!$match || ($match['status'] ?? '') !== 'active') return $no;
    $hash = molavi_domain_hash($identity);
    if (($match['signature'] ?? '') !== '') {
        if ((string)$match['signature'] !== $hash) return $no;
    } elseif (($match['identity_json'] ?? '') !== '') {
        if (molavi_domain_hash(molavi_domain_decode($match['identity_json'])) !== $hash) return $no;
    } else return $no;
    if ((string)($match['url'] ?? '') === '' || (string)$match['url'] !== (string)$row['url']) return $no;
    $window = defined('MOLAVI_MARKET_SOURCE_CHECK_SECONDS') ? MOLAVI_MARKET_SOURCE_CHECK_SECONDS : 86400;
    $verifiedAt = (int)($row['verified_at'] ?? 0);
    if ($verifiedAt <= 0 || $verifiedAt > $now || $verifiedAt + $window <= $now) return $no;
    $pack = max(1, (int)($identity['pack_count'] ?? 1));
    $regular = (int)($row['regular_price'] ?? 0);
    if ($regular <= 0) return $no;
    $displayed = $regular * $pack;
    $evidence = molavi_domain_regular_price_evidence($record, $offer, $row, $now);
    if (($evidence['state'] ?? '') !== 'current' || (int)($evidence['amount'] ?? 0) !== $displayed) return $no;
    return ['ok' => true, 'reason' => '', 'url' => (string)$row['url'], 'verified_at' => $verifiedAt,
        'regular_price' => $displayed, 'displayed_price' => $displayed,
        'reference_id' => (string)($match['reference_id'] ?? ''),
        'match_id' => $id . ':market', 'target' => (string)($row['target'] ?? '')];
}

/**
 * Kept-market-source leg. Returns ['ok'=>bool, 'reason'=>string] plus the
 * source detail when ok. The picked source is the market_product_sources
 * row for this product (written when the owner picks a quote, refreshed
 * by the market tick): its stored identity must still match the current
 * product identity, its verified_at must be within 24h, and its per-unit
 * regular price x pack must EXACTLY equal the sheet Consumer Price.
 * Nothing here sets or invents a price.
 */
function molavi_domain_auto_verify_market_leg(array $record, string $id, int $now, int $consumer): array {
    $no = ['ok' => false, 'reason' => 'no_kept_market_source'];
    $found = molavi_domain_auto_verify_market_source($record, $id);
    $identity = $found['identity'];
    $row = $found['row'];
    if (!$row) return $no;
    $window = defined('MOLAVI_MARKET_SOURCE_CHECK_SECONDS') ? MOLAVI_MARKET_SOURCE_CHECK_SECONDS : 86400;
    $verifiedAt = (int)($row['verified_at'] ?? 0);
    if ($verifiedAt <= 0 || $verifiedAt > $now || $verifiedAt + $window <= $now) return $no;
    $pack = max(1, (int)($identity['pack_count'] ?? 1));
    $regular = (int)($row['regular_price'] ?? 0);
    if ($regular <= 0 || $regular * $pack !== $consumer) return $no;
    return ['ok' => true, 'reason' => '', 'url' => (string)$row['url'], 'verified_at' => $verifiedAt,
        'regular_price' => $regular * $pack, 'target' => (string)($row['target'] ?? '')];
}

/** Eligibility (task shape): the evidence when BOTH conditions hold, else null. */
function molavi_domain_auto_verify_eligible(array $record, array $offer, int $now): ?array {
    $check = molavi_domain_auto_verify_check($record, $offer, $now);
    return $check['eligible'] ? $check['evidence'] : null;
}

/**
 * Auto-verify one product through the existing verify path. One
 * transaction: verify (method 'auto') + the same publish job the manual
 * path queues + mark the link check confirmed. If the product is
 * already verified for this revision, or already confirmed, nothing is
 * written and the result is idempotent.
 */
function molavi_domain_auto_verify_one(array $record, array $offer, int $now, ?string $isoNow = null): array {
    $isoNow = $isoNow ?? molavi_domain_now();
    $id = (string)($offer['product_id'] ?? '');
    $check = molavi_domain_auto_verify_check($record, $offer, $now);
    if (!$check['eligible']) return ['ok' => false, 'eligible' => false, 'reason' => $check['reason'], 'product_id' => $id];
    $evidence = $check['evidence'];
    $revision = $evidence['source_revision'];
    if (($evidence['leg'] ?? 'catalog') === 'link_message') {
        $note = 'تأیید خودکار: بررسی لینک تأمین‌کننده پیام منبع را بدون تغییر نشان داد · '.$evidence['check_ref']
            .' · قیمت مصرف‌کننده '.$evidence['consumer_price'].' تومان در پیام منبع با قیمت مصرف‌کننده برابر است';
    } elseif (($evidence['leg'] ?? '') === 'market') {
        // Q4: a market-first certification certifies the DISPLAYED
        // price, which may differ from the sheet Consumer Price, so
        // the note says so instead of claiming sheet agreement.
        if (!empty($evidence['market_first'])) {
            $note = 'تأیید خودکار: بررسی لینک تأمین‌کننده قیمت بدون تغییر نشان داد · '.$evidence['check_ref']
                .' · قیمت عادی بازار '.$evidence['market_regular_price'].' تومان در منبع انتخاب‌شده همان قیمت نمایشی کالاست · '.$evidence['market_url'];
        } else {
            $note = 'تأیید خودکار: بررسی لینک تأمین‌کننده قیمت بدون تغییر نشان داد · '.$evidence['check_ref']
                .' · قیمت عادی بازار '.$evidence['market_regular_price'].' تومان در منبع انتخاب‌شده با قیمت مصرف‌کننده برابر است · '.$evidence['market_url'];
        }
    } else {
        $note = 'تأیید خودکار: بررسی لینک تأمین‌کننده قیمت بدون تغییر نشان داد · '.$evidence['check_ref']
            .' · قیمت عادی مرجع '.$evidence['catalog_price'].' تومان با قیمت مصرف‌کننده برابر است · snapshot '.$evidence['snapshot_id'];
    }
    // Q8: when the kept match's supply price agrees with the sheet sale
    // price, name that corroboration in the note too.
    if (!empty($evidence['supply_agrees'])) {
        $note .= ' · قیمت تأمین‌کننده '.(int)$evidence['supply_price'].' تومان در مرجع با قیمت فروش برابر است';
    }
    return molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($id, $revision, $evidence, $note, $isoNow, $now): array {
        $saved = molavi_domain_row($db, 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', [$id]);
        if ($saved && (string)$saved['source_revision'] === $revision) {
            // Race guard with the same freshness rule as the gate: only
            // still-current evidence is idempotent; stale evidence falls
            // through and is re-verified (the upsert in
            // molavi_domain_verify_price_in_transaction overwrites it).
            $verifiedAt = molavi_domain_timestamp($saved['verified_at'] ?? null);
            if ($verifiedAt !== null && $verifiedAt + 14*86400 > $now) {
                return ['ok' => true, 'product_id' => $id, 'source_revision' => $revision,
                    'verified_at' => (string)$saved['verified_at'], 'method' => (string)($saved['method'] ?? ''),
                    'idempotent' => true, 'already_verified' => true];
            }
        }
        $link = molavi_domain_supplier_link_check_row($db, $id);
        if ($link && $link['confirmed_at'] !== null) {
            return ['ok' => true, 'product_id' => $id, 'source_revision' => $revision,
                'verified_at' => (string)($link['confirmed_verified_at'] ?? ''), 'method' => 'auto',
                'idempotent' => true, 'already_confirmed' => true];
        }
        $verified = molavi_domain_verify_price_in_transaction($db, $id, $revision, MOLAVI_AUTO_VERIFY_ACTOR, $note,
            $isoNow, null, 'auto');
        $receipt = hash('sha256', $revision.'|'.$verified['verified_at']);
        $job = molavi_domain_queue_in_transaction($db, 'publish', 'supplier-verification:'.$id.':'.$receipt,
            ['product_id' => $id, 'source_revision' => $revision]);
        $confirmed = molavi_domain_supplier_link_check_confirm_receipt($db, $id, $evidence['check_ref'],
            $verified['verified_at'], MOLAVI_AUTO_VERIFY_ACTOR, $now);
        return ['ok' => true, 'product_id' => $id, 'source_revision' => $revision,
            'verified_at' => $verified['verified_at'], 'method' => 'auto',
            'check_ref' => $evidence['check_ref'], 'snapshot_id' => $evidence['snapshot_id'],
            'catalog_price' => $evidence['catalog_price'], 'publish_job' => $job,
            'link_check_confirmed' => !empty($confirmed['ok']), 'idempotent' => false];
    });
}

/**
 * Batch runner: scan the confirm queue oldest-first and auto-verify the
 * eligible ones. Per-tick cap of 5 verified (observable and safe); the
 * dry-run computes and reports the eligible list + count WITHOUT writing
 * anything, so the exact qualifying subset of the confirm queue is
 * determined from live data. 'eligible' lists every qualifying product
 * (verified + over-cap remainder in live mode); 'skipped' counts the
 * ineligible queue items by first failing gate.
 */
function molavi_domain_auto_verify_tick(int $now, ?string $isoNow = null, bool $dryRun = false,
    int $cap = MOLAVI_AUTO_VERIFY_PER_TICK): array {
    $isoNow = $isoNow ?? molavi_domain_now();
    $cap = max(0, $cap);
    $queue = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_rows($db,
        "SELECT * FROM supplier_link_checks WHERE outcome='unchanged' AND confirmed_at IS NULL ORDER BY checked_at ASC, product_id"));
    $records = molavi_domain_records(molavi_domain_sheets(['Products'], true)['Products'] ?? []);
    $byId = [];
    foreach ($records as $record) {
        $id = trim((string)($record['Product ID'] ?? ''));
        if ($id !== '') $byId[$id] = $record;
    }
    $offers = [];
    foreach (molavi_domain_freshness(array_values($byId), (float)$now, false) as $offer) $offers[$offer['product_id']] = $offer;
    $eligible = [];
    $verified = [];
    $skipped = [];
    foreach ($queue as $row) {
        $id = (string)$row['product_id'];
        if (!isset($byId[$id], $offers[$id])) { $skipped['product_not_in_sheet'] = ($skipped['product_not_in_sheet'] ?? 0) + 1; continue; }
        $check = molavi_domain_auto_verify_check($byId[$id], $offers[$id], $now);
        if (!$check['eligible']) { $skipped[$check['reason']] = ($skipped[$check['reason']] ?? 0) + 1; continue; }
        if ($dryRun) { $eligible[] = $check['evidence']; continue; }
        if (count($verified) >= $cap) { $eligible[] = $check['evidence']; continue; }
        $result = molavi_domain_auto_verify_one($byId[$id], $offers[$id], $now, $isoNow);
        if (!empty($result['ok']) && empty($result['idempotent'])) { $verified[] = $result; $eligible[] = $check['evidence']; }
        elseif (!empty($result['ok'])) $skipped['already_verified'] = ($skipped['already_verified'] ?? 0) + 1;
        else $skipped[$result['reason'] ?? 'verify_failed'] = ($skipped[$result['reason'] ?? 'verify_failed'] ?? 0) + 1;
    }
    return ['ok' => true, 'dry_run' => $dryRun, 'scanned' => count($queue),
        'eligible_count' => count($eligible), 'eligible' => $eligible,
        'verified' => $verified, 'verified_count' => count($verified), 'cap' => $cap, 'skipped' => $skipped];
}
