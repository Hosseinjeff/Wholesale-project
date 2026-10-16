<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';
shop_start();
$id = (string) ($_SESSION['last_order'] ?? '');
$order = $id !== '' ? shop_find_order($id) : [];
$catalog = shop_present();
$boot = shop_boot($catalog, shop_quote($catalog, $_SESSION['cart']));
shop_header('سفارش · مولوی‌سل', 'shop', $boot);
?>
<section class="prose">
    <?php if ($order === []): ?>
        <h1>سفارشی برای نمایش نیست.</h1>
        <p><a href="index.php">برگشت به فروشگاه</a></p>
    <?php else: ?>
        <div class="ok-mark">✓</div>
        <h1>سفارش ثبت شد.</h1>
        <p>شماره سفارش <strong><?= shop_h((string) $order['id']) ?></strong> است. جمع <?= shop_h(shop_toman((int) $order['total'])) ?>.</p>
        <p class="note">خرید از تأمین‌کننده جدا از این ثبت است و بعد از تأیید انجام می‌شود.</p>
        <div>
            <?php foreach ($order['lines'] as $line): ?>
                <article class="dline">
                    <?php if (!empty($line['image'])): ?><img src="<?= shop_h((string) $line['image']) ?>" alt=""><?php endif; ?>
                    <div class="dtext">
                        <h3><?= shop_h((string) $line['name']) ?></h3>
                        <p><?= (int) $line['qty'] ?> عدد · <?= shop_h(shop_toman((int) $line['total'])) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <p><a href="index.php">ادامه خرید</a></p>
    <?php endif; ?>
</section>
<?php
shop_footer($boot);
