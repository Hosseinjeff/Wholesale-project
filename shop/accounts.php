<?php
declare(strict_types=1);

require_once __DIR__ . '/mail.php';

function shop_ensure_column(SQLite3 $db, string $table, string $column, string $definition): void
{
    $allowed = [
        'orders' => ['customer_id', 'email', 'address', 'username', 'published'],
        'product_meta' => ['min_qty'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        return;
    }
    $info = $db->query('PRAGMA table_info(' . $table . ')');
    $names = [];
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        $names[] = (string) $row['name'];
    }
    if (!in_array($column, $names, true)) {
        $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

function shop_migrate_accounts(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT \'\',
        phone TEXT NOT NULL DEFAULT \'\',
        address TEXT NOT NULL DEFAULT \'\',
        city TEXT NOT NULL DEFAULT \'\',
        note TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role TEXT NOT NULL,
        email TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        used INTEGER NOT NULL DEFAULT 0
    )');
    shop_ensure_column($db, 'orders', 'customer_id', 'INTEGER NOT NULL DEFAULT 0');
    shop_ensure_column($db, 'orders', 'email', "TEXT NOT NULL DEFAULT ''");
    shop_ensure_column($db, 'orders', 'address', "TEXT NOT NULL DEFAULT ''");
    shop_ensure_column($db, 'orders', 'username', "TEXT NOT NULL DEFAULT ''");
    shop_ensure_column($db, 'orders', 'published', 'INTEGER NOT NULL DEFAULT 0');
    shop_ensure_column($db, 'product_meta', 'min_qty', 'INTEGER');
    if ((int) $db->querySingle('SELECT COUNT(*) FROM admins') > 0) {
        return;
    }
    $hash = $db->querySingle('SELECT password_hash FROM admin WHERE id = 1');
    if (!is_string($hash) || $hash === '') {
        return;
    }
    $username = 'admin';
    $email = 'admin@localhost';
    $path = __DIR__ . '/private/admin.php';
    if (is_file($path)) {
        $seed = require $path;
        if (is_array($seed)) {
            $seedUser = trim((string) ($seed['username'] ?? ''));
            $seedEmail = trim((string) ($seed['email'] ?? ''));
            if (shop_username_ok($seedUser)) {
                $username = $seedUser;
            }
            if (shop_email_ok($seedEmail)) {
                $email = $seedEmail;
            }
        }
    }
    $stmt = $db->prepare('INSERT INTO admins (username, email, password_hash, display_name, created_at)
        VALUES (:username, :email, :hash, :name, :at)');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':name', 'مدیر', SQLITE3_TEXT);
    $stmt->bindValue(':at', gmdate('c'), SQLITE3_TEXT);
    $stmt->execute();
    $stmt->close();
}

function shop_username_ok(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_\x{0600}-\x{06FF}]{3,32}$/u', $username);
}

function shop_email_ok(string $email): bool
{
    return mb_strlen($email) <= 120 && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function shop_password_ok(string $password): bool
{
    return mb_strlen($password) >= 8 && mb_strlen($password) <= 200;
}

function shop_current_admin(): ?array
{
    $id = (int) ($_SESSION['admin'] ?? 0);
    if ($id < 1) {
        return null;
    }
    return shop_db_one('SELECT id, username, email, display_name, created_at FROM admins WHERE id = :id', [':id' => $id]);
}

function shop_current_customer(): ?array
{
    $id = (int) ($_SESSION['customer_id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    return shop_db_one('SELECT id, username, email, name, phone, address, city, note, created_at FROM customers WHERE id = :id', [':id' => $id]);
}

function shop_admin_login(string $username, string $password): bool
{
    $row = shop_db_one('SELECT id, username, password_hash FROM admins WHERE username = :u COLLATE NOCASE', [
        ':u' => trim($username),
    ]);
    if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = (int) $row['id'];
    $_SESSION['admin_username'] = (string) $row['username'];
    $_SESSION['admin_fails'] = 0;
    return true;
}

function shop_check_password(string $password): bool
{
    $admin = shop_current_admin();
    if ($admin === null) {
        return false;
    }
    $row = shop_db_one('SELECT password_hash FROM admins WHERE id = :id', [':id' => (int) $admin['id']]);
    return $row !== null && password_verify($password, (string) $row['password_hash']);
}

function shop_set_password(string $password): void
{
    $admin = shop_current_admin();
    if ($admin === null) {
        return;
    }
    shop_db_exec('UPDATE admins SET password_hash = :hash WHERE id = :id', [
        ':hash' => password_hash($password, PASSWORD_DEFAULT),
        ':id' => (int) $admin['id'],
    ]);
}

function shop_update_admin_email(string $email): void
{
    $admin = shop_current_admin();
    if ($admin === null || !shop_email_ok($email)) {
        throw new RuntimeException('ایمیل درست نیست.');
    }
    $taken = shop_db_one('SELECT id FROM admins WHERE email = :email COLLATE NOCASE AND id != :id', [
        ':email' => $email,
        ':id' => (int) $admin['id'],
    ]);
    if ($taken !== null) {
        throw new RuntimeException('این ایمیل برای مدیر دیگری است.');
    }
    shop_db_exec('UPDATE admins SET email = :email WHERE id = :id', [
        ':email' => $email,
        ':id' => (int) $admin['id'],
    ]);
}

function shop_admins(): array
{
    return shop_db_all('SELECT id, username, email, display_name, created_at FROM admins ORDER BY id');
}

function shop_create_admin(string $username, string $email, string $password, string $displayName): void
{
    $username = trim($username);
    $email = trim($email);
    $displayName = mb_substr(trim($displayName), 0, 80);
    if (!shop_username_ok($username)) {
        throw new RuntimeException('نام کاربری ۳ تا ۳۲ حرف، عدد یا خط باشد.');
    }
    if (!shop_email_ok($email)) {
        throw new RuntimeException('ایمیل مدیر درست نیست.');
    }
    if (!shop_password_ok($password)) {
        throw new RuntimeException('رمز حداقل ۸ حرف باشد.');
    }
    if (shop_db_one('SELECT id FROM admins WHERE username = :u COLLATE NOCASE', [':u' => $username]) !== null) {
        throw new RuntimeException('این نام کاربری گرفته شده است.');
    }
    if (shop_db_one('SELECT id FROM admins WHERE email = :e COLLATE NOCASE', [':e' => $email]) !== null) {
        throw new RuntimeException('این ایمیل گرفته شده است.');
    }
    shop_db_exec(
        'INSERT INTO admins (username, email, password_hash, display_name, created_at)
         VALUES (:username, :email, :hash, :name, :at)',
        [
            ':username' => $username,
            ':email' => $email,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':name' => $displayName,
            ':at' => gmdate('c'),
        ]
    );
}

function shop_delete_admin(int $id): void
{
    $current = shop_current_admin();
    if ($current === null || $id < 1 || $id === (int) $current['id']) {
        throw new RuntimeException('این مدیر حذف نمی‌شود.');
    }
    if ((int) shop_db()->querySingle('SELECT COUNT(*) FROM admins') < 2) {
        throw new RuntimeException('آخرین مدیر می‌ماند.');
    }
    shop_db_exec('DELETE FROM admins WHERE id = :id', [':id' => $id]);
}

function shop_customer_login(string $username, string $password): bool
{
    $key = trim($username);
    $row = shop_db_one(
        'SELECT id, username, password_hash FROM customers WHERE username = :u COLLATE NOCASE OR email = :u COLLATE NOCASE',
        [':u' => $key]
    );
    if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int) $row['id'];
    $_SESSION['customer_username'] = (string) $row['username'];
    $_SESSION['customer_fails'] = 0;
    return true;
}

function shop_customer_logout(): void
{
    unset($_SESSION['customer_id'], $_SESSION['customer_username']);
}

function shop_register_customer(array $fields): int
{
    $username = trim((string) ($fields['username'] ?? ''));
    $email = trim((string) ($fields['email'] ?? ''));
    $password = (string) ($fields['password'] ?? '');
    $name = mb_substr(trim((string) ($fields['name'] ?? '')), 0, 80);
    $phone = preg_replace('/\D+/', '', (string) ($fields['phone'] ?? '')) ?? '';
    $address = mb_substr(trim((string) ($fields['address'] ?? '')), 0, 300);
    $city = mb_substr(trim((string) ($fields['city'] ?? '')), 0, 80);
    if (!shop_username_ok($username)) {
        throw new RuntimeException('نام کاربری ۳ تا ۳۲ حرف، عدد یا خط باشد.');
    }
    if (!shop_email_ok($email)) {
        throw new RuntimeException('ایمیل درست نیست.');
    }
    if (!shop_password_ok($password)) {
        throw new RuntimeException('رمز حداقل ۸ حرف باشد.');
    }
    if (shop_db_one('SELECT id FROM customers WHERE username = :u COLLATE NOCASE', [':u' => $username]) !== null) {
        throw new RuntimeException('این نام کاربری گرفته شده است.');
    }
    if (shop_db_one('SELECT id FROM customers WHERE email = :e COLLATE NOCASE', [':e' => $email]) !== null) {
        throw new RuntimeException('این ایمیل گرفته شده است.');
    }
    shop_db_exec(
        'INSERT INTO customers (username, email, password_hash, name, phone, address, city, note, created_at)
         VALUES (:username, :email, :hash, :name, :phone, :address, :city, \'\', :at)',
        [
            ':username' => $username,
            ':email' => $email,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':name' => $name,
            ':phone' => $phone,
            ':address' => $address,
            ':city' => $city,
            ':at' => gmdate('c'),
        ]
    );
    return (int) shop_db()->lastInsertRowID();
}

function shop_update_customer(array $fields): void
{
    $customer = shop_current_customer();
    if ($customer === null) {
        throw new RuntimeException('وارد حساب نشده‌اید.');
    }
    $email = trim((string) ($fields['email'] ?? ''));
    if (!shop_email_ok($email)) {
        throw new RuntimeException('ایمیل درست نیست.');
    }
    $taken = shop_db_one('SELECT id FROM customers WHERE email = :email COLLATE NOCASE AND id != :id', [
        ':email' => $email,
        ':id' => (int) $customer['id'],
    ]);
    if ($taken !== null) {
        throw new RuntimeException('این ایمیل برای حساب دیگری است.');
    }
    $password = (string) ($fields['password'] ?? '');
    if ($password !== '' && !shop_password_ok($password)) {
        throw new RuntimeException('رمز تازه حداقل ۸ حرف باشد.');
    }
    shop_db_exec(
        'UPDATE customers SET email = :email, name = :name, phone = :phone, address = :address, city = :city, note = :note WHERE id = :id',
        [
            ':email' => $email,
            ':name' => mb_substr(trim((string) ($fields['name'] ?? '')), 0, 80),
            ':phone' => preg_replace('/\D+/', '', (string) ($fields['phone'] ?? '')) ?? '',
            ':address' => mb_substr(trim((string) ($fields['address'] ?? '')), 0, 300),
            ':city' => mb_substr(trim((string) ($fields['city'] ?? '')), 0, 80),
            ':note' => mb_substr(trim((string) ($fields['note'] ?? '')), 0, 300),
            ':id' => (int) $customer['id'],
        ]
    );
    if ($password !== '') {
        shop_db_exec('UPDATE customers SET password_hash = :hash WHERE id = :id', [
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => (int) $customer['id'],
        ]);
    }
}

function shop_customers(): array
{
    return shop_db_all('SELECT id, username, email, name, phone, address, city, created_at FROM customers ORDER BY id DESC');
}

function shop_customer_orders(array $customer): array
{
    $rows = shop_db_all(
        'SELECT * FROM orders
         WHERE customer_id = :id
            OR (customer_id = 0 AND email = :email AND email != \'\' AND phone = :phone AND phone != \'\')
         ORDER BY created_at DESC',
        [
            ':id' => (int) $customer['id'],
            ':email' => (string) $customer['email'],
            ':phone' => (string) $customer['phone'],
        ]
    );
    foreach ($rows as &$row) {
        $row['lines'] = json_decode((string) $row['lines_json'], true) ?: [];
    }
    return $rows;
}

function shop_public_base(): string
{
    $config = shop_config();
    $explicit = trim((string) ($config['public_url'] ?? ''));
    if ($explicit !== '') {
        return rtrim($explicit, '/');
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $dir = rtrim($dir, '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function shop_request_reset(string $email, string $role): string
{
    $email = trim($email);
    if (!in_array($role, ['admin', 'customer'], true) || !shop_email_ok($email)) {
        return 'sent';
    }
    if (!shop_mail_configured()) {
        return 'unconfigured';
    }
    $table = $role === 'admin' ? 'admins' : 'customers';
    $row = shop_db_one('SELECT id FROM ' . $table . ' WHERE email = :email COLLATE NOCASE', [':email' => $email]);
    if ($row === null) {
        return 'sent';
    }
    $token = bin2hex(random_bytes(24));
    shop_db_exec(
        'INSERT INTO password_resets (role, email, token_hash, expires_at, used) VALUES (:role, :email, :hash, :exp, 0)',
        [
            ':role' => $role,
            ':email' => $email,
            ':hash' => hash('sha256', $token),
            ':exp' => time() + 3600,
        ]
    );
    $url = shop_public_base() . '/recover.php?token=' . rawurlencode($token);
    $text = "برای انتخاب رمز تازه مولوی‌سل این پیوند را باز کنید:\n{$url}\n\nاین پیوند یک ساعت معتبر است. اگر شما این درخواست را نداده‌اید، این نامه را نادیده بگیرید.";
    if (!shop_send_mail($email, 'بازیابی رمز مولوی‌سل', $text)) {
        return 'unconfigured';
    }
    return 'sent';
}

function shop_reset_with_token(string $token, string $password): bool
{
    if (!shop_password_ok($password) || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return false;
    }
    $row = shop_db_one(
        'SELECT id, role, email FROM password_resets WHERE token_hash = :hash AND used = 0 AND expires_at > :now',
        [':hash' => hash('sha256', $token), ':now' => time()]
    );
    if ($row === null) {
        return false;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $table = $row['role'] === 'admin' ? 'admins' : 'customers';
    shop_db_exec('UPDATE ' . $table . ' SET password_hash = :hash WHERE email = :email COLLATE NOCASE', [
        ':hash' => $hash,
        ':email' => (string) $row['email'],
    ]);
    shop_db_exec('UPDATE password_resets SET used = 1 WHERE id = :id', [':id' => (int) $row['id']]);
    return true;
}

function shop_pack_count(string $name, string $packaging): int
{
    $text = strtr($name . ' ' . $packaging, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if (preg_match_all('/(?:کارتن|باکس|شیرینگ|shell|شل(?![\x{0600}-\x{06FF}]))\s*(\d+)/ui', $text, $outer) && !empty($outer[1])) {
        return (int) $outer[1][count($outer[1]) - 1];
    }
    if (preg_match_all('/(\d+)\s*(?:عددی|عدد|تایی)/u', $text, $counts) && !empty($counts[1])) {
        return (int) $counts[1][count($counts[1]) - 1];
    }
    return 0;
}

function shop_auto_min(array $product): int
{
    $count = shop_pack_count((string) ($product['name'] ?? ''), (string) ($product['packaging'] ?? ''));
    return in_array($count, [6, 12, 24], true) ? 6 : 1;
}

function shop_product_min(array $product): int
{
    $set = (int) ($product['min'] ?? 0);
    if ($set > 0) {
        return min(999, $set);
    }
    return shop_auto_min($product);
}

function shop_product_facts(array $product): string
{
    $parts = [];
    foreach (['packaging', 'volume', 'variation', 'supplier'] as $key) {
        $value = trim((string) ($product[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    $parts[] = 'حداقل خرید ' . shop_digits((string) shop_product_min($product)) . ' عدد';
    return implode(' · ', $parts);
}

function shop_publish_order(array $order, bool $notify): bool
{
    $config = shop_config();
    $url = rtrim((string) ($config['bot_url'] ?? ''), '/');
    $secret = (string) ($config['notify_secret'] ?? '');
    if ($url === '' || $secret === '') {
        return false;
    }
    $body = json_encode(['order' => shop_order_payload($order), 'notify' => $notify], JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return false;
    }
    shop_http('POST', $url . '/shop/order', [
        'Content-Type: application/json',
        'X-Shop-Token: ' . $secret,
    ], $body, 20);
    return true;
}

function shop_order_payload(array $order): array
{
    $customer = shop_current_customer();
    return [
        'id' => (string) ($order['id'] ?? ''),
        'at' => (string) ($order['created_at'] ?? $order['at'] ?? ''),
        'name' => (string) ($order['name'] ?? ''),
        'username' => (string) ($order['username'] ?? ''),
        'phone' => (string) ($order['phone'] ?? ''),
        'email' => (string) ($order['email'] ?? ''),
        'address' => (string) ($order['address'] ?? ''),
        'note' => (string) ($order['note'] ?? ''),
        'progress' => (float) ($order['progress'] ?? 0),
        'total' => (int) ($order['total'] ?? 0),
        'status' => (string) ($order['status'] ?? 'new'),
        'lines' => $order['lines'] ?? [],
    ];
}

function shop_mark_published(string $id): void
{
    shop_db_exec('UPDATE orders SET published = 1 WHERE id = :id', [':id' => $id]);
}
