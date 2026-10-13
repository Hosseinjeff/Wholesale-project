<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok()) {
    shop_add((string) ($_POST['id'] ?? ''), (int) ($_POST['qty'] ?? 1));
    header('Location: index.php');
    exit;
}

$catalog = shop_catalog();
$quote = shop_quote($catalog, $_SESSION['cart']);
$priced = [];
foreach ($quote['lines'] as $line) {
    $priced[$line['id']] = $line['unit'];
}
$reference = $quote['reference'];
$count = 0;
foreach ($_SESSION['cart'] as $qty) {
    $count += (int) $qty;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مولوی‌سل</title>
    <link rel="stylesheet" href="assets/site.css">
</head>
<body>
<header class="top">
    <a class="brand" href="index.php">مولوی‌سل</a>
    <a class="cart-link" href="cart.php">سبد<?= $count ? ' (' . $count . ')' : '' ?></a>
</header>
<main>
    <section class="intro">
        <p class="kicker">مواد غذایی بسته‌بندی</p>
        <h1>قیمت هر کالا با اندازه سبد عوض می‌شود.</h1>
        <p>سبد کوچک نزدیک قیمت مصرف می‌ماند. هرچه جمع سبد بالاتر برود، قیمت هر قلم به قیمت فروش تأمین‌کننده نزدیک‌تر می‌شود.</p>
        <?php if ($reference): ?>
            <p class="floor">کف سبد، قیمت مصرف <?= SHOP_TUNA_PACK ?> عدد <?= shop_h($reference['name']) ?> است: <strong><?= shop_h(shop_toman($reference['floor'])) ?></strong>. قیمت فروش تأمین‌کننده از <?= shop_h(shop_toman($reference['ceiling'])) ?> به بعد کامل می‌شود.</p>
        <?php endif; ?>
    </section>
    <section class="grid">
        <?php foreach ($catalog['products'] as $product): ?>
            <?php
            $unit = $priced[$product['id']] ?? (($product['shelf'] > $product['sale']) ? $product['shelf'] : $product['sale']);
            ?>
            <article class="card">
                <h2><?= shop_h($product['name']) ?></h2>
                <p class="meta">
                    <?= shop_h($product['supplier']) ?>
                    <?php if ($product['packaging'] !== ''): ?> · <?= shop_h($product['packaging']) ?><?php endif; ?>
                    <?php if ($product['volume'] !== ''): ?> · <?= shop_h($product['volume']) ?><?php endif; ?>
                </p>
                <?php if ($product['variation'] !== ''): ?>
                    <p class="meta"><?= shop_h($product['variation']) ?></p>
                <?php endif; ?>
                <p class="price"><?= shop_h(shop_toman($unit)) ?></p>
                <?php if ($product['shelf'] > $product['sale']): ?>
                    <p class="range">قیمت مصرف <?= shop_h(shop_toman($product['shelf'])) ?> · قیمت فروش <?= shop_h(shop_toman($product['sale'])) ?></p>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="token" value="<?= shop_h($_SESSION['token']) ?>">
                    <input type="hidden" name="id" value="<?= shop_h($product['id']) ?>">
                    <label>تعداد <input name="qty" type="number" min="1" max="999" value="1"></label>
                    <button type="submit">افزودن به سبد</button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
