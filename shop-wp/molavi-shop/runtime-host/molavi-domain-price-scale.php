<?php
/** Plugin Name: Molavi Native Price Scaling (Slice 2) */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Faithful port of catalog/price_scale.py (Slice 2).
 *
 * Conservative price-role fallback recovered from GAS reference/history logic.
 * molavi_domain_price_scale_assess() only describes a price (certain or not);
 * molavi_domain_price_scale_apply() acts on that assessment, and only the
 * four certain actions (category/history unit/package price) mutate prices.
 * Reference rows are 21-column lists in molavi_domain_reference_headers()
 * order; package counts come from molavi_domain_match_package().
 */

function molavi_domain_price_scale_name($value): string {
    if (is_bool($value)) {
        $value = $value ? 'True' : '';
    }
    $text = molavi_domain_normalize_name((string) molavi_domain_py_value($value, ''));
    $text = (string) preg_replace('/\([^)]*\)/u', ' ', $text);
    $text = (string) preg_replace('/\d+\s*(?:ع|عدد|عددی|تایی|ف)/u', ' ', $text);
    $text = (string) preg_replace('#[\d.,/\\\\*×x]+#iu', ' ', $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

function molavi_domain_price_scale_similarity($left, $right): float {
    $a = molavi_domain_price_scale_name($left);
    $b = molavi_domain_price_scale_name($right);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    if (str_contains($a, $b) || str_contains($b, $a)) {
        return 0.9;
    }
    $at = array_values(array_filter(preg_split('/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        static fn($part) => molavi_domain_strlen($part) > 1));
    $bt = array_values(array_filter(preg_split('/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        static fn($part) => molavi_domain_strlen($part) > 1));
    $btSet = array_flip($bt);
    $hits = 0;
    foreach ($at as $token) {
        if (isset($btSet[$token])) {
            $hits++;
        }
    }
    return $hits / max(count($at), count($bt), 1);
}

function molavi_domain_price_scale_number($value): float {
    if (is_bool($value)) {
        return $value ? 1.0 : 0.0;
    }
    if (is_int($value) || is_float($value)) {
        $result = (float) $value;
        return $result > 0 ? $result : 0.0;
    }
    if (is_array($value) || is_object($value) || $value === null) {
        return 0.0;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return 0.0;
    }
    $lower = strtolower($raw);
    if ($lower === 'nan') {
        return 0.0;
    }
    if (in_array($lower, ['inf', '+inf', 'infinity', '+infinity'], true)) {
        return INF;
    }
    if (in_array($lower, ['-inf', '-infinity'], true)) {
        return 0.0;
    }
    if (!is_numeric($raw)) {
        return 0.0;
    }
    $result = (float) $raw;
    return $result > 0 ? $result : 0.0;
}

function molavi_domain_price_scale_attribute_score($left, $right): float {
    $fold = static function ($value): string {
        if (is_bool($value)) {
            $value = $value ? 'True' : '';
        }
        $text = trim((string) molavi_domain_py_value($value, ''));
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    };
    $a = $fold($left);
    $b = $fold($right);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    return str_contains($a, $b) || str_contains($b, $a) ? 0.7 : 0.0;
}

function molavi_domain_price_scale_distance(float $value, float $baseline): float {
    return $value > 0 && $baseline > 0 ? abs($value - $baseline) / $baseline : 999.0;
}

function molavi_domain_price_scale_category_stats(array $product, ?array $referenceRows): ?array {
    $headers = molavi_domain_reference_headers();
    $category = molavi_domain_normalize_name((string) molavi_domain_py_value($product['category'] ?? null, ''));
    if ($category === '') {
        return null;
    }
    $categoryIdx = (int) array_search('Category', $headers, true);
    $refs = [];
    foreach ($referenceRows ?? [] as $row) {
        if (!is_array($row) || count($row) !== count($headers)) {
            continue;
        }
        $refCategory = molavi_domain_normalize_name((string) molavi_domain_py_value($row[$categoryIdx] ?? null, ''));
        if ($refCategory === '' || (!str_contains($refCategory, $category) && !str_contains($category, $refCategory))) {
            continue;
        }
        $refs[] = $row;
    }
    if (count($refs) < 3) {
        return null;
    }
    $unitIdx = (int) array_search('Reference Unit Price', $headers, true);
    $packageIdx = (int) array_search('Reference Package Price', $headers, true);
    $codeIdx = (int) array_search('Product Code', $headers, true);
    $keyIdx = (int) array_search('Normalized Key', $headers, true);
    $nameIdx = (int) array_search('Product Name', $headers, true);
    $units = [];
    $packages = [];
    foreach ($refs as $row) {
        $unit = molavi_domain_price_scale_number($row[$unitIdx] ?? null);
        if ($unit) {
            $units[] = $unit;
        }
        $package = molavi_domain_price_scale_number($row[$packageIdx] ?? null);
        if ($package) {
            $packages[] = $package;
        }
    }
    sort($units, SORT_NUMERIC);
    sort($packages, SORT_NUMERIC);
    // Upper middle element, like Python units[len(units) // 2]; not an average.
    $medianUnit = $units ? $units[intdiv(count($units), 2)] : 0;
    $medianPackage = $packages ? $packages[intdiv(count($packages), 2)] : 0;
    $ids = [];
    $names = [];
    foreach ($refs as $row) {
        $identity = (string) molavi_domain_py_value($row[$codeIdx] ?? null, molavi_domain_py_value($row[$keyIdx] ?? null, ''));
        $label = (string) molavi_domain_py_value($row[$nameIdx] ?? null, '');
        if ($identity !== '' && !in_array($identity, $ids, true)) {
            $ids[] = $identity;
        }
        if ($label !== '' && !in_array($label, $names, true)) {
            $names[] = $label;
        }
    }
    $anchorScore = 0.0;
    foreach ($refs as $row) {
        $anchorScore = max($anchorScore, molavi_domain_price_scale_similarity($product['name'] ?? null, $row[$nameIdx] ?? null));
    }
    $anchorName = 'Category median: ' . (string) molavi_domain_py_value($product['category'] ?? null, '');
    if ($names) {
        $anchorName .= ' [' . implode(' | ', $names) . ']';
    }
    return [
        'count' => count($refs),
        'unit' => $medianUnit,
        'package' => $medianPackage,
        'anchor_id' => implode(',', $ids),
        'anchor_name' => $anchorName,
        'anchor_score' => $anchorScore,
    ];
}

function molavi_domain_price_scale_history(array $product, ?array $message, ?array $offerRecords, int $limit = 8): array {
    $targetName = (string) molavi_domain_py_value($product['name'] ?? null, molavi_domain_py_value($product['product_name'] ?? null, ''));
    if ($targetName === '') {
        return ['count' => 0, 'median' => 0, 'prices' => []];
    }
    $targetBrand = (string) molavi_domain_py_value($product['brand'] ?? null, '');
    $targetCategory = (string) molavi_domain_py_value($product['category'] ?? null, '');
    $targetVariant = (string) molavi_domain_py_value($product['variation_type'] ?? null, molavi_domain_py_value($product['variant'] ?? null, ''));
    $targetCount = molavi_domain_price_scale_number(molavi_domain_match_package($product));
    $message = $message ?? [];
    $channelRaw = (string) molavi_domain_py_value($message['channel_username'] ?? null, molavi_domain_py_value($message['channel'] ?? null, ''));
    $targetChannel = function_exists('mb_strtolower') ? mb_strtolower(trim($channelRaw), 'UTF-8') : strtolower(trim($channelRaw));
    $matches = [];
    foreach (array_reverse($offerRecords ?? []) as $offer) {
        if (!is_array($offer)) {
            continue;
        }
        if (strtolower(trim((string) molavi_domain_py_value($offer['Status'] ?? null, ''))) !== 'imported') {
            continue;
        }
        if (!in_array(strtolower(trim((string) molavi_domain_py_value($offer['Price Type'] ?? null, ''))), ['unit', 'single', 'per_unit'], true)) {
            continue;
        }
        $price = molavi_domain_price_scale_number($offer['Sale Price'] ?? null);
        if (!$price) {
            continue;
        }
        $nameScore = molavi_domain_price_scale_similarity($targetName, $offer['Product Name'] ?? null);
        if ($nameScore < 0.72) {
            continue;
        }
        // Python for/else: any provided target whose attribute scores 0 skips
        // the offer; otherwise fall through to the pack-count/channel checks.
        $blocked = false;
        foreach ([[$targetBrand, 'Brand'], [$targetCategory, 'Category'], [$targetVariant, 'Variation Type']] as [$target, $key]) {
            $value = $offer[$key] ?? null;
            if ($target !== '' && ($value || (is_string($value) && $value === '0'))
                && molavi_domain_price_scale_attribute_score($target, $value) === 0.0) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        $oldCount = molavi_domain_price_scale_number(molavi_domain_match_package([
            'package_count' => $offer['Package Count'] ?? null,
            'packaging' => $offer['Packaging'] ?? null,
        ]));
        if ($targetCount && $oldCount && $oldCount != $targetCount) {
            continue;
        }
        $oldChannelRaw = (string) molavi_domain_py_value($offer['Channel Username'] ?? null, '');
        $oldChannel = function_exists('mb_strtolower') ? mb_strtolower(trim($oldChannelRaw), 'UTF-8') : strtolower(trim($oldChannelRaw));
        if ($targetChannel !== '' && $oldChannel !== '' && $oldChannel !== $targetChannel && $nameScore < 0.9) {
            continue;
        }
        $matches[] = ['price' => $price, 'score' => $nameScore, 'offer' => $offer];
        if (count($matches) >= $limit) {
            break;
        }
    }
    $prices = [];
    foreach ($matches as $match) {
        $prices[] = $match['price'];
    }
    sort($prices, SORT_NUMERIC);
    if (!$prices) {
        return ['count' => 0, 'median' => 0, 'prices' => []];
    }
    $middle = intdiv(count($prices), 2);
    $median = count($prices) % 2 ? $prices[$middle] : ($prices[$middle - 1] + $prices[$middle]) / 2;
    $anchorIds = [];
    $anchorNames = [];
    $bestScore = 0.0;
    foreach ($matches as $match) {
        $row = $match['offer'];
        $bestScore = max($bestScore, $match['score']);
        $rawId = $row['Offer ID'] ?? null;
        if ($rawId || (is_string($rawId) && $rawId === '0')) {
            $anchorIds[] = (string) $rawId;
        }
        $rawName = $row['Product Name'] ?? null;
        if ($rawName || (is_string($rawName) && $rawName === '0')) {
            $anchorNames[] = (string) $rawName;
        }
    }
    return [
        'count' => count($prices),
        'median' => $median,
        'prices' => $prices,
        'anchor_ids' => $anchorIds,
        'anchor_names' => $anchorNames,
        'best_match_score' => $bestScore,
    ];
}

/** Return a reviewable price-scale assessment; never act on explicit roles. */
function molavi_domain_price_scale_assess(?array $product, ?array $message = null, ?array $offers = null, array $referenceRows = [], int $minHistory = 1): array {
    $product = $product ?? [];
    $count = molavi_domain_price_scale_number(molavi_domain_match_package($product));
    $currency = strtoupper(trim((string) molavi_domain_py_value($product['currency'] ?? null, 'IRT')));
    if (!in_array($currency, ['IRT', 'TOMAN', 'T', 'تومان', 'تومن'], true)) {
        return ['certain' => false, 'action' => 'currency_review',
            'reason' => 'Currency must be reviewed before reference/history price comparison'];
    }
    $explicitRole = strtolower(trim((string) molavi_domain_py_value($product['confirmed_price_role'] ?? null, molavi_domain_py_value($product['price_type'] ?? null, ''))));
    if (in_array($explicitRole, ['unit', 'single', 'per_unit', 'package', 'pack', 'carton', 'both'], true)) {
        return ['certain' => true, 'action' => 'explicit_role', 'package_count' => $count];
    }
    $raw = molavi_domain_price_scale_number(molavi_domain_py_value($product['detected_price'] ?? null, molavi_domain_py_value($product['source_price_amount'] ?? null, molavi_domain_py_value($product['unit_price'] ?? null, molavi_domain_py_value($product['sale_price'] ?? null, molavi_domain_py_value($product['price'] ?? null, molavi_domain_py_value($product['package_price'] ?? null, null)))))));
    if ($raw <= 0) {
        return ['certain' => true, 'action' => 'none', 'package_count' => $count];
    }
    if (!empty($product['supplier_price_basis_conflict'])) {
        return ['certain' => false, 'action' => 'confirm_price_scale', 'detected_price' => $raw,
            'reason' => 'unlabeled supplier amount exceeds the consumer unit price'];
    }

    $stats = molavi_domain_price_scale_category_stats($product, $referenceRows);
    if ($stats) {
        $unitDistance = molavi_domain_price_scale_distance($raw, (float) $stats['unit']);
        $packageDistance = molavi_domain_price_scale_distance($raw, (float) $stats['package']);
        if ($stats['unit'] && $unitDistance <= 0.40 && (!$stats['package'] || $unitDistance + 0.15 < $packageDistance)) {
            return ['certain' => true, 'action' => 'category_unit_price', 'method' => 'category_median',
                'detected_price' => $raw, 'suggested_unit_price' => (int) ($raw + 0.5),
                'suggested_package_price' => $count > 1 ? (int) ($raw * $count + 0.5) : 0,
                'package_count' => $count, 'reference_unit_price' => $stats['unit'],
                'reference_package_price' => $stats['package'], 'anchor_type' => 'reference',
                'anchor_id' => $stats['anchor_id'], 'anchor_name' => $stats['anchor_name'],
                'anchor_score' => $stats['anchor_score'], 'category_reference_count' => $stats['count']];
        }
        if ($stats['package'] && $count > 1 && $packageDistance <= 0.40 && (!$stats['unit'] || $packageDistance + 0.15 < $unitDistance)) {
            return ['certain' => true, 'action' => 'category_package_price', 'method' => 'category_median',
                'detected_price' => $raw, 'suggested_unit_price' => (int) ($raw / $count + 0.5),
                'suggested_package_price' => (int) ($raw + 0.5), 'package_count' => $count,
                'reference_unit_price' => $stats['unit'], 'reference_package_price' => $stats['package'],
                'anchor_type' => 'reference', 'anchor_id' => $stats['anchor_id'],
                'anchor_name' => $stats['anchor_name'], 'anchor_score' => $stats['anchor_score'],
                'category_reference_count' => $stats['count']];
        }
    }

    $history = molavi_domain_price_scale_history($product, $message ?? [], $offers ?? []);
    $minHistory = $minHistory ?: 1;
    if ($history['count'] < max(1, (int) $minHistory) || $history['median'] <= 0) {
        return ['certain' => $count <= 1, 'action' => $count > 1 ? 'confirm_price_scale' : 'none',
            'reason' => 'Pack count is known but price role has no sufficient confirmed history',
            'detected_price' => $raw, 'package_count' => $count, 'history_count' => $history['count']];
    }
    if ($count <= 1) {
        return ['certain' => true, 'action' => 'none', 'package_count' => $count,
            'historical_median_unit_price' => $history['median']];
    }
    $currentRatio = molavi_domain_price_scale_distance($raw, (float) $history['median']);
    $divided = $raw / $count;
    $dividedRatio = molavi_domain_price_scale_distance($divided, (float) $history['median']);
    $likelyPackage = $raw >= (float) $history['median'] * 2.5 && $dividedRatio <= 0.35 && $dividedRatio < $currentRatio * 0.5;
    $historyAnchorName = implode(' | ', $history['anchor_names'] ?? []);
    if ($historyAnchorName === '') {
        $historyAnchorName = 'Confirmed supplier offer history';
    }
    if ($likelyPackage) {
        return ['certain' => true, 'action' => 'history_package_price', 'method' => 'history_confirmed_package',
            'detected_price' => $raw, 'suggested_unit_price' => (int) ($divided + 0.5),
            'suggested_package_price' => (int) ($raw + 0.5), 'package_count' => $count,
            'historical_median_unit_price' => $history['median'], 'history_count' => $history['count'],
            'anchor_type' => 'history', 'anchor_id' => implode(',', $history['anchor_ids'] ?? []),
            'anchor_name' => $historyAnchorName,
            'anchor_score' => molavi_domain_py_value($history['best_match_score'] ?? null, '')];
    }
    if ($currentRatio > 1.5) {
        return ['certain' => false, 'action' => 'confirm_price_scale',
            'reason' => 'Unit price deviates strongly from recent confirmed history',
            'detected_price' => $raw, 'suggested_unit_price' => (int) ($raw + 0.5),
            'suggested_package_price' => (int) ($raw * $count + 0.5), 'package_count' => $count,
            'historical_median_unit_price' => $history['median'], 'history_count' => $history['count']];
    }
    return ['certain' => true, 'action' => 'history_unit_price', 'method' => 'history_confirmed',
        'detected_price' => $raw, 'suggested_unit_price' => (int) ($raw + 0.5), 'package_count' => $count,
        'historical_median_unit_price' => $history['median'], 'history_count' => $history['count'],
        'anchor_type' => 'history', 'anchor_id' => implode(',', $history['anchor_ids'] ?? []),
        'anchor_name' => $historyAnchorName,
        'anchor_score' => molavi_domain_py_value($history['best_match_score'] ?? null, '')];
}

/** Apply only a certain role inference and attach source/anchor audit fields. */
function molavi_domain_price_scale_apply(array $product, ?array $assessment): array {
    if (!$assessment) {
        return $product;
    }
    $action = $assessment['action'] ?? null;
    if (in_array($action, ['category_unit_price', 'category_package_price', 'history_package_price', 'history_unit_price'], true) && !empty($assessment['certain'])) {
        $unit = (int) molavi_domain_py_value($assessment['suggested_unit_price'] ?? null, molavi_domain_py_value($assessment['detected_price'] ?? null, 0));
        if ($unit > 0) {
            $product['sale_price'] = $unit;
            $product['price'] = $unit;
            $product['price_type'] = 'unit';
            $product['confirmed_price_role'] = 'unit';
            if (in_array($action, ['category_package_price', 'history_package_price'], true)) {
                $product['package_price'] = (int) molavi_domain_py_value($assessment['suggested_package_price'] ?? null, 0);
                $product['min_qty'] = max((int) molavi_domain_price_scale_number($product['min_qty'] ?? null), (int) molavi_domain_price_scale_number($assessment['package_count'] ?? null));
            }
            $product['price_scale_status'] = 'confirmed';
            $reasons = [];
            foreach (explode(';', (string) molavi_domain_py_value($product['review_reason'] ?? null, '')) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $reasons[] = $part;
                }
            }
            $unresolved = [];
            foreach ($reasons as $part) {
                if (!in_array(strtolower($part), [
                    'supplier price role is unclear',
                    'supplier sale amount has no confirmed unit/package basis',
                    'sale price role is ambiguous: unit or package',
                    'pack count is detected but sale price role is not explicit',
                ], true)) {
                    $unresolved[] = $part;
                }
            }
            if (count($unresolved) !== count($reasons)) {
                $product['review_reason'] = implode('; ', $unresolved);
                $confidence = molavi_domain_price_scale_number(molavi_domain_py_value($product['confidence'] ?? null, molavi_domain_py_value($product['extraction_confidence'] ?? null, null)));
                if (!$unresolved && $confidence >= 0.85) {
                    $product['needs_review'] = false;
                }
            }
        }
    } elseif ($action === 'confirm_price_scale') {
        $product['needs_review'] = true;
        $reason = (string) molavi_domain_py_value($assessment['reason'] ?? null, 'Supplier price scale needs owner review');
        $existing = [];
        foreach (explode(';', (string) molavi_domain_py_value($product['review_reason'] ?? null, '')) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $existing[] = $part;
            }
        }
        if (!in_array($reason, $existing, true)) {
            $existing[] = $reason;
        }
        $product['review_reason'] = implode('; ', $existing);
        $product['price_scale_status'] = 'needs_review';
    }
    $product['detected_price'] = molavi_domain_py_value($assessment['detected_price'] ?? null, molavi_domain_py_value($product['detected_price'] ?? null, ''));
    $product['price_scale_method'] = molavi_domain_py_value($assessment['method'] ?? null, '');
    $product['price_anchor_type'] = molavi_domain_py_value($assessment['anchor_type'] ?? null, '');
    $product['price_anchor_id'] = molavi_domain_py_value($assessment['anchor_id'] ?? null, '');
    $product['price_anchor_name'] = molavi_domain_py_value($assessment['anchor_name'] ?? null, '');
    $product['price_anchor_score'] = molavi_domain_py_value($assessment['anchor_score'] ?? null, '');
    $product['historical_median_unit_price'] = molavi_domain_py_value($assessment['historical_median_unit_price'] ?? null, '');
    $product['price_scale_reason'] = molavi_domain_py_value($assessment['reason'] ?? null, '');
    $product['category_reference_count'] = molavi_domain_py_value($assessment['category_reference_count'] ?? null, '');
    return $product;
}
