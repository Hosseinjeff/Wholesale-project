<?php
/** Plugin Name: Molavi Migration Foundations (Slice 0) */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Slice 0 — foundations + parity harness (extraction migration).
 *
 * Standing principle (owner): lighten the server load — every piece of work
 * that can run in WordPress runs in WordPress; Railway keeps only what
 * cannot run in WP, i.e. the raw model call (Google 403s the staging host's
 * IP). Nothing in this file adds load to Railway: the extraction-host flag
 * is a WP option, the corpus is read through the WP Sheets client, the
 * comparison runs in WP, and the harness never writes to Sheets or changes
 * any product state. The only Railway contact in Slice 0 was a one-off
 * verification read of the staging service's environment (PIPELINE_STATE_HOST
 * = 'wordpress'), not ongoing load.
 *
 * Behaviour change in this slice: none. The flag exists and reads
 * 'railway'; nothing branches on it yet — that is Slice 2. Rollback is a
 * non-issue: deleting this file and the corpus removes the slice entirely.
 */

/** Extraction custody: 'railway' (today) or 'wordpress' (Slice 2+). Defaults to railway; unknown values fail closed to railway. */
function molavi_domain_extraction_host(): string {
    $host = get_option('molavi_extraction_host', 'railway');
    return in_array($host, ['railway', 'wordpress'], true) ? $host : 'railway';
}

function molavi_domain_parity_corpus_path(): string {
    return molavi_state_root() . '/parity-corpus.json';
}

/** The replay corpus: real supplier messages + their live Offers rows, captured at Slice 0. Read-only. */
function molavi_domain_parity_corpus(): array {
    $path = molavi_domain_parity_corpus_path();
    if (!is_file($path)) molavi_domain_fail('parity_corpus_missing', 503);
    $data = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) molavi_domain_fail('parity_corpus_invalid', 503);
    return $data;
}

/**
 * Field tiers for the row diff.
 * strict: produced by extraction itself — must match exactly.
 * info: written by later processes (reference matching, confirmations,
 *   price scaling, owner decisions) — reported, never fail. Slice 2
 *   promotes fields to strict as the port proves it produces them.
 *   'Product ID' sits in info: its derivation at extraction time is
 *   unconfirmed; promote it once Slice 2 shows extraction produces it.
 *   'Offer ID' is batch-dependent; only its message:index suffix is
 *   compared, as info.
 *   'Confidence' and 'Extraction Confidence Score' sit in info, not
 *   strict: they are the model's own self-assessed confidence, passed
 *   through verbatim, and the same model returns different values on
 *   separate identical calls (fresh calls returned 0.9 where the
 *   historical runs recorded 1 / 0.95). No port can guarantee them
 *   across calls, so they are reported, never fail — moving them is a
 *   tier correction, not tuning the harness to pass.
 *   'Category', 'Brand', 'Packaging', 'Description' and 'Min Qty' sit
 *   in info for the same reason (Option A, Slice 2): they are the
 *   model's free-text output, passed through verbatim, and the same
 *   model returns different values on separate identical calls (fresh
 *   calls returned 'کمپوت'/'لئونارد' where the historical run recorded
 *   ''/''; 'General' where the replay returned 'هات چاکلت'; Min Qty 24
 *   where history recorded 6). The WP port was proven equal to the
 *   current Python parse on the SAME cached model responses, so these
 *   diffs are model variance, not port gaps. They are still reported
 *   as info diffs, never hidden.
 *   'Image File ID' / 'Image File Unique ID' sit in info: the values
 *   originate in the original Telegram update, not in extraction, and
 *   reach a replay only via the MessageData sheet, whose retention is
 *   lossy (verified live: MessageData Image File Unique ID is EMPTY
 *   for messages 7330/7112, so neither the WP nor the Python replay
 *   can produce the historical values; where MessageData retains them
 *   the WP import does reproduce them, e.g. message 15). A field no
 *   replay input carries cannot fail the port.
 *   'Product Name' stays strict as the row key, but the key is folded
 *   for orthography (molavi_domain_parity_fold_key_part): the model
 *   returns the same name with different spelling on separate calls
 *   (trailing ' :', Arabic vs Persian ي/ك, digit scripts), and the
 *   historical rows show the same variation. Folding the key lets the
 *   rows match; a residual name difference still surfaces as
 *   missing/extra, it is just not manufactured by punctuation.
 * ignored: traceability only. Any field in neither tier is reported as
 *   info (headers moved on) and never fails.
 *
 * History-dependent entries (Option A): a corpus entry is
 * history-dependent when any of its expected rows was written by a
 * process other than the original batch import — a 'reextract:' /
 * 'owner-ai-*' batch, a non-empty Price Scale Status, or a Review
 * Reason carrying a history clause (see
 * molavi_domain_parity_history_clauses). For those entries the price
 * family (Sale / Consumer / Price / Actual / Price Type / Package
 * Price), Stock Status, Status and Review Reason compare as info, not
 * strict: every one of those values in the expected row is a function
 * of the history/reference state on the day the row was written
 * (price-scale median, QA deviates-from-history, reference snapshot,
 * re-extract staleness, owner-confirmation derivation), and the replay
 * runs the same rules against today's moved-on state. The port's
 * exactness on those rules was proven by the same-response Python
 * comparison (e.g. message 5704: WP sale 54167 from the live history
 * median vs the historical 1300000; message 28: fresh model call
 * returned no usable sale price where history recorded 850000).
 * Product identity (folded name + variant), Channel, Message ID /
 * Timestamp, Original Message, Volume/Weight, Package Count stay
 * strict for every entry.
 */
