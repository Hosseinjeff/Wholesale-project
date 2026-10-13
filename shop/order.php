<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_start();
$id = (string) ($_SESSION['last_order'] ?? '');
$order = $id !== '' ? shop_read_json(__DIR__ . '/data/orders/' . basename($id) . '.json') : [];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سفارش · مولوی‌سل</title>
    <link rel="stylesheet" href="assets/site.css">
</head>
<body>
<header class="top">
    <a class="brand" href="index.php">مولوی‌سل</a>
    <a class="cart-link" href="cart.php">سبد</a>
</header>
<main class="narrow">
    <?php if ($order === []): ?>
        <h1>سفارشی ثبت نشده است.</h1>
        <p><a href="index.php">برگشت به کالاها</a></p>
    <?php else: ?>
        <h1>سفارش ثبت شد.</h1>
        <p>شماره سفارش <strong><?= shop_h($order['id']) ?></strong> است. جمع <?= shop_h(shop_toman((int) $order['total'])) ?>.</p>
        <p>خرید از تأمین‌کننده جدا از این ثبت است و بعد از تأیید انجام می‌شود.</p>
        <ul>
            <?php foreach ($order['lines'] as $line): ?>
                <li><?= shop_h($line['name']) ?> · <?= (int) $line['qty'] ?> · <?= shop_h(shop_toman((int) $line['total'])) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
