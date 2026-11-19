<?php

declare(strict_types=1);

namespace BarberLead\Tests\Integration;

use BarberLead\App;
use BarberLead\Auth;
use BarberLead\Http\Request;
use BarberLead\Http\Response;
use BarberLead\Http\Session;
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

    public function testRelevanceFilterHidesWomensSalonsByDefault(): void
    {
        $this->login();
        $this->db->exec("INSERT INTO businesses (name, name_normalized, relevance) VALUES ('FIXTURE Lady Salon', 'lady', 'low'), ('FIXTURE Gents', 'gents', 'high')");
        $list = $this->req('GET', '/businesses')->body;
        $this->assertStringContainsString('FIXTURE Gents', $list);
        $this->assertStringNotContainsString('FIXTURE Lady Salon', $list);
        $this->assertStringContainsString('FIXTURE Lady Salon', $this->req('GET', '/businesses', [], ['rel' => 'all'])->body);
    }
}
