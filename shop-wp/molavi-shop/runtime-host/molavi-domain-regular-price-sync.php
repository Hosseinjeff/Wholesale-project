<?php
/** Plugin Name: Molavi Regular-Price Catalogue/Sheet Sync (owner-approved)
 *
 * The owner decided (2026-11-26): the WooCommerce catalogue regular price
 * (from the reference catalog) and the Products sheet Consumer Price are
 * the SAME value, kept in sync. There is no bottleneck here except the
 * workflow treating a sync like a verification.
 *
 * Rule:
 * - Catalogue wins by DEFAULT: when the catalogue/reference price is set
 *   or updated for a product, write it to the sheet (Consumer Price) and
 *   to WooCommerce (via the projection), so both agree.
 * - Human sheet wins IF NEWER: when the sheet Consumer Price was updated
 *   by a HUMAN user AND it is newer than the catalogue price's timestamp,
 *   the sheet price wins — write it to the WooCommerce regular price (via
 *   the projection) and record it as the current regular price.
 * - The regular-price step is satisfied once a price exists in either
 *   place (after the sync both agree), so a price mismatch no longer
 *   blocks the product.
 * - NEVER invents a price: the sync only copies a price that already
 *   exists in one of the two places. A product with a price in NEITHER
 *   place is left alone.
 *
 * Human-edit detection (provenance):
 * The Products sheet has NO per-cell edit timestamp and NO provenance
 * marking whether the Consumer Price was written by the system
 * (projection from the latest offer) or by a human editing the sheet
 * directly. The Google Sheets values API does not report per-cell edit
 * history, and the product row's "Last Updated" is set to now on every
 * system rebuild, so it cannot serve as a human-edit timestamp either.
 * So the system keeps its own ledger (SQLite table
 * regular_price_sync_state, in the pipeline store): every time the system
 * writes or observes the sheet Consumer Price, it records the price, the
 * source ('offer' | 'catalogue' | 'human') and the timestamp of the
 * system's last write (sheet_written_at). A sheet price is a HUMAN edit
 * when it differs from BOTH the latest offer's Consumer Price AND the
 * ledger's last system-recorded price.
 *
 * "Newer" comparison:
 * - Catalogue timestamp T_cat = the catalog row's "Last Updated"
 *   (column 20 of the 21-column reference row), falling back to the
 *   snapshot's last_checked_at / extracted_at.
 * - The human edit happened AFTER the system's last write to the sheet
 *   (T_sys = the ledger's sheet_written_at, or the latest offer's
 *   Import Timestamp when there is no ledger row). So the human edit is
 *   provably newer than the catalogue iff T_sys >= T_cat. When T_sys <
 *   T_cat the human edit might be older or newer than the catalogue
 *   update — it cannot be proven newer — so the catalogue wins by
 *   default (the owner's default rule).
 * - Once a human price has won, it stays the winner while the catalogue
 *   price is unchanged. A FRESH catalogue price (value changed) wins by
 *   default; the human can re-edit the sheet to override it again.
 *
 * Where it runs:
 * molavi_domain_regular_price_sync_tick() runs on the host minute-tick
 * (molavi-runtime-host.php), right before the auto-verify tick, under
 * the same gate (state active + the link-checks native route enabled).
 * It is WordPress-only (no Railway), needs no new route, and adds no
 * always-on worker. It also has a dry-run action 'regular_price_sync'
 * on the existing POST /pipeline/supplier-link-checks route (mirroring
 * the 'auto_verify' action) for testing and re-runs.
 *
 * How WooCommerce gets the price:
 * The sync writes to the sheet (Consumer Price) when the catalogue wins,
 * then triggers the projection (molavi_domain_observe_projection), which
 * reads the sheet, computes the regular-price evidence (now current,
 * because the catalogue price equals the sheet price, or because the
 * ledger deadline applies for a human/sheet price), and writes the
 * regular price to WooCommerce via molavi_upsert_product (regular_price
 * + _molavi_market_shelf_toman). The sync itself never writes to
 * WooCommerce directly.
 *
 * How the regular price becomes "current":
 * molavi_domain_regular_price_evidence() (sale-offers.php) takes the max
 * of the direct deadline, the reference deadline, and the sync-ledger
 * deadline (molavi_domain_regular_price_sync_deadline). The ledger
 * deadline applies when the ledger's recorded regular price still
 * equals the sheet Consumer Price and the deadline is in the future.
 * Catalogue prices get a 24h deadline (the snapshot refresh window);
 * human/sheet prices get a 14-day deadline (the owner-decision clock).
 * The deadline is NOT auto-renewed: when it passes, the regular price
 * is expired until the owner re-confirms or the catalogue updates.
 *
 * Price basis: the sync compares the catalog consumer_price (column 5,
 * Consumer Unit Price, via molavi_domain_matching_reference) directly
 * against the sheet Consumer Price — exactly as the auto-verify gate
 * and molavi_domain_reference_regular_deadline already do. No new
 * pack-scaling is introduced.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

const MOLAVI_REGULAR_PRICE_SYNC_ACTOR = 'system:regular-price-sync';
const MOLAVI_REGULAR_PRICE_SYNC_PER_TICK = 50;
const MOLAVI_REGULAR_PRICE_SYNC_FRESH_DAYS = 14;
const MOLAVI_REGULAR_PRICE_SYNC_REFERENCE_STATES = ['owner_approved', 'approved_compatible', 'approved_within_margin'];

/**
 * Pure decision: which price wins for one product, and what to write.
 * No reads, no writes — fully testable. Inputs are all plain values.
 *
 * Returns: action, price, source, deadline, write_sheet, sheet_price,
 * reason, and (for a human win) human_observed_at.
 *
 * Actions:
 *  no_price        — no price in either place; leave alone.
 *  catalogue_only  — only the catalogue has a price; write it to the sheet.
 *  sheet_only      — only the sheet has a price; it is the regular price.
 *  catalogue_wins  — catalogue wins by default; write it to the sheet.
 *  human_wins      — a human sheet edit, proven newer; it is the regular price.
 *  human_retained  — a human price that already won, catalogue unchanged.
 *  in_sync         — both places already agree; nothing to write.
 */
