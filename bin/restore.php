<?php
/** php bin/restore.php storage/backups/barberlead-XXXX.sql.gz --yes   (takes a safety backup first) */
declare(strict_types=1);

require __DIR__ . '/common.php';

use BarberLead\Backup;
use BarberLead\Database;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file) || !in_array('--yes', $argv, true)) {
    fwrite(STDERR, "Usage: php bin/restore.php <backup.sql.gz> --yes\nThis REPLACES all current data (a safety backup is taken first).\n");
    exit(1);
}
$config = bl_config();
$backup = new Backup(Database::connect($config['db']), bl_backup_dir($config));
echo 'Safety backup: ' . $backup->create('pre-restore') . PHP_EOL;
$backup->restore($file);
echo "Restored from $file\n";