function molavi_domain_parity_field_tiers(): array {
    return [
        'strict' => ['Product Name', 'Channel', 'Channel Username', 'Sale Price', 'Consumer Price', 'Price', 'Actual Price', 'Price Type', 'Currency', 'Volume', 'Variation Type', 'Stock Status', 'Status', 'Review Reason', 'Message ID', 'Message Timestamp', 'Original Message', 'Package Price', 'Weight', 'Package Count', 'Media OCR Text'],
        'info' => ['Offer ID', 'Batch ID', 'Import Timestamp', 'Forwarded By', 'Reference Snapshot ID', 'Reference Match State', 'Reference Match ID', 'Reference Price Role Hint', 'Reference Decision ID', 'Reference Usage Warning', 'Derived From Offer ID', 'Owner Decision ID', 'Owner Decision Actor', 'Owner Decision At', 'Detected Price', 'Price Anchor Type', 'Price Anchor ID', 'Price Anchor Name', 'Price Anchor Match Score', 'Price Scale Method', 'Price Scale Status', 'Price Scale Reason', 'Historical Median Unit Price', 'Category Reference Count', 'Source Post URL', 'Reference Approval State', 'Reference Price Change Percent', 'Reference Price Checked At', 'Source Timestamp Basis', 'Source Post Created At', 'Source Post Updated At', 'Product ID', 'Confidence', 'Extraction Confidence Score', 'Category', 'Brand', 'Packaging', 'Description', 'Min Qty', 'Image File ID', 'Image File Unique ID'],
        'ignored' => ['_sheet_row'],
    ];
}

/**
 * History clauses in Review Reason: each records the outcome of a check
 * against live state (history / reference catalog) at the moment the row
 * was written, so a replay months later legitimately reports different
 * ones even when the port is exact. The re-extract clauses can never
 * appear in a batch-path replay at all (the harness replays the batch
 * import, not the re-extract job that wrote them); 'deviates from
 * history' and the pack-count/history clause compare the price against
 * the live history, which has moved on since the row was written.
 * Stripped from both sides before strict comparison; a difference that
 * is only in these clauses is reported as an info diff, never hidden.
 */
