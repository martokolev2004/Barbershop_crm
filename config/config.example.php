<?php
// Copy to config/config.php (or let the web installer at /setup create it).
// config/config.php is git-ignored: it holds the database password.
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'martest_barberlead',
        'user' => 'martest_barberlead',
        'pass' => 'change-me',
    ],
    'app' => [
        'env' => 'production',              // 'development' shows PHP errors
        'base_url' => 'https://crm.testvane.org',
        'secure_cookies' => true,           // false only for local http development
    ],
    // Outside the web root. Relative paths are relative to the project root.
    'backup_dir' => 'storage/backups',
];
