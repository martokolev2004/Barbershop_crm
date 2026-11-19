<?php

declare(strict_types=1);

use BarberLead\App;
use BarberLead\Database;
use BarberLead\Http\Request;
use BarberLead\Http\Response;
use BarberLead\Http\Session;
use BarberLead\Installer;

require dirname(__DIR__) . '/app/bootstrap.php';

$configFile = BL_ROOT . '/config/config.php';
$config = is_file($configFile) ? require $configFile : null;
$dev = is_array($config) && (($config['app']['env'] ?? 'production') === 'development');
ini_set('display_errors', $dev ? '1' : '0');
error_reporting(E_ALL);

$secure = is_array($config) ? (bool) ($config['app']['secure_cookies'] ?? true) : !empty($_SERVER['HTTPS']);
Session::start($secure, BL_ROOT . '/storage/sessions');
$request = Request::fromGlobals();

if (!is_array($config)) {
    (new Installer(BL_ROOT, new Session()))->handle($request)->send();
    exit;
}

try {
    $db = Database::connect($config['db']);
    $app = new App($config, $db, new Session());
    $app->handle($request)->send();
} catch (Throwable $e) {
    $id = bin2hex(random_bytes(4));
    @file_put_contents(
        BL_ROOT . '/storage/logs/app.log',
        sprintf("[%s] %s %s %s: %s in %s:%d\n", date('c'), $id, $request->method, $request->path, $e->getMessage(), $e->getFile(), $e->getLine()),
        FILE_APPEND | LOCK_EX
    );
    $msg = $dev ? e($e->getMessage()) : 'Нещо се обърка. Код на грешката: ' . $id;
    (new Response('<!DOCTYPE html><meta charset="utf-8"><title>Грешка</title><link rel="stylesheet" href="/assets/app.css"><main class="auth-box"><h2>Грешка</h2><p>' . $msg . '</p><p><a href="/">Към началото</a></p></main>', 500))->send();
}
