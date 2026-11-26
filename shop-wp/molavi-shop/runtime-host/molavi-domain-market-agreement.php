<?php
/** Plugin Name: Molavi Market Agreement Verification (round nine) */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Autonomous re-verification of stale supplier prices by MARKET AGREEMENT:
 * the stored supplier price is checked against current market quotes for
 * the same product. When the quotes agree with each other (G4's reliability
 * standard) and the supplier price sits inside the system's own pricing
 * band when measured against the market price, the price is re-verified
 * with evidence method 'market' and the 14-day clock resets.
 *
 * Comparison (coordinator's choice): IMPLIED SPREAD, using the system's
 * own spread definition, spread = (regular - cost) / regular, where
 * cost = the supplier Sale Price (pack basis, from the Latest Offer) and
 * regular = the market quote's regular price on the product's pack basis
 * (regular_unit_price x pack_count). All prices on the same pack basis;
 * quotes must be exact-pack.
 *
 * Tolerance (agreement band): implied_spread in [0.12, 0.60].
 *   - Lower bound 0.12 = min_margin: below this the supplier price cannot
 *     produce a discountable sale offer (thin_spread), so there is nothing
 *     worth verifying.
 *   - Upper bound 0.60 = spread_high: above this the implied wholesale
 *     margin exceeds the maximum discount the pricing rules contemplate —
 *     the supplier price has likely drifted low relative to the current
 *     market (stale-low) or the quote is pack-mismatched.
 * The band is the system's own pricing band, and it contains the observed
 * healthy spreads (27-39% for the would-admit products; the live-admitted
 * tuna at 34.5%). It is a coarse check by design — it catches clear drift,
 * not precise validation — and every verification is listed in the simple
 * report for the owner to review.
 *
 * Quote reliability reuses G4's standard: >=2 priced quotes with
 * (max-min)/median <= 0.05 (quote_band) -> the median-ranked quote
 * (exact-pack first, pinned source overrides). Exactly 1 quote -> cross-
 * check against the Rezvan reference: |quote - ref|/ref <= 0.05
 * (reference_margin), else no agreement.
 *
 * Safety: this runner never sets or adopts any price and never touches
 * the sheet. A product with no quotes, or quotes that do not agree, stays
 * held (the outcome is recorded, no evidence is written). Before writing,
 * consecutive non-manual verifications are counted exactly as G3 counts
 * them; at >=3 the write is refused ('chain_limit') and the item waits
 * for a manual touch. A 'market' write counts toward G3's chain too,
 * because G3 counts every method except 'manual'.
 *
 * What this does NOT verify: the regular-price gate. Re-verifying the
 * supplier price does not unblock gA Arm 5 by itself — the product still
 * needs a current regular price (sheet price current, owner-picked quote,
 * or a G4 live-pick). Mere quotes in candidates_json do not count as a
 * regular price; only the SELECTED market source does.
 *
 * Cadence/lease/event pattern reused from the link-check runner
 * (molavi-domain-supplier-link-checks.php): one item per tick, 5-minute
 * lease, 24-hour re-check cadence, an events table for audit. A changed
 * supplier revision re-arms the row (due now). The runner reads quotes
 * from the reference DB and writes evidence to the pipeline DB (same
 * cross-DB shape as G4/G3). A verify queues the same
 * supplier-verification publish job as the manual verify, so a newly
 * verified product is re-projected promptly.
 */

const MOLAVI_MARKET_AGREEMENT_MIN_SPREAD = 0.12;
const MOLAVI_MARKET_AGREEMENT_MAX_SPREAD = 0.60;
const MOLAVI_MARKET_AGREEMENT_QUOTE_BAND = 0.05;
const MOLAVI_MARKET_AGREEMENT_REFERENCE_MARGIN = 0.05;
const MOLAVI_MARKET_AGREEMENT_SECONDS = 86400;
const MOLAVI_MARKET_AGREEMENT_LEASE_SECONDS = 300;
const MOLAVI_MARKET_AGREEMENT_CHAIN_LIMIT = 3;
/** Stale-supplier seeds run below the market seed's default priority (0),
 * so they never starve the missing-regular-price lookups. The market seed
 * never deactivates priority<0 rows (see molavi-domain-market-prices.php). */
const MOLAVI_MARKET_AGREEMENT_SEED_PRIORITY = -1;

/** Reason codes. */
function molavi_domain_market_agreement_reason_label(string $code): string {
    return [
        'agreement'=>'نقل‌قول‌های بازار با قیمت تأمین‌کننده همخوان است',
        'rezvan_crosscheck'=>'یک نقل‌قول با مرجع رضوان همخوان است',
        'quotes_disagree'=>'نقل‌قول‌های بازار با هم اختلاف دارند',
        'single_quote_no_reference'=>'فقط یک نقل‌قول هست و مرجع ندارد',
        'rezvan_mismatch'=>'یک نقل‌قول با مرجع تطبیق داده نشد',
        'spread_too_thin'=>'اختلاف ضمنی کمتر از ۱۲٪ است؛ تخفیف‌پذیر نیست',
        'spread_too_wide'=>'اختلاف ضمنی بیشتر از ۶۰٪ است؛ قیمت احتمالاً قدیمی است',
        'no_quotes'=>'هیچ نقل‌قول قابل‌استفاده‌ای نیست',
        'no_match'=>'جست‌وجوی بازار نتیجه‌ای نداشت',
        'lookup_not_ready'=>'جست‌وجوی بازار هنوز آماده نیست',
        'lookup_pending'=>'جست‌وجوی بازار هنوز ثبت نشده',
        'quotes_stale'=>'نقل‌قول‌ها قدیمی‌اند؛ بازخوانی بازار در انتظار است',
        'no_identity'=>'هویت بازار کالا مشخص نیست',
        'chain_limit'=>'چند بار پیاپی خودکار تأیید شده؛ نوبت بررسی دستی است',
        'invalid_price'=>'قیمت قابل‌مقایسه نیست',
    ][$code] ?? $code;
}

/** Stable check reference for the evidence note: market-agreement:<product>@<checked_at>. */
function molavi_domain_market_agreement_ref(string $productId, int $checkedAt): string {
    return 'market-agreement:'.$productId.'@'.$checkedAt;
}

/** Structured provenance note for the 'market' evidence write (<=1000 chars). */
function molavi_domain_market_agreement_note(string $productId, int $checkedAt, float $spread, array $urls): string {
    $note = 'توافق بازار: قیمت تأمین‌کننده با نقل‌قول‌های بازار همخوان است · '
        .molavi_domain_market_agreement_ref($productId, $checkedAt)
        .' · اختلاف ضمنی '.number_format($spread * 100, 1).'٪';
    foreach (array_slice(array_values(array_unique($urls)), 0, 2) as $url) {
        if (!is_string($url) || $url === '') continue;
        $candidate = $note.' · '.$url;
        if (molavi_domain_strlen($candidate) > 1000) break;
        $note = $candidate;
    }
    return $note;
}

/**
 * Pure agreement decision. $quotes are raw candidate quotes (as stored in
 * market_price_lookups.candidates_json); $salePrice is the supplier Sale
 * Price on the pack basis; $pack is the product's pack count (>=1);
 * $referencePrice is the Rezvan catalog consumer price on the pack basis,
 * or null/0 when there is no match; $pinnedUrl is the selected market
 * source URL, which overrides the median pick. Never touches the database.
 * Returns outcome/reason/basis/quote/quote_pack_price/implied_spread/
 * quote_count.
 */
function molavi_domain_market_agreement_classify(array $quotes, float $salePrice, int $pack, $referencePrice, ?string $pinnedUrl): array {
    $pack = max(1, $pack);
    $priced = [];
    foreach ($quotes as $quote) {
        if (!is_array($quote)) continue;
        if ((int)($quote['pack_count'] ?? 1) !== $pack) continue;
        $unit = (int)($quote['regular_unit_price'] ?? $quote['unit_price'] ?? 0);
        if ($unit <= 0) continue;
        $priced[] = $quote;
    }
    usort($priced, static fn($a, $b) => ((int)($a['regular_unit_price'] ?? $a['unit_price'] ?? 0))
        <=> ((int)($b['regular_unit_price'] ?? $b['unit_price'] ?? 0)));
    $count = count($priced);
    if ($count === 0) {
        return ['outcome'=>'no_quotes', 'reason_code'=>'no_quotes', 'basis'=>'', 'quote'=>null,
            'quote_pack_price'=>null, 'implied_spread'=>null, 'quote_count'=>0];
    }
    $units = array_map(static fn($quote) => (int)($quote['regular_unit_price'] ?? $quote['unit_price']), $priced);
    sort($units);
    $median = $units[intdiv($count, 2)];
    $spread = $median > 0 ? (max($units) - min($units)) / $median : 1.0;
    $pick = null;
    $basis = '';
    if ($count >= 2) {
        if ($spread > MOLAVI_MARKET_AGREEMENT_QUOTE_BAND) {
            return ['outcome'=>'disagree', 'reason_code'=>'quotes_disagree', 'basis'=>'', 'quote'=>null,
                'quote_pack_price'=>null, 'implied_spread'=>null, 'quote_count'=>$count];
        }
        $pick = $priced[intdiv($count, 2)];
        $basis = 'agreement';
    } else {
        // Exactly one quote: it only counts when the Rezvan reference backs it.
        $ref = (int)($referencePrice ?? 0);
        if ($ref <= 0) {
            return ['outcome'=>'disagree', 'reason_code'=>'single_quote_no_reference', 'basis'=>'', 'quote'=>null,
                'quote_pack_price'=>null, 'implied_spread'=>null, 'quote_count'=>1];
        }
        $perUnitRef = $ref / $pack;
        $diff = $perUnitRef > 0 ? abs($units[0] - $perUnitRef) / $perUnitRef : 1.0;
        if ($diff > MOLAVI_MARKET_AGREEMENT_REFERENCE_MARGIN) {
            return ['outcome'=>'disagree', 'reason_code'=>'rezvan_mismatch', 'basis'=>'', 'quote'=>null,
                'quote_pack_price'=>null, 'implied_spread'=>null, 'quote_count'=>1];
        }
        $pick = $priced[0];
        $basis = 'rezvan_crosscheck';
    }
    if ($pinnedUrl !== null && $pinnedUrl !== '') {
        foreach ($priced as $quote) {
            if ((string)($quote['url'] ?? '') === $pinnedUrl) { $pick = $quote; break; }
        }
    }
    $pickUnit = (int)($pick['regular_unit_price'] ?? $pick['unit_price'] ?? 0);
    $quotePack = $pickUnit * $pack;
    if ($quotePack <= 0 || $salePrice <= 0) {
        return ['outcome'=>'disagree', 'reason_code'=>'invalid_price', 'basis'=>'', 'quote'=>null,
            'quote_pack_price'=>null, 'implied_spread'=>null, 'quote_count'=>$count];
    }
    $implied = ($quotePack - $salePrice) / $quotePack;
    if ($implied < MOLAVI_MARKET_AGREEMENT_MIN_SPREAD) {
        return ['outcome'=>'disagree', 'reason_code'=>'spread_too_thin', 'basis'=>$basis, 'quote'=>$pick,
            'quote_pack_price'=>$quotePack, 'implied_spread'=>$implied, 'quote_count'=>$count];
    }
    if ($implied > MOLAVI_MARKET_AGREEMENT_MAX_SPREAD) {
        return ['outcome'=>'disagree', 'reason_code'=>'spread_too_wide', 'basis'=>$basis, 'quote'=>$pick,
            'quote_pack_price'=>$quotePack, 'implied_spread'=>$implied, 'quote_count'=>$count];
    }
    return ['outcome'=>'agree', 'reason_code'=>$basis === 'rezvan_crosscheck' ? 'rezvan_crosscheck' : 'agreement',
        'basis'=>$basis, 'quote'=>$pick, 'quote_pack_price'=>$quotePack, 'implied_spread'=>$implied,
        'quote_count'=>$count];
}

/** Priced quotes behind a product's market lookup, cheapest first. Reuses
 * G4's helper when it is loaded; otherwise reads the same lookup row
 * directly and applies the same filter/sort. */
function molavi_domain_market_agreement_quotes(array $record): array {
    if (function_exists('molavi_domain_autopilot_market_quotes')) {
        return molavi_domain_autopilot_market_quotes($record);
    }
    if (!function_exists('molavi_domain_market_identity')) return [];
    $identity = molavi_domain_market_identity($record);
    if (!$identity) return [];
    $row = molavi_domain_read('reference', static function (SQLite3 $db) use ($identity): ?array {
        return molavi_domain_row($db, 'SELECT candidates_json FROM market_price_lookups WHERE lookup_key=?',
            [molavi_domain_hash($identity)]);
    });
    if (!$row) return [];
    try { $quotes = molavi_domain_decode($row['candidates_json'] ?? '[]', []); }
    catch (Throwable $e) { return []; }
    if (!is_array($quotes)) return [];
    $priced = [];
    foreach ($quotes as $quote) {
        $unit = (int)($quote['regular_unit_price'] ?? $quote['unit_price'] ?? 0);
        if ($unit > 0) $priced[] = $quote;
    }
    usort($priced, static fn($a, $b) => ((int)($a['regular_unit_price'] ?? $a['unit_price'] ?? 0))
        <=> ((int)($b['regular_unit_price'] ?? $b['unit_price'] ?? 0)));
    return $priced;
}

/** Consecutive non-manual verifications, newest first — the same count G3
 * uses. A 'manual' history row breaks the chain. 'market' counts, because
 * it is not 'manual', so market writes count toward G3's chain too. */
function molavi_domain_market_agreement_chain_count(SQLite3 $db, string $productId): int {
    $methods = molavi_domain_rows($db,
        'SELECT method FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY id DESC LIMIT 10', [$productId]);
    $chain = 0;
    foreach ($methods as $row) {
        if (($row['method'] ?? '') === 'manual') break;
        $chain++;
    }
    return $chain;
}

function molavi_domain_market_agreement_row(SQLite3 $db, string $productId): ?array {
    return molavi_domain_row($db, 'SELECT * FROM market_agreement_checks WHERE product_id=?', [$productId]);
}

function molavi_domain_market_agreement_public_row(array $row): array {
    return [
        'product_id'=>$row['product_id'], 'offer_id'=>$row['offer_id'], 'title'=>$row['title'],
        'source_revision'=>$row['source_revision'], 'stored_sale_price'=>$row['stored_sale_price'],
        'outcome'=>$row['outcome'], 'reason_code'=>$row['reason_code'],
        'reason_label'=>molavi_domain_market_agreement_reason_label((string)($row['reason_code'] ?? '')),
        'basis'=>$row['basis'], 'quote_url'=>$row['quote_url'],
        'quote_price'=>$row['quote_price'] === null ? null : (int)$row['quote_price'],
        'implied_spread'=>$row['implied_spread'] === null ? null : (float)$row['implied_spread'],
        'checked_at'=>$row['checked_at'] === null ? null : (int)$row['checked_at'],
        'next_at'=>(int)$row['next_at'],
        'check_ref'=>$row['checked_at'] === null ? null : molavi_domain_market_agreement_ref($row['product_id'], (int)$row['checked_at']),
        'verified_at'=>$row['verified_at'],
    ];
}

function molavi_domain_market_agreement_record_event(SQLite3 $db, array $row, string $kind, ?string $old, ?int $quotePrice, ?float $spread, ?string $code, ?string $url, int $now): string {
    $id = bin2hex(random_bytes(16));
    molavi_domain_sql($db, 'INSERT INTO market_agreement_events
        (id,product_id,offer_id,title,kind,old_price,quote_price,implied_spread,code,url,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
        [$id, $row['product_id'], $row['offer_id'], $row['title'], $kind, $old, $quotePrice, $spread, $code, $url, $now]);
    return $id;
}

/**
 * Seed due rows from the Products sheet: every item whose supplier price
 * state is needs_review or blocked and whose market identity is derivable.
 * This is the eligibility widening: unlike molavi_domain_market_records(),
 * a stale regular price does NOT exclude the item — the item is seeded
 * even when it already has a (stale) regular price, because the agreement
 * check is about the supplier price, not the regular price. The market
 * lookup row is seeded at the low priority (-1) so missing-regular-price
 * lookups are not starved; the daily budget (25 lookups) is enforced by
 * molavi_domain_market_run and is not touched here. A new row (or a
 * changed revision) is due now. Returns the number of rows seeded/updated.
 */
function molavi_domain_market_agreement_seed(int $now): int {
    $tables = molavi_domain_sheets(['Products'], true);
    $records = molavi_domain_records($tables['Products'] ?? []);
    $fresh = molavi_domain_freshness($records);
    $byId = [];
    foreach ($fresh as $offer) $byId[$offer['product_id']] = $offer;
    $seeded = 0;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($records, $byId, $now, &$seeded): void {
        foreach ($records as $record) {
            $id = trim((string)($record['Product ID'] ?? ''));
            if ($id === '' || !isset($byId[$id])) continue;
            $offer = $byId[$id];
            if (!in_array($offer['state'], ['needs_review', 'blocked'], true)) continue;
            $identity = function_exists('molavi_domain_market_identity') ? molavi_domain_market_identity($record) : null;
            if (!$identity || !empty($identity['assortment'])) continue;
            if (molavi_domain_positive_price($record['Sale Price'] ?? null) <= 0) continue;
            $revision = (string)($offer['source_revision'] ?? '');
            $existing = molavi_domain_market_agreement_row($db, $id);
            if ($existing && $existing['source_revision'] === $revision
                && $existing['stored_sale_price'] === (string)($record['Sale Price'] ?? '')) continue;
            $row = [
                'product_id'=>$id, 'offer_id'=>(string)($offer['offer_id'] ?? ($record['Latest Offer ID'] ?? '')),
                'title'=>(string)($record['Product Name'] ?? ''), 'source_revision'=>$revision,
                'stored_sale_price'=>(string)($record['Sale Price'] ?? ''),
            ];
            if ($existing) {
                molavi_domain_sql($db, "UPDATE market_agreement_checks SET offer_id=?,title=?,source_revision=?,stored_sale_price=?,
                    outcome=NULL,reason_code=NULL,basis=NULL,quote_url=NULL,quote_price=NULL,implied_spread=NULL,
                    checked_at=NULL,next_at=?,lease=NULL,verified_at=NULL WHERE product_id=?",
                    [$row['offer_id'], $row['title'], $revision, $row['stored_sale_price'], $now, $id]);
            } else {
                molavi_domain_sql($db, 'INSERT INTO market_agreement_checks
                    (product_id,offer_id,title,source_revision,stored_sale_price,next_at,created_at)
                    VALUES(?,?,?,?,?,?,?)',
                    [$id, $row['offer_id'], $row['title'], $revision, $row['stored_sale_price'], $now, $now]);
            }
            $seeded++;
        }
    });
    // The market queue row itself, at the low priority. An existing row
    // keeps its state/next_at/candidates — seeding only (re)activates it.
    if ($seeded > 0) {
        molavi_domain_tx('reference', static function (SQLite3 $db) use ($records, $byId, $now): void {
            foreach ($records as $record) {
                $id = trim((string)($record['Product ID'] ?? ''));
                if ($id === '' || !isset($byId[$id])) continue;
                $offer = $byId[$id];
                if (!in_array($offer['state'], ['needs_review', 'blocked'], true)) continue;
                $identity = function_exists('molavi_domain_market_identity') ? molavi_domain_market_identity($record) : null;
                if (!$identity || !empty($identity['assortment'])) continue;
                if (molavi_domain_positive_price($record['Sale Price'] ?? null) <= 0) continue;
                molavi_domain_sql($db, 'INSERT INTO market_price_lookups(lookup_key,identity_json,next_at,priority)
                    VALUES(?,?,?,?) ON CONFLICT(lookup_key) DO UPDATE SET active=1',
                    [molavi_domain_hash($identity), molavi_domain_json($identity), $now, MOLAVI_MARKET_AGREEMENT_SEED_PRIORITY]);
            }
        });
    }
    return $seeded;
}

/** Run one due check (lease 5 min). Returns the outcome row, or null when nothing is due. */
function molavi_domain_market_agreement_run_one(int $now, ?string $isoNow = null): ?array {
    $lease = bin2hex(random_bytes(12));
    $row = molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($now, $lease): ?array {
        $due = molavi_domain_row($db, 'SELECT * FROM market_agreement_checks
            WHERE next_at<=? ORDER BY next_at,product_id LIMIT 1', [$now]);
        if ($due) molavi_domain_sql($db, 'UPDATE market_agreement_checks SET lease=?,next_at=? WHERE product_id=?',
            [$lease, $now + MOLAVI_MARKET_AGREEMENT_LEASE_SECONDS, $due['product_id']]);
        return $due;
    });
    if (!$row) return null;
    return molavi_domain_market_agreement_execute($row, $lease, $now, $isoNow);
}

/** Force-check one product now (owner-triggered), regardless of cadence. */
function molavi_domain_market_agreement_run_product(string $productId, int $now, ?string $isoNow = null): ?array {
    $lease = bin2hex(random_bytes(12));
    $row = molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($productId, $now, $lease): ?array {
        $due = molavi_domain_row($db, 'SELECT * FROM market_agreement_checks
            WHERE product_id=? AND (lease IS NULL OR next_at<=?)', [$productId, $now]);
        if ($due) molavi_domain_sql($db, 'UPDATE market_agreement_checks SET lease=?,next_at=? WHERE product_id=?',
            [$lease, $now + MOLAVI_MARKET_AGREEMENT_LEASE_SECONDS, $productId]);
        return $due;
    });
    if (!$row) return molavi_domain_read('pipeline', static fn(SQLite3 $db) => ($found = molavi_domain_market_agreement_row($db, $productId))
        ? molavi_domain_market_agreement_public_row($found) : null);
    return molavi_domain_market_agreement_execute($row, $lease, $now, $isoNow);
}

/**
 * Execute a check for an already-leased row. Writes the outcome row +
 * event, and on agreement writes supplier_price_evidence (method
 * 'market') and queues the supplier-verification publish job. Never sets
 * or adopts any price, never touches the sheet.
 */
function molavi_domain_market_agreement_execute(array $row, string $lease, int $now, ?string $isoNow = null): array {
    $record = null;
    $state = null;
    try {
        $tables = molavi_domain_sheets(['Products'], true);
        foreach (molavi_domain_records($tables['Products'] ?? []) as $candidate) {
            if (trim((string)($candidate['Product ID'] ?? '')) !== $row['product_id']) continue;
            $record = $candidate;
            $state = molavi_domain_freshness([$candidate], null, false)[0]['state'] ?? null;
            break;
        }
    } catch (Throwable $e) {
        // A sheet read failure must not lose the check: fall through and
        // record it as not-ready, revisited in a day.
    }
    $finish = static function (string $outcome, string $reason, ?string $basis, ?array $quote, ?int $quotePack,
        ?float $spread, ?string $verifiedAt, ?int $nextCheck = null, string $note = '') use ($row, $lease, $now): array {
        return molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($row, $lease, $outcome, $reason, $basis,
            $quote, $quotePack, $spread, $verifiedAt, $nextCheck, $now, $note): array {
            if ($verifiedAt !== null) {
                $current = molavi_domain_market_agreement_row($db, $row['product_id']);
                if (!$current || $current['lease'] !== $lease) return molavi_domain_market_agreement_public_row($current ?? $row);
                $saved = molavi_domain_row($db, 'SELECT * FROM supplier_price_evidence WHERE offer_id=?', [$row['product_id']]);
                if (molavi_domain_saved_price_state($row['source_revision'], $saved, null, $now)['state'] === 'current') {
                    molavi_domain_sql($db, 'UPDATE market_agreement_checks SET lease=NULL,next_at=? WHERE product_id=?',
                        [$now + MOLAVI_MARKET_AGREEMENT_SECONDS, $row['product_id']]);
                    return array_merge(molavi_domain_market_agreement_public_row($current), ['skipped'=>'already_current']);
                }
                if (molavi_domain_market_agreement_chain_count($db, $row['product_id']) >= MOLAVI_MARKET_AGREEMENT_CHAIN_LIMIT) {
                    $outcome = $reason = 'chain_limit';
                    $verifiedAt = null;
                } else {
                    molavi_domain_verify_price_in_transaction($db, $row['product_id'], $row['source_revision'], 'market', $note, $verifiedAt, null, 'market');
                    $receipt = hash('sha256', $row['source_revision'].'|'.$verifiedAt);
                    molavi_domain_queue_in_transaction($db, 'publish', 'supplier-verification:'.$row['product_id'].':'.$receipt,
                        ['product_id'=>$row['product_id'], 'source_revision'=>$row['source_revision']]);
                }
            }
            $updated = molavi_domain_sql($db, 'UPDATE market_agreement_checks SET lease=NULL,outcome=?,reason_code=?,basis=?,
                quote_url=?,quote_price=?,implied_spread=?,checked_at=?,next_at=?,verified_at=? WHERE product_id=? AND lease=?',
                [$outcome, $reason, $basis, $quote ? (string)($quote['url'] ?? '') : null, $quotePack, $spread,
                    $now, $nextCheck ?? ($now + MOLAVI_MARKET_AGREEMENT_SECONDS), $verifiedAt, $row['product_id'], $lease]);
            if ($updated['changes'] !== 1) return molavi_domain_market_agreement_public_row($row);
            molavi_domain_market_agreement_record_event($db, $row, $outcome, $row['stored_sale_price'],
                $quotePack, $spread, $reason, $quote ? (string)($quote['url'] ?? '') : null, $now);
            $fresh = molavi_domain_market_agreement_row($db, $row['product_id']);
            return $fresh ? molavi_domain_market_agreement_public_row($fresh) : molavi_domain_market_agreement_public_row($row);
        });
    };
    $skip = $record === null ? 'no_record' : ($state === 'current' ? 'already_current' : null);
    if ($record !== null && molavi_domain_source_revision($record) !== $row['source_revision']) $skip = 'source_changed';
    if ($skip !== null) {
        // No record, or the price is current again (owner confirmed, or a
        // fresh source observation): park it for a day, no event.
        $parked = molavi_domain_tx('pipeline', static fn(SQLite3 $db) => molavi_domain_sql($db,
            'UPDATE market_agreement_checks SET lease=NULL,next_at=? WHERE product_id=? AND lease=?',
            [$now + MOLAVI_MARKET_AGREEMENT_SECONDS, $row['product_id'], $lease]));
        if (($parked['changes'] ?? 0) !== 1) return molavi_domain_market_agreement_public_row($row);
        return array_merge(molavi_domain_market_agreement_public_row($row), ['skipped'=>$skip]);
    }
    $identity = function_exists('molavi_domain_market_identity') ? molavi_domain_market_identity($record) : null;
    if (!$identity || !empty($identity['assortment'])) {
        return $finish('no_quotes', 'no_identity', '', null, null, null, null);
    }
    $lookup = molavi_domain_read('reference', static function (SQLite3 $db) use ($identity): ?array {
        return molavi_domain_row($db, 'SELECT * FROM market_price_lookups WHERE lookup_key=?', [molavi_domain_hash($identity)]);
    });
    if (!$lookup) {
        return $finish('no_quotes', 'lookup_pending', '', null, null, null, null);
    }
    $lookupState = (string)($lookup['state'] ?? '');
    if ($lookupState === 'queued') {
        // Revisit stored results after the existing market worker has had a turn.
        $nextCheck=max($now+1800,min($now+MOLAVI_MARKET_AGREEMENT_SECONDS,(int)$lookup['next_at']));
        return $finish('no_quotes', 'lookup_pending', '', null, null, null, null, $nextCheck);
    }
    if ($lookupState !== 'ready') {
        return $finish('no_quotes', $lookupState === 'no_match' ? 'no_match' : 'lookup_not_ready', '', null, null, null, null);
    }
    $checkedAt = (int)($lookup['checked_at'] ?? 0);
    if ($checkedAt <= 0 || $now - $checkedAt >= MOLAVI_MARKET_SOURCE_CHECK_SECONDS) {
        // Quotes older than a day are not evidence. The market tick
        // refreshes the due lookup; this row is revisited in a day.
        return $finish('no_quotes', 'quotes_stale', '', null, null, null, null);
    }
    $quotes = molavi_domain_market_agreement_quotes($record);
    $salePrice = (float)molavi_domain_positive_price($record['Sale Price'] ?? null);
    $pack = max(1, (int)($identity['pack_count'] ?? 1));
    $pinned = null;
    try {
        $tables = molavi_domain_sheets(['Products', 'Offers'], true);
        $subject = function_exists('molavi_domain_market_subject')
            ? molavi_domain_market_subject((string)($record['Latest Offer ID'] ?? ''), $tables) : null;
        if ($subject && function_exists('molavi_domain_market_source_for') && function_exists('molavi_domain_market_source_rows')) {
            $sources = molavi_domain_market_source_for($subject['keys'], molavi_domain_market_source_rows(), $identity);
            $pinned = $sources['price']['url'] ?? null;
        }
    } catch (Throwable $e) {
        $pinned = null;
    }
    $referencePrice = null;
    $matchId = trim((string)($record['Reference Match ID'] ?? ''));
    if ($matchId !== '' && function_exists('molavi_domain_autopilot_reference_price')) {
        $referencePrice = molavi_domain_autopilot_reference_price($matchId);
    }
    $decision = molavi_domain_market_agreement_classify($quotes, $salePrice, $pack, $referencePrice, $pinned);
    if ($decision['outcome'] !== 'agree') {
        return $finish($decision['outcome'], $decision['reason_code'], $decision['basis'], $decision['quote'],
            $decision['quote_pack_price'], $decision['implied_spread'], null);
    }
    $iso = $isoNow ?? molavi_domain_now();
    $urls = array_values(array_unique(array_filter(array_map(
        static fn($quote) => (string)($quote['url'] ?? ''),
        array_filter($quotes, static fn($quote) => (int)($quote['regular_unit_price'] ?? $quote['unit_price'] ?? 0) > 0)))));
    $note = molavi_domain_market_agreement_note($row['product_id'], $now, (float)$decision['implied_spread'], $urls);
    return $finish('agree', $decision['reason_code'], $decision['basis'], $decision['quote'],
        $decision['quote_pack_price'], $decision['implied_spread'], $iso, null, $note);
}

