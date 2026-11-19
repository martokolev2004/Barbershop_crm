<?php
/** Nightly backup for cron: php bin/backup.php [keep=30] */
declare(strict_types=1);

require __DIR__ . '/common.php';

use BarberLead\Backup;
use BarberLead\Database;

$config = bl_config();
$backup = new Backup(Database::connect($config['db']), bl_backup_dir($config));
$file = $backup->create('nightly');
$removed = $backup->prune((int) ($argv[1] ?? 30));
echo "Backup written: $file (pruned $removed old)\n";
