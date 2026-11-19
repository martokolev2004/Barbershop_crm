<?php

declare(strict_types=1);

// Self-contained PSR-4 autoloader: production on shared hosting needs no vendor/ directory.
spl_autoload_register(static function (string $class): void {
    $prefix = 'BarberLead\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

date_default_timezone_set('Europe/Sofia');
mb_internal_encoding('UTF-8');

define('BL_ROOT', dirname(__DIR__));
require __DIR__ . '/Support/helpers.php';