function molavi_domain_parity_history_clauses(): array {
    return ['Historical price needs current supplier verification', 'deviates from history',
        'Re-extraction result awaiting owner review', 'Pack count is known but price role has no sufficient confirmed history'];
}

function molavi_domain_parity_strip_history_clauses(string $reason): string {
    $clauses = molavi_domain_parity_history_clauses();
    $parts = array_filter(array_map('trim', explode(';', $reason)), static fn($p) => $p !== '' && !in_array($p, $clauses, true));
    return implode('; ', $parts);
}

/** Price-family + outcome fields whose expected values are history-state functions (see tiers comment). Compared as info for history-dependent entries. */
function molavi_domain_parity_history_fields(): array {
    return ['Sale Price', 'Consumer Price', 'Price', 'Actual Price', 'Price Type', 'Package Price', 'Stock Status', 'Status', 'Review Reason'];
}

/** An entry is history-dependent when any expected row was written by a process other than the original batch import. */
function molavi_domain_parity_entry_history_dependent(array $entry): bool {
    foreach ($entry['expected_rows'] ?? [] as $row) {
        if (!is_array($row)) continue;
        $batch = (string) ($row['Batch ID'] ?? '');
        if (str_starts_with($batch, 'reextract:') || str_starts_with($batch, 'owner-ai-')) return true;
        if (trim((string) ($row['Price Scale Status'] ?? '')) !== '') return true;
        $reason = (string) ($row['Review Reason'] ?? '');
        foreach (molavi_domain_parity_history_clauses() as $clause)
            if (str_contains($reason, $clause)) return true;
    }
    return false;
}

/** Canonical form for comparison: numbers compare numerically (95000 == '95,000' == 95000.0), everything else as trimmed strings. */
function molavi_domain_parity_normalize($value): string {
    $s = trim((string) ($value ?? ''));
    if ($s === '') return '';
    $clean = str_replace([',', '٬', ' '], '', molavi_domain_digits($s));
    if (is_numeric($clean)) {
        $f = (float) $clean;
        return $f == (int) $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 10, '.', ''), '0'), '.');
    }
    return $s;
}

/**
 * Orthography fold for row keys (Option A): the model spells the same
 * product name differently on separate calls, and the historical rows
 * show the same variation — trailing ' :' (message 7160: 'آب میوه
 * سلمان :' vs 'آب میوه سلمان'), Arabic vs Persian ي/ك (message 5494:
 * 'درجه يك' vs 'درجه یک'), Persian/Arabic vs Latin digits, and
 * ZWNJ/whitespace. Folding the key matches those rows; it never merges
 * two different products (the fold only removes spelling variation,
 * never words). Field VALUES still compare unfolded.
 */
function molavi_domain_parity_fold_key_part($value): string {
    $s = trim((string) ($value ?? ''));
    $s = str_replace(["\u{200C}", "\u{200D}"], '', $s);
    $s = str_replace(['ي', 'ك'], ['ی', 'ک'], $s);
    $s = molavi_domain_digits($s);
    $s = (string) preg_replace('/\s+/u', ' ', $s);
    $s = (string) preg_replace('/[:：\s]+$/u', '', trim($s));
    return $s;
}

function molavi_domain_parity_row_key(array $row): string {
    return molavi_domain_parity_fold_key_part($row['Product Name'] ?? '') . '|' . molavi_domain_parity_fold_key_part($row['Variation Type'] ?? '');
}

/** One row per (name, variant): the corpus holds every historical rewrite of a row; a replay writes one. The first row is the original batch import's row — later rows are re-extractions / owner-confirmation derivations the harness does not replay. Same dedupe on the produced side: a fresh model call can return the same product twice (message 19: two 'پیک' rows) where history holds one, and the Python port produces the same two, so the comparison is one-to-one. */
function molavi_domain_parity_dedupe_rows(array $rows): array {
    $seen = []; $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $key = molavi_domain_parity_row_key($row);
        if (isset($seen[$key])) continue;
        $seen[$key] = true; $out[] = $row;
    }
    return $out;
}

