<?php
declare(strict_types=1);

function shop_svg(string $name): string
{
    $icons = [
        'cart' => '<path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"/><circle cx="10" cy="19" r="1.2"/><circle cx="17" cy="19" r="1.2"/>',
        'heart' => '<path d="M12 19s-6.2-3.8-6.2-8A3.4 3.4 0 0 1 12 8.6 3.4 3.4 0 0 1 18.2 11c0 4.2-6.2 8-6.2 8z"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="M16 16l4 4"/>',
        'menu' => '<path d="M5 7h14M5 12h14M5 17h14"/>',
        'close' => '<path d="M7 7l10 10M17 7 7 17"/>',
        'filter' => '<path d="M4 6h16l-6 7v5l-4 2v-7z"/>',
        'grid' => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.2"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.2"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.2"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.2"/>',
        'list' => '<path d="M9 7h11M9 12h11M9 17h11"/><circle cx="5.5" cy="7" r="1"/><circle cx="5.5" cy="12" r="1"/><circle cx="5.5" cy="17" r="1"/>',
    ];
    $body = $icons[$name] ?? '';
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

function shop_meter_text(array $boot): string
{
    if ((int) ($boot['count'] ?? 0) < 1) {
        return '';
    }
    $percent = (int) round(((float) ($boot['progress'] ?? 0)) * 100);
    if (empty($boot['floor'])) {
        return 'جمع سبد ' . shop_toman((int) ($boot['total'] ?? 0));
    }
    if ((int) ($boot['measure'] ?? 0) <= (int) $boot['floor']) {
        return 'تخفیف از جمع ' . shop_toman((int) $boot['floor']) . ' با قیمت مصرف شروع می‌شود. الان ' . shop_toman((int) ($boot['measure'] ?? 0)) . ' است.';
    }
    if ($percent >= 100) {
        return 'قیمت فروش تأمین‌کننده روی این سبد اعمال شده است.';
    }
    return $percent . '٪ از راه تا قیمت فروش تأمین‌کننده. سقف این تخفیف ' . shop_toman((int) $boot['ceiling']) . ' است.';
}

function shop_json(array $data): string
{
    return (string) json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

function shop_header(string $title, string $active, array $boot): void
{
    $count = (int) ($boot['count'] ?? 0);
    $progress = (int) round(((float) ($boot['progress'] ?? 0)) * 100);
    $nav = [
        'shop' => ['index.php', 'فروشگاه'],
        'cats' => ['index.php#cat', 'دسته‌بندی‌ها'],
        'home' => ['index.php', 'خانه'],
        'about' => ['about.php', 'درباره ما'],
        'contact' => ['contact.php', 'تماس با ما'],
    ];
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= shop_h($title) ?></title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/site.css">
</head>
<body>
<header class="bar">
    <div class="bar-inner">
        <a class="logo" href="index.php">مولوی‌سل</a>
        <nav class="nav" id="nav">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= shop_h($href) ?>"<?= $key === $active ? ' class="on"' : '' ?>><?= shop_h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="search" id="search" role="search">
            <input id="q" type="search" placeholder="جستجوی محصولات…" autocomplete="off" enterkeyhint="search">
            <button type="button" id="search-toggle" aria-label="جستجو"><?= shop_svg('search') ?></button>
        </form>
        <div class="tools-end">
            <button type="button" class="icon" id="wish" aria-pressed="false" aria-label="علاقه‌مندی‌ها">
                <?= shop_svg('heart') ?>
                <span class="badge" id="wish-badge" hidden>0</span>
            </button>
            <button type="button" class="icon" id="open-cart" aria-label="سبد خرید">
                <?= shop_svg('cart') ?>
                <span class="badge" id="cart-badge"<?= $count > 0 ? '' : ' hidden' ?>><?= $count ?></span>
            </button>
            <button type="button" class="icon" id="open-menu" aria-label="منو"><?= shop_svg('menu') ?></button>
        </div>
    </div>
</header>
<div class="meter" id="meter"<?= $count > 0 ? '' : ' hidden' ?>>
    <p id="meter-text"><?= shop_h(shop_meter_text($boot)) ?></p>
    <div class="track" aria-hidden="true"><span id="meter-bar" style="width: <?= $progress ?>%"></span></div>
</div>
<div class="shade" id="shade" hidden></div>
<aside class="drawer" id="drawer" hidden aria-label="سبد خرید">
    <div class="drawer-head">
        <h2>سبد خرید</h2>
        <button type="button" class="icon" id="close-drawer" aria-label="بستن"><?= shop_svg('close') ?></button>
    </div>
    <div class="drawer-body" id="drawer-lines"></div>
    <div class="drawer-foot">
        <p class="fine" id="drawer-note"></p>
        <div class="payline"><span>قابل پرداخت</span><strong id="pay">۰</strong></div>
        <a class="go" href="cart.php">ثبت سفارش</a>
    </div>
</aside>
<nav class="sheet" id="sheet" hidden>
    <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= shop_h($href) ?>"><?= shop_h($label) ?></a>
    <?php endforeach; ?>
</nav>
<div class="toast" id="toast" hidden></div>
<div class="sr" id="live" aria-live="polite"></div>
<main>
    <?php
}

function shop_footer(array $boot): void
{
    ?>
</main>
<script id="shop-boot" type="application/json"><?= shop_json($boot) ?></script>
<script src="assets/shop.js"></script>
</body>
</html>
    <?php
}

function shop_control(string $id, int $qty, string $back): string
{
    $token = shop_h((string) ($_SESSION['token'] ?? ''));
    $id = shop_h($id);
    $back = shop_h($back);
    if ($qty > 0) {
        $inner = '<div class="stepper"><button type="button" data-step="-1" aria-label="کم کردن">−</button><b>'
            . $qty . '</b><button type="button" data-step="1" aria-label="افزودن">+</button></div>';
    } else {
        $inner = '<button class="add" type="submit" aria-label="افزودن به سبد">' . shop_svg('cart') . '</button>';
    }
    return '<form class="control" method="post" action="api.php" data-pid="' . $id . '">'
        . '<input type="hidden" name="token" value="' . $token . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="qty" value="' . max(1, $qty) . '">'
        . '<input type="hidden" name="back" value="' . $back . '">'
        . $inner
        . '</form>';
}
