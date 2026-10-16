<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';
shop_start();
$catalog = shop_present();
$quote = shop_quote($catalog, $_SESSION['cart']);
$boot = shop_boot($catalog, $quote);
$reference = $quote['reference'];
$about = shop_setting('about');
shop_header('درباره ما · مولوی‌سل', 'about', $boot);
?>
<article class="prose">
    <h1>درباره مولوی‌سل</h1>
    <p><?= nl2br(shop_h($about)) ?></p>
    <?php if ($reference): ?>
        <p>کف تخفیف، قیمت مصرف <?= SHOP_TUNA_PACK ?> عدد <?= shop_h((string) $reference['name']) ?> است: <strong><?= shop_h(shop_toman((int) $reference['floor'])) ?></strong>. قیمت فروش تأمین‌کننده از <?= shop_h(shop_toman((int) $reference['ceiling'])) ?> به بعد کامل می‌شود.</p>
    <?php endif; ?>
    <p>ثبت سفارش در سایت برای هماهنگی است. خرید از تأمین‌کننده جدا انجام می‌شود و بعد از تأیید قطعی است.</p>
</article>
<?php
shop_footer($boot);