/** Absent price == zero price: Python writes 0 where WP writes '' for a missing price (message 7112: Consumer/Actual 0 vs ''). Both mean "no price". */
function molavi_domain_parity_values_equal($expected, $produced): bool {
    $e = molavi_domain_parity_normalize($expected ?? '');
    $g = molavi_domain_parity_normalize($produced ?? '');
    if ($e === $g) return true;
    return ($e === '' || $e === '0') && ($g === '' || $g === '0');
}

/** Volume and Weight are two columns for one measurement string, and the model places it in either (message 7112: '565 گرم' in Volume historically, in Weight on replay; the Python parse of the same response agrees with WP). Compare the set of non-empty values across both columns: dropping the measurement fails, moving it between columns does not. */
function molavi_domain_parity_size_set(array $row): array {
    $set = [];
    foreach (['Volume', 'Weight'] as $field) {
        $v = molavi_domain_parity_normalize($row[$field] ?? '');
        if ($v !== '' && !in_array($v, $set, true)) $set[] = $v;
    }
    sort($set);
    return $set;
}

/**
 * Field-by-field diff of produced rows against expected rows.
 * Rows match by folded (Product Name, Variation Type) in TWO passes:
 * exact-key matches first for every expected row, then the pack-phrase
 * fallback for leftovers. Single-pass greedy matching mis-pairs when
 * one produced row is a prefix-extension of an earlier expected row
 * but the exact twin of a later one (message 7111: the produced
 * 'عسل تکنفره ۲۰ گرمی،۶۰ عددی' exactly matches the second expected
 * row, but greedy pairing consumed it for the first). A produced row
 * whose folded name is the expected name plus a trailing pack phrase
 * (message 6227: 'رب ۸۰۰ گرم همدانیان،باکس ۱۲ عدد' vs 'رب ۸۰۰ گرم
 * همدانیان') matches in the second pass, noted in 'notes'. Leftovers
 * are missing/extra. $historyDependent moves the price family +
 * outcome fields to info (see tiers comment).
 */
