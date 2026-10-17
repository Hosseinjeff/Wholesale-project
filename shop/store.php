<?php
declare(strict_types=1);

function shop_db(): SQLite3
{
    static $db = null;
    if ($db instanceof SQLite3) {
        return $db;
    }
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('sqlite missing');
    }
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('data directory missing');
    }
    $db = new SQLite3($dir . '/shop.sqlite');
    $db->enableExceptions(true);
    $db->busyTimeout(4000);
    try {
        $db->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable $e) {
        // The host can still store the shop if WAL is refused.
    }
    shop_migrate($db);
    return $db;
}

function shop_migrate(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        password_hash TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS orders (
        id TEXT PRIMARY KEY,
        created_at TEXT NOT NULL,
        name TEXT NOT NULL,
        phone TEXT NOT NULL,
        note TEXT NOT NULL,
        progress REAL NOT NULL,
        total INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT \'new\',
        lines_json TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS product_meta (
        id TEXT PRIMARY KEY,
        hidden INTEGER NOT NULL DEFAULT 0,
        category TEXT NOT NULL DEFAULT \'\',
        image TEXT NOT NULL DEFAULT \'\'
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    $defaults = [
        'phone' => '',
        'address' => '',
        'hours' => 'هر روز، ۹ تا ۱۸',
        'about' => 'مولوی‌سل مواد غذایی بسته‌بندی را مستقیم از بنکدار عرضه می‌کند.',
    ];
    $replace = $db->prepare('UPDATE settings SET value = :next WHERE key = :k AND value = :prev');
    $replace->bindValue(':k', 'about', SQLITE3_TEXT);
    $replace->bindValue(':prev', 'مولوی‌سل مواد غذایی بسته‌بندی را مستقیم از بنکدار عرضه می‌کند. قیمت روی هر کارت از قیمت مصرف شروع می‌شود و با بزرگ‌تر شدن سبد به قیمت فروش تأمین‌کننده نزدیک می‌شود.', SQLITE3_TEXT);
    $replace->bindValue(':next', $defaults['about'], SQLITE3_TEXT);
    $replace->execute();
    $replace->close();
    $insert = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
    foreach ($defaults as $key => $value) {
        $insert->bindValue(':k', $key, SQLITE3_TEXT);
        $insert->bindValue(':v', $value, SQLITE3_TEXT);
        $insert->execute();
        $insert->reset();
    }
    $insert->close();
    if ((int) $db->querySingle('SELECT COUNT(*) FROM admin') === 0) {
        $path = __DIR__ . '/private/admin.php';
        if (is_file($path)) {
            $seed = require $path;
            $hash = is_array($seed) ? (string) ($seed['hash'] ?? '') : '';
            if ($hash !== '') {
                $admin = $db->prepare('INSERT INTO admin (id, password_hash) VALUES (1, :hash)');
                $admin->bindValue(':hash', $hash, SQLITE3_TEXT);
                $admin->execute();
                $admin->close();
            }
        }
    }
    shop_migrate_accounts($db);
    shop_import_json_orders($db);
}

function shop_import_json_orders(SQLite3 $db): void
{
    $files = glob(__DIR__ . '/data/orders/*.json') ?: [];
    if ($files === []) {
        return;
    }
    $stmt = $db->prepare('INSERT OR IGNORE INTO orders
        (id, created_at, name, phone, note, progress, total, status, lines_json)
        VALUES (:id, :at, :name, :phone, :note, :progress, :total, :status, :lines)');
    foreach ($files as $file) {
        $order = json_decode((string) file_get_contents($file), true);
        if (!is_array($order) || empty($order['id']) || empty($order['lines'])) {
            continue;
        }
        $stmt->bindValue(':id', (string) $order['id'], SQLITE3_TEXT);
        $stmt->bindValue(':at', (string) ($order['at'] ?? gmdate('c')), SQLITE3_TEXT);
        $stmt->bindValue(':name', (string) ($order['name'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':phone', (string) ($order['phone'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':note', (string) ($order['note'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':progress', (float) ($order['progress'] ?? 0), SQLITE3_FLOAT);
        $stmt->bindValue(':total', (int) ($order['total'] ?? 0), SQLITE3_INTEGER);
        $stmt->bindValue(':status', 'new', SQLITE3_TEXT);
        $stmt->bindValue(':lines', json_encode($order['lines'], JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
    }
    $stmt->close();
}

function shop_db_all(string $sql, array $params = []): array
{
    $stmt = shop_db()->prepare($sql);
    shop_bind($stmt, $params);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $result->finalize();
    $stmt->close();
    return $rows;
}

function shop_db_one(string $sql, array $params = []): ?array
{
    $rows = shop_db_all($sql, $params);
    return $rows[0] ?? null;
}

function shop_db_exec(string $sql, array $params = []): void
{
    $stmt = shop_db()->prepare($sql);
    shop_bind($stmt, $params);
    $result = $stmt->execute();
    if ($result instanceof SQLite3Result) {
        $result->finalize();
    }
    $stmt->close();
}

function shop_bind(SQLite3Stmt $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, SQLITE3_NULL);
            continue;
        }
        if (is_int($value)) {
            $type = SQLITE3_INTEGER;
        } elseif (is_float($value)) {
            $type = SQLITE3_FLOAT;
        } else {
            $type = SQLITE3_TEXT;
            $value = (string) $value;
        }
        $stmt->bindValue($key, $value, $type);
    }
}

function shop_setting(string $key, string $default = ''): string
{
    try {
        $row = shop_db_one('SELECT value FROM settings WHERE key = :k', [':k' => $key]);
    } catch (Throwable $e) {
        return $default;
    }
    return $row ? (string) $row['value'] : $default;
}

function shop_categories(): array
{
    return ['نوشیدنی', 'کنسرو', 'رب', 'لبنیات', 'چای', 'شیرینی', 'خواربار', 'عرقیات', 'تنقلات', 'سایر'];
}

function shop_mentions(string $haystack, string $needle): bool
{
    $haystack = str_replace('کارتن', ' ', $haystack);
    if ($needle === 'رب') {
        $haystack = str_replace(['ربیع', 'درب'], ' ', $haystack);
    }
    if ($needle === 'شیر') {
        $haystack = str_replace('شیرینی', ' ', $haystack);
    }
    return mb_strpos($haystack, $needle) !== false;
}

function shop_guess_category(array $product): string
{
    $name = (string) ($product['name'] ?? '') . ' ' . (string) ($product['packaging'] ?? '');
    $rules = [
        'چای' => ['چای', 'گیلان'],
        'عرقیات' => ['گلاب'],
        'شیرینی' => ['کروسان', 'کلوچه', 'کیک'],
        'رب' => ['رب'],
        'کنسرو' => ['تن', 'عدس'],
        'لبنیات' => ['شیر'],
        'خواربار' => ['قند', 'پاستا'],
        'تنقلات' => ['ژل'],
        'نوشیدنی' => ['سودا', 'انرژی', 'نوشیدنی', 'شلغم', 'آنتارس'],
    ];
    foreach ($rules as $category => $keys) {
        foreach ($keys as $key) {
            if (shop_mentions($name, $key)) {
                return $category;
            }
        }
    }
    return 'سایر';
}

function shop_stock_image(array $product): string
{
    $name = (string) ($product['name'] ?? '');
    $pairs = [
        'گلاب' => 'rosewater.jpg',
        'شلغم' => 'beet.jpg',
        'آنتارس' => 'energy-liter.jpg',
        'انرژی زا یک' => 'energy-liter.jpg',
        'سودا' => 'soda.jpg',
        'انرژی' => 'energy.jpg',
        'نوشیدنی' => 'soda-can.jpg',
        'چای' => 'tea.jpg',
        'گیلان' => 'tea-tin.jpg',
        'کروسان' => 'croissant.jpg',
        'کلوچه' => 'cookie.jpg',
        'کیک' => 'cake.jpg',
        'تن' => 'tuna.jpg',
        'عدس' => 'lentil.jpg',
        'رب' => 'paste.jpg',
        'شیر' => 'milk.jpg',
        'قند' => 'sugar.jpg',
        'پاستا' => 'pasta.jpg',
        'ژل' => 'jelly.jpg',
    ];
    foreach ($pairs as $key => $file) {
        if (shop_mentions($name, $key)) {
            return 'assets/products/' . $file;
        }
    }
    return 'assets/products/soda.jpg';
}

function shop_meta_map(): array
{
    try {
        $rows = shop_db_all('SELECT id, hidden, category, image, min_qty FROM product_meta');
    } catch (Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['id']] = $row;
    }
    return $map;
}

function shop_present(): array
{
    $catalog = shop_catalog();
    $settings = shop_settings($catalog);
    $meta = shop_meta_map();
    $products = [];
    $index = 0;
    foreach ($catalog['products'] ?? [] as $product) {
        $id = (string) ($product['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $row = $meta[$id] ?? [];
        $custom = (string) ($row['category'] ?? '');
        $product['category'] = $custom !== '' ? $custom : shop_guess_category($product);
        $product['hidden'] = (int) ($row['hidden'] ?? 0) === 1 ? 1 : 0;
        $autoMin = shop_auto_min($product);
        $override = $row['min_qty'] ?? null;
        $product['min'] = ($override !== null && $override !== '' && (int) $override > 0)
            ? min(999, (int) $override)
            : $autoMin;
        $product['min_auto'] = $autoMin;
        $product['image'] = shop_image_src($id, (string) ($row['image'] ?? ''), $product);
        $product['sku'] = 'MS-' . strtoupper(substr($id, 0, 6));
        $product['index'] = $index;
        $band = shop_band((int) $product['sale'], (int) $product['shelf'], $settings);
        $product['floor'] = $band['floor'];
        $product['ceiling'] = $band['ceiling'];
        $product['opening'] = $band['opening'];
        $product['depth'] = $band['depth'];
        $product['max'] = $band['max'];
        $product['discountable'] = $band['discountable'] ? 1 : 0;
        $index++;
        $products[] = $product;
    }
    $catalog['products'] = $products;
    return $catalog;
}

function shop_image_src(string $id, string $custom, array $product): string
{
    if (preg_match('/^assets\/uploads\/' . preg_quote($id, '/') . '\.(jpg|png|webp)$/', $custom)
        && is_file(__DIR__ . '/' . $custom)) {
        return $custom;
    }
    $fileId = (string) ($product['image_file_id'] ?? '');
    $base = rtrim((string) (shop_config()['media_base'] ?? ''), '/');
    if ($fileId !== '' && $base !== '' && preg_match('/^[A-Za-z0-9_-]{8,300}$/', $fileId)) {
        return $base . '/media/file/' . rawurlencode($fileId);
    }
    return shop_stock_image($product);
}

function shop_visible(array $catalog): array
{
    return array_values(array_filter(
        $catalog['products'] ?? [],
        static fn(array $product): bool => empty($product['hidden']) && !empty($product['discountable'])
    ));
}

function shop_find(array $catalog, string $id): ?array
{
    foreach ($catalog['products'] ?? [] as $product) {
        if ((string) $product['id'] === $id) {
            return $product;
        }
    }
    return null;
}

function shop_public(array $quote): array
{
    $count = 0;
    $lines = [];
    foreach ($quote['lines'] as $line) {
        $count += (int) $line['qty'];
        $lines[] = [
            'id' => (string) $line['id'],
            'name' => (string) $line['name'],
            'qty' => (int) $line['qty'],
            'unit' => (int) $line['unit'],
            'shelf' => (int) $line['shelf'],
            'total' => (int) $line['total'],
            'discount_amount' => (int) ($line['discount_amount'] ?? 0),
            'discount_rate' => (float) ($line['discount_rate'] ?? 0),
            'image' => (string) ($line['image'] ?? ''),
            'sku' => (string) ($line['sku'] ?? ''),
        ];
    }
    $units = [];
    foreach ($quote['units'] ?? [] as $id => $unit) {
        $units[(string) $id] = (int) $unit;
    }
    return [
        'ok' => true,
        'count' => $count,
        'total' => (int) $quote['total'],
        'measure' => (int) $quote['measure'],
        'discount_total' => (int) ($quote['discount_total'] ?? 0),
        'discount_rate' => (float) ($quote['discount_rate'] ?? 0),
        'progress' => (float) $quote['progress'],
        'unlock' => (int) ($quote['unlock'] ?? 0),
        'units' => $units,
        'lines' => $lines,
    ];
}

function shop_boot(array $catalog, array $quote): array
{
    $boot = shop_public($quote);
    $boot['token'] = (string) ($_SESSION['token'] ?? '');
    $book = [];
    foreach ($catalog['products'] ?? [] as $product) {
        $id = (string) $product['id'];
        $inCart = !empty($_SESSION['cart'][$id]);
        if ((empty($product['discountable']) || !empty($product['hidden'])) && !$inCart) {
            continue;
        }
        $shelf = (int) $product['shelf'];
        $floor = (int) $product['floor'];
        $ceiling = (int) $product['ceiling'];
        if (empty($product['discountable'])) {
            $flat = $shelf > 0 ? $shelf : (int) $product['sale'];
            $shelf = $flat;
            $floor = $flat;
            $ceiling = $flat;
        }
        $book[$id] = [
            'name' => (string) $product['name'],
            'shelf' => $shelf,
            'floor' => $floor,
            'ceiling' => $ceiling,
            'image' => (string) $product['image'],
            'sku' => (string) $product['sku'],
            'category' => (string) $product['category'],
            'supplier' => (string) ($product['supplier'] ?? ''),
            'min' => shop_product_min($product),
            'packaging' => (string) ($product['packaging'] ?? ''),
        ];
    }
    $boot['book'] = $book;
    return $boot;
}

function shop_save_order(array $order): void
{
    shop_db_exec(
        'INSERT INTO orders (id, created_at, name, phone, note, progress, total, status, lines_json, customer_id, email, address, username, published)
         VALUES (:id, :at, :name, :phone, :note, :progress, :total, :status, :lines, :customer, :email, :address, :username, 0)',
        [
            ':id' => (string) $order['id'],
            ':at' => (string) $order['at'],
            ':name' => (string) $order['name'],
            ':phone' => (string) $order['phone'],
            ':note' => (string) $order['note'],
            ':progress' => (float) $order['progress'],
            ':total' => (int) $order['total'],
            ':status' => 'new',
            ':lines' => json_encode($order['lines'], JSON_UNESCAPED_UNICODE),
            ':customer' => (int) ($order['customer_id'] ?? 0),
            ':email' => (string) ($order['email'] ?? ''),
            ':address' => (string) ($order['address'] ?? ''),
            ':username' => (string) ($order['username'] ?? ''),
        ]
    );
}

function shop_orders(): array
{
    $rows = shop_db_all('SELECT * FROM orders ORDER BY created_at DESC');
    foreach ($rows as &$row) {
        $row['lines'] = json_decode((string) $row['lines_json'], true) ?: [];
    }
    return $rows;
}

function shop_find_order(string $id): array
{
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id)) {
        return [];
    }
    try {
        $row = shop_db_one('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
    } catch (Throwable $e) {
        $row = null;
    }
    if ($row) {
        $row['lines'] = json_decode((string) $row['lines_json'], true) ?: [];
        return $row;
    }
    $file = shop_read_json(__DIR__ . '/data/orders/' . $id . '.json');
    return is_array($file) ? $file : [];
}

function shop_set_status(string $id, string $status): void
{
    $allowed = ['new', 'review', 'confirmed', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        return;
    }
    shop_db_exec('UPDATE orders SET status = :status WHERE id = :id', [
        ':status' => $status,
        ':id' => $id,
    ]);
    try {
        $order = shop_find_order($id);
        if ($order !== [] && shop_publish_order($order, false)) {
            shop_mark_published($id);
        }
    } catch (Throwable $e) {
        shop_db_exec('UPDATE orders SET published = 0 WHERE id = :id', [':id' => $id]);
    }
}

function shop_status_label(string $status): string
{
    return [
        'new' => 'جدید',
        'review' => 'در حال بررسی',
        'confirmed' => 'تأیید شده',
        'cancelled' => 'لغو شده',
    ][$status] ?? $status;
}

function shop_save_product(string $id, int $hidden, string $category, ?string $image, ?int $minQty): void
{
    $current = shop_db_one('SELECT image FROM product_meta WHERE id = :id', [':id' => $id]);
    $kept = $image !== null ? $image : (string) ($current['image'] ?? '');
    shop_db_exec(
        'INSERT INTO product_meta (id, hidden, category, image, min_qty) VALUES (:id, :hidden, :category, :image, :min)
         ON CONFLICT(id) DO UPDATE SET hidden = :hidden, category = :category, image = :image, min_qty = :min',
        [
            ':id' => $id,
            ':hidden' => $hidden ? 1 : 0,
            ':category' => $category,
            ':image' => $kept,
            ':min' => $minQty,
        ]
    );
}

function shop_save_setting(string $key, string $value): void
{
    shop_db_exec(
        'INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v',
        [':k' => $key, ':v' => $value]
    );
}

require_once __DIR__ . '/accounts.php';

function shop_store_image(string $id, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فایل تصویر دریافت نشد.');
    }
    if (($file['size'] ?? 0) > 2500000) {
        throw new RuntimeException('حجم تصویر باید کمتر از ۲٫۵ مگابایت باشد.');
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('فقط تصویر JPG یا PNG یا WebP پذیرفته می‌شود.');
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width * $height > 24000000) {
        throw new RuntimeException('اندازه تصویر قابل استفاده نیست.');
    }
    $raw = file_get_contents((string) $file['tmp_name']);
    $source = is_string($raw) ? @imagecreatefromstring($raw) : false;
    if ($source === false) {
        throw new RuntimeException('تصویر باز نشد.');
    }
    $max = 900;
    $scale = min($max / $width, $max / $height, 1);
    $nw = max(1, (int) round($width * $scale));
    $nh = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
    $bg = imagecolorallocate($canvas, 246, 243, 238);
    imagefilledrectangle($canvas, 0, 0, $nw, $nh, $bg);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $width, $height);
    imagedestroy($source);
    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        imagedestroy($canvas);
        throw new RuntimeException('پوشه تصویر ساخته نشد.');
    }
    foreach (['jpg', 'png', 'webp'] as $ext) {
        $old = $dir . '/' . $id . '.' . $ext;
        if (is_file($old)) {
            unlink($old);
        }
    }
    $dest = $dir . '/' . $id . '.jpg';
    $ok = imagejpeg($canvas, $dest, 84);
    imagedestroy($canvas);
    if (!$ok) {
        throw new RuntimeException('ذخیره تصویر انجام نشد.');
    }
    return 'assets/uploads/' . $id . '.jpg';
}

function shop_g2j(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function shop_jalali(string $iso): string
{
    $stamp = strtotime($iso);
    if ($stamp === false) {
        return $iso;
    }
    $date = new DateTime('@' . $stamp);
    $date->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$jy, $jm, $jd] = shop_g2j((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, $date->format('H:i'));
}
