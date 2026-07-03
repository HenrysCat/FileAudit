<?php

return [
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'fileaudit',
    'DB_USER' => 'fileaudit_user',
    'DB_PASS' => 'change_me',

    'APP_NAME' => 'FileAudit',
    'APP_TIMEZONE' => 'Europe/London',

    'ADMIN_USERNAME' => 'admin',
    'ADMIN_PASSWORD_HASH' => '$2y$10$replace_this_with_a_password_hash',

    'API_TOKEN' => 'replace_this_with_a_long_random_token',

    // Leave empty to allow collectors from any IP.
    'TRUSTED_COLLECTOR_IPS' => [
        // '192.0.2.10',
    ],
];
