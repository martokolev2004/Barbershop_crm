<?php

declare(strict_types=1);

namespace BarberLead;

use BarberLead\Controllers\AuthController;
use BarberLead\Controllers\BusinessController;
use BarberLead\Controllers\DashboardController;
use BarberLead\Controllers\KanbanController;
use BarberLead\Controllers\OfferController;
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
        $this->router = $this->routes();
    }

    private function routes(): Router
    {
        $r = new Router();
        $auth = new AuthController($this);
        $dash = new DashboardController($this);
        $biz = new BusinessController($this);
        $offer = new OfferController($this);
        $kanban = new KanbanController($this);
        $settings = new SettingsController($this);

        $r->get('/login', [$auth, 'form']);
        $r->post('/login', [$auth, 'login']);
        $r->post('/logout', [$auth, 'logout']);

        $r->get('/', [$dash, 'index']);

        $r->get('/businesses', [$biz, 'index']);
        $r->get('/businesses/new', [$biz, 'createForm']);
        $r->post('/businesses', [$biz, 'create']);
        $r->get('/businesses/{id}', [$biz, 'show']);
        $r->get('/businesses/{id}/edit', [$biz, 'editForm']);
        $r->post('/businesses/{id}', [$biz, 'update']);
        $r->post('/businesses/{id}/status', [$biz, 'status']);
        $r->post('/businesses/{id}/note', [$biz, 'note']);
        $r->post('/businesses/{id}/demos', [$biz, 'addDemo']);
        $r->post('/businesses/{id}/offer', [$offer, 'markSent']);

        $r->get('/offers', [$offer, 'index']);
        $r->post('/offers/{id}/follow-up-done', [$offer, 'followUpDone']);

        $r->get('/kanban', [$kanban, 'index']);

        $r->get('/settings', [$settings, 'index']);
        $r->post('/settings', [$settings, 'save']);
        $r->post('/settings/password', [$settings, 'password']);
        $r->post('/settings/backup', [$settings, 'backup']);
        return $r;
    }

    public function handle(Request $req): Response
    {
        $loggedIn = $this->auth->userId() !== null;
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

        $this->view->share('app', $this);
        $this->view->share('user', $this->auth->username());
        $this->view->share('csrf', $this->session->csrfToken());
        $this->view->share('path', $req->path);
        $this->view->share('dueFollowUps', $loggedIn ? $this->offers->countDueFollowUps() : 0);

        $match = $this->router->match($req->method, $req->path);
        if ($match === null) {
            return $this->error(404, 'Няма такава страница.');
        }
        [$handler, $params] = $match;
        return $handler($req, ...array_map('intval', array_values($params)));
    }

    public function actor(): string
    {
        return $this->auth->username() ?? 'system';
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