function molavi_domain_regular_price_sync_decide(array $input): array {
    $now = (int)($input['now'] ?? 0);
    $sheet = (int)($input['sheet_price'] ?? 0);
    $catalogue = (int)($input['catalogue_price'] ?? 0);
    $catAt = (float)($input['catalogue_updated_at'] ?? 0);
    $checkedAt = (float)($input['snapshot_checked_at'] ?? 0);
    $offer = (int)($input['offer_price'] ?? 0);
    $offerAt = (float)($input['offer_imported_at'] ?? 0);
    $ledger = $input['ledger'] ?? null;
    $catDeadline = $checkedAt > 0 ? (int)($checkedAt + MOLAVI_REFERENCE_REFRESH_SECONDS) : 0;
    $fresh = MOLAVI_REGULAR_PRICE_SYNC_FRESH_DAYS * 86400;
    // No price anywhere: leave alone, never invent one.
    if ($sheet <= 0 && $catalogue <= 0) {
        return ['action' => 'no_price', 'price' => 0, 'source' => '', 'deadline' => 0,
            'write_sheet' => false, 'sheet_price' => 0, 'reason' => 'no_price_anywhere'];
    }
    // Catalogue only: catalogue wins, write it to the sheet.
    if ($sheet <= 0) {
        return ['action' => 'catalogue_only', 'price' => $catalogue, 'source' => 'catalogue',
            'deadline' => $catDeadline, 'write_sheet' => true, 'sheet_price' => $catalogue,
            'reason' => 'catalogue_only'];
    }
    // Sheet only (no catalogue price): the sheet price is the regular price.
    if ($catalogue <= 0) {
        if ($sheet === $offer && $offer > 0) {
            $deadline = $offerAt > 0 ? (int)($offerAt + $fresh) : (int)($now + $fresh);
            return ['action' => 'sheet_only', 'price' => $sheet, 'source' => 'sheet',
                'deadline' => $deadline, 'write_sheet' => false, 'sheet_price' => $sheet,
                'reason' => 'sheet_only_offer'];
        }
        // Human-edited sheet price, no catalogue to compare: the human price wins.
        $observed = ($ledger && !empty($ledger['human_observed_at']))
            ? (float)$ledger['human_observed_at'] : (float)$now;
        return ['action' => 'sheet_only', 'price' => $sheet, 'source' => 'sheet',
            'deadline' => (int)($observed + $fresh), 'write_sheet' => false, 'sheet_price' => $sheet,
            'reason' => 'sheet_only_human', 'human_observed_at' => $observed];
    }
    // Both places have a price.
    if ($sheet === $catalogue) {
        $source = ($ledger && !empty($ledger['regular_price_source']))
            ? (string)$ledger['regular_price_source'] : 'catalogue';
        $deadline = $catDeadline;
        if ($ledger && (float)($ledger['regular_price_deadline'] ?? 0) > $deadline)
            $deadline = (int)$ledger['regular_price_deadline'];
        return ['action' => 'in_sync', 'price' => $sheet, 'source' => $source,
            'deadline' => $deadline, 'write_sheet' => false, 'sheet_price' => $sheet,
            'reason' => 'in_sync'];
    }
    // Prices differ. Is the sheet price a known human price that already won?
    $isKnownHuman = $ledger && (int)($ledger['sheet_price'] ?? 0) === $sheet
        && (string)($ledger['sheet_price_source'] ?? '') === 'human';
    if ($isKnownHuman) {
        // A FRESH catalogue price (value changed) wins by default.
        if ((int)($ledger['catalogue_price'] ?? 0) !== $catalogue) {
            return ['action' => 'catalogue_wins', 'price' => $catalogue, 'source' => 'catalogue',
                'deadline' => $catDeadline, 'write_sheet' => true, 'sheet_price' => $catalogue,
                'reason' => 'catalogue_price_changed'];
        }
        // Catalogue unchanged: the human price stays the winner. The
        // deadline is NOT auto-renewed: when it passes, the regular
        // price is expired until the owner re-confirms or the catalogue
        // updates.
        return ['action' => 'human_retained', 'price' => $sheet, 'source' => 'sheet',
            'deadline' => (int)($ledger['regular_price_deadline'] ?? 0),
            'write_sheet' => false, 'sheet_price' => $sheet, 'reason' => 'human_retained'];
    }
    // Is the sheet price a NEW human edit? It differs from the latest
    // offer's price AND from the system's last catalogue-synced price.
    $isHuman = ($sheet !== $offer)
        && !($ledger && (int)($ledger['sheet_price'] ?? 0) === $sheet
            && (string)($ledger['sheet_price_source'] ?? '') === 'catalogue');
    if ($isHuman) {
        // The human edit happened after the system's last write (T_sys).
        // It is provably newer than the catalogue iff T_sys >= T_cat.
        $sysAt = ($ledger && (float)($ledger['sheet_written_at'] ?? 0) > 0)
            ? (float)$ledger['sheet_written_at'] : $offerAt;
        if ($catAt > 0 && $sysAt >= $catAt) {
            return ['action' => 'human_wins', 'price' => $sheet, 'source' => 'sheet',
                'deadline' => (int)($now + $fresh), 'write_sheet' => false, 'sheet_price' => $sheet,
                'reason' => 'human_newer', 'human_observed_at' => (float)$now];
        }
        // Cannot prove the human edit is newer: catalogue wins by default.
        return ['action' => 'catalogue_wins', 'price' => $catalogue, 'source' => 'catalogue',
            'deadline' => $catDeadline, 'write_sheet' => true, 'sheet_price' => $catalogue,
            'reason' => 'human_not_proven_newer'];
    }
    // Offer-derived sheet price that differs from the catalogue:
    // catalogue wins by default.
    return ['action' => 'catalogue_wins', 'price' => $catalogue, 'source' => 'catalogue',
        'deadline' => $catDeadline, 'write_sheet' => true, 'sheet_price' => $catalogue,
        'reason' => 'catalogue_default'];
}