/** Simple report: market-verified products + counts. No confirm flow. */
function molavi_domain_market_agreement_lists(): array {
    return molavi_domain_read('pipeline', static function (SQLite3 $db): array {
        $rows = molavi_domain_rows($db, 'SELECT * FROM market_agreement_checks ORDER BY checked_at DESC, product_id');
        $public = array_map('molavi_domain_market_agreement_public_row', $rows);
        $verified = array_values(array_filter($public, static fn($row) => $row['outcome'] === 'agree'));
        $disagree = array_values(array_filter($public, static fn($row) => $row['outcome'] === 'disagree'));
        $noQuotes = array_values(array_filter($public, static fn($row) => $row['outcome'] === 'no_quotes'));
        $chainLimit = array_values(array_filter($public, static fn($row) => $row['outcome'] === 'chain_limit'));
        $counts = ['total'=>count($public), 'verified'=>count($verified),
            'disagree'=>count($disagree), 'no_quotes'=>count($noQuotes), 'chain_limit'=>count($chainLimit),
            'pending'=>count(array_filter($public, static fn($row) => $row['outcome'] === null))];
        return ['ok'=>true, 'generated_at'=>time(), 'counts'=>$counts,
            'verified'=>$verified, 'disagree'=>$disagree, 'no_quotes'=>$noQuotes, 'chain_limit'=>$chainLimit];
    });
}

