<?php
declare(strict_types=1);

function shop_config(): array
{
    $path = __DIR__ . '/private/config.php';
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function shop_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function shop_digits(string $value): string
{
    return strtr($value, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
        ',' => '٬',
    ]);
}

function shop_toman(int $amount): string
{
    return shop_digits(number_format($amount, 0, '.', ',')) . ' تومان';
}

function shop_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    if (empty($_SESSION['token'])) {
        $_SESSION['token'] = bin2hex(random_bytes(16));
    }
    header('Cache-Control: no-store');
}

function shop_token_ok(): bool
{
    $sent = $_POST['token'] ?? '';
    return is_string($sent) && hash_equals($_SESSION['token'] ?? '', $sent);
}

function shop_catalog(): array
{
    $snapshot = shop_read_json(__DIR__ . '/data/catalog.json');
    $cache = __DIR__ . '/data/cache.json';
    if (is_file($cache) && time() - filemtime($cache) < 600) {
        $cached = shop_read_json($cache);
        if (!empty($cached['products'])) {
            return $cached;
        }
    }
    try {
        $live = shop_fetch_sheet();
        if (!empty($live['products'])) {
            file_put_contents($cache, json_encode($live, JSON_UNESCAPED_UNICODE));
            return $live;
        }
    } catch (Throwable $e) {
        // The Iran host may not reach Google. The snapshot still serves the shop.
    }
    return $snapshot ?: ['generated_at' => '', 'products' => []];
}

function shop_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function shop_fetch_sheet(): array
{
    $config = shop_config();
    $account = $config['service_account'] ?? null;
    $sheetId = $config['sheet_id'] ?? '';
    if (!is_array($account) || $sheetId === '') {
        return [];
    }
    $token = shop_access_token($account);
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId) . '/values/' . rawurlencode('Products');
    $response = shop_http('GET', $url, [
        'Authorization: Bearer ' . $token,
    ]);
    $payload = json_decode($response, true);
    $rows = $payload['values'] ?? null;
    if (!is_array($rows) || count($rows) < 2) {
        return [];
    }
    $header = array_map('strval', $rows[0]);
    $index = array_flip($header);
    $labels = [
        '@bonakdarjavan' => 'بنکدار جوان',
        '@top_shop_rahimi' => 'تاپ‌شاپ رحیمی',
        '@nobelshop118' => 'نوبل',
    ];
    $products = [];
    foreach (array_slice($rows, 1) as $row) {
        $cell = static function (string $name) use ($index, $row): string {
            if (!isset($index[$name])) {
                return '';
            }
            $i = $index[$name];
            return isset($row[$i]) ? trim((string) $row[$i]) : '';
        };
        $status = strtolower($cell('Status'));
        if ($status === 'skipped' || $status === 'needs_review') {
            continue;
        }
        $name = shop_trim_name($cell('Product Name'));
        $sale = shop_int($cell('Sale Price'));
        if ($name === '' || $sale <= 0) {
            continue;
        }
        $shelf = shop_int($cell('Consumer Price'));
        if ($shelf <= 0) {
            $shelf = shop_int($cell('Actual Price'));
        }
        $channel = $cell('Channel Username');
        $products[] = [
            'id' => substr(hash('sha256', $channel . '|' . $name), 0, 12),
            'name' => $name,
            'packaging' => $cell('Packaging'),
            'volume' => $cell('Volume'),
            'variation' => $cell('Variation Type'),
            'sale' => $sale,
            'shelf' => $shelf,
            'channel' => $channel,
            'supplier' => $labels[$channel] ?? $channel,
            'image_file_id' => $cell('Image File ID'),
        ];
    }
    usort($products, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    $settings = shop_default_settings();
    try {
        $settings = shop_parse_settings(shop_sheet_rows($token, (string) $sheetId, 'Shop!A1:B9'));
    } catch (Throwable $e) {
        $settings = shop_default_settings();
    }
    return [
        'generated_at' => gmdate('c'),
        'settings' => $settings,
        'products' => $products,
    ];
}

function shop_sheet_rows(string $token, string $sheetId, string $range): array
{
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId)
        . '/values/' . rawurlencode($range);
    $response = shop_http('GET', $url, [
        'Authorization: Bearer ' . $token,
    ]);
    $payload = json_decode($response, true);
    $rows = $payload['values'] ?? null;
    return is_array($rows) ? $rows : [];
}

function shop_trim_name(string $name): string
{
    $name = trim($name);
    $trimmed = preg_replace('/^[،,\s]+|[،,\s]+$/u', '', $name);
    return is_string($trimmed) ? $trimmed : $name;
}

function shop_int(string $value): int
{
    $clean = str_replace([',', ' '], '', $value);
    if ($clean === '' || !is_numeric($clean)) {
        return 0;
    }
    return (int) round((float) $clean);
}