/** Read one ledger row by product id (null when never synced). */
function molavi_domain_regular_price_sync_row(string $productId): ?array {
    if ($productId === '') return null;
    return molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
        'SELECT * FROM regular_price_sync_state WHERE product_id=?', [$productId]));
}

/** Read all ledger rows, keyed by product id. */
function molavi_domain_regular_price_sync_ledger(): array {
    $out = [];
    foreach (molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_rows($db,
        'SELECT * FROM regular_price_sync_state')) as $row) {
        $out[(string)($row['product_id'] ?? '')] = $row;
    }
    return $out;
}

/**
 * Sync-ledger deadline for the regular-price evidence (sale-offers.php):
 * the ledger's recorded regular price is current while its deadline is
 * in the future AND the sheet price still equals it. Returns 0 otherwise
 * (no sync deadline — the direct/reference paths decide instead).
 */
function molavi_domain_regular_price_sync_deadline(array $record, int $price, int $now): int {
    $id = (string)($record['Product ID'] ?? '');
    if ($id === '' || $price <= 0) return 0;
    $row = molavi_domain_regular_price_sync_row($id);
    if (!$row) return 0;
    if ((int)($row['regular_price'] ?? 0) !== $price) return 0;
    $deadline = (float)($row['regular_price_deadline'] ?? 0);
    return $deadline > $now ? (int)$deadline : 0;
}

