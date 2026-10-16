<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';
shop_start();
$catalog = shop_present();
$boot = shop_boot($catalog, shop_quote($catalog, $_SESSION['cart']));
$phone = shop_setting('phone');
$address = shop_setting('address');
$hours = shop_setting('hours');
shop_header('تماس با ما · مولوی‌سل', 'contact', $boot);
?>
<article class="prose">
    <h1>تماس با ما</h1>
    <?php if ($phone !== ''): ?>
        <p>تلفن: <a href="tel:<?= shop_h($phone) ?>"><?= shop_h($phone) ?></a></p>
    <?php else: ?>
        <p class="note">شماره تماس به‌زودی در همین صفحه قرار می‌گیرد. سفارش را از سبد ثبت کنید.</p>
    <?php endif; ?>
    <?php if ($address !== ''): ?><p>نشانی: <?= shop_h($address) ?></p><?php endif; ?>
    <?php if ($hours !== ''): ?><p>ساعت پاسخ‌گویی: <?= shop_h($hours) ?></p><?php endif; ?>
    <p><a href="index.php">برگشت به فروشگاه</a></p>
</article>
<?php
shop_footer($boot);
