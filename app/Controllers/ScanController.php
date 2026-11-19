<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Discovery\HttpClient;
use BarberLead\Discovery\JobRunner;
use BarberLead\Http\Request;
use BarberLead\Http\Response;

final class ScanController
{
    public function __construct(private App $app)
    {
    }

    private function runner(): JobRunner
    {
        return new JobRunner($this->app->db, $this->app->events, $this->app->settings, new HttpClient());
    }

    public function start(Request $req, int $areaId): Response
    {
        $area = $this->app->areas->find($areaId);
        if ($area === null || $area['type'] === 'country') {
            return $this->app->error(404, 'Няма такъв квартал.');
        }
        $sources = [];
        if ($req->str('osm') === '1') {
            $sources[] = 'osm';
        }
        if ($req->str('google') === '1' && $this->app->settings->get('google_api_key')) {
            $sources[] = 'google';
        }
        if (!$sources) {
            $this->app->session->flash('err', 'Избери поне един източник.');
            return Response::redirect(url('/businesses', ['area' => $areaId]));
        }
        [$jobId, $created] = $this->runner()->queueScan($areaId, $sources, $this->app->actor());
        $this->app->audit->record($this->app->actor(), 'scan_queued', 'area', $areaId, ['job' => $jobId, 'sources' => $sources], $req->ip);
        $this->app->session->flash($created ? 'ok' : 'err', $created ? 'Сканирането започна.' : 'За този квартал вече тече сканиране.');
        return Response::redirect(url('/businesses', ['area' => $areaId, 'job' => $jobId]));
    }

    /** Called repeatedly by the open page: runs one ~20 s slice (the same lock as cron, so never twice). */
    public function run(Request $req, int $jobId): Response
    {
        $runner = $this->runner();
        $job = $runner->claim($jobId);
        if ($job !== null) {
            @set_time_limit(60);
            $job = $runner->runSlice($job, 20);
        } else {
            $job = $runner->find($jobId);
        }
        if ($job === null) {
            return Response::json(['error' => 'Няма такава задача.'], 404);
        }
        return Response::json($this->jobJson($job));
    }

    public function status(Request $req, int $jobId): Response
    {
        $job = $this->runner()->find($jobId);
        return $job === null ? Response::json(['error' => 'Няма такава задача.'], 404) : Response::json($this->jobJson($job));
    }

    public function cancel(Request $req, int $jobId): Response
    {
        $this->runner()->cancel($jobId);
        $this->app->session->flash('ok', 'Задачата е спряна.');
        return Response::redirect('/jobs');
    }

    public function index(Request $req): Response
    {
        $jobs = $this->app->db->query(
            'SELECT j.*, a.name_bg AS area_name FROM jobs j LEFT JOIN areas a ON a.id = j.area_id ORDER BY j.id DESC LIMIT 100'
        )->fetchAll();
        return $this->app->page('jobs', [
            'title' => 'Сканирания',
            'jobs' => $jobs,
            'workerSeen' => $this->app->settings->get('worker_last_seen'),
            'googleUsage' => $this->runner()->googleUsageThisMonth(),
            'googleCap' => (int) $this->app->settings->get('google_monthly_cap', '900'),
            'hasGoogleKey' => (bool) $this->app->settings->get('google_api_key'),
        ]);
    }

    public function schedule(Request $req, int $areaId): Response
    {
        if ($this->app->areas->find($areaId) === null) {
            return $this->app->error(404, 'Няма такъв квартал.');
        }
        $days = $req->int('interval_days', 30);
        if ($req->str('enabled') === '1' && $days >= 7 && $days <= 365) {
            $this->app->db->prepare(
                'INSERT INTO scan_schedules (area_id, interval_days, next_run_at, enabled) VALUES (?, ?, NOW() + INTERVAL ? DAY, 1)
                 ON DUPLICATE KEY UPDATE interval_days = VALUES(interval_days), next_run_at = VALUES(next_run_at), enabled = 1'
            )->execute([$areaId, $days, $days]);
            $this->app->session->flash('ok', "Автоматично сканиране на всеки $days дни.");
        } else {
            $this->app->db->prepare('UPDATE scan_schedules SET enabled = 0 WHERE area_id = ?')->execute([$areaId]);
            $this->app->session->flash('ok', 'Автоматичното сканиране е изключено.');
        }
        return Response::redirect(url('/businesses', ['area' => $areaId]));
    }

    /** Turns automatic scanning on/off for every district of a city (districts cover their neighborhoods). */
    public function scheduleAll(Request $req, int $cityId): Response
    {
        $city = $this->app->areas->find($cityId);
        if ($city === null || $city['type'] !== 'city') {
            return $this->app->error(404, 'Няма такъв град.');
        }
        $days = $req->int('interval_days', 15);
        $districts = $this->app->db->prepare("SELECT id FROM areas WHERE parent_id = ? AND type = 'district' ORDER BY sort_order, id");
        $districts->execute([$cityId]);
        $ids = array_map('intval', $districts->fetchAll(\PDO::FETCH_COLUMN));
        if (!$ids) {
            $this->app->session->flash('err', 'Този град още няма районите си. Те се зареждат от OpenStreetMap при първото сканиране.');
            return Response::redirect(url('/businesses', ['area' => $cityId]));
        }
        if ($req->str('enabled') === '1') {
            if ($days < 7 || $days > 365) {
                $this->app->session->flash('err', 'Интервалът трябва да е между 7 и 365 дни.');
                return Response::redirect(url('/businesses', ['area' => $cityId]));
            }
            $stmt = $this->app->db->prepare(
                'INSERT INTO scan_schedules (area_id, interval_days, next_run_at, enabled) VALUES (?, ?, NOW() + INTERVAL ? MINUTE, 1)
                 ON DUPLICATE KEY UPDATE interval_days = VALUES(interval_days), enabled = 1,
                    next_run_at = IF(enabled = 1, LEAST(next_run_at, NOW() + INTERVAL VALUES(interval_days) DAY), VALUES(next_run_at))'
            );
            foreach ($ids as $i => $id) {
                $stmt->execute([$id, $days, $i * 5]);
            }
            $this->app->session->flash('ok', count($ids) . " района ще се сканират автоматично на всеки $days дни.");
        } else {
            $this->app->db->exec('UPDATE scan_schedules SET enabled = 0 WHERE area_id IN (' . implode(',', $ids) . ')');
            $this->app->session->flash('ok', 'Автоматичното сканиране на града е изключено.');
        }
        $this->app->audit->record($this->app->actor(), 'schedule_city', 'area', $cityId, ['days' => $days, 'enabled' => $req->str('enabled')], $req->ip);
        return Response::redirect(url('/businesses', ['area' => $cityId]));
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function jobJson(array $job): array
    {
        return [
            'id' => (int) $job['id'],
            'status' => $job['status'],
            'progress' => $job['progress'],
            'found' => (int) $job['found_count'],
            'new' => (int) $job['new_count'],
            'updated' => (int) $job['updated_count'],
            'review' => (int) $job['review_count'],
            'error' => $job['error'],
            'log' => $job['log'],
            'run_after' => $job['run_after'],
            'final' => in_array($job['status'], ['done', 'partial', 'failed', 'cancelled'], true),
        ];
    }
}