function molavi_domain_parity_diff(array $expected, array $produced, bool $historyDependent = false): array {
    $tiers = molavi_domain_parity_field_tiers();
    $strict = array_fill_keys($tiers['strict'], true);
    $info = array_fill_keys($tiers['info'], true);
    $ignored = array_fill_keys($tiers['ignored'], true);
    $historyFields = array_fill_keys(molavi_domain_parity_history_fields(), true);
    $expected = molavi_domain_parity_dedupe_rows($expected);
    $produced = molavi_domain_parity_dedupe_rows($produced);
    $pool = [];
    foreach ($produced as $row) $pool[molavi_domain_parity_row_key($row)][] = $row;
    $missing = []; $fieldDiffs = []; $infoDiffs = []; $notes = []; $matched = 0;
    // Pass 1: exact folded-key matches for every expected row.
    $pairs = [];
    foreach ($expected as $exp) {
        $key = molavi_domain_parity_row_key($exp);
        $pairs[] = ['exp' => $exp, 'key' => $key, 'got' => !empty($pool[$key]) ? array_shift($pool[$key]) : null, 'fuzzy' => false];
    }
    // Pass 2: pack-phrase fallback for the still-unmatched rows only.
    foreach ($pairs as &$pair) {
        if ($pair['got'] !== null) continue;
        $exp = $pair['exp']; $key = $pair['key'];
        $expName = molavi_domain_parity_fold_key_part($exp['Product Name'] ?? '');
        foreach ($pool as $pkey => $rows) {
            if (empty($rows)) continue;
            $prodName = molavi_domain_parity_fold_key_part($rows[0]['Product Name'] ?? '');
            if (str_starts_with($prodName, $expName . '،') || str_starts_with($prodName, $expName . ' ')) {
                $tail = trim(mb_substr($prodName, mb_strlen($expName)));
                if (preg_match('/\d/u', $tail) || preg_match('/باکس|کارتن|بسته|عدد|تایی|عددی|پک|شانه|ورق/u', $tail)) {
                    $pair['got'] = array_shift($pool[$pkey]); $pair['fuzzy'] = true;
                    $notes[] = ['row' => $key, 'note' => 'fuzzy_name_match', 'produced_name' => (string) ($pair['got']['Product Name'] ?? '')];
                    break;
                }
            }
        }
    }
    unset($pair);
    foreach ($pairs as $pair) {
        $exp = $pair['exp']; $key = $pair['key']; $got = $pair['got']; $fuzzy = $pair['fuzzy'];
        if ($got === null) { $missing[] = $key; continue; }
        $matched++;
        // Volume/Weight compare jointly (see molavi_domain_parity_size_set).
        if (molavi_domain_parity_size_set($exp) !== molavi_domain_parity_size_set($got)) {
            $fieldDiffs[] = ['row' => $key, 'field' => 'Volume', 'expected' => implode(' / ', molavi_domain_parity_size_set($exp)), 'produced' => implode(' / ', molavi_domain_parity_size_set($got))];
        }
        foreach (array_unique(array_merge(array_keys($exp), array_keys($got))) as $field) {
            if (isset($ignored[$field])) continue;
            if ($field === 'Volume' || $field === 'Weight') continue;
            $diff = ['row' => $key, 'field' => $field, 'expected' => $exp[$field] ?? '', 'produced' => $got[$field] ?? ''];
            if ($field === 'Product Name' || $field === 'Variation Type') {
                // The key is folded for orthography; the field value compares folded too, or a folded match would fail on its own spelling.
                // A fuzzy-matched name differs by the pack phrase the fallback already noted: info, not strict.
                if (molavi_domain_parity_fold_key_part($exp[$field] ?? '') === molavi_domain_parity_fold_key_part($got[$field] ?? '')) continue;
                if ($fuzzy && $field === 'Product Name') { $infoDiffs[] = $diff; continue; }
                if (isset($strict[$field])) { $fieldDiffs[] = $diff; continue; }
                $infoDiffs[] = $diff;
                continue;
            }
            if ($field === 'Review Reason') {
                // History clauses stripped on both sides first: a diff
                // that is only in those clauses is reported, never fails.
                $eStrip = molavi_domain_parity_strip_history_clauses((string) ($exp[$field] ?? ''));
                $gStrip = molavi_domain_parity_strip_history_clauses((string) ($got[$field] ?? ''));
                if ($eStrip !== $gStrip && !molavi_domain_parity_values_equal($eStrip, $gStrip)) {
                    if ($historyDependent && isset($historyFields[$field])) $infoDiffs[] = $diff;
                    elseif (isset($strict[$field])) $fieldDiffs[] = $diff;
                    else $infoDiffs[] = $diff;
                } elseif (($exp[$field] ?? '') !== ($got[$field] ?? '')) {
                    $infoDiffs[] = $diff;
                }
                continue;
            }
            if (molavi_domain_parity_values_equal($exp[$field] ?? '', $got[$field] ?? '')) continue;
            if ($field === 'Offer ID') {
                // Batch-dependent: compare the message:index suffix only.
                $es = (string) ($exp[$field] ?? ''); $gs = (string) ($got[$field] ?? '');
                $es = strpos($es, ':') !== false ? substr($es, strpos($es, ':') + 1) : $es;
                $gs = strpos($gs, ':') !== false ? substr($gs, strpos($gs, ':') + 1) : $gs;
                if ($es === $gs) continue;
                $diff['expected'] = $es; $diff['produced'] = $gs;
            }
            if ($historyDependent && isset($historyFields[$field])) { $infoDiffs[] = $diff; continue; }
            if (isset($strict[$field])) { $fieldDiffs[] = $diff; continue; }
            $infoDiffs[] = $diff;
        }
    }
    $extra = [];
    foreach ($pool as $key => $rows) foreach ($rows as $row) $extra[] = $key;
    return ['matched' => $matched, 'missing' => $missing, 'extra' => $extra, 'field_diffs' => $fieldDiffs, 'info_diffs' => $infoDiffs, 'notes' => $notes];
}

