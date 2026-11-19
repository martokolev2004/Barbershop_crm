<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

use BarberLead\Repositories\EventRepository;
use BarberLead\Repositories\SettingsRepository;
use PDO;
use PDOException;

/**
 * Runs scan jobs in short slices (shared hosting: ~40 s per PHP request/cron run).
 * - A job is claimed atomically (lock_token + locked_until), so the browser and cron never run it twice.
 * - Progress lives in jobs.state; an interrupted slice simply resumes from the last finished step.
 * - Temporary source errors are retried 3 times with back-off; permanent ones are reported, never hidden.
 * - "done" = every configured source was processed successfully. It does NOT mean every real business was found.
 */
class JobRunner
{
    public const LOCK_SECONDS = 150;
    public const MAX_ATTEMPTS = 3;
    private const BACKOFF = [60, 300, 900];

    private string $token;

    public function __construct(
        private PDO $db,
        private EventRepository $events,
        private SettingsRepository $settings,
        private HttpClient $http,
    ) {
        $this->token = bin2hex(random_bytes(16));
    }

    /**
     * Queues a neighborhood scan. Returns [job id, created?]; when a scan for the area is already active, its id.
     * @param list<string> $sources
     * @return array{0:int, 1:bool}
     */
    public function queueScan(int $areaId, array $sources, string $requestedBy): array
    {
        $sources = array_values(array_intersect(['osm', 'google'], $sources));
        try {
            $this->db->prepare("INSERT INTO jobs (type, area_id, params, requested_by, progress) VALUES ('scan', ?, ?, ?, 'На опашка')")
                ->execute([$areaId, json_encode(['sources' => $sources]), $requestedBy]);
            return [(int) $this->db->lastInsertId(), true];
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $stmt = $this->db->prepare("SELECT id FROM jobs WHERE type = 'scan' AND area_id = ? AND status IN ('queued','running')");
            $stmt->execute([$areaId]);
            return [(int) $stmt->fetchColumn(), false];
        }
    }

