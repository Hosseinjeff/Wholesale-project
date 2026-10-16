<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';
shop_start();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok() && (string) ($_POST['action'] ?? '') === 'order') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
    $note = trim((string) ($_POST['note'] ?? ''));
    $name = mb_substr($name, 0, 80);
    $note = mb_substr($note, 0, 500);
    $catalog = shop_present();
    $quote = shop_quote($catalog, $_SESSION['cart']);
    if ($name !== '' && strlen($phone) >= 10 && $quote['lines'] !== []) {
        $id = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        try {
            shop_save_order([
                'id' => $id,
                'at' => gmdate('c'),
                'name' => $name,
                'phone' => $phone,
                'note' => $note,
                'progress' => $quote['progress'],
                'total' => $quote['total'],
                'lines' => $quote['lines'],
            ]);
            $_SESSION['cart'] = [];
            $_SESSION['last_order'] = $id;
            header('Location: order.php');
            exit;
        } catch (Throwable $e) {
            $error = 'ثبت سفارش روی میزبان انجام نشد.';
        }
    } else {
        $error = 'نام، شماره تماس و حداقل یک کالا لازم است.';
    }
}

$catalog = shop_present();
$quote = shop_quote($catalog, $_SESSION['cart']);
$boot = shop_boot($catalog, $quote);
shop_header('ثبت سفارش · مولوی‌سل', 'shop', $boot);
?>
<div class="split">
    <form class="panel order" method="post">
        <h1>ثبت سفارش</h1>
        <p class="note">این ثبت برای هماهنگی است. خرید از تأمین‌کننده بعد از تأیید جدا انجام می‌شود.</p>
        <?php if ($error !== ''): ?><p class="error"><?= shop_h($error) ?></p><?php endif; ?>
        <?php if ($quote['lines'] === []): ?>
            <p>سبد خالی است. <a href="index.php">برگشت به فروشگاه</a></p>
        <?php else: ?>
            <input type="hidden" name="token" value="<?= shop_h($_SESSION['token']) ?>">
            <input type="hidden" name="action" value="order">
            <label class="field"><span>نام</span><input name="name" required maxlength="80" autocomplete="name"></label>
            <label class="field"><span>تلفن</span><input name="phone" required inputmode="tel" autocomplete="tel" placeholder="09…"></label>
            <label class="field"><span>یادداشت</span><textarea name="note" rows="3" maxlength="500"></textarea></label>
            <button class="primary" type="submit">ثبت این سبد</button>
        <?php endif; ?>
    </form>
    <aside class="summary">
        <h1>سبد</h1>
        <div id="checkout-lines">
            <?php foreach ($quote['lines'] as $line): ?>
                <article class="dline" data-pid="<?= shop_h($line['id']) ?>">
                    <?php if ($line['image'] !== ''): ?><img src="<?= shop_h($line['image']) ?>" alt=""><?php endif; ?>
                    <div class="dtext">
                        <h3><?= shop_h($line['name']) ?></h3>
                        <p>
                            <?php if ((int) $line['shelf'] > (int) $line['unit']): ?><span class="rate" dir="ltr"><?= shop_h('−' . shop_percent((float) $line['discount_rate'])) ?></span><?php endif; ?>
                            <strong><?= shop_h(shop_toman((int) $line['unit'])) ?></strong>
                            <?php if ((int) $line['shelf'] > (int) $line['unit']): ?><s class="was"><?= shop_h(shop_toman((int) $line['shelf'])) ?></s><?php endif; ?>
                        </p>
                        <p class="sku"><?= shop_h($line['sku']) ?></p>
                    </div>
                    <?= shop_control($line['id'], (int) $line['qty'], 'cart.php') ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($quote['lines'] !== []): ?>
            <div class="payline" id="discount-line"><span>تخفیف سبد</span><strong id="discount-total"><?= shop_h(shop_percent((float) $quote['discount_rate'])) ?> · <?= shop_h(shop_toman((int) $quote['discount_total'])) ?></strong></div>
            <div class="payline"><span>قابل پرداخت</span><strong id="checkout-total"><?= shop_h(shop_toman((int) $quote['total'])) ?></strong></div>
        <?php endif; ?>
    </aside>
</div>
<?php
shop_footer($boot);