/**
 * Build the ledger row to record after a decision. Returns null when the
 * existing ledger already records exactly this state (no write needed).
 */
function molavi_domain_regular_price_sync_ledger_row(string $productId, array $decision, array $input, ?array $existing, int $now): ?array {
    $sheet = (int)($input['sheet_price'] ?? 0);
    $catalogue = (int)($input['catalogue_price'] ?? 0);
    $catAt = (float)($input['catalogue_updated_at'] ?? 0);
    $offerAt = (float)($input['offer_imported_at'] ?? 0);
    $action = (string)($decision['action'] ?? '');
    // The sheet price after this decision, and how it got there.
    $sheetPrice = !empty($decision['write_sheet']) ? (int)$decision['sheet_price'] : $sheet;
    if ($action === 'catalogue_only' || $action === 'catalogue_wins') {
        $sheetSource = 'catalogue';
        $sheetWrittenAt = (float)$now;
    } elseif ($action === 'human_wins' || $action === 'human_retained') {
        $sheetSource = 'human';
        // The system's last write is unchanged: the human wrote the sheet,
        // the system did not. Keep the previous sheet_written_at so the
        // "newer" proof stays based on the last system write.
        $sheetWrittenAt = ($existing && (float)($existing['sheet_written_at'] ?? 0) > 0)
            ? (float)$existing['sheet_written_at'] : $offerAt;
    } else {
        // in_sync / sheet_only: the sheet price is as observed. If the
        // ledger already records this exact sheet price, keep its source:
        // a catalogue-synced price that happens to equal the offer price
        // is still a catalogue price, not an offer price (re-classifying
        // it as 'offer' would re-write the ledger on every tick and lose
        // the catalogue provenance).
        if ($existing && (int)($existing['sheet_price'] ?? 0) === $sheet
            && !empty($existing['sheet_price_source'])) {
            $sheetSource = (string)$existing['sheet_price_source'];
            $sheetWrittenAt = (float)($existing['sheet_written_at'] ?? 0) > 0
                ? (float)$existing['sheet_written_at'] : $offerAt;
        } elseif ((int)($input['offer_price'] ?? 0) > 0 && $sheet === (int)($input['offer_price'] ?? 0)) {
            $sheetSource = 'offer';
            $sheetWrittenAt = $offerAt > 0 ? $offerAt : (float)$now;
        } else {
            $sheetSource = ($existing && !empty($existing['sheet_price_source']))
                ? (string)$existing['sheet_price_source'] : '';
            $sheetWrittenAt = ($existing && (float)($existing['sheet_written_at'] ?? 0) > 0)
                ? (float)$existing['sheet_written_at'] : $offerAt;
        }
    }
    $humanObserved = null;
    if (isset($decision['human_observed_at'])) $humanObserved = (float)$decision['human_observed_at'];
    elseif ($existing && !empty($existing['human_observed_at'])) $humanObserved = (float)$existing['human_observed_at'];
    $row = [
        'product_id' => $productId,
        'sheet_price' => $sheetPrice,
        'sheet_price_source' => $sheetSource,
        'sheet_written_at' => $sheetWrittenAt,
        'catalogue_price' => $catalogue,
        'catalogue_updated_at' => $catAt,
        'reference_id' => (string)($input['reference_id'] ?? ''),
        'snapshot_id' => (string)($input['snapshot_id'] ?? ''),
        'regular_price' => (int)($decision['price'] ?? 0),
        'regular_price_source' => (string)($decision['source'] ?? ''),
        'regular_price_deadline' => (int)($decision['deadline'] ?? 0),
        'human_observed_at' => $humanObserved,
        'updated_at' => (float)$now,
    ];
    if ($existing) {
        $same = true;
        foreach ($row as $key => $value) {
            if ($key === 'updated_at') continue;
            $old = $existing[$key] ?? null;
            if (is_float($value) || is_int($value)) { if ((float)$old !== (float)$value) { $same = false; break; } }
            elseif ((string)$old !== (string)$value) { $same = false; break; }
        }
        if ($same) return null;
    }
    return $row;
}

