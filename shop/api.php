<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$json = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
    || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

try {
    $catalog = shop_present();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        echo shop_json_out(shop_public(shop_quote($catalog, $_SESSION['cart'])));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    $body = $json ? json_decode((string) file_get_contents('php://input'), true) : $_POST;
    if (!is_array($body)) {
        $body = [];
    }
    $token = (string) ($body['token'] ?? '');
    if (!hash_equals((string) ($_SESSION['token'] ?? ''), $token)) {
        shop_fail($json, 'نشست فروشگاه منقضی شده. صفحه را تازه کنید.');
    }
    $id = (string) ($body['id'] ?? '');
    $qty = (int) ($body['qty'] ?? 0);
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        shop_fail($json, 'کالا پیدا نشد.');
    }
    $product = shop_find($catalog, $id);
    if ($product === null || (!empty($product['hidden']) && $qty > 0) || (empty($product['discountable']) && $qty > 0)) {
        shop_fail($json, 'کالا پیدا نشد.');
    }
    shop_set($id, $qty, $product === null ? 1 : shop_product_min($product));
    $payload = shop_public(shop_quote($catalog, $_SESSION['cart']));
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo shop_json_out($payload);
        exit;
    }
    $back = basename((string) ($body['back'] ?? 'index.php'));
    $allowed = ['index.php', 'cart.php', 'about.php', 'contact.php', 'account.php'];
    header('Location: ' . (in_array($back, $allowed, true) ? $back : 'index.php'));
} catch (Throwable $e) {
    shop_fail($json, 'سبد به‌روز نشد.');
}

function shop_json_out(array $payload): string
{
    $payload['ok'] = true;
    return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function shop_fail(bool $json, string $message): void
{
    if ($json) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo (string) json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: index.php');
    }
    exit;
}
