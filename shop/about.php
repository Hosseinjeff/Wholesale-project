<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';
shop_start();
$catalog = shop_present();
$quote = shop_quote($catalog, $_SESSION['cart']);
$boot = shop_boot($catalog, $quote);
$about = shop_setting('about');
shop_header('درباره ما · مولوی‌سل', 'about', $boot);
?>
<article class="prose">
    <h1>درباره مولوی‌سل</h1>
    <p><?= nl2br(shop_h($about)) ?></p>
    <p>روی هر کالا قیمت مصرف خط می‌خورد. نشان روی عکس بیشترین تخفیف آن کالا است، و درصد کنار قیمت تخفیفی است که همین حالا داخل قیمت است. با سنگین‌تر شدن سبد، قیمت هر قلم تا همان سقف پایین می‌آید.</p>
    <p>ثبت سفارش در سایت برای هماهنگی است. خرید از تأمین‌کننده جدا انجام می‌شود و بعد از تأیید قطعی است.</p>
</article>
<?php
shop_footer($boot);
