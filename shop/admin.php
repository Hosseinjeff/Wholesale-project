<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_start();
header('X-Robots-Tag: noindex');

if (isset($_GET['logout'])) {
    unset($_SESSION['admin'], $_SESSION['admin_username']);
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
    } elseif (shop_admin_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: admin.php');
        exit;
    } else {
        $_SESSION['admin_fails'] = (int) ($_SESSION['admin_fails'] ?? 0) + 1;
        $error = 'نام کاربری یا رمز درست نیست.';
        $locked = (int) $_SESSION['admin_fails'] >= 8;
    }
}

if (!empty($_SESSION['admin']) && $_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok()) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'status') {
            shop_set_status((string) ($_POST['id'] ?? ''), (string) ($_POST['status'] ?? ''));
            $notice = 'وضعیت سفارش ذخیره شد.';
        } elseif ($action === 'publish') {
            $order = shop_find_order((string) ($_POST['id'] ?? ''));
            if ($order === [] || !shop_publish_order($order, true)) {
                throw new RuntimeException('ارسال سفارش به برگه و ربات انجام نشد.');
            }
            shop_mark_published((string) $order['id']);
            $notice = 'سفارش به برگه و ربات فرستاده شد.';
        } elseif ($action === 'product') {
            $id = (string) ($_POST['id'] ?? '');
            if (preg_match('/^[a-f0-9]{12}$/', $id)) {
                $category = mb_substr(trim((string) ($_POST['category'] ?? '')), 0, 40);
                $image = null;
                if (!empty($_FILES['image']['name'])) {
                    $image = shop_store_image($id, $_FILES['image']);
                }
                $minRaw = trim((string) ($_POST['min_qty'] ?? ''));
                $minQty = null;
                if ($minRaw !== '') {
                    $minQty = (int) $minRaw;
                    if ($minQty < 1 || $minQty > 999) {
                        throw new RuntimeException('حداقل خرید بین ۱ و ۹۹۹ است.');
                    }
                }
                shop_save_product($id, isset($_POST['hidden']) ? 1 : 0, $category, $image, $minQty);
                $notice = 'کالا ذخیره شد.';
            }
        } elseif ($action === 'admin-add') {
            shop_create_admin(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['display_name'] ?? '')
            );
            $notice = 'مدیر تازه ساخته شد.';
        } elseif ($action === 'admin-delete') {
            shop_delete_admin((int) ($_POST['id'] ?? 0));
            $notice = 'مدیر حذف شد.';
        } elseif ($action === 'settings') {
            foreach (['phone', 'address', 'hours'] as $key) {
                shop_save_setting($key, mb_substr(trim((string) ($_POST[$key] ?? '')), 0, 200));
            }
            shop_save_setting('about', mb_substr(trim((string) ($_POST['about'] ?? '')), 0, 2000));
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($email !== '') {
                shop_update_admin_email($email);
            }
            $next = (string) ($_POST['next'] ?? '');
            if ($next !== '') {
                $again = (string) ($_POST['again'] ?? '');
                $current = (string) ($_POST['current'] ?? '');
                if (!shop_password_ok($next)) {
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
    <link rel="stylesheet" href="assets/site.css?v=<?= (int) @filemtime(__DIR__ . '/assets/site.css') ?>">
</head>
<body class="dash">
    <?php
}

if (empty($_SESSION['admin'])) {
    $ready = false;
    try {
        $ready = shop_db_one('SELECT id FROM admins LIMIT 1') !== null;
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
            <?php if (!$ready && $error === ''): ?><p class="note">هنوز مدیری روی میزبان گذاشته نشده است.</p><?php endif; ?>
            <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
            <label class="field"><span>نام کاربری</span><input name="username" required autocomplete="username" autofocus <?= $locked ? 'disabled' : '' ?>></label>
            <label class="field"><span>رمز</span><input type="password" name="password" required autocomplete="current-password" <?= $locked ? 'disabled' : '' ?>></label>
            <button class="primary" type="submit" <?= $locked ? 'disabled' : '' ?>>ورود</button>
            <p class="note"><a href="recover.php?role=admin">بازیابی رمز با ایمیل</a></p>
        </form>
    </div>
    </body></html>
    <?php
    exit;
}

$views = ['home', 'orders', 'products', 'customers', 'admins', 'settings'];
$view = (string) ($_GET['view'] ?? 'home');
if (!in_array($view, $views, true)) {
    $view = 'home';
}
$catalog = shop_present();
$orders = shop_orders();
$customers = shop_customers();
$admins = shop_admins();
$me = shop_current_admin();
$counts = ['new' => 0, 'review' => 0, 'confirmed' => 0, 'cancelled' => 0];
$openTotal = 0;
$unpublished = 0;
foreach ($orders as $order) {
    $status = (string) ($order['status'] ?? 'new');
    if (!isset($counts[$status])) {
        $counts[$status] = 0;
    }
    $counts[$status]++;
    if ($status !== 'cancelled') {
        $openTotal += (int) $order['total'];
    }
    if ((int) ($order['published'] ?? 0) !== 1) {
        $unpublished++;
    }
}
$hidden = 0;
$missingPhoto = 0;
foreach ($catalog['products'] as $product) {
    if (!empty($product['hidden'])) {
        $hidden++;
    }
    if (str_starts_with((string) $product['image'], 'assets/products/')) {
        $missingPhoto++;
    }
}
$needsEmail = $me !== null && (str_ends_with((string) $me['email'], '@localhost') || (string) $me['email'] === '');
dash_head('مدیریت · مولوی‌سل');
?>
<header class="dash-bar">
    <div class="inner">
        <a class="logo" href="index.php"><span>م</span>مولوی‌سل</a>
        <a href="admin.php?view=home"<?= $view === 'home' ? ' class="on"' : '' ?>>نمای کلی</a>
        <a href="admin.php?view=orders"<?= $view === 'orders' ? ' class="on"' : '' ?>>سفارش‌ها</a>
        <a href="admin.php?view=products"<?= $view === 'products' ? ' class="on"' : '' ?>>کالاها</a>
        <a href="admin.php?view=customers"<?= $view === 'customers' ? ' class="on"' : '' ?>>مشتری‌ها</a>
        <a href="admin.php?view=admins"<?= $view === 'admins' ? ' class="on"' : '' ?>>مدیران</a>
        <a href="admin.php?view=settings"<?= $view === 'settings' ? ' class="on"' : '' ?>>تنظیمات</a>
        <a href="index.php">نمایش فروشگاه</a>
        <a href="admin.php?logout=1">خروج <?= shop_h((string) ($me['username'] ?? '')) ?></a>
    </div>
</header>
<main class="dash-main">
    <?php if ($needsEmail): ?><p class="banner">ایمیل این مدیر هنوز واقعی نیست. در تنظیمات ایمیل بگذارید تا بازیابی رمز کار کند.</p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="note"><?= shop_h($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?= shop_h($error) ?></p><?php endif; ?>
    <?php if ($view === 'home'): ?>
        <div class="stats more">
            <div class="stat"><b><?= (int) $counts['new'] ?></b><span>سفارش جدید</span></div>
            <div class="stat"><b><?= (int) $counts['review'] ?></b><span>در حال بررسی</span></div>
            <div class="stat"><b><?= (int) $counts['confirmed'] ?></b><span>تأیید شده</span></div>
            <div class="stat"><b><?= shop_h(shop_toman($openTotal)) ?></b><span>جمع سفارش‌های باز</span></div>
            <div class="stat"><b><?= count($orders) ?></b><span>همه سفارش‌ها<?= $unpublished ? ' · ' . $unpublished . ' نرسیده به برگه' : '' ?></span></div>
            <div class="stat"><b><?= count($customers) ?></b><span>حساب مشتری</span></div>
            <div class="stat"><b><?= count($catalog['products']) - $hidden ?></b><span>کالای روی ویترین<?= $hidden ? ' · ' . $hidden . ' پنهان' : '' ?></span></div>
            <div class="stat"><b><?= $missingPhoto ?></b><span>کالا با تصویر آماده، نه عکس پست</span></div>
        </div>
        <div class="panel">
            <h1>آخرین سفارش‌ها</h1>
            <?php if ($orders === []): ?>
                <p>هنوز سفارشی ثبت نشده است.</p>
            <?php else: ?>
                <?php foreach (array_slice($orders, 0, 6) as $order): ?>
                    <p>
                        <strong><?= shop_h((string) $order['id']) ?></strong>
                        · <?= shop_h((string) $order['name']) ?>
                        · <?= shop_h(shop_toman((int) $order['total'])) ?>
                        · <?= shop_h(shop_status_label((string) $order['status'])) ?>
                        · <?= count($order['lines']) ?> قلم
                    </p>
                <?php endforeach; ?>
                <p><a href="admin.php?view=orders">همه سفارش‌ها</a></p>
            <?php endif; ?>
        </div>
    <?php elseif ($view === 'orders'): ?>
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
                                    <div><?= shop_h((string) $line['name']) ?> · <?= (int) $line['qty'] ?><?php if (!empty($line['min'])): ?> · حداقل <?= (int) $line['min'] ?><?php endif; ?></div>
                                <?php endforeach; ?>
                                <?php if ((string) $order['note'] !== ''): ?><div class="note"><?= shop_h((string) $order['note']) ?></div><?php endif; ?>
                                <?php if ((int) ($order['published'] ?? 0) !== 1): ?>
                                    <form method="post">
                                        <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
                                        <input type="hidden" name="action" value="publish">
                                        <input type="hidden" name="id" value="<?= shop_h((string) $order['id']) ?>">
                                        <button class="ghost" type="submit">فرستادن به برگه و ربات</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= shop_h((string) $order['name']) ?>
                                <div class="sku"><?= shop_h((string) $order['phone']) ?></div>
                                <?php if ((string) ($order['email'] ?? '') !== ''): ?><div class="sku"><?= shop_h((string) $order['email']) ?></div><?php endif; ?>
                                <?php if ((string) ($order['address'] ?? '') !== ''): ?><div class="note"><?= shop_h((string) $order['address']) ?></div><?php endif; ?>
                            </td>
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
            <p class="note">حداقل خرید از بسته‌بندی حساب می‌شود: کارتن یا باکس ۶، ۱۲ یا ۲۴ تایی از ۶ عدد شروع می‌شود. عدد بگذارید تا همین‌جا عوض شود. خالی یعنی محاسبه خودکار.</p>
            <?php foreach ($catalog['products'] as $product): ?>
                <form class="product-row" method="post" enctype="multipart/form-data">
                    <img src="<?= shop_h((string) $product['image']) ?>" alt="">
                    <div>
                        <strong><?= shop_h((string) $product['name']) ?></strong>
                        <div class="sku"><?= shop_h(shop_product_facts($product)) ?> · فروش <?= shop_h(shop_toman((int) $product['sale'])) ?><?php if ((int) $product['shelf'] > 0): ?> · مصرف <?= shop_h(shop_toman((int) $product['shelf'])) ?><?php endif; ?></div>
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
                            <label class="pill">حداقل
                                <input name="min_qty" type="number" min="1" max="999" inputmode="numeric" placeholder="<?= (int) $product['min_auto'] ?>" value="<?= (int) $product['min'] !== (int) $product['min_auto'] ? (int) $product['min'] : '' ?>" style="width:5rem">
                            </label>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
                            <button class="ghost" type="submit">ذخیره</button>
                        </div>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php elseif ($view === 'customers'): ?>
        <?php if ($customers === []): ?>
            <p class="panel">هنوز مشتری‌ای حساب نساخته است.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>حساب</th><th>تماس</th><th>نشانی</th></tr></thead>
                    <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><strong><?= shop_h((string) $customer['username']) ?></strong><div class="sku"><?= shop_h((string) $customer['name']) ?></div></td>
                            <td><?= shop_h((string) $customer['email']) ?><div class="sku"><?= shop_h((string) $customer['phone']) ?></div></td>
                            <td><?= shop_h(trim((string) $customer['city'] . ' ' . (string) $customer['address'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($view === 'admins'): ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>نام کاربری</th><th>ایمیل</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td><?= shop_h((string) $admin['username']) ?><div class="sku"><?= shop_h((string) $admin['display_name']) ?></div></td>
                        <td><?= shop_h((string) $admin['email']) ?></td>
                        <td>
                            <?php if ((int) $admin['id'] !== (int) ($me['id'] ?? 0)): ?>
                                <form method="post">
                                    <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
                                    <input type="hidden" name="action" value="admin-delete">
                                    <input type="hidden" name="id" value="<?= (int) $admin['id'] ?>">
                                    <button class="ghost" type="submit">حذف</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form class="panel" method="post">
            <h1>مدیر تازه</h1>
            <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
            <input type="hidden" name="action" value="admin-add">
            <label class="field"><span>نام کاربری</span><input name="username" required autocomplete="off"></label>
            <label class="field"><span>ایمیل</span><input name="email" type="email" required></label>
            <label class="field"><span>نام نمایشی</span><input name="display_name"></label>
            <label class="field"><span>رمز</span><input type="password" name="password" required autocomplete="new-password"></label>
            <button class="primary" type="submit">ساختن حساب</button>
        </form>
    <?php else: ?>
        <form class="panel" method="post">
            <h1>تنظیمات فروشگاه</h1>
            <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
            <input type="hidden" name="action" value="settings">
            <label class="field"><span>تلفن</span><input name="phone" value="<?= shop_h(shop_setting('phone')) ?>"></label>
            <label class="field"><span>نشانی</span><input name="address" value="<?= shop_h(shop_setting('address')) ?>"></label>
            <label class="field"><span>ساعت پاسخ‌گویی</span><input name="hours" value="<?= shop_h(shop_setting('hours')) ?>"></label>
            <label class="field"><span>متن درباره ما</span><textarea name="about" rows="5"><?= shop_h(shop_setting('about')) ?></textarea></label>
            <h2>حساب شما</h2>
            <label class="field"><span>ایمیل بازیابی</span><input name="email" type="email" value="<?= shop_h((string) ($me['email'] ?? '')) ?>"></label>
            <label class="field"><span>رمز فعلی</span><input type="password" name="current" autocomplete="current-password"></label>
            <label class="field"><span>رمز تازه</span><input type="password" name="next" autocomplete="new-password"></label>
            <label class="field"><span>تکرار رمز تازه</span><input type="password" name="again" autocomplete="new-password"></label>
            <button class="primary" type="submit">ذخیره</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