/**
 * Run the corpus through an extractor and diff every message.
 * $extract receives one corpus entry and returns its Offers rows as field
 * maps (same shape as the expected rows, minus _sheet_row). A message passes
 * only when every strict field matches and no rows are missing or extra.
 * The runner writes nothing to Sheets and changes no product state.
 */
function molavi_domain_parity_run(array $corpus, callable $extract): array {
    $results = []; $passed = 0; $byClass = [];
    foreach ($corpus['entries'] as $entry) {
        $produced = $extract($entry);
        if (!is_array($produced)) molavi_domain_fail('parity_extractor_invalid', 500);
        $history = molavi_domain_parity_entry_history_dependent($entry);
        $diff = molavi_domain_parity_diff($entry['expected_rows'] ?? [], $produced, $history);
        $ok = empty($diff['missing']) && empty($diff['extra']) && empty($diff['field_diffs']);
        if ($ok) $passed++;
        $class = (string) ($entry['class'] ?? 'general');
        $byClass[$class]['total'] = ($byClass[$class]['total'] ?? 0) + 1;
        if ($ok) $byClass[$class]['passed'] = ($byClass[$class]['passed'] ?? 0) + 1;
        $results[] = [
            'message_id' => $entry['message_id'] ?? '',
            'class' => $class,
            'channel' => $entry['channel'] ?? '',
            'timestamp' => $entry['timestamp'] ?? null,
            'history_dependent' => $history,
            'status' => $ok ? 'pass' : 'fail',
            'expected_rows' => count($entry['expected_rows'] ?? []),
            'produced_rows' => count($produced),
            'matched' => $diff['matched'],
            'missing' => $diff['missing'],
            'extra' => $diff['extra'],
            'field_diffs' => $diff['field_diffs'],
            'info_diffs' => $diff['info_diffs'],
            'notes' => $diff['notes'] ?? [],
        ];
    }
    $total = count($results);
    return ['summary' => ['total' => $total, 'passed' => $passed, 'failed' => $total - $passed, 'by_class' => $byClass], 'results' => $results];
}

/** Slice 0 baseline extractor: re-reads the live Offers rows for the message. Read-only; loads the sheet once per request. */
function molavi_domain_parity_reference_extractor(): callable {
    $byMessage = null;
    return static function(array $entry) use (&$byMessage): array {
        if ($byMessage === null) {
            $byMessage = [];
            $rows = molavi_domain_records(molavi_domain_sheets(['Offers'], true, true)['Offers'] ?? []);
            foreach ($rows as $row) $byMessage[(string) ($row['Message ID'] ?? '')][] = $row;
        }
        return $byMessage[(string) ($entry['message_id'] ?? '')] ?? [];
    };
}

/** Slice 2 WP-path extractor: replay one entry through the WP extraction path in SHADOW.
 *  The model is called via Railway (the only Railway contact in the harness);
 *  the import runs dry (no Sheet writes, no product rebuild) and returns the
 *  message's own Offers rows as field maps. The reference snapshot is loaded
 *  once per harness run; if it is not eligible the run fails loudly instead
 *  of silently producing rows without reference matching. */
