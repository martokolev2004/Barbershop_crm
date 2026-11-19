<?php
/**
 * Usage:
 *   php bin/migrate.php status
 *   php bin/migrate.php up               (backs up first when data exists)
 *   php bin/migrate.php down [--force]   (backs up first; refuses data-dropping rollbacks without --force)
 */
declare(strict_types=1);

require __DIR__ . '/common.php';

use BarberLead\Backup;
use BarberLead\Database;
use BarberLead\Migrator;

$config = bl_config();
$db = Database::connect($config['db']);
$m = new Migrator($db, BL_ROOT . '/migrations');
$cmd = $argv[1] ?? 'status';

switch ($cmd) {
    case 'status':
        $applied = $m->applied();
        foreach ($m->available() as $v) {
            echo (in_array($v, $applied, true) ? '[x] ' : '[ ] ') . $v . PHP_EOL;
        }
        break;
    case 'up':
        if (!$m->pending()) {
            echo "Nothing to migrate.\n";
            break;
        }
        if ($m->applied()) {
            echo 'Backup: ' . (new Backup($db, bl_backup_dir($config)))->create('pre-migrate') . PHP_EOL;
        }
        foreach ($m->migrate() as $v) {
            echo "Applied $v\n";
        }
        break;
    case 'down':
        echo 'Backup: ' . (new Backup($db, bl_backup_dir($config)))->create('pre-rollback') . PHP_EOL;
        $v = $m->rollback(in_array('--force', $argv, true));
        echo $v ? "Rolled back $v\n" : "Nothing to roll back.\n";
        break;
    default:
        fwrite(STDERR, "Unknown command $cmd\n");
        exit(1);
}
