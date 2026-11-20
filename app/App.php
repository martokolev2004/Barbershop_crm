<?php

declare(strict_types=1);

namespace BarberLead;

use BarberLead\Controllers\AuthController;
use BarberLead\Controllers\BusinessController;
use BarberLead\Controllers\DashboardController;
use BarberLead\Controllers\DuplicateController;
use BarberLead\Controllers\ScanController;
use BarberLead\Controllers\KanbanController;
use BarberLead\Controllers\OfferController;
use BarberLead\Controllers\SearchController;
use BarberLead\Discovery\HttpClient;
use BarberLead\Controllers\SettingsController;
use BarberLead\Http\Request;
use BarberLead\Http\Response;
use BarberLead\Http\Router;
use BarberLead\Http\Session;
use BarberLead\Repositories\AreaRepository;
use BarberLead\Repositories\AuditLog;
use BarberLead\Repositories\BusinessRepository;
use BarberLead\Repositories\DemoRepository;
use BarberLead\Repositories\EventRepository;
use BarberLead\Repositories\OfferRepository;
use BarberLead\Repositories\SettingsRepository;
use PDO;

final class App
{
    public readonly Auth $auth;
    public readonly View $view;
    public readonly AreaRepository $areas;
    public readonly EventRepository $events;
    public readonly BusinessRepository $businesses;
    public readonly OfferRepository $offers;
    public readonly DemoRepository $demos;
    public readonly SettingsRepository $settings;
    public readonly AuditLog $audit;
    /** Outbound HTTP (replaced by a fake in tests). */
    public HttpClient $http;
    private Router $router;

    /** Paths reachable without login. */
    private const PUBLIC_PATHS = ['/login'];

    /** @param array<string,mixed> $config */
    public function __construct(public readonly array $config, public readonly PDO $db, public readonly Session $session)
    {
        $this->auth = new Auth($db, $session);
        $this->view = new View(BL_ROOT . '/views');
        $this->areas = new AreaRepository($db);
        $this->events = new EventRepository($db);
        $this->businesses = new BusinessRepository($db, $this->events);
        $this->offers = new OfferRepository($db, $this->events, $this->businesses);
        $this->demos = new DemoRepository($db, $this->events);
        $this->settings = new SettingsRepository($db);
        $this->audit = new AuditLog($db);
        $this->http = new HttpClient();
        $this->router = $this->routes();
    }

    private function routes(): Router
    {
        $r = new Router();
        $r->get('/login', 'login.form');
        $r->post('/login', 'login');
        $r->post('/logout', 'logout');
        $r->get('/', 'dashboard');
        $r->get('/businesses', 'biz.index');
        $r->get('/businesses/new', 'biz.new');
        $r->post('/businesses', 'biz.create');
        $r->get('/businesses/{id}', 'biz.show');
        $r->get('/businesses/{id}/edit', 'biz.edit');
        $r->post('/businesses/{id}', 'biz.update');
        $r->post('/businesses/{id}/status', 'biz.status');
        $r->post('/businesses/{id}/note', 'biz.note');
        $r->post('/businesses/{id}/reject', 'biz.reject');
        $r->post('/businesses/{id}/restore', 'biz.restore');
        $r->post('/businesses/{id}/relevance', 'biz.relevance');
        $r->post('/businesses/{id}/demos', 'biz.demo');
        $r->post('/businesses/{id}/offer', 'offer.sent');
        $r->get('/offers', 'offer.index');
        $r->post('/offers/{id}/follow-up-done', 'offer.followup');
        $r->get('/kanban', 'kanban');
        $r->get('/settings', 'settings');
        $r->post('/settings', 'settings.save');
        $r->post('/settings/password', 'settings.password');
        $r->post('/settings/backup', 'settings.backup');
        $r->post('/settings/google', 'settings.google');
        $r->post('/settings/migrate', 'settings.migrate');
        $r->post('/areas/{id}/scan', 'scan.start');
        $r->post('/areas/{id}/schedule', 'scan.schedule');
        $r->post('/areas/{id}/schedule-all', 'scan.scheduleAll');
        $r->get('/jobs', 'jobs');
        $r->get('/jobs/{id}', 'job.status');
        $r->post('/jobs/{id}/run', 'job.run');
        $r->post('/jobs/{id}/cancel', 'job.cancel');
        $r->get('/search', 'search');
        $r->post('/search/google', 'search.google');
        $r->post('/search/add', 'search.add');
        $r->get('/duplicates', 'dup.index');
        $r->post('/duplicates/{id}/merge', 'dup.merge');
        $r->post('/duplicates/{id}/dismiss', 'dup.dismiss');
        return $r;
    }