/** Upsert one ledger row. */
function molavi_domain_regular_price_sync_save(array $row): void {
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($row): void {
        molavi_domain_sql($db, 'INSERT INTO regular_price_sync_state
            (product_id, sheet_price, sheet_price_source, sheet_written_at, catalogue_price, catalogue_updated_at,
             reference_id, snapshot_id, regular_price, regular_price_source, regular_price_deadline, human_observed_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(product_id) DO UPDATE SET sheet_price=excluded.sheet_price, sheet_price_source=excluded.sheet_price_source,
            sheet_written_at=excluded.sheet_written_at, catalogue_price=excluded.catalogue_price,
            catalogue_updated_at=excluded.catalogue_updated_at, reference_id=excluded.reference_id,
            snapshot_id=excluded.snapshot_id, regular_price=excluded.regular_price,
            regular_price_source=excluded.regular_price_source, regular_price_deadline=excluded.regular_price_deadline,
            human_observed_at=excluded.human_observed_at, updated_at=excluded.updated_at',
            [(string)$row['product_id'], (int)$row['sheet_price'], (string)$row['sheet_price_source'],
             (float)$row['sheet_written_at'], (int)$row['catalogue_price'], (float)$row['catalogue_updated_at'],
             (string)$row['reference_id'], (string)$row['snapshot_id'], (int)$row['regular_price'],
             (string)$row['regular_price_source'], (float)$row['regular_price_deadline'],
             $row['human_observed_at'] === null ? null : (float)$row['human_observed_at'], (float)$row['updated_at']]);
    });
}

/**
 * Batch runner: scan every Products row, decide the sync, and (unless
 * dry-run) write catalogue prices to the sheet, record the ledger, and
 * trigger the projection so WooCommerce picks up the new regular price.
 * Per-tick cap on products WRITTEN (sheet or ledger); the dry-run reports
 * every decision without writing anything. Idempotent: a product already
 * in sync records no write.
 */
