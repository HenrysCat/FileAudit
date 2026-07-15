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

    // DNS query records are retained independently from file audit events.
    'DNS_LOG_RETENTION_DAYS' => 7,
    // Keep at most one query per computer IP and domain during this interval.
    'DNS_LOG_DEDUPLICATE_MINUTES' => 1,
];
