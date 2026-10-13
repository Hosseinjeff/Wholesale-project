<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_start();
header('X-Robots-Tag: noindex');

if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header('Location: admin.php');
    exit;
}

$error = '';
$notice = '';
$locked = (int) ($_SESSION['admin_fails'] ?? 0) >= 8;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['admin'])) {
    if ($locked) {
        $error = 'چند دقیقه بعد دوباره تلاش کنید.';
    } elseif (!shop_token_ok()) {
        $error = 'صفحه را تازه کنید و دوباره وارد شوید.';
    } elseif (shop_admin_login((string) ($_POST['password'] ?? ''))) {
        header('Location: admin.php');
        exit;
    } else {
        $_SESSION['admin_fails'] = (int) ($_SESSION['admin_fails'] ?? 0) + 1;
        $error = 'رمز درست نیست.';
        $locked = (int) $_SESSION['admin_fails'] >= 8;
    }
}

if (!empty($_SESSION['admin']) && $_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok()) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'status') {
            shop_set_status((string) ($_POST['id'] ?? ''), (string) ($_POST['status'] ?? ''));
            $notice = 'وضعیت سفارش ذخیره شد.';
        } elseif ($action === 'product') {
            $id = (string) ($_POST['id'] ?? '');
            if (preg_match('/^[a-f0-9]{12}$/', $id)) {
                $category = trim((string) ($_POST['category'] ?? ''));
                $category = mb_substr($category, 0, 40);
                $image = null;
                if (!empty($_FILES['image']['name'])) {
                    $image = shop_store_image($id, $_FILES['image']);
                }
                shop_save_product($id, isset($_POST['hidden']) ? 1 : 0, $category, $image);
                $notice = 'کالا ذخیره شد.';
            }
        } elseif ($action === 'settings') {
            foreach (['phone', 'address', 'hours'] as $key) {
                shop_save_setting($key, mb_substr(trim((string) ($_POST[$key] ?? '')), 0, 200));
            }
            shop_save_setting('about', mb_substr(trim((string) ($_POST['about'] ?? '')), 0, 2000));
            $next = (string) ($_POST['next'] ?? '');
            if ($next !== '') {
                $again = (string) ($_POST['again'] ?? '');
                $current = (string) ($_POST['current'] ?? '');
                if (mb_strlen($next) < 8) {
                    $error = 'رمز تازه حداقل ۸ حرف باشد.';
                } elseif ($next !== $again) {
                    $error = 'تکرار رمز یکسان نیست.';
                } elseif (!shop_check_password($current)) {
                    $error = 'رمز فعلی درست نیست.';
                } else {
                    shop_set_password($next);
                    $notice = 'رمز تازه ذخیره شد.';
                }
            }
            if ($error === '' && $notice === '') {
                $notice = 'تنظیمات ذخیره شد.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage() !== '' ? $e->getMessage() : 'ذخیره انجام نشد.';
    }
}

function dash_head(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= shop_h($title) ?></title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/site.css">
</head>
<body class="dash">
    <?php
}

if (empty($_SESSION['admin'])) {
    $ready = false;
    try {
        $ready = shop_db_one('SELECT id FROM admin WHERE id = 1') !== null;
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : 'پایگاه فروشگاه روی میزبان باز نشد.';
    }
    dash_head('ورود مدیر · مولوی‌سل');
    ?>
    <div class="login">
        <form class="panel" method="post">
            <a class="logo" href="index.php"><span>م</span>مولوی‌سل</a>
            <h1>ورود مدیر</h1>
            <?php if ($error !== ''): ?><p class="error"><?= shop_h($error) ?></p><?php endif; ?>
            <?php if (!$ready && $error === ''): ?><p class="note">رمز مدیر هنوز روی میزبان گذاشته نشده است.</p><?php endif; ?>
            <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
            <label class="field"><span>رمز</span><input type="password" name="password" required autofocus <?= $locked ? 'disabled' : '' ?>></label>
            <button class="primary" type="submit" <?= $locked ? 'disabled' : '' ?>>ورود</button>
        </form>
    </div>
    </body></html>
    <?php
    exit;
}

$view = (string) ($_GET['view'] ?? 'orders');
if (!in_array($view, ['orders', 'products', 'settings'], true)) {
    $view = 'orders';
}
$catalog = shop_present();
$orders = shop_orders();
$newOrders = 0;
foreach ($orders as $order) {
    if (($order['status'] ?? '') === 'new') {
        $newOrders++;
    }
}
$hidden = 0;
foreach ($catalog['products'] as $product) {
    if (!empty($product['hidden'])) {
        $hidden++;
    }
}
dash_head('مدیریت · مولوی‌سل');
?>
<header class="dash-bar">
    <div class="inner">
        <a class="logo" href="index.php"><span>م</span>مولوی‌سل</a>
        <a href="admin.php?view=orders"<?= $view === 'orders' ? ' class="on"' : '' ?>>سفارش‌ها</a>
        <a href="admin.php?view=products"<?= $view === 'products' ? ' class="on"' : '' ?>>کالاها</a>
        <a href="admin.php?view=settings"<?= $view === 'settings' ? ' class="on"' : '' ?>>تنظیمات</a>
        <a href="index.php">نمایش فروشگاه</a>
        <a href="admin.php?logout=1">خروج</a>
    </div>
