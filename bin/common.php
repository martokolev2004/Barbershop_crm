<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

/** @return array<string,mixed> */
function bl_config(): array
{
    $file = BL_ROOT . '/config/config.php';
    if (!is_file($file)) {
        fwrite(STDERR, "config/config.php is missing. Copy config/config.example.php or run the web installer.\n");
        exit(1);
    }
    return require $file;
}

/** @param array<string,mixed> $config */
function bl_backup_dir(array $config): string
{
    $dir = (string) ($config['backup_dir'] ?? 'storage/backups');
    return str_starts_with($dir, '/') ? $dir : BL_ROOT . '/' . $dir;
}
