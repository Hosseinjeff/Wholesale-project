<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';
shop_start();

$error = '';
$notice = '';
$locked = (int) ($_SESSION['customer_fails'] ?? 0) >= 8;

if (isset($_GET['logout'])) {
    shop_customer_logout();
    header('Location: account.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok()) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'login' && empty($_SESSION['customer_id'])) {
            if ($locked) {
                $error = 'چند دقیقه بعد دوباره تلاش کنید.';
            } elseif (shop_customer_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                header('Location: account.php');
                exit;
            } else {
                $_SESSION['customer_fails'] = (int) ($_SESSION['customer_fails'] ?? 0) + 1;
                $error = 'نام کاربری یا رمز درست نیست.';
                $locked = (int) $_SESSION['customer_fails'] >= 8;
            }
        } elseif ($action === 'register' && empty($_SESSION['customer_id'])) {
            if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['again'] ?? '')) {
                throw new RuntimeException('تکرار رمز یکسان نیست.');
            }
            $id = shop_register_customer($_POST);
            $_SESSION['customer_id'] = $id;
            $_SESSION['customer_username'] = trim((string) ($_POST['username'] ?? ''));
            header('Location: account.php');
            exit;
        } elseif ($action === 'profile' && !empty($_SESSION['customer_id'])) {
            if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['again'] ?? '')) {
                throw new RuntimeException('تکرار رمز یکسان نیست.');
            }
            shop_update_customer($_POST);
            $notice = 'اطلاعات حساب ذخیره شد.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage() !== '' ? $e->getMessage() : 'انجام نشد.';
    }
}

$customer = shop_current_customer();
$catalog = shop_present();
$quote = shop_quote($catalog, $_SESSION['cart']);
$boot = shop_boot($catalog, $quote);
shop_header($customer ? 'حساب من · مولوی‌سل' : 'ورود · مولوی‌سل', 'account', $boot);

if ($customer === null):
?>
<div class="account-grid">
    <form class="panel" method="post">
        <h1>ورود</h1>
        <?php if ($error !== ''): ?><p class="error"><?= shop_h($error) ?></p><?php endif; ?>
        <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
        <input type="hidden" name="action" value="login">
        <label class="field"><span>نام کاربری</span><input name="username" required autocomplete="username" <?= $locked ? 'disabled' : '' ?>></label>
        <label class="field"><span>رمز</span><input type="password" name="password" required autocomplete="current-password" <?= $locked ? 'disabled' : '' ?>></label>
        <button class="primary" type="submit" <?= $locked ? 'disabled' : '' ?>>ورود</button>
        <p class="note"><a href="recover.php?role=customer">بازیابی رمز با ایمیل</a></p>
    </form>
    <form class="panel" method="post">
        <h1>حساب تازه</h1>
        <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
        <input type="hidden" name="action" value="register">
        <label class="field"><span>نام کاربری</span><input name="username" required autocomplete="off"></label>
        <label class="field"><span>ایمیل</span><input name="email" type="email" required autocomplete="email"></label>
        <label class="field"><span>نام</span><input name="name" autocomplete="name"></label>
        <label class="field"><span>تلفن</span><input name="phone" inputmode="tel" autocomplete="tel"></label>
        <label class="field"><span>شهر</span><input name="city"></label>
        <label class="field"><span>نشانی</span><textarea name="address" rows="2"></textarea></label>
        <label class="field"><span>رمز</span><input type="password" name="password" required autocomplete="new-password"></label>
        <label class="field"><span>تکرار رمز</span><input type="password" name="again" required autocomplete="new-password"></label>
        <button class="primary" type="submit">ساختن حساب</button>
    </form>
</div>
<?php
else:
    $view = (string) ($_GET['view'] ?? 'orders');
    if (!in_array($view, ['orders', 'profile'], true)) {
        $view = 'orders';
    }
    $orders = shop_customer_orders($customer);
?>
<p class="note"><a href="account.php">سفارش‌ها</a> · <a href="account.php?view=profile">اطلاعات من</a> · <a href="account.php?logout=1">خروج</a></p>
<?php if ($notice !== ''): ?><p class="note"><?= shop_h($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error"><?= shop_h($error) ?></p><?php endif; ?>
<?php if ($view === 'profile'): ?>
    <form class="panel" method="post">
        <h1>اطلاعات <?= shop_h((string) $customer['username']) ?></h1>
        <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
        <input type="hidden" name="action" value="profile">
        <label class="field"><span>ایمیل</span><input name="email" type="email" required value="<?= shop_h((string) $customer['email']) ?>"></label>
        <label class="field"><span>نام</span><input name="name" value="<?= shop_h((string) $customer['name']) ?>"></label>
        <label class="field"><span>تلفن</span><input name="phone" value="<?= shop_h((string) $customer['phone']) ?>"></label>
        <label class="field"><span>شهر</span><input name="city" value="<?= shop_h((string) $customer['city']) ?>"></label>
        <label class="field"><span>نشانی</span><textarea name="address" rows="3"><?= shop_h((string) $customer['address']) ?></textarea></label>
        <label class="field"><span>یادداشت تحویل</span><textarea name="note" rows="2"><?= shop_h((string) $customer['note']) ?></textarea></label>
        <h2>رمز تازه</h2>
        <label class="field"><span>رمز تازه، اگر می‌خواهید عوض شود</span><input type="password" name="password" autocomplete="new-password"></label>
        <label class="field"><span>تکرار رمز تازه</span><input type="password" name="again" autocomplete="new-password"></label>
        <button class="primary" type="submit">ذخیره</button>
    </form>
<?php else: ?>
    <section class="panel">
        <h1>سفارش‌های من</h1>
        <?php if ($orders === []): ?>
            <p>هنوز سفارشی به این حساب وصل نیست. <a href="index.php">رفتن به فروشگاه</a></p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <article>
                    <h2><?= shop_h((string) $order['id']) ?></h2>
                    <p class="sku"><?= shop_h(shop_jalali((string) $order['created_at'])) ?> · <?= shop_h(shop_status_label((string) $order['status'])) ?> · <?= shop_h(shop_toman((int) $order['total'])) ?></p>
                    <?php foreach ($order['lines'] as $line): ?>
                        <p><?= shop_h((string) $line['name']) ?> · <?= (int) $line['qty'] ?> عدد</p>
                    <?php endforeach; ?>
                    <?php if ((string) ($order['address'] ?? '') !== ''): ?><p class="note"><?= shop_h((string) $order['address']) ?></p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php endif; ?>
<?php
shop_footer($boot);
