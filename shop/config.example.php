<?php
declare(strict_types=1);

return [
    'sheet_id' => 'your_google_sheet_id',
    'service_account' => [
        'client_email' => 'name@project.iam.gserviceaccount.com',
        'private_key' => "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
    ],
    // Railway bot. Orders are posted here so the sheet and the Telegram notice stay together.
    'bot_url' => 'https://your-service.up.railway.app',
    'notify_secret' => 'same-value-as-SHOP_NOTIFY_SECRET',
    // Public bot origin. Product photos from posts are loaded from /media/file/<id>.
    'media_base' => 'https://your-service.up.railway.app',
    // Used in password-reset links. Empty means the current host.
    'public_url' => 'https://shop.example.com',
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'starttls',
        'user' => 'shop@example.com',
        'password' => 'smtp-password',
        'from' => 'shop@example.com',
        'from_name' => 'مولوی‌سل',
    ],
];