function shop_access_token(array $account): string
{
    $cache = __DIR__ . '/data/token.json';
    if (is_file($cache)) {
        $saved = shop_read_json($cache);
        if (!empty($saved['access_token']) && ($saved['exp'] ?? 0) > time() + 60) {
            return (string) $saved['access_token'];
        }
    }
    $now = time();
    $header = shop_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = shop_b64(json_encode([
        'iss' => $account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $input = $header . '.' . $claim;
    $private = openssl_pkey_get_private($account['private_key']);
    if ($private === false) {
        throw new RuntimeException('sheet key rejected');
    }
    $signature = '';
    if (!openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('sheet key could not sign');
    }
    $jwt = $input . '.' . shop_b64($signature);
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);
    $response = shop_http('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], $body);
    $decoded = json_decode($response, true);
    if (empty($decoded['access_token'])) {
        throw new RuntimeException('sheet token refused');
    }
    file_put_contents($cache, json_encode([
        'access_token' => $decoded['access_token'],
        'exp' => $now + (int) ($decoded['expires_in'] ?? 3600),
    ]));
    return (string) $decoded['access_token'];
}

function shop_b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function shop_http(string $method, string $url, array $headers, string $body = '', int $timeout = 8): string
{
    $handle = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];
    if ($body !== '') {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($handle, $options);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('sheet request failed');
    }
    return $response;
}

function shop_default_settings(): array
{
    return [
        'min_margin' => 0.12,
        'min_profit' => 5000,
        'spread_low' => 0.10,
        'spread_high' => 0.60,
        'profit_share_low' => 0.70,
        'profit_share_high' => 0.35,
        'opening_share_low' => 0.20,
        'opening_share_high' => 0.25,
        'unlock_target' => 2000000,
    ];
}

function shop_parse_settings(array $rows): array
{
    $map = [
        'Min Margin' => ['min_margin', true],
        'Min Profit' => ['min_profit', false],
        'Spread Low' => ['spread_low', true],
        'Spread High' => ['spread_high', true],
        'Profit Share Low' => ['profit_share_low', true],
        'Profit Share High' => ['profit_share_high', true],
        'Opening Share Low' => ['opening_share_low', true],
        'Opening Share High' => ['opening_share_high', true],
        'Unlock Target' => ['unlock_target', false],
    ];
    $found = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row[0])) {
            continue;
        }
        $label = trim((string) $row[0]);
        if ($label === '' || array_key_exists($label, $found) || !isset($row[1]) || $row[1] === '') {
            continue;
        }
        $found[$label] = $row[1];
    }
    $settings = shop_default_settings();
    foreach ($map as $label => [$field, $percent]) {
        if (!array_key_exists($label, $found) || !is_numeric($found[$label])) {
            continue;
        }
        $number = (float) $found[$label];
        if ($field === 'min_profit' || $field === 'unlock_target') {
            $settings[$field] = (int) round($number);
            continue;
        }
        if ($percent && $number >= 2) {
            $number /= 100;
        }
        $settings[$field] = $number;
    }
    return $settings;
}

function shop_settings(array $catalog): array
{
    $settings = shop_default_settings();
    $given = $catalog['settings'] ?? null;
    if (!is_array($given)) {
        return $settings;
    }
    foreach ($settings as $field => $default) {
        if (!isset($given[$field]) || !is_numeric($given[$field])) {
            continue;
        }
        $settings[$field] = $field === 'min_profit' || $field === 'unlock_target'
            ? (int) $given[$field]
            : (float) $given[$field];
    }
    return $settings;
}

function shop_round_up(float $value): int
{
    $nearest = round($value, 8);
    $whole = (int) $nearest;
    if ($nearest - $whole > 1e-8) {
        return $whole + 1;
    }
    return $whole;
}

function shop_round_down(float $value): int
{
    return (int) round($value, 8);
}

function shop_round_half_up(float $value): int
{
    $nearest = round($value, 8);
    $whole = (int) $nearest;
    if ($nearest - $whole >= 0.5 - 1e-8) {
        return $whole + 1;
    }
    return $whole;
}

function shop_band(int $cost, int $regular, array $settings): array
{
    $blank = static function (int $regularPrice): array {
        $price = $regularPrice > 0 ? $regularPrice : 0;
        return [
            'floor' => $price,
            'ceiling' => $price,
            'opening' => 0.0,
            'depth' => 0.0,
            'max' => 0.0,
            'discountable' => false,
        ];
    };
    if ($cost <= 0 || $regular <= 0 || $regular <= $cost) {
        return $blank($regular);
    }
    $gap = $regular - $cost;
    $spread = $gap / $regular;
    $span = $settings['spread_high'] - $settings['spread_low'];
    $t = $span <= 0 ? 0.0 : max(0.0, min(1.0, ($spread - $settings['spread_low']) / $span));
    $profitShare = $settings['profit_share_low'] + ($settings['profit_share_high'] - $settings['profit_share_low']) * $t;
    $openingShare = $settings['opening_share_low'] + ($settings['opening_share_high'] - $settings['opening_share_low']) * $t;
    $profit = max($profitShare * $gap, max($cost * $settings['min_margin'], (float) $settings['min_profit']));
    if ($cost + $profit >= $regular) {
        return $blank($regular);
    }
    $floor = shop_round_up($cost + $profit);
    if ($floor >= $regular) {
        return $blank($regular);
    }
    $room = $regular - $floor;
    $openingToman = min($openingShare * $gap, (float) $room);
    $ceiling = shop_round_down($regular - $openingToman);
    if ($ceiling < $floor) {
        $ceiling = $floor;
    }
    if ($ceiling > $regular) {
        $ceiling = $regular;
    }
    return [
        'floor' => $floor,
        'ceiling' => $ceiling,
        'opening' => ($regular - $ceiling) / $regular,
        'depth' => ($ceiling - $floor) / $regular,
        'max' => ($regular - $floor) / $regular,
        'discountable' => ($regular - $floor) > 0,
    ];
}

