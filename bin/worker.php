<?php
/**
 * Background worker for cron (every 5 minutes):  php bin/worker.php
 * Queues scheduled scans, works on jobs for at most ~45 s, then housekeeping.
 * Jobs are locked in the database, so overlapping runs (or an open browser tab) never process the same job twice.
 */
declare(strict_types=1);

require __DIR__ . '/common.php';

use BarberLead\Database;
use BarberLead\Discovery\HttpClient;
use BarberLead\Discovery\JobRunner;
use BarberLead\Migrator;
use BarberLead\Repositories\EventRepository;
use BarberLead\Repositories\SettingsRepository;

$config = bl_config();
$db = Database::connect($config['db']);
if ((new Migrator($db, BL_ROOT . '/migrations'))->pending()) {
    echo "Database update pending (Settings → Обнови базата); worker idle.\n";
    exit(0);
}
$settings = new SettingsRepository($db);
$settings->set('worker_last_seen', date('Y-m-d H:i:s'));
$runner = new JobRunner($db, new EventRepository($db), $settings, new HttpClient());

$queued = $runner->enqueueDueSchedules();
$deadline = microtime(true) + 45;
$worked = 0;
while (microtime(true) < $deadline - 5 && ($job = $runner->claim()) !== null) {
    $job = $runner->runSlice($job, max(5, $deadline - microtime(true)));
    $worked++;
    echo "job #{$job['id']}: {$job['status']} – {$job['progress']}\n";
}
$purged = $runner->purgeOldGoogleData();
$db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
echo "queued $queued scheduled scan(s), worked on $worked job slice(s), purged $purged old Google record(s)\n";
