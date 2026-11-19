<?php
// Router for PHP's built-in server (local development only):  php -S 127.0.0.1:8099 -t public bin/dev-router.php
$file = __DIR__ . '/../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($file)) {
    return false;
}
require __DIR__ . '/../public/index.php';
