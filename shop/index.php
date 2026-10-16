<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';
shop_start();

$catalog = shop_present();
$products = shop_visible($catalog);
$quote = shop_quote($catalog, $_SESSION['cart']);
$boot = shop_boot($catalog, $quote);
$categories = [];
$suppliers = [];
foreach ($products as $product) {
    $categories[$product['category']] = true;
    if (($product['supplier'] ?? '') !== '') {
        $suppliers[$product['supplier']] = true;
    }
}
$categories = array_keys($categories);
$suppliers = array_keys($suppliers);
sort($categories);
$total = count($products);

shop_header('مولوی‌سل', 'shop', $boot);
?>
<div class="toolbar">
    <div class="cluster">
        <label class="sort"><span>مرتب‌سازی:</span>
            <select id="sort" aria-label="مرتب‌سازی">
                <option value="new">جدیدترین</option>
                <option value="cheap">ارزان‌ترین</option>
                <option value="dear">گران‌ترین</option>
                <option value="name">نام</option>
            </select>
        </label>
        <button type="button" class="ghost" id="filter-toggle" aria-label="فیلتر"><?= shop_svg('filter') ?></button>
        <div class="views" role="group" aria-label="نوع نمایش">
            <button type="button" data-view="grid" aria-label="شبکه"><?= shop_svg('grid') ?></button>
            <button type="button" data-view="list" aria-label="فهرست"><?= shop_svg('list') ?></button>
        </div>
        <div class="pop" id="filter-pop" hidden>
            <p>تأمین‌کننده</p>
            <?php foreach ($suppliers as $supplier): ?>
                <label><input type="checkbox" data-supplier-filter value="<?= shop_h($supplier) ?>"> <?= shop_h($supplier) ?></label>
            <?php endforeach; ?>
            <label><input type="checkbox" id="only-shelf"> فقط کالاهای دارای قیمت مصرف</label>
            <button type="button" class="linkish" id="filter-clear">پاک کردن فیلتر</button>
        </div>
    </div>
    <div class="cluster">
        <label class="choice">
            <select id="cat">
                <option value="">همه دسته‌بندی‌ها</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= shop_h($category) ?>"><?= shop_h($category) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="count" id="count">نمایش ۱ تا <?= $total ?> از <?= $total ?> محصول</p>
    </div>
</div>
<div class="chips" id="cats">
    <button type="button" data-cat="" class="on">همه</button>
    <?php foreach ($categories as $category): ?>
        <button type="button" data-cat="<?= shop_h($category) ?>"><?= shop_h($category) ?></button>
    <?php endforeach; ?>
</div>
<div class="catalog view-auto" id="catalog">
    <?php foreach ($products as $product): ?>
        <?php
        $qty = (int) ($_SESSION['cart'][$product['id']] ?? 0);
        $unit = (int) ($quote['units'][$product['id']] ?? shop_unit_price($product, (float) $quote['progress']));
        $shelf = (int) $product['shelf'];
        ?>
        <article class="card"
                 data-pid="<?= shop_h($product['id']) ?>"
                 data-name="<?= shop_h($product['name']) ?>"
                 data-cat="<?= shop_h($product['category']) ?>"
                 data-supplier="<?= shop_h($product['supplier']) ?>"
                 data-sale="<?= (int) $product['sale'] ?>"
                 data-shelf="<?= $shelf ?>"
                 data-index="<?= (int) $product['index'] ?>">
            <div class="photo">
                <img src="<?= shop_h($product['image']) ?>" alt="<?= shop_h($product['name']) ?>">
                <button type="button" class="heart" aria-label="نشان کردن" aria-pressed="false"><?= shop_svg('heart') ?></button>
            </div>
            <div class="info">
                <h2><?= shop_h($product['name']) ?></h2>
                <div class="buy">
                    <div>
                        <p class="now" data-unit="<?= $unit ?>"><?= shop_h(shop_toman($unit)) ?></p>
                        <p class="sub">
                            <span class="was"<?= $shelf > $unit ? '' : ' hidden' ?>><?= shop_h(shop_toman($shelf)) ?></span>
                            <span class="sku"><?= shop_h($product['sku']) ?></span>
                        </p>
                    </div>
                    <?= shop_control($product['id'], $qty, 'index.php') ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<p class="empty" id="empty" hidden>کالایی با این مشخصات پیدا نشد.</p>
<?php
shop_footer($boot);