    public function cancel(int $jobId): bool
    {
        $stmt = $this->db->prepare("UPDATE jobs SET status = 'cancelled', finished_at = NOW(), progress = 'Отказано', lock_token = NULL, locked_until = NULL
                                    WHERE id = ? AND status IN ('queued','running')");
        $stmt->execute([$jobId]);
        return $stmt->rowCount() === 1;
    }

    /** Claims a specific job, or the oldest runnable one. @return array<string,mixed>|null */
    public function claim(?int $jobId = null): ?array
    {
        $sql = "SELECT id FROM jobs WHERE status IN ('queued','running') AND (locked_until IS NULL OR locked_until < NOW())
                AND (run_after IS NULL OR run_after <= NOW())";
        $ids = $jobId !== null
            ? $this->db->query($sql . ' AND id = ' . $jobId)->fetchAll(PDO::FETCH_COLUMN)
            : $this->db->query($sql . ' ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $stmt = $this->db->prepare(
                "UPDATE jobs SET lock_token = ?, locked_until = NOW() + INTERVAL " . self::LOCK_SECONDS . " SECOND, heartbeat_at = NOW(),
                        status = 'running', started_at = COALESCE(started_at, NOW())
                 WHERE id = ? AND status IN ('queued','running') AND (locked_until IS NULL OR locked_until < NOW())
                   AND (run_after IS NULL OR run_after <= NOW())"
            );
            $stmt->execute([$this->token, $id]);
            if ($stmt->rowCount() === 1) {
                return $this->find((int) $id);
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Works on a claimed job until it finishes or the time budget runs out.
     * @param array<string,mixed> $job
     * @return array<string,mixed> the job row afterwards
     */
    public function runSlice(array $job, float $budgetSeconds): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $state = json_decode((string) ($job['state'] ?? ''), true) ?: $this->initialState($job);
        $id = (int) $job['id'];

        while ($state['i'] < count($state['steps'])) {
            if (microtime(true) > $deadline) {
                $this->release($id, $state, null);
                return (array) $this->find($id);
            }
            if ($this->find($id)['status'] === 'cancelled') {
                return (array) $this->find($id);
            }
            $step = $state['steps'][$state['i']];
            $this->heartbeat($id, $this->stepLabel($step, $state));
            $result = $this->runStep($step, $state, $id);
            if ($result === 'retry') {
                $n = $state['attempts'][$step] ?? 0;
                $this->release($id, $state, date('Y-m-d H:i:s', time() + self::BACKOFF[min($n - 1, 2)]));
                return (array) $this->find($id);
            }
            if ($result === 'continue') {
                $this->saveState($id, $state);
                continue; // same step, next page
            }
            $state['i']++;
            $this->saveState($id, $state);
        }
        return $this->finish($id, $state);
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function initialState(array $job): array
    {
        $params = json_decode((string) $job['params'], true) ?: [];
        $sources = $params['sources'] ?? ['osm'];
        $steps = ['prepare'];
        foreach ($sources as $s) {
            $steps[] = 'source:' . $s;
        }
        $steps[] = 'finalize';
        return ['steps' => $steps, 'i' => 0, 'attempts' => [], 'sources' => array_fill_keys($sources, 'pending'),
            'touched' => [], 'notes' => [], 'google' => ['q' => 0, 'token' => null], 'counts' => ['found' => 0, 'new' => 0, 'updated' => 0, 'review' => 0],
            'seen' => []];
    }

    /**
     * @param array<string,mixed> $state
     * @return string 'next' | 'continue' | 'retry'
     */
    private function runStep(string $step, array &$state, int $jobId): string
    {
        $job = (array) $this->find($jobId);
        $areaId = (int) $job['area_id'];
        if ($step === 'prepare') {
            return $this->prepare($areaId, $state);
        }
        if ($step === 'finalize') {
            return 'next';
        }
        $source = substr($step, 7);
        if (!isset($state['scan'])) {
            return 'next';
        }
        if ($source === 'osm') {
            $res = (new OverpassSource($this->http))->search($state['scan']['bbox']);
            return $this->handleResult('osm', $res, $state, $areaId, true);
        }
        if ($source === 'google') {
            $key = (string) $this->settings->get('google_api_key', '');
            if ($key === '') {
                $state['sources']['google'] = 'skipped: няма въведен Google API ключ (Настройки)';
                return 'next';
            }
            if (!$this->googleBudgetLeft()) {
                $state['sources']['google'] = 'failed: достигнат е месечният лимит на заявки (Настройки)';
                return 'next';
            }
            $q = $state['google']['q'];
            if ($q >= count(GooglePlacesSource::QUERIES)) {
                $state['sources']['google'] = 'ok';
                return 'next';
            }
            [$res, $next] = (new GooglePlacesSource($this->http, $key))->searchPage($state['scan']['bbox'], GooglePlacesSource::QUERIES[$q], $state['google']['token']);
            $this->countGoogleRequest();
            $outcome = $this->handleResult('google', $res, $state, $areaId, false);
            if ($outcome !== 'next' || !$res->ok()) {
                return $outcome;
            }
            $pages = ($state['google']['pages'] ?? 0) + 1;
            if ($next !== null && $pages < GooglePlacesSource::MAX_PAGES) {
                $state['google'] = ['q' => $q, 'token' => $next, 'pages' => $pages];
            } else {
                $state['google'] = ['q' => $q + 1, 'token' => null, 'pages' => 0];
            }
            if ($state['google']['q'] < count(GooglePlacesSource::QUERIES)) {
                return 'continue';
            }
            $state['sources']['google'] = 'ok';
            return 'next';
        }
        $state['sources'][$source] = 'failed: непознат източник';
        return 'next';
    }

    /** Works out what to search: the area's own OSM polygon, else its district's, else fail honestly. */
    private function prepare(int $areaId, array &$state): string
    {
        $area = $this->area($areaId);
        if ($area === null) {
            $state['notes'][] = 'Кварталът не съществува.';
            return 'next';
        }
        $cityId = $this->cityOf($area);
        if ($area['geometry'] === null && $cityId !== null && !$this->settings->get("boundaries_city_$cityId")) {
            $importer = new BoundaryImporter($this->db, $this->http);
            foreach (['districts', 'neighborhoods'] as $part) {
                $r = $importer->run($cityId, $part);
                if (!$r['ok']) {
                    $n = ($state['attempts']['prepare'] ?? 0) + 1;
                    $state['attempts']['prepare'] = $n;
                    if ($r['transient'] && $n < self::MAX_ATTEMPTS) {
                        return 'retry';
                    }
                    $state['notes'][] = 'Границите от OpenStreetMap не се заредиха: ' . $r['message'];
                    break;
                }
                $state['notes'][] = $r['message'];
            }
            if (!array_filter($state['notes'], fn ($n) => str_contains($n, 'не се заредиха'))) {
                $this->settings->set("boundaries_city_$cityId", date('c'));
            }
            $area = $this->area($areaId);
        }
        $geomArea = $area;
        while ($geomArea !== null && $geomArea['geometry'] === null && $geomArea['type'] === 'neighborhood') {
            $geomArea = $this->area((int) $geomArea['parent_id']);
        }
        if ($geomArea === null || $geomArea['geometry'] === null) {
            foreach (array_keys($state['sources']) as $s) {
                $state['sources'][$s] = 'failed: няма граници на района в OpenStreetMap, затова не може да се сканира';
            }
            return 'next';
        }
        if ((int) $geomArea['id'] !== $areaId) {
            $state['notes'][] = 'Кварталът няма собствени граници в OpenStreetMap; сканиран е целият район ' . $geomArea['name_bg'] . '.';
        }
        $rings = json_decode((string) $geomArea['geometry'], true);
        $state['scan'] = ['area_id' => (int) $geomArea['id'], 'name' => $geomArea['name_bg'], 'bbox' => Geo::bbox($rings), 'rings' => $rings];
        return 'next';
    }

    /** @param array<string,mixed> $state */
    private function handleResult(string $source, SourceResult $res, array &$state, int $areaId, bool $markOk): string
    {
        if (!$res->ok()) {
            $n = ($state['attempts']['source:' . $source] ?? 0) + 1;
            $state['attempts']['source:' . $source] = $n;
            if ($res->transient && $n < self::MAX_ATTEMPTS) {
                $state['notes'][] = "$res->error (опит $n, ще опитам пак)";
                return 'retry';
            }
            $state['sources'][$source] = 'failed: ' . $res->error;
            return 'next';
        }
        $locator = new AreaLocator($this->db);
        $importer = new Importer($this->db, $this->events, $locator);
        $context = 'сканиране на ' . $state['scan']['name'];
        foreach ($res->candidates as $c) {
            if ($c->lat !== null && $c->lng !== null && !Geo::contains($state['scan']['rings'], $c->lat, $c->lng)) {
                continue; // outside the scanned polygon: belongs to a neighbouring area
            }
            $seenKey = $c->source . ':' . $c->sourceId;
            if (isset($state['seen'][$seenKey])) {
                continue; // same place returned by several Google queries
            }
            $state['seen'][$seenKey] = 1;
            $state['counts']['found']++;
            $r = $importer->import($c, $state['scan']['area_id'], $context);
            match ($r['action']) {
                'new' => $state['counts']['new']++,
                'review' => $state['counts']['review']++,
                'updated' => $state['counts']['updated']++,
                default => null,
            };
            $state['touched'][$r['business_id']] = 1;
        }
        if ($markOk) {
            $state['sources'][$source] = 'ok';
        }
        return 'next';
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function finish(int $id, array $state): array
    {
        $statuses = array_values($state['sources']);
        $ok = count(array_filter($statuses, fn ($s) => $s === 'ok'));
        $failed = count(array_filter($statuses, fn ($s) => str_starts_with((string) $s, 'failed')));
        $status = $failed === 0 && $ok > 0 ? 'done' : ($ok > 0 ? 'partial' : 'failed');

        // Every source answered (incl. Google, which reliably lists websites) and none listed a website:
        // "no website discovered" – still not proof that none exists; Phase 3 checks further.
        if ($status === 'done' && ($state['sources']['google'] ?? null) === 'ok' && $state['touched']) {
            $ids = implode(',', array_map('intval', array_keys($state['touched'])));
            $this->db->exec("UPDATE businesses SET website_status = 'none' WHERE id IN ($ids) AND website_url IS NULL AND website_status = 'unknown'");
        }
        $errors = [];
        foreach ($state['sources'] as $src => $s) {
            if ($s !== 'ok') {
                $errors[] = Importer::sourceLabel($src) . ': ' . preg_replace('/^(failed|skipped): /', '', (string) $s);
            }
        }
        $c = $state['counts'];
        $progress = sprintf('Готово: %d намерени, %d нови, %d обновени, %d за преглед', $c['found'], $c['new'], $c['updated'], $c['review']);
        unset($state['seen']);
        $this->db->prepare(
            'UPDATE jobs SET status = ?, finished_at = NOW(), progress = ?, found_count = ?, new_count = ?, updated_count = ?, review_count = ?,
                    error = ?, log = ?, state = ?, lock_token = NULL, locked_until = NULL WHERE id = ?'
        )->execute([$status, $progress, $c['found'], $c['new'], $c['updated'], $c['review'],
            $errors ? implode("\n", $errors) : null, implode("\n", $state['notes']) ?: null, json_encode($state), $id]);
        return (array) $this->find($id);
    }

    /** @param array<string,mixed> $state */
    private function release(int $id, array $state, ?string $runAfter): void
    {
        $this->db->prepare('UPDATE jobs SET state = ?, lock_token = NULL, locked_until = NULL, run_after = ?, heartbeat_at = NOW(),
                found_count = ?, new_count = ?, updated_count = ?, review_count = ?, log = ? WHERE id = ? AND lock_token = ?')
            ->execute([json_encode($state), $runAfter, $state['counts']['found'], $state['counts']['new'], $state['counts']['updated'],
                $state['counts']['review'], implode("\n", $state['notes']) ?: null, $id, $this->token]);
    }

    /** @param array<string,mixed> $state */
    private function saveState(int $id, array $state): void
    {
        $this->db->prepare('UPDATE jobs SET state = ?, found_count = ?, new_count = ?, updated_count = ?, review_count = ? WHERE id = ? AND lock_token = ?')
            ->execute([json_encode($state), $state['counts']['found'], $state['counts']['new'], $state['counts']['updated'], $state['counts']['review'], $id, $this->token]);
    }

    private function heartbeat(int $id, string $progress): void
    {
        $this->db->prepare('UPDATE jobs SET heartbeat_at = NOW(), locked_until = NOW() + INTERVAL ' . self::LOCK_SECONDS . ' SECOND, progress = ?
                            WHERE id = ? AND lock_token = ?')->execute([$progress, $id, $this->token]);
    }

    /** @param array<string,mixed> $state */
    private function stepLabel(string $step, array $state): string
    {
        return match (true) {
            $step === 'prepare' => 'Подготовка: граници на района',
            $step === 'source:osm' => 'OpenStreetMap…',
            $step === 'source:google' => sprintf('Google Places: търсене %d от %d', min($state['google']['q'] + 1, count(GooglePlacesSource::QUERIES)), count(GooglePlacesSource::QUERIES)),
            default => 'Приключване…',
        };
    }

    /** @return array<string,mixed>|null */
    private function area(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM areas WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    /** @param array<string,mixed> $area */
    private function cityOf(array $area): ?int
    {
        for ($a = $area, $i = 0; $a !== null && $i < 5; $i++) {
            if ($a['type'] === 'city') {
                return (int) $a['id'];
            }
            $a = $a['parent_id'] !== null ? $this->area((int) $a['parent_id']) : null;
        }
        return null;
    }

    public function googleUsageThisMonth(): int
    {
        return (int) $this->settings->get('google_usage_' . date('Ym'), '0');
    }

    private function googleBudgetLeft(): bool
    {
        return $this->googleUsageThisMonth() < (int) $this->settings->get('google_monthly_cap', '900');
    }

    private function countGoogleRequest(): void
    {
        $this->db->prepare('INSERT INTO settings (name, value) VALUES (?, 1) ON DUPLICATE KEY UPDATE value = value + 1')
            ->execute(['google_usage_' . date('Ym')]);
    }

    /** Queues scans whose schedule is due. @return int number queued */
    public function enqueueDueSchedules(): int
    {
        $n = 0;
        foreach ($this->db->query('SELECT area_id, interval_days FROM scan_schedules WHERE enabled = 1 AND next_run_at <= NOW()')->fetchAll() as $s) {
            $sources = $this->settings->get('google_api_key') ? ['osm', 'google'] : ['osm'];
            [, $created] = $this->queueScan((int) $s['area_id'], $sources, 'график');
            $this->db->prepare('UPDATE scan_schedules SET next_run_at = NOW() + INTERVAL ? DAY WHERE area_id = ?')
                ->execute([(int) $s['interval_days'], (int) $s['area_id']]);
            $n += $created ? 1 : 0;
        }
        return $n;
    }

    /** Google terms: keep only the place id long-term. Clears other Google fields older than 30 days. */
    public function purgeOldGoogleData(): int
    {
        return (int) $this->db->exec("UPDATE business_sources SET data = '{\"purged\":true}' WHERE source = 'google' AND last_seen_at < NOW() - INTERVAL 30 DAY AND data <> '{\"purged\":true}'");
    }
}
