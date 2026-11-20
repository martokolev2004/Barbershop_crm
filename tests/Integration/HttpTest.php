<?php

declare(strict_types=1);

namespace BarberLead\Tests\Integration;

use BarberLead\App;
use BarberLead\Auth;
use BarberLead\Http\Request;
use BarberLead\Http\Response;
use BarberLead\Http\Session;
use BarberLead\Tests\FakeHttp;
use BarberLead\Tests\Fixtures;
use BarberLead\Tests\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Drives the real router/controllers/views end to end (no web server needed). */
final class HttpTest extends TestCase
{
    private PDO $db;
    private App $app;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->db = TestDatabase::fresh();
        (new Auth($this->db, new Session()))->createUser('owner', 'test password 123');
        $this->app = new App(['backup_dir' => sys_get_temp_dir() . '/bl-http-test'], $this->db, new Session());
    }

    /** @param array<string,mixed> $post */
    private function req(string $method, string $path, array $post = [], array $query = [], bool $json = false): Response
    {
        if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
            $post['_csrf'] = (new Session())->csrfToken();
        }
        return $this->app->handle(new Request($method, $path, $query, $post, '127.0.0.1', $json));
    }

    private function login(): void
    {
        $r = $this->req('POST', '/login', ['username' => 'owner', 'password' => 'test password 123']);
        $this->assertSame(303, $r->status);
    }

    public function testGuestsAreRedirectedToLogin(): void
    {
        $r = $this->req('GET', '/businesses/1');
        $this->assertSame(303, $r->status);
        $this->assertSame('/login?next=%2Fbusinesses%2F1', $r->headers['Location']);
        $this->assertSame(401, $this->req('GET', '/kanban', [], [], true)->status);
        $this->assertSame(200, $this->req('GET', '/login')->status);
    }

    public function testWrongPasswordShowsError(): void
    {
        $r = $this->req('POST', '/login', ['username' => 'owner', 'password' => 'nope']);
        $this->assertSame(401, $r->status);
        $this->assertStringContainsString('Грешно потребителско име или парола', $r->body);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $this->login();
        $r = $this->req('POST', '/businesses', ['name' => 'X', '_csrf' => 'forged']);
        $this->assertSame(419, $r->status);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM businesses')->fetchColumn(), 'only the imported Prime record');
    }

    public function testAllPagesRenderForLoggedInOwner(): void
    {
        $this->login();
        $prime = (int) $this->db->query("SELECT id FROM businesses WHERE name = 'Prime Barbershop'")->fetchColumn();
        $lozenets = (int) $this->db->query("SELECT id FROM areas WHERE slug = 'lozenets' AND type = 'district'")->fetchColumn();
        foreach (['/', '/businesses', '/businesses/new', "/businesses/$prime", "/businesses/$prime/edit", '/kanban', '/offers', '/settings'] as $path) {
            $r = $this->req('GET', $path);
            $this->assertSame(200, $r->status, "GET $path");
            $this->assertStringContainsString('BARBER', $r->body);
        }
        $r = $this->req('GET', '/businesses', [], ['area' => (string) $lozenets]);
        $this->assertStringContainsString('Още няма бизнеси в Лозенец', $r->body);
        $this->assertSame(404, $this->req('GET', '/businesses/99999')->status);
        $this->assertSame(404, $this->req('GET', '/nope')->status);
    }

    public function testCreateBusinessOfferFlowAndEscaping(): void
    {
        $this->login();
        $r = $this->req('POST', '/businesses', ['name' => '<script>alert(1)</script> Barbers', 'phone' => '0888 555 666', 'website_status' => 'none']);
        $this->assertSame(303, $r->status);
        $id = (int) basename($r->headers['Location']);

        $show = $this->req('GET', "/businesses/$id");
        $this->assertStringNotContainsString('<script>alert(1)</script>', $show->body);
        $this->assertStringContainsString('&lt;script&gt;', $show->body);

        $r = $this->req('POST', "/businesses/$id/offer", ['channel' => 'instagram', 'demo_url' => 'https://x.testvane.org', 'follow_up_days' => '5']);
        $this->assertSame(303, $r->status);
        $this->assertSame('offer_sent', $this->db->query("SELECT status FROM businesses WHERE id = $id")->fetchColumn());
        $this->assertStringContainsString('Пусната оферта през Instagram', $this->req('GET', "/businesses/$id")->body);
        $this->assertStringContainsString('&lt;script&gt;', $this->req('GET', '/offers')->body);

        // duplicate warning on the same phone, saved only after confirmation
        $dup = $this->req('POST', '/businesses', ['name' => 'Друго име', 'phone' => '+359888555666']);
        $this->assertSame(409, $dup->status);
        $ok = $this->req('POST', '/businesses', ['name' => 'Друго име', 'phone' => '+359888555666', 'confirm_duplicate' => '1']);
        $this->assertSame(303, $ok->status);
    }

    public function testKanbanJsonStatusChange(): void
    {
        $this->login();
        $id = (int) $this->db->query("SELECT id FROM businesses LIMIT 1")->fetchColumn();
        $r = $this->req('POST', "/businesses/$id/status", ['status' => 'negotiation'], [], true);
        $this->assertSame(200, $r->status);
        $this->assertSame(['ok' => true, 'status' => 'negotiation', 'label' => 'Преговори'], json_decode($r->body, true));
        $this->assertSame(422, $this->req('POST', "/businesses/$id/status", ['status' => 'hacked'], [], true)->status);
    }

    public function testSettingsAndBackupButton(): void
    {
        $this->login();
        $this->req('POST', '/settings', ['outreach_template' => 'Здравейте, {name}! {demo_url}', 'my_name' => 'Мартин', 'follow_up_days' => '3']);
        $id = (int) $this->db->query("SELECT id FROM businesses WHERE name = 'Prime Barbershop'")->fetchColumn();
        $this->assertStringContainsString('Здравейте, Prime Barbershop! https://primebarbershop.testvane.org/', $this->req('GET', "/businesses/$id")->body);
        $r = $this->req('POST', '/settings/backup');
        $this->assertSame(303, $r->status);
        $this->assertNotEmpty(glob(sys_get_temp_dir() . '/bl-http-test/barberlead-*.sql.gz'));
        array_map('unlink', glob(sys_get_temp_dir() . '/bl-http-test/*') ?: []);
    }

    public function testLogout(): void
    {
        $this->login();
        $this->req('POST', '/logout');
        $this->assertSame(303, $this->req('GET', '/')->status);
    }

    public function testScanPagesAndQueueing(): void
    {
        $this->login();
        $lozenets = (int) $this->db->query("SELECT id FROM areas WHERE slug = 'lozenets' AND type = 'district'")->fetchColumn();
        foreach (['/jobs', '/duplicates'] as $path) {
            $this->assertSame(200, $this->req('GET', $path)->status, "GET $path");
        }
        $this->req('POST', "/areas/$lozenets/scan", []);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn(), 'no source chosen → nothing queued');
        $r = $this->req('POST', "/areas/$lozenets/scan", ['osm' => '1', 'google' => '1']);
        $this->assertSame(303, $r->status);
        $job = $this->db->query('SELECT * FROM jobs')->fetch();
        $this->assertSame(['sources' => ['osm']], json_decode($job['params'], true), 'Google is not used without a key');
        $page = $this->req('GET', '/businesses', [], ['area' => (string) $lozenets, 'job' => (string) $job['id']]);
        $this->assertStringContainsString('data-job="' . $job['id'] . '"', $page->body);
        $status = json_decode($this->req('GET', '/jobs/' . $job['id'], [], [], true)->body, true);
        $this->assertSame('queued', $status['status']);
        $this->assertFalse($status['final']);
        // second click while active → no new job
        $this->req('POST', "/areas/$lozenets/scan", ['osm' => '1']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $this->req('POST', '/jobs/' . $job['id'] . '/cancel');
        $this->assertSame('cancelled', $this->db->query('SELECT status FROM jobs')->fetchColumn());

        $this->req('POST', "/areas/$lozenets/schedule", ['enabled' => '1', 'interval_days' => '14']);
        $this->assertSame(14, (int) $this->db->query("SELECT interval_days FROM scan_schedules WHERE area_id = $lozenets")->fetchColumn());

        $sofia = (int) $this->db->query("SELECT id FROM areas WHERE slug = 'sofia'")->fetchColumn();
        $this->assertStringContainsString('24 от 24 района са включени', $this->req('GET', '/businesses', [], ['area' => (string) $sofia])->body);
        $this->req('POST', "/areas/$sofia/schedule-all", ['interval_days' => '15']);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM scan_schedules WHERE enabled = 1')->fetchColumn());
        $this->req('POST', "/areas/$sofia/schedule-all", ['enabled' => '1', 'interval_days' => '20']);
        $this->assertSame(24, (int) $this->db->query('SELECT COUNT(*) FROM scan_schedules WHERE enabled = 1 AND interval_days = 20')->fetchColumn());
    }

    public function testGoogleKeyIsStoredButNeverShown(): void
    {
        $this->login();
        $key = 'AIzaFIXTUREkey0123456789abcdefghijklmn';
        $this->req('POST', '/settings/google', ['google_api_key' => $key, 'google_monthly_cap' => '500']);
        $this->assertSame($key, $this->db->query("SELECT value FROM settings WHERE name = 'google_api_key'")->fetchColumn());
        $body = $this->req('GET', '/settings')->body;
        $this->assertStringNotContainsString($key, $body);
        $this->assertStringContainsString('…klmn', $body);
        $this->req('POST', '/settings/google', ['google_api_key' => 'bad key!']);
        $this->assertSame($key, $this->db->query("SELECT value FROM settings WHERE name = 'google_api_key'")->fetchColumn());
    }

    public function testBarbershopsAndSalonsAreSeparateTabs(): void
    {
        $this->login();
        $this->db->exec("INSERT INTO businesses (id, name, name_normalized, relevance) VALUES
            (960, 'FIXTURE Lady Salon', 'lady', 'low'), (961, 'FIXTURE Gents', 'gents', 'high'), (962, 'FIXTURE Hair Studio', 'hair', 'medium')");
        $list = $this->req('GET', '/businesses')->body;
        $this->assertStringContainsString('FIXTURE Gents', $list, 'barbershops are the default tab');
        $this->assertStringNotContainsString('FIXTURE Hair Studio', $list);
        $this->assertStringNotContainsString('FIXTURE Lady Salon', $list);
        $this->assertMatchesRegularExpression('/Бръснарници <span>2<\/span>/', $list, 'Gents + the imported Prime');
        $this->assertMatchesRegularExpression('/Салони <span>1<\/span>/', $list);
        $this->assertStringContainsString('FIXTURE Hair Studio', $this->req('GET', '/businesses', [], ['rel' => 'medium'])->body);
        $this->assertStringContainsString('FIXTURE Lady Salon', $this->req('GET', '/businesses', [], ['rel' => 'all'])->body);

        // manual move: salon → barbershop, kept by rescans
        $this->req('POST', '/businesses/962/relevance', ['relevance' => 'high', 'back' => '/businesses?rel=medium']);
        $this->req('GET', '/');
        $this->assertStringContainsString('FIXTURE Hair Studio', $this->req('GET', '/businesses')->body);
        $this->assertSame('Ръчно: Бръснарници', $this->db->query('SELECT relevance_reason FROM businesses WHERE id = 962')->fetchColumn());
        $this->assertSame(422, $this->req('POST', '/businesses/962/relevance', ['relevance' => 'bogus'])->status);
    }

    public function testRejectHidesBusinessAndRestoreBringsItBack(): void
    {
        $this->login();
        $this->db->exec("INSERT INTO businesses (id, name, name_normalized, relevance) VALUES (900, 'FIXTURE Reject Me', 'reject me', 'high')");
        $r = $this->req('POST', '/businesses/900/reject', ['reason' => 'има си сайт', 'back' => '/businesses']);
        $this->assertSame('/businesses', $r->headers['Location']);
        $this->assertSame('rejected', $this->db->query('SELECT status FROM businesses WHERE id = 900')->fetchColumn());
        $this->assertStringContainsString('е преместен в Отхвърлени', $this->req('GET', '/')->body, 'confirmation shown once');
        $this->assertStringNotContainsString('FIXTURE Reject Me', $this->req('GET', '/businesses')->body, 'hidden from the normal list');
        $rejected = $this->req('GET', '/businesses', [], ['status' => 'rejected'])->body;
        $this->assertStringContainsString('FIXTURE Reject Me', $rejected);
        $this->assertStringContainsString('Върни', $rejected);
        $this->assertStringContainsString('Причина за отхвърляне: има си сайт', $this->req('GET', '/businesses/900')->body);

        // a rescan finding the same place does not bring it back
        $this->db->exec("INSERT INTO business_sources (business_id, source, source_id) VALUES (900, 'google', 'gREJ')");
        (new \BarberLead\Discovery\Importer($this->db, new \BarberLead\Repositories\EventRepository($this->db)))
            ->import(new \BarberLead\Discovery\Candidate('google', 'gREJ', 'FIXTURE Reject Me', phone: '0888 900 900'), null, 'test');
        $this->assertSame('rejected', $this->db->query('SELECT status FROM businesses WHERE id = 900')->fetchColumn());

        $this->req('POST', '/businesses/900/restore');
        $this->req('GET', '/');
        $this->assertSame('new', $this->db->query('SELECT status FROM businesses WHERE id = 900')->fetchColumn());
        $this->assertStringContainsString('FIXTURE Reject Me', $this->req('GET', '/businesses')->body);
    }

    public function testResearchingHasItsOwnPage(): void
    {
        $this->login();
        $this->db->exec("INSERT INTO businesses (id, name, name_normalized, relevance) VALUES (950, 'FIXTURE Research Me', 'research me', 'high')");
        $list = $this->req('GET', '/businesses')->body;
        $this->assertStringContainsString('FIXTURE Research Me', $list);
        $this->assertStringContainsString('>Проучвам</button>', $list);

        $r = $this->req('POST', '/businesses/950/status', ['status' => 'researching', 'back' => '/businesses']);
        $this->assertSame('/businesses', $r->headers['Location']);
        $this->req('GET', '/'); // consume flash
        $this->assertStringNotContainsString('FIXTURE Research Me', $this->req('GET', '/businesses')->body, 'moved out of the main list');
        $page = $this->req('GET', '/businesses', [], ['status' => 'researching'])->body;
        $this->assertStringContainsString('FIXTURE Research Me', $page);
        $this->assertStringContainsString('>За оферта</button>', $page);
        $this->assertStringContainsString('<span class="pill">1</span>', $page, 'menu counter');

        $this->req('POST', '/businesses/950/status', ['status' => 'qualified', 'back' => '/businesses?status=researching']);
        $this->req('GET', '/');
        $this->assertStringContainsString('FIXTURE Research Me', $this->req('GET', '/businesses')->body, 'back in the main list');
    }

    public function testWriteButtonAndWebsiteKinds(): void
    {
        $this->login();
        $this->req('POST', '/settings', ['outreach_template' => 'Здравейте, {name}! {demo_url}', 'my_name' => 'Мартин', 'follow_up_days' => '5']);
        $this->req('GET', '/');
        $this->assertStringContainsString('>Здравейте, {name}! {demo_url}</textarea>', $this->req('GET', '/settings')->body, 'settings shows the real template');
        $this->db->exec("INSERT INTO businesses (id, name, name_normalized, relevance, instagram_url, phone, phone_normalized, reviews_count) VALUES
            (970, 'FIXTURE Insta Only', 'insta', 'high', 'https://www.instagram.com/fixture_insta', '0888 970 970', '+359888970970', 300),
            (971, 'FIXTURE With Site', 'site', 'high', NULL, NULL, NULL, 10)");
        $this->db->exec("UPDATE businesses SET website_url = 'https://site.example', website_domain = 'site.example' WHERE id = 971");
        $this->db->exec("INSERT INTO demos (business_id, url) VALUES (970, 'https://insta-demo.testvane.org/')");
        $this->req('GET', '/');
        $list = $this->req('GET', '/businesses')->body;
        $this->assertStringContainsString('data-message="Здравейте, FIXTURE Insta Only! https://insta-demo.testvane.org/"', $list, 'personal message with its own demo');
        $this->assertStringContainsString('data-open="https://www.instagram.com/fixture_insta"', $list);
        $this->assertStringContainsString('viber://chat?number=%2B359888970970', $list);
        $this->assertStringContainsString('Само Instagram/FB', $list);
        $this->assertStringContainsString('Има сайт', $list);
        $this->assertLessThan(strpos($list, 'FIXTURE With Site'), strpos($list, 'FIXTURE Insta Only'), 'most reviews first');
        $social = $this->req('GET', '/businesses', [], ['web' => 'social'])->body;
        $this->assertStringContainsString('FIXTURE Insta Only', $social);
        $this->assertStringNotContainsString('FIXTURE With Site', $social);
        $today = $this->req('GET', '/')->body;
        $this->assertStringContainsString('Да пиша пак днес', $today);
        $this->assertStringContainsString('FIXTURE Insta Only', $today, 'new barbershop of this week');
    }

    public function testSearchOursFirstThenGoogleAndAdd(): void
    {
        $this->login();
        $this->db->exec("INSERT INTO businesses (id, name, name_normalized, relevance, status, phone, phone_normalized) VALUES
            (980, 'FIXTURE Barber One', 'fixture barber one', 'high', 'rejected', '0888 100 001', '+359888100001')");
        $r = $this->req('GET', '/search', [], ['q' => 'fixture barber']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('FIXTURE Barber One', $r->body, 'rejected ones are found too');
        $this->assertStringContainsString('Добави Google ключ', $r->body);

        $this->app->settings->set('google_api_key', 'FIXTURE-KEY-0123456789abcdefghijklmnop');
        $http = new FakeHttp();
        $http->push('places.googleapis.com', 200, Fixtures::googleJson());
        $this->app->http = $http;
        $r = $this->req('POST', '/search/google', ['q' => 'fixture barber']);
        $this->assertSame(303, $r->status);
        $this->assertCount(1, $http->calls);
        $this->assertStringContainsString('"textQuery":"fixture barber"', $http->calls[0]['body']);
        $this->assertSame(1, (int) $this->app->settings->get('google_usage_' . date('Ym')), 'counted against the monthly cap');

        $page = $this->req('GET', '/search', [], ['q' => 'fixture barber'])->body;
        $this->assertStringContainsString('Вече го имаме', $page, 'same phone → already ours');
        $this->assertStringContainsString('FIXTURE Gents Club', $page);
        $this->assertSame(2, substr_count($page, 'name="place"'), 'two can be added');

        $r = $this->req('POST', '/search/add', ['place' => 'gFIX2']);
        $this->assertSame(303, $r->status);
        $id = (int) $this->db->query("SELECT id FROM businesses WHERE name = 'FIXTURE Gents Club'")->fetchColumn();
        $this->assertGreaterThan(0, $id);
        $this->assertSame("/businesses/$id", $r->headers['Location']);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM business_sources WHERE source = 'google' AND source_id = 'gFIX2'")->fetchColumn());
        $this->assertSame(303, $this->req('POST', '/search/add', ['place' => 'not-from-google'])->status);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM businesses WHERE name = 'FIXTURE Gents Club'")->fetchColumn());

        $this->app->settings->set('google_monthly_cap', '1');
        $this->req('POST', '/search/google', ['q' => 'other']);
        $this->assertCount(1, $http->calls, 'cap reached → no request');
    }
}