function molavi_domain_parity_wp_extractor(): callable {
    $snapshot = null;
    $loaded = false;
    return static function(array $entry) use (&$snapshot, &$loaded): array {
        if (!$loaded) {
            $snapshot = molavi_domain_extract_reference_snapshot_for_batch();
            if ($snapshot === null) molavi_domain_fail('reference_pending', 503);
            $loaded = true;
        }
        $message = [
            'id' => (string) ($entry['message_id'] ?? ''),
            'channel' => (string) ($entry['channel'] ?? ''),
            'channel_username' => (string) ($entry['channel_username'] ?? ''),
            'content' => (string) ($entry['message_text'] ?? ''),
            'timestamp' => (string) ($entry['timestamp'] ?? ''),
            'batch_id' => 'parity-wp',
        ];
        $result = molavi_domain_extract_import_product_data($message, [
            'reference_snapshot' => $snapshot, 'dry_run' => true]);
        if (empty($result['success']))
            molavi_domain_fail('parity_wp_import_failed', 503, ['error' => (string) ($result['error'] ?? '')]);
        return $result['rows'] ?? [];
    };
}

/** Negative control: produces no rows. Must pass exactly the non_product entries and fail the rest. */
function molavi_domain_parity_empty_extractor(): callable {
    return static fn(array $entry): array => [];
}

/** Mutation control: reference rows with one strict field perturbed. Must fail exactly one message, naming the field.
 * The perturbation targets the first NON-history-dependent entry with rows: history-dependent entries compare Sale Price
 * as info, so perturbing one there would not fail. (First such entry in corpus order is message 24.) */
function molavi_domain_parity_mutated_extractor(): callable {
    $ref = molavi_domain_parity_reference_extractor();
    $done = false;
    return static function(array $entry) use ($ref, &$done): array {
        $rows = $ref($entry);
        if (!$done && !empty($rows) && !molavi_domain_parity_entry_history_dependent($entry)) {
            $done = true;
            $first = $rows[0];
            $sale = molavi_domain_number((string) ($first['Sale Price'] ?? ''));
            $first['Sale Price'] = $sale > 0 ? (string) ($sale + 1) : '1';
            $rows[0] = $first;
        }
        return $rows;
    };
}

add_action('rest_api_init', static function(): void {
    register_rest_route('molavi-workflow/v1', '/parity-harness/run', ['methods' => 'GET', 'permission_callback' => 'molavi_runtime_permission',
        'callback' => static function($request) {
            $mode = (string) ($request->get_param('mode') ?? 'reference');
            if (!in_array($mode, ['reference', 'empty', 'mutated', 'wp'], true)) {
                return new WP_Error('invalid_parity_mode', 'Unknown parity mode', ['status' => 400]);
            }
            try {
                $corpus = molavi_domain_parity_corpus();
                // Chunking for the wp mode: 40 live model calls do not fit
                // one request (or one quota minute). offset/limit slice the
                // entries; the summary counts only the slice.
                $offset = max(0, (int) ($request->get_param('offset') ?? 0));
                $limit = max(0, (int) ($request->get_param('limit') ?? 0));
                if ($limit > 0) $corpus['entries'] = array_slice($corpus['entries'] ?? [], $offset, $limit);
                $extract = $mode === 'empty' ? molavi_domain_parity_empty_extractor()
                    : ($mode === 'mutated' ? molavi_domain_parity_mutated_extractor()
                    : ($mode === 'wp' ? molavi_domain_parity_wp_extractor()
                    : molavi_domain_parity_reference_extractor()));
                $report = molavi_domain_parity_run($corpus, $extract);
                return ['ok' => true, 'mode' => $mode, 'offset' => $offset, 'limit' => $limit,
                    'corpus' => ['version' => $corpus['version'] ?? 0, 'captured_at' => $corpus['captured_at'] ?? null, 'entries' => count($corpus['entries'] ?? [])],
                    'report' => $report];
            } catch (MolaviWorkflowError $e) {
                return new WP_Error($e->getMessage(), 'Parity harness failed', ['status' => $e->status]);
            } catch (Throwable $e) {
                return new WP_Error('parity_harness_failed', 'Parity harness failed', ['status' => 503]);
            }
        }]);
});
