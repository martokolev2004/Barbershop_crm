<?php

declare(strict_types=1);

namespace BarberLead\Tests\Discovery;

use BarberLead\Discovery\JobRunner;
use BarberLead\Discovery\Merger;
use BarberLead\Repositories\EventRepository;
use BarberLead\Repositories\SettingsRepository;
use BarberLead\Tests\FakeHttp;
use BarberLead\Tests\Fixtures;
use BarberLead\Tests\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** End-to-end scan of the FIXTURE "Лозенец" with fake OSM/Google responses against a real MariaDB. */
final class ScanTest extends TestCase
{
    private PDO $db;
    private FakeHttp $http;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $this->db = TestDatabase::fresh();
        $this->http = new FakeHttp();
        $this->settings = new SettingsRepository($this->db);
    }

    private function runner(): JobRunner
    {
        return new JobRunner($this->db, new EventRepository($this->db), $this->settings, $this->http);
    }

    private function areaId(string $slug, string $type): int
    {
        $stmt = $this->db->prepare('SELECT id FROM areas WHERE slug = ? AND type = ?');
        $stmt->execute([$slug, $type]);
        return (int) $stmt->fetchColumn();
    }

    private function pushBoundaries(): void
    {
        $this->http->push('overpass-api.de', 200, Fixtures::districtsJson())->push('overpass-api.de', 200, Fixtures::neighborhoodsJson());
    }

    /** @return array<string,mixed> */
    private function runToEnd(int $jobId): array
    {
        $r = $this->runner();
        for ($i = 0; $i < 20; $i++) {
            $job = $r->claim($jobId);
            if ($job === null) {
                break;
            }
            $job = $r->runSlice($job, 30);
            if (!in_array($job['status'], ['queued', 'running'], true)) {
                return $job;
            }
        }
        return (array) $r->find($jobId);
    }

    private function countRows(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }

    public function testOsmScanImportsBoundariesAndBusinesses(): void
    {
        $lozenets = $this->areaId('lozenets', 'district');
        $this->pushBoundaries();
        $this->http->push('overpass-api.de', 200, Fixtures::overpassBusinessesJson());
        [$jobId, $created] = $this->runner()->queueScan($lozenets, ['osm'], 'tester');
        $this->assertTrue($created);

        $job = $this->runToEnd($jobId);
        $this->assertSame('done', $job['status'], (string) $job['error']);
        $this->assertSame(3, (int) $job['found_count'], 'the Триадица barber is outside the polygon; unnamed skipped');
        $this->assertSame(3, (int) $job['new_count']);

        // boundaries: geometry stored, Хладилника moved to Триадица, new neighborhood added
        $this->assertSame('verified', $this->db->query("SELECT geo_confidence FROM areas WHERE id = $lozenets")->fetchColumn());
        $hl = $this->db->query("SELECT p.slug FROM areas a JOIN areas p ON p.id = a.parent_id WHERE a.slug = 'hladilnika'")->fetchColumn();
        $this->assertSame('triaditsa', $hl);
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM areas WHERE name_bg = 'Фикстура Парк' AND geometry IS NOT NULL"));

        // businesses: located in the most specific polygon, relevance classified
        $one = $this->db->query("SELECT b.*, a.name_bg area FROM businesses b JOIN areas a ON a.id = b.area_id WHERE b.name = 'FIXTURE Barber One'")->fetch();
        $this->assertSame('Лозенец', $one['area']);
        $this->assertSame('neighborhood', $this->db->query('SELECT type FROM areas WHERE id = ' . (int) $one['area_id'])->fetchColumn());
        $this->assertSame('high', $one['relevance']);
        $this->assertSame('verified', $one['geo_confidence']);
        $this->assertSame('+359888100001', $one['phone_normalized']);
        $this->assertSame('unknown', $one['website_status'], 'OSM alone does not prove there is no website');
        $salon = $this->db->query("SELECT b.relevance, a.name_bg FROM businesses b JOIN areas a ON a.id = b.area_id WHERE b.name = 'FIXTURE Салон Красота'")->fetch();
        $this->assertSame(['relevance' => 'medium', 'name_bg' => 'Фикстура Парк'], $salon);
        $this->assertSame('low', $this->db->query("SELECT relevance FROM businesses WHERE name = 'FIXTURE Дамски салон'")->fetchColumn());
        $this->assertSame(3, $this->countRows("SELECT COUNT(*) FROM business_sources WHERE source = 'osm'"));
    }

    public function testRescanIsIdempotent(): void
    {
        $lozenets = $this->areaId('lozenets', 'district');
        $this->pushBoundaries();
        $this->http->push('overpass-api.de', 200, Fixtures::overpassBusinessesJson());
        $this->runToEnd($this->runner()->queueScan($lozenets, ['osm'], 't')[0]);
        $before = $this->countRows('SELECT COUNT(*) FROM businesses');

        $this->http->push('overpass-api.de', 200, Fixtures::overpassBusinessesJson()); // boundaries are not fetched again
        $job = $this->runToEnd($this->runner()->queueScan($lozenets, ['osm'], 't')[0]);
        $this->assertSame('done', $job['status']);
        $this->assertSame(0, (int) $job['new_count']);
        $this->assertSame($before, $this->countRows('SELECT COUNT(*) FROM businesses'));
        $this->assertCount(4, $this->http->calls, '2 boundary + 2 business queries');
    }

    public function testGoogleAttachesToExistingAndQueuesUncertainOnesForReview(): void
    {
        $this->settings->set('google_api_key', 'FIXTURE-KEY-0123456789abcdefghijklmnop');
        $lozenets = $this->areaId('lozenets', 'district');
        $this->pushBoundaries();
        $this->http->push('overpass-api.de', 200, Fixtures::overpassBusinessesJson());
        for ($i = 0; $i < 6; $i++) {
            $this->http->push('places.googleapis.com', 200, Fixtures::googleJson());
        }
        $job = $this->runToEnd($this->runner()->queueScan($lozenets, ['osm', 'google'], 't')[0]);
        $this->assertSame('done', $job['status'], (string) $job['error']);
        $this->assertCount(6, array_filter($this->http->calls, fn ($c) => str_contains($c['url'], 'googleapis')), 'one call per query, no next page');
        $this->assertSame('6', $this->settings->get('google_usage_' . date('Ym')));

        $one = $this->db->query("SELECT * FROM businesses WHERE name = 'FIXTURE Barber One'")->fetch();
        $this->assertSame('4.9', (string) $one['rating']);
        $this->assertSame('https://www.instagram.com/fixturebarberone', $one['instagram_url']);
        $this->assertSame(2, $this->countRows('SELECT COUNT(*) FROM business_sources WHERE business_id = ' . (int) $one['id']), 'OSM + Google on one record');
        $this->assertSame('none', $one['website_status'], 'no website in OSM nor Google');

        $this->assertSame('https://gents-fixture.example/', $this->db->query("SELECT website_url FROM businesses WHERE name = 'FIXTURE Gents Club'")->fetchColumn());
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM duplicate_candidates WHERE status = 'open'"), 'Barber One Studio needs review');
        $this->assertSame(1, (int) $job['review_count']);
    }

    public function testGoogleMonthlyCapStopsRequests(): void
    {
        $this->settings->set('google_api_key', 'FIXTURE-KEY-0123456789abcdefghijklmnop');
        $this->settings->set('google_monthly_cap', '2');
        $this->settings->set('google_usage_' . date('Ym'), '2');
        $lozenets = $this->areaId('lozenets', 'district');
        $this->pushBoundaries();
        $this->http->push('overpass-api.de', 200, Fixtures::overpassBusinessesJson());
        $job = $this->runToEnd($this->runner()->queueScan($lozenets, ['osm', 'google'], 't')[0]);
        $this->assertSame('partial', $job['status']);
        $this->assertStringContainsString('месечният лимит', (string) $job['error']);
        $this->assertCount(0, array_filter($this->http->calls, fn ($c) => str_contains($c['url'], 'googleapis')));
    }

    public function testTransientFailureIsRetriedThenReportedNotHidden(): void
    {
        $lozenets = $this->areaId('lozenets', 'district');
        $this->pushBoundaries();
        $this->http->push('overpass-api.de', 429, 'rate limited');
        $r = $this->runner();
        [$jobId] = $r->queueScan($lozenets, ['osm'], 't');
        $job = $r->runSlice((array) $r->claim($jobId), 30);
        $this->assertSame('running', $job['status']);
        $this->assertNotNull($job['run_after'], 'back-off scheduled');
        $this->assertNull($r->claim($jobId), 'not claimable before run_after');

        // pretend the back-off passed; every retry goes to the next mirror and fails too → reported as failed
        for ($i = 1; $i < JobRunner::MAX_ATTEMPTS; $i++) {
            $this->db->exec("UPDATE jobs SET run_after = NOW() - INTERVAL 1 SECOND WHERE id = $jobId");
            $this->http->push((string) parse_url(\BarberLead\Discovery\OverpassSource::endpoint($i), PHP_URL_HOST), 503, '');
            $job = $r->runSlice((array) $r->claim($jobId), 30);
        }
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('HTTP 503', (string) $job['error']);
        $hosts = array_map(fn ($c) => parse_url($c['url'], PHP_URL_HOST), array_slice($this->http->calls, 2));
        $this->assertSame(['overpass-api.de', 'overpass.kumi.systems', 'overpass.private.coffee', 'overpass-api.de'], $hosts, 'mirrors rotate');
        $this->assertSame(0, $this->countRows("SELECT COUNT(*) FROM businesses WHERE name LIKE 'FIXTURE%'"));
    }

    public function testNoOverlappingJobs(): void
    {
        $lozenets = $this->areaId('lozenets', 'district');
        [$a, $createdA] = $this->runner()->queueScan($lozenets, ['osm'], 't');
        [$b, $createdB] = $this->runner()->queueScan($lozenets, ['osm'], 't');
        $this->assertTrue($createdA);
        $this->assertFalse($createdB);
        $this->assertSame($a, $b, 'second request returns the active scan');

        $r1 = $this->runner();
        $r2 = $this->runner();
        $this->assertNotNull($r1->claim($a));
        $this->assertNull($r2->claim($a), 'a locked job cannot be claimed by another worker');
        $this->assertNull($r2->claim(), 'nor picked as "any job"');

        $this->assertTrue($r2->cancel($a));
        $this->assertSame('cancelled', $r1->find($a)['status']);
        [, $createdC] = $this->runner()->queueScan($lozenets, ['osm'], 't');
        $this->assertTrue($createdC, 'a new scan is allowed after the old one ended');
    }

    public function testNeighborhoodWithoutOwnBoundaryScansItsDistrictAndSaysSo(): void
    {
        $krastova = $this->areaId('krastova-vada', 'neighborhood'); // not in the fixture boundaries
        $this->pushBoundaries();
        $this->http->push('overpass-api.de', 200, Fixtures::overpassBusinessesJson());
        $job = $this->runToEnd($this->runner()->queueScan($krastova, ['osm'], 't')[0]);
        $this->assertSame('done', $job['status']);
        $this->assertStringContainsString('сканиран е целият район Лозенец', (string) $job['log']);
    }

    public function testScheduleQueuesDueScan(): void
    {
        $lozenets = $this->areaId('lozenets', 'district');
        $this->db->exec("INSERT INTO scan_schedules (area_id, interval_days, next_run_at) VALUES ($lozenets, 30, NOW() - INTERVAL 1 MINUTE)");
        $this->assertSame(1, $this->runner()->enqueueDueSchedules());
        $this->assertSame(0, $this->runner()->enqueueDueSchedules(), 'next run moved 30 days ahead');
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM jobs WHERE status = 'queued' AND requested_by = 'график'"));
    }

    public function testMergeMovesEverythingAndArchives(): void
    {
        $this->db->exec("INSERT INTO businesses (id, name, name_normalized, phone, phone_normalized) VALUES (500, 'FIXTURE A', 'a', '0888 1', '+3598881'), (501, 'FIXTURE B', 'b', NULL, NULL)");
        $this->db->exec("UPDATE businesses SET website_url = 'https://b.example', website_domain = 'b.example', notes = 'бележка B' WHERE id = 501");
        $this->db->exec("INSERT INTO offers (business_id, sent_at, channel) VALUES (501, NOW(), 'phone')");
        $this->db->exec("INSERT INTO business_sources (business_id, source, source_id) VALUES (501, 'osm', 'node/77')");
        $this->db->exec("INSERT INTO duplicate_candidates (business_id, other_id, score, reasons) VALUES (501, 500, 0.7, 'test')");

        (new Merger($this->db, new EventRepository($this->db)))->merge(500, 501, 'tester');

        $a = $this->db->query('SELECT * FROM businesses WHERE id = 500')->fetch();
        $this->assertSame('https://b.example', $a['website_url'], 'empty field filled from the merged record');
        $this->assertStringContainsString('бележка B', (string) $a['notes']);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM offers WHERE business_id = 500'));
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM business_sources WHERE business_id = 500'));
        $this->assertSame(['archived', 500], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v,
            $this->db->query('SELECT status, merged_into_id FROM businesses WHERE id = 501')->fetch())));
        $this->assertSame('merged', $this->db->query('SELECT status FROM duplicate_candidates')->fetchColumn());
    }

    public function testOverloadedServerFallsBackToMirror(): void
    {
        $lozenets = $this->areaId('lozenets', 'district');
        $this->pushBoundaries();
        $this->http->push('overpass-api.de', 502, 'Bad Gateway');
        $this->http->push('overpass.kumi.systems', 200, Fixtures::overpassBusinessesJson());
        $r = $this->runner();
        [$jobId] = $r->queueScan($lozenets, ['osm'], 't');
        $job = $r->runSlice((array) $r->claim($jobId), 30);
        $this->assertSame('running', $job['status']);
        $this->assertStringContainsString('HTTP 502 (опит 1', (string) $job['log'], 'the reason is visible while waiting');
        $this->db->exec("UPDATE jobs SET run_after = NOW() - INTERVAL 1 SECOND WHERE id = $jobId");
        $job = $r->runSlice((array) $r->claim($jobId), 30);
        $this->assertSame('done', $job['status']);
        $this->assertSame(3, (int) $job['new_count']);
    }
}