function shop_percent(float $rate): string
{
    return shop_digits((string) (int) round($rate * 100)) . '٪';
}

function shop_upto(float $rate): string
{
    return 'تا ' . shop_percent($rate);
}

function shop_unit_price(array $product, float $progress): int
{
    $regular = (int) $product['shelf'];
    $sale = (int) $product['sale'];
    if ($regular <= 0) {
        return $sale;
    }
    $ceiling = (int) ($product['ceiling'] ?? $regular);
    $floor = (int) ($product['floor'] ?? $regular);
    $unit = shop_round_half_up($ceiling + ($floor - $ceiling) * $progress);
    $low = min($floor, $ceiling);
    $high = min(max($floor, $ceiling, $low), $regular);
    if ($unit < $low) {
        $unit = $low;
    }
    if ($unit > $high) {
        $unit = $high;
    }
    return $unit;
}

function shop_quote(array $catalog, array $cart): array
{
    $unlock = (int) shop_settings($catalog)['unlock_target'];
    $byId = [];
    foreach ($catalog['products'] ?? [] as $product) {
        $byId[$product['id']] = $product;
    }
    $measure = 0;
    $pending = [];
    foreach ($cart as $id => $qty) {
        $qty = (int) $qty;
        if ($qty < 1 || !isset($byId[$id])) {
            continue;
        }
        $min = shop_product_min($byId[$id]);
        if ($qty < $min) {
            $qty = $min;
            $_SESSION['cart'][$id] = $min;
        }
        $product = $byId[$id];
        if (!empty($product['discountable']) && (int) $product['shelf'] > 0) {
            $measure += (int) $product['shelf'] * $qty;
        }
        $pending[] = ['product' => $product, 'qty' => $qty];
    }
    $progress = ($unlock > 0 && $measure > 0) ? min(1.0, $measure / $unlock) : 0.0;
    $units = [];
    foreach ($byId as $id => $product) {
        if (empty($product['discountable'])) {
            continue;
        }
        $units[$id] = shop_unit_price($product, $progress);
    }
    $lines = [];
    $total = 0;
    $discountTotal = 0;
    foreach ($pending as $item) {
        $product = $item['product'];
        $qty = $item['qty'];
        $shelf = (int) $product['shelf'];
        $unit = $units[$product['id']] ?? shop_unit_price($product, $progress);
        $saved = $shelf > $unit ? ($shelf - $unit) * $qty : 0;
        $discountTotal += $saved;
        $total += $unit * $qty;
        $lines[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'packaging' => $product['packaging'] ?? '',
            'volume' => $product['volume'] ?? '',
            'supplier' => $product['supplier'] ?? '',
            'image' => (string) ($product['image'] ?? ''),
            'sku' => (string) ($product['sku'] ?? ''),
            'qty' => $qty,
            'sale' => (int) $product['sale'],
            'shelf' => $shelf,
            'unit' => $unit,
            'total' => $unit * $qty,
            'discount_amount' => $saved,
            'discount_rate' => $shelf > $unit ? ($shelf - $unit) / $shelf : 0.0,
            'min' => shop_product_min($product),
        ];
    }
    return [
        'lines' => $lines,
        'total' => $total,
        'measure' => $measure,
        'discount_total' => $discountTotal,
        'discount_rate' => $measure > 0 ? $discountTotal / $measure : 0.0,
        'progress' => $progress,
        'unlock' => $unlock,
        'units' => $units,
    ];
}

function shop_add(string $id, int $qty): void
{
    if ($id === '' || $qty < 1) {
        return;
    }
    $qty = min(999, $qty);
    $current = (int) ($_SESSION['cart'][$id] ?? 0);
    $_SESSION['cart'][$id] = min(999, $current + $qty);
}

function shop_set(string $id, int $qty, int $min = 1): void
{
    $min = max(1, min(999, $min));
    $current = (int) ($_SESSION['cart'][$id] ?? 0);
    if ($qty < 1) {
        unset($_SESSION['cart'][$id]);
        return;
    }
    if ($qty < $min) {
        if ($current >= $min && $qty < $current) {
            unset($_SESSION['cart'][$id]);
            return;
        }
        $qty = $min;
    }
    $_SESSION['cart'][$id] = min(999, $qty);
}

require_once __DIR__ . '/store.php';