    private function dispatch(string $route, Request $req, int $id): Response
    {
        return match ($route) {
            'login.form' => (new AuthController($this))->form($req),
            'login' => (new AuthController($this))->login($req),
            'logout' => (new AuthController($this))->logout($req),
            'dashboard' => (new DashboardController($this))->index($req),
            'biz.index' => (new BusinessController($this))->index($req),
            'biz.new' => (new BusinessController($this))->createForm($req),
            'biz.create' => (new BusinessController($this))->create($req),
            'biz.show' => (new BusinessController($this))->show($req, $id),
            'biz.edit' => (new BusinessController($this))->editForm($req, $id),
            'biz.update' => (new BusinessController($this))->update($req, $id),
            'biz.status' => (new BusinessController($this))->status($req, $id),
            'biz.note' => (new BusinessController($this))->note($req, $id),
            'biz.reject' => (new BusinessController($this))->reject($req, $id),
            'biz.restore' => (new BusinessController($this))->restore($req, $id),
            'biz.relevance' => (new BusinessController($this))->relevance($req, $id),
            'biz.demo' => (new BusinessController($this))->addDemo($req, $id),
            'offer.sent' => (new OfferController($this))->markSent($req, $id),
            'offer.index' => (new OfferController($this))->index($req),
            'offer.followup' => (new OfferController($this))->followUpDone($req, $id),
            'kanban' => (new KanbanController($this))->index($req),
            'settings' => (new SettingsController($this))->index($req),
            'settings.save' => (new SettingsController($this))->save($req),
            'settings.password' => (new SettingsController($this))->password($req),
            'settings.backup' => (new SettingsController($this))->backup($req),
            'settings.google' => (new SettingsController($this))->google($req),
            'settings.migrate' => (new SettingsController($this))->migrate($req),
            'scan.start' => (new ScanController($this))->start($req, $id),
            'scan.schedule' => (new ScanController($this))->schedule($req, $id),
            'scan.scheduleAll' => (new ScanController($this))->scheduleAll($req, $id),
            'jobs' => (new ScanController($this))->index($req),
            'job.status' => (new ScanController($this))->status($req, $id),
            'job.run' => (new ScanController($this))->run($req, $id),
            'job.cancel' => (new ScanController($this))->cancel($req, $id),
            'search' => (new SearchController($this))->index($req),
            'search.google' => (new SearchController($this))->google($req),
            'search.add' => (new SearchController($this))->add($req),
            'dup.index' => (new DuplicateController($this))->index($req),
            'dup.merge' => (new DuplicateController($this))->merge($req, $id),
            'dup.dismiss' => (new DuplicateController($this))->dismiss($req, $id),
            default => $this->error(404, 'Няма такава страница.'),
        };
    }

    public function handle(Request $req): Response
    {
        $loggedIn = $this->auth->userId() !== null;
        $this->view->share('app', $this);
        $this->view->share('user', $this->auth->username());
        $this->view->share('csrf', $this->session->csrfToken());
        $this->view->share('path', $req->path);
        $this->view->share('dueFollowUps', $loggedIn ? $this->offers->countDueFollowUps() : 0);
        $this->view->share('pendingMigrations', $loggedIn ? count((new Migrator($this->db, BL_ROOT . '/migrations'))->pending()) : 0);
        $this->view->share('openDuplicates', $loggedIn ? $this->countOpenDuplicates() : 0);
        $this->view->share('rejectedCount', $loggedIn ? $this->businesses->countRejected() : 0);
        $this->view->share('researchingCount', $loggedIn ? $this->businesses->countResearching() : 0);

        if (!$loggedIn && !in_array($req->path, self::PUBLIC_PATHS, true)) {
            if ($req->wantsJson) {
                return Response::json(['error' => 'Не си влязъл.'], 401);
            }
            $next = $req->method === 'GET' && $req->path !== '/' ? $req->path : '';
            return Response::redirect(url('/login', ['next' => $next]));
        }
        if ($req->method === 'POST' && !$this->session->checkCsrf($req->str('_csrf'))) {
            return $req->wantsJson
                ? Response::json(['error' => 'Сесията е изтекла. Презареди страницата.'], 419)
                : $this->error(419, 'Сесията е изтекла или формата е стара. Върни се назад и презареди страницата.');
        }

        $match = $this->router->match($req->method, $req->path);
        if ($match === null) {
            return $this->error(404, 'Няма такава страница.');
        }
        // New code on an old schema would fail: only the update button works until migrations ran.
        if ($loggedIn && !in_array($match[0], ['settings.migrate', 'logout', 'login', 'login.form'], true)
            && (new Migrator($this->db, BL_ROOT . '/migrations'))->pending()) {
            return $req->wantsJson
                ? Response::json(['error' => 'Базата трябва да се обнови (Настройки).'], 503)
                : $this->page('migrate', ['title' => 'Обновяване', 'pending' => (new Migrator($this->db, BL_ROOT . '/migrations'))->pending()], 503);
        }
        return $this->dispatch($match[0], $req, $match[1]);
    }

    private function countOpenDuplicates(): int
    {
        try {
            return (int) $this->db->query("SELECT COUNT(*) FROM duplicate_candidates WHERE status = 'open'")->fetchColumn();
        } catch (\PDOException) {
            return 0; // table not migrated yet
        }
    }

    public function actor(): string
    {
        return $this->auth->username() ?? 'auto';
    }

    /** @param array<string,mixed> $vars */
    public function page(string $template, array $vars = [], int $status = 200): Response
    {
        $vars['flashes'] = $this->session->takeFlashes();
        return new Response($this->view->render($template, $vars), $status);
    }

    public function error(int $status, string $message): Response
    {
        return $this->page('error', ['title' => 'Грешка', 'message' => $message, 'code' => $status], $status);
    }
}
