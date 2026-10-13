<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update' && is_array($_POST['qty'] ?? null)) {
        foreach ($_POST['qty'] as $id => $qty) {
            shop_set((string) $id, (int) $qty);
        }
        header('Location: cart.php');
        exit;
    }
    if ($action === 'order') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $quote = shop_quote(shop_catalog(), $_SESSION['cart']);
        if ($name !== '' && strlen($phone) >= 10 && $quote['lines'] !== []) {
            $id = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $order = [
                'id' => $id,
                'at' => gmdate('c'),
                'name' => $name,
                'phone' => $phone,
                'note' => $note,
                'progress' => $quote['progress'],
                'total' => $quote['total'],
                'lines' => $quote['lines'],
            ];
            $dir = __DIR__ . '/data/orders';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $written = file_put_contents($dir . '/' . $id . '.json', json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if ($written !== false) {
                $_SESSION['cart'] = [];
                $_SESSION['last_order'] = $id;
                header('Location: order.php');
                exit;
            }
            $error = 'ثبت سفارش روی میزبان انجام نشد.';
        } else {
            $error = 'نام، شماره تماس و حداقل یک کالا لازم است.';
        }
    }
}

$quote = shop_quote(shop_catalog(), $_SESSION['cart']);
$reference = $quote['reference'];
$percent = (int) round($quote['progress'] * 100);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سبد · مولوی‌سل</title>
    <link rel="stylesheet" href="assets/site.css">
</head>
<body>
<header class="top">
    <a class="brand" href="index.php">مولوی‌سل</a>
    <a class="cart-link" href="cart.php">سبد</a>
</header>
<main class="narrow">
    <h1>سبد</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= shop_h($error) ?></p>
    <?php endif; ?>
    <?php if ($quote['lines'] === []): ?>
        <p>سبد خالی است. <a href="index.php">برگشت به کالاها</a></p>
    <?php else: ?>
        <?php if ($reference): ?>
            <section class="meter">
                <p>جمع این سبد با قیمت مصرف: <strong><?= shop_h(shop_toman($quote['measure'])) ?></strong></p>
                <p>کف <?= shop_h(shop_toman($reference['floor'])) ?> · سقف تخفیف <?= shop_h(shop_toman($reference['ceiling'])) ?></p>
                <div class="bar" role="img" aria-label="نزدیکی به قیمت فروش <?= $percent ?> درصد"><span style="width: <?= $percent ?>%"></span></div>
                <p><?= $percent ?>٪ از فاصله تا قیمت فروش تأمین‌کننده</p>
            </section>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="token" value="<?= shop_h($_SESSION['token']) ?>">
            <input type="hidden" name="action" value="update">
            <table>
                <thead>
                <tr><th>کالا</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr>
                </thead>
                <tbody>
                <?php foreach ($quote['lines'] as $line): ?>
                    <tr>
                        <td>
                            <?= shop_h($line['name']) ?>
                            <span class="meta"><?= shop_h($line['supplier']) ?></span>
                        </td>
                        <td><input name="qty[<?= shop_h($line['id']) ?>]" type="number" min="0" max="999" value="<?= (int) $line['qty'] ?>"></td>
                        <td><?= shop_h(shop_toman($line['unit'])) ?></td>
                        <td><?= shop_h(shop_toman($line['total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="total">قابل پرداخت: <?= shop_h(shop_toman($quote['total'])) ?></p>
            <button type="submit">به‌روزرسانی تعداد</button>
        </form>
        <form method="post" class="order">
            <input type="hidden" name="token" value="<?= shop_h($_SESSION['token']) ?>">
            <input type="hidden" name="action" value="order">
            <h2>ثبت سفارش</h2>
            <label>نام <input name="name" required></label>
            <label>تلفن <input name="phone" required inputmode="tel"></label>
            <label>یادداشت <textarea name="note" rows="3"></textarea></label>
            <button type="submit">ثبت این سبد</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