function molavi_domain_regular_price_sync_tick(int $now, ?string $isoNow = null, bool $dryRun = false,
    int $cap = MOLAVI_REGULAR_PRICE_SYNC_PER_TICK): array {
    $isoNow = $isoNow ?? molavi_domain_now();
    $cap = max(0, $cap);
    $active = molavi_domain_reference_active();
    $snapshotId = (string)($active['snapshot_id'] ?? '');
    $checkedAt = molavi_domain_timestamp($active['last_checked_at'] ?? $active['extracted_at'] ?? null);
    $checkedAt = $checkedAt !== null ? (float)$checkedAt : 0.0;
    // Catalogue map: reference_id => [price, updated_at]. The timestamp is
    // the row's "Last Updated" (column 20), falling back to the snapshot's
    // last_checked_at.
    $catalogue = [];
    foreach ($active['rows'] ?? [] as $row) {
        if (!is_array($row) || count($row) < 21) continue;
        $entry = molavi_domain_matching_reference($row);
        $refId = (string)($entry['reference_id'] ?? '');
        if ($refId === '') continue;
        $price = (int)($entry['consumer_price'] ?? 0);
        if ($price <= 0) continue;
        $updatedAt = molavi_domain_timestamp($row[20] ?? null);
        if ($updatedAt === null) $updatedAt = $checkedAt;
        $catalogue[$refId] = ['price' => $price, 'updated_at' => (float)$updatedAt];
    }
    $tables = molavi_domain_sheets(['Products', 'Offers'], true);
    $headers = array_map('strval', $tables['Products'][0] ?? []);
    $records = molavi_domain_records($tables['Products'] ?? []);
    $offersById = [];
    foreach (molavi_domain_records($tables['Offers'] ?? []) as $offer) {
        $oid = (string)molavi_domain_py_value($offer['Offer ID'] ?? null);
        if ($oid !== '') $offersById[$oid] = $offer;
    }
    $ledger = molavi_domain_regular_price_sync_ledger();
    $scanned = 0; $written = 0; $overCap = 0;
    $decisions = []; $actions = []; $skipped = [];
    $sheetCells = [];
    $ledgerRows = [];
    foreach ($records as $index => $record) {
        $productId = (string)molavi_domain_py_value($record['Product ID'] ?? null);
        if ($productId === '') continue;
        $scanned++;
        $sheet = molavi_domain_positive_price($record['Consumer Price'] ?? null);
        $refId = trim((string)molavi_domain_py_value($record['Reference Match ID'] ?? null));
        $approval = (string)molavi_domain_py_value($record['Reference Approval State'] ?? null);
        $cat = null;
        if ($refId !== '' && in_array($approval, MOLAVI_REGULAR_PRICE_SYNC_REFERENCE_STATES, true)
            && isset($catalogue[$refId])) {
            $cat = $catalogue[$refId];
        }
        $offerId = (string)molavi_domain_py_value($record['Latest Offer ID'] ?? null);
        $offer = $offerId !== '' ? ($offersById[$offerId] ?? null) : null;
        $offerPrice = $offer ? molavi_domain_positive_price($offer['Consumer Price'] ?? null) : 0;
        $offerAt = $offer ? molavi_domain_timestamp($offer['Import Timestamp'] ?? null) : null;
        $offerAt = $offerAt !== null ? (float)$offerAt : 0.0;
        $input = [
            'product_id' => $productId,
            'sheet_price' => $sheet,
            'catalogue_price' => $cat ? (int)$cat['price'] : 0,
            'catalogue_updated_at' => $cat ? (float)$cat['updated_at'] : 0.0,
            'snapshot_checked_at' => $checkedAt,
            'offer_price' => $offerPrice,
            'offer_imported_at' => $offerAt,
            'ledger' => $ledger[$productId] ?? null,
            'reference_id' => $refId,
            'snapshot_id' => $snapshotId,
            'now' => $now,
        ];
        $decision = molavi_domain_regular_price_sync_decide($input);
        $decisions[] = ['product_id' => $productId, 'action' => $decision['action'],
            'price' => $decision['price'], 'source' => $decision['source'], 'reason' => $decision['reason']];
        $actions[$decision['action']] = ($actions[$decision['action']] ?? 0) + 1;
        if ($decision['action'] === 'no_price') { $skipped['no_price'] = ($skipped['no_price'] ?? 0) + 1; continue; }
        if ($dryRun) continue;
        if ($written >= $cap) { $overCap++; continue; }
        $row = molavi_domain_regular_price_sync_ledger_row($productId, $decision, $input, $ledger[$productId] ?? null, $now);
        if ($row === null && empty($decision['write_sheet'])) continue;
        if (!empty($decision['write_sheet'])) {
            $column = array_search('Consumer Price', $headers, true);
            if ($column === false) { $skipped['sheet_header_missing'] = ($skipped['sheet_header_missing'] ?? 0) + 1; continue; }
            $sheetCells[] = ['range' => molavi_domain_sheet_column($column + 1) . ($index + 2),
                'values' => [[$decision['sheet_price']]], 'product_id' => $productId];
        }
        if ($row !== null) $ledgerRows[$productId] = $row;
        $written++;
    }
    $sheetWritten = 0; $sheetFailures = [];
    if ($sheetCells) {
        // Batch the sheet writes (500 cells per request, as reference
        // reconciliation does), then verify each written cell by re-read.
        $batches = array_chunk($sheetCells, 500);
        foreach ($batches as $batch) {
            $cells = array_map(static fn($c) => ['range' => $c['range'], 'values' => $c['values']], $batch);
            molavi_domain_sheet_cells('Products', $cells);
        }
        $verified = molavi_domain_records(molavi_domain_sheets(['Products'], true, true)['Products'] ?? []);
        foreach ($sheetCells as $cell) {
            $ok = false;
            foreach ($verified as $rec) {
                if ((string)molavi_domain_py_value($rec['Product ID'] ?? null) !== $cell['product_id']) continue;
                $ok = molavi_domain_positive_price($rec['Consumer Price'] ?? null) === (int)$cell['values'][0][0];
                break;
            }
            if ($ok) $sheetWritten++;
            else $sheetFailures[] = $cell['product_id'];
        }
        // A sheet write that did not verify must not be recorded as synced.
        foreach ($sheetFailures as $pid) unset($ledgerRows[$pid]);
    }
    foreach ($ledgerRows as $row) molavi_domain_regular_price_sync_save($row);
    $publishJob = null;
    if ($sheetWritten > 0) {
        $fresh = molavi_domain_sheets(['Products', 'Offers', 'Shop'], true);
        $publishJob = molavi_domain_observe_projection(molavi_domain_projection_digest($fresh));
    }
    return ['ok' => true, 'dry_run' => $dryRun, 'snapshot_id' => $snapshotId,
        'scanned' => $scanned, 'written' => $written, 'sheet_written' => $sheetWritten,
        'sheet_failures' => $sheetFailures, 'over_cap' => $overCap, 'cap' => $cap,
        'actions' => $actions, 'skipped' => $skipped, 'decisions' => $decisions,
        'publish_job' => $publishJob];
}
