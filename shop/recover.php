<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
shop_start();
header('X-Robots-Tag: noindex');

$error = '';
$notice = '';
$role = (string) ($_GET['role'] ?? $_POST['role'] ?? 'customer');
if (!in_array($role, ['customer', 'admin'], true)) {
    $role = 'customer';
}
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = preg_match('/^[a-f0-9]{48}$/', $token) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok()) {
    $action = (string) ($_POST['action'] ?? '');
    if ((int) ($_SESSION['reset_fails'] ?? 0) >= 8) {
        $error = 'چند دقیقه بعد دوباره تلاش کنید.';
    } elseif ($action === 'request') {
        $_SESSION['reset_fails'] = (int) ($_SESSION['reset_fails'] ?? 0) + 1;
        $result = shop_request_reset((string) ($_POST['email'] ?? ''), $role);
        if ($result === 'unconfigured') {
            $error = 'ارسال ایمیل روی میزبان تنظیم نشده است.';
        } else {
            $notice = 'اگر این ایمیل در فروشگاه باشد، پیوند بازیابی فرستاده شد.';
        }
    } elseif ($action === 'reset' && $reset) {
        if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['again'] ?? '')) {
            $error = 'تکرار رمز یکسان نیست.';
        } elseif (shop_reset_with_token($token, (string) ($_POST['password'] ?? ''))) {
            $notice = 'رمز تازه ذخیره شد.';
            $reset = false;
        } else {
            $error = 'این پیوند دیگر معتبر نیست.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>بازیابی رمز · مولوی‌سل</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/site.css?v=<?= (int) @filemtime(__DIR__ . '/assets/site.css') ?>">
</head>
<body class="dash">
<div class="login">
    <form class="panel" method="post">
        <a class="logo" href="index.php"><span>م</span>مولوی‌سل</a>
        <h1>بازیابی رمز</h1>
        <?php if ($error !== ''): ?><p class="error"><?= shop_h($error) ?></p><?php endif; ?>
        <?php if ($notice !== ''): ?><p class="note"><?= shop_h($notice) ?></p><?php endif; ?>
        <input type="hidden" name="token" value="<?= shop_h((string) $_SESSION['token']) ?>">
        <input type="hidden" name="role" value="<?= shop_h($role) ?>">
        <?php if ($reset): ?>
            <input type="hidden" name="reset" value="<?= shop_h($token) ?>">
            <input type="hidden" name="action" value="reset">
            <label class="field"><span>رمز تازه</span><input type="password" name="password" required autocomplete="new-password"></label>
            <label class="field"><span>تکرار رمز تازه</span><input type="password" name="again" required autocomplete="new-password"></label>
            <button class="primary" type="submit">ذخیره رمز</button>
        <?php else: ?>
            <input type="hidden" name="action" value="request">
            <p class="note"><?= $role === 'admin' ? 'ایمیل حساب مدیر' : 'ایمیل حساب مشتری' ?></p>
            <label class="field"><span>ایمیل</span><input name="email" type="email" required autocomplete="email"></label>
            <button class="primary" type="submit">فرستادن پیوند</button>
        <?php endif; ?>
        <p class="note"><a href="<?= $role === 'admin' ? 'admin.php' : 'account.php' ?>">برگشت به ورود</a></p>
    </form>
</div>
</body>
</html>