</header>
<main class="dash-main">
    <?php if ($notice !== ''): ?><p class="note"><?= shop_h($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?= shop_h($error) ?></p><?php endif; ?>
    <div class="stats">
        <div class="stat"><b><?= $newOrders ?></b><span>سفارش جدید</span></div>
        <div class="stat"><b><?= count($orders) ?></b><span>همه سفارش‌ها</span></div>
        <div class="stat"><b><?= count($catalog['products']) - $hidden ?></b><span>کالای روی ویترین<?= $hidden ? ' · ' . $hidden . ' پنهان' : '' ?></span></div>
    </div>
    <?php if ($view === 'orders'): ?>
        <?php if ($orders === []): ?>
            <p class="panel">هنوز سفارشی ثبت نشده است.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>سفارش</th><th>مشتری</th><th>جمع</th><th>وضعیت</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <strong><?= shop_h((string) $order['id']) ?></strong>
                                <div class="sku"><?= shop_h(shop_jalali((string) $order['created_at'])) ?></div>
                                <?php foreach ($order['lines'] as $line): ?>
                                    <div><?= shop_h((string) $line['name']) ?> · <?= (int) $line['qty'] ?></div>
                                <?php endforeach; ?>
                                <?php if ((string) $order['note'] !== ''): ?><div class="note"><?= shop_h((string) $order['note']) ?></div><?php endif; ?>
                            </td>
                            <td><?= shop_h((string) $order['name']) ?><div class="sku"><?= shop_h((string) $order['phone']) ?></div></td>
                            <td><?= shop_h(shop_toman((int) $order['total'])) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= shop_h((string) $order['id']) ?>">
                                    <select name="status" onchange="this.form.submit()">
                                        <?php foreach (['new', 'review', 'confirmed', 'cancelled'] as $status): ?>
                                            <option value="<?= $status ?>"<?= $order['status'] === $status ? ' selected' : '' ?>><?= shop_h(shop_status_label($status)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($view === 'products'): ?>
        <div class="panel">
            <?php foreach ($catalog['products'] as $product): ?>
                <form class="product-row" method="post" enctype="multipart/form-data">
                    <img src="<?= shop_h((string) $product['image']) ?>" alt="">
                    <div>
                        <strong><?= shop_h((string) $product['name']) ?></strong>
                        <div class="sku"><?= shop_h((string) $product['sku']) ?> · فروش <?= shop_h(shop_toman((int) $product['sale'])) ?><?php if ((int) $product['shelf'] > 0): ?> · مصرف <?= shop_h(shop_toman((int) $product['shelf'])) ?><?php endif; ?> · <?= shop_h((string) $product['supplier']) ?></div>
                        <div class="row-actions">
                            <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
                            <input type="hidden" name="action" value="product">
                            <input type="hidden" name="id" value="<?= shop_h((string) $product['id']) ?>">
                            <label class="pill"><input type="checkbox" name="hidden" value="1"<?= !empty($product['hidden']) ? ' checked' : '' ?>> پنهان از ویترین</label>
                            <select name="category">
                                <?php
                                $options = shop_categories();
                                if (!in_array($product['category'], $options, true)) {
                                    array_unshift($options, (string) $product['category']);
                                }
                                foreach ($options as $category):
                                ?>
                                    <option value="<?= shop_h($category) ?>"<?= $product['category'] === $category ? ' selected' : '' ?>><?= shop_h($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
                            <button class="ghost" type="submit">ذخیره</button>
                        </div>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <form class="panel" method="post">
            <h1>تنظیمات فروشگاه</h1>
            <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
            <input type="hidden" name="action" value="settings">
            <label class="field"><span>تلفن</span><input name="phone" value="<?= shop_h(shop_setting('phone')) ?>"></label>
            <label class="field"><span>نشانی</span><input name="address" value="<?= shop_h(shop_setting('address')) ?>"></label>
            <label class="field"><span>ساعت پاسخ‌گویی</span><input name="hours" value="<?= shop_h(shop_setting('hours')) ?>"></label>
            <label class="field"><span>متن درباره ما</span><textarea name="about" rows="5"><?= shop_h(shop_setting('about')) ?></textarea></label>
            <h2>تغییر رمز</h2>
            <label class="field"><span>رمز فعلی</span><input type="password" name="current" autocomplete="current-password"></label>
            <label class="field"><span>رمز تازه</span><input type="password" name="next" autocomplete="new-password"></label>
            <label class="field"><span>تکرار رمز تازه</span><input type="password" name="again" autocomplete="new-password"></label>
            <button class="primary" type="submit">ذخیره</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