function molavi_domain_market_agreement_api(string $method, array $query, ?array $body, string $actor): array {
    if ($method === 'GET') {
        $product = trim((string)($query['product_id'] ?? ''));
        if ($product !== '') {
            $row = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_market_agreement_row($db, $product));
            if (!$row) molavi_domain_fail('market_agreement_not_found', 404);
            $events = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_rows($db,
                'SELECT * FROM market_agreement_events WHERE product_id=? ORDER BY occurred_at DESC LIMIT 20', [$product]));
            return ['ok'=>true, 'check'=>molavi_domain_market_agreement_public_row($row), 'events'=>$events];
        }
        return molavi_domain_market_agreement_lists();
    }
    if ($method !== 'POST' || $body === null) molavi_domain_fail('invalid_body', 400);
    $action = $body['action'] ?? '';
    if ($action === 'run') {
        molavi_domain_body_keys($body, ['action']);
        $result = molavi_domain_market_agreement_run_one(time());
        return ['ok'=>true, 'ran'=>$result !== null, 'result'=>$result];
    }
    if ($action === 'run_product') {
        molavi_domain_body_keys($body, ['action', 'product_id']);
        $product = $body['product_id'];
        if (!is_string($product) || $product === '' || molavi_domain_strlen($product) > 200) molavi_domain_fail('invalid_product_id', 400);
        $result = molavi_domain_market_agreement_run_product($product, time());
        if ($result === null) molavi_domain_fail('market_agreement_not_found', 404);
        return ['ok'=>true, 'result'=>$result];
    }
    molavi_domain_fail('invalid_body', 400);
}

/** One minute-tick step: seed stale items (daily), then run a single due check. */
function molavi_domain_market_agreement_tick(int $now, ?string $isoNow = null): void {
    if ((int)get_option('molavi_market_agreement_seed_at', 0) <= $now) {
        molavi_domain_market_agreement_seed($now);
        update_option('molavi_market_agreement_seed_at', $now + MOLAVI_MARKET_AGREEMENT_SECONDS, false);
    }
    molavi_domain_market_agreement_run_one($now, $isoNow);
}
