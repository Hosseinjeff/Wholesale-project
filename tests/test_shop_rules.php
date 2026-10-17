<?php
declare(strict_types=1);

require __DIR__ . '/../shop/lib.php';

$cases = [
    ['در کارتن6 تایی', 'آب شلغم', 6],
    ['در کارتن 12تایی', '', 6],
    ['در کارتن 24تایی', 'آنتارس', 6],
    ['', 'آب سودا یک لیتری سون،۶ عددی', 6],
    ['بسته ۲۵ عدد و کارتن ۲۴ بسته', 'چای', 6],
    ['کارتن ۳۶ عددی', 'کروسان', 1],
    ['', 'قند شکسته،۵ کیلویی', 1],
    ['', 'آب شلغم تخمیری', 1],
];

$failed = 0;
foreach ($cases as [$pack, $name, $expect]) {
    $got = shop_auto_min(['packaging' => $pack, 'name' => $name]);
    if ($got !== $expect) {
        fwrite(STDERR, "min {$name} / {$pack}: got {$got}, want {$expect}\n");
        $failed++;
    }
}

if ($failed > 0) {
    exit(1);
}
echo "shop rules ok\n";
