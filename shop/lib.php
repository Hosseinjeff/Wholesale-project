<?php
declare(strict_types=1);

const SHOP_CEILING_MULTIPLE = 5;
const SHOP_TUNA_PACK = 24;

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

function shop_toman(int $amount): string
{
    return number_format($amount, 0, '.', ',') . ' تومان';
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
        $name = shop_trim_name($cell('Product Name'));
        $sale = shop_int($cell('Sale Price'));
        if ($name === '' || $sale <= 0) {
            continue;
        }
        $channel = $cell('Channel Username');
        $products[] = [
            'id' => substr(hash('sha256', $channel . '|' . $name), 0, 12),
            'name' => $name,
            'packaging' => $cell('Packaging'),
            'volume' => $cell('Volume'),
            'variation' => $cell('Variation Type'),
            'sale' => $sale,
            'shelf' => shop_int($cell('Consumer Price')),
            'channel' => $channel,
            'supplier' => $labels[$channel] ?? $channel,
        ];
    }
    usort($products, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return [
        'generated_at' => gmdate('c'),
        'products' => $products,
    ];
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

function shop_http(string $method, string $url, array $headers, string $body = ''): string
{
    $handle = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
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

function shop_reference(array $products): ?array
{
    $candidates = [];
    foreach ($products as $product) {
        $name = (string) ($product['name'] ?? '');
        if (mb_strpos($name, 'تن') === false) {
            continue;
        }
        if (!preg_match('/180|۱۸۰/u', $name)) {
            continue;
        }
        if ((int) ($product['shelf'] ?? 0) <= 0) {
            continue;
        }
        $candidates[] = $product;
    }
    if ($candidates === []) {
        return null;
    }
    usort($candidates, static fn(array $a, array $b): int => $a['shelf'] <=> $b['shelf']);
    $pick = $candidates[intdiv(count($candidates) - 1, 2)];
    $unit = (int) $pick['shelf'];
    return [
        'name' => (string) $pick['name'],
        'unit' => $unit,
        'floor' => $unit * SHOP_TUNA_PACK,
        'ceiling' => $unit * SHOP_TUNA_PACK * SHOP_CEILING_MULTIPLE,
    ];
}

function shop_unit_price(array $product, float $progress): int
{
    $sale = (int) $product['sale'];
    $shelf = (int) $product['shelf'];
    if ($shelf > $sale) {
        $unit = (int) (round(($shelf - $progress * ($shelf - $sale)) / 1000) * 1000);
        return max($sale, min($shelf, $unit));
    }
    return $sale;
}

function shop_quote(array $catalog, array $cart): array
{
    $byId = [];
    foreach ($catalog['products'] ?? [] as $product) {
        $byId[$product['id']] = $product;
    }
    $reference = shop_reference($catalog['products'] ?? []);
    $floor = $reference['floor'] ?? 0;
    $ceiling = $reference['ceiling'] ?? 0;
    $measure = 0;
    $pending = [];
    foreach ($cart as $id => $qty) {
        $qty = (int) $qty;
        if ($qty < 1 || !isset($byId[$id])) {
            continue;
        }
        $product = $byId[$id];
        $basis = (int) $product['shelf'] > 0 ? (int) $product['shelf'] : (int) $product['sale'];
        $measure += $basis * $qty;
        $pending[] = ['product' => $product, 'qty' => $qty];
    }
    $progress = 0.0;
    if ($floor > 0 && $ceiling > $floor && $measure > $floor) {
        $progress = min(1.0, ($measure - $floor) / ($ceiling - $floor));
    }
    $units = [];
    foreach ($byId as $id => $product) {
        $units[$id] = shop_unit_price($product, $progress);
    }
    $lines = [];
    $total = 0;
    $shelfTotal = 0;
    foreach ($pending as $item) {
        $product = $item['product'];
        $qty = $item['qty'];
        $sale = (int) $product['sale'];
        $shelf = (int) $product['shelf'];
        $unit = $units[$product['id']] ?? shop_unit_price($product, $progress);
        $lineTotal = $unit * $qty;
        $total += $lineTotal;
        $shelfTotal += ($shelf > 0 ? $shelf : $sale) * $qty;
        $lines[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'packaging' => $product['packaging'] ?? '',
            'volume' => $product['volume'] ?? '',
            'supplier' => $product['supplier'] ?? '',
            'image' => (string) ($product['image'] ?? ''),
            'sku' => (string) ($product['sku'] ?? ''),
            'qty' => $qty,
            'sale' => $sale,
            'shelf' => $shelf,
            'unit' => $unit,
            'total' => $lineTotal,
        ];
    }
    return [
        'lines' => $lines,
        'total' => $total,
        'measure' => $measure,
        'shelf_total' => $shelfTotal,
        'progress' => $progress,
        'reference' => $reference,
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

function shop_set(string $id, int $qty): void
{
    if ($qty < 1) {
        unset($_SESSION['cart'][$id]);
        return;
    }
    $_SESSION['cart'][$id] = min(999, $qty);
}

require_once __DIR__ . '/store.php';
