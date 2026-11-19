<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Http\Request;
use BarberLead\Http\Response;

final class AuthController
{
    public function __construct(private App $app)
    {
    }

    public function form(Request $req): Response
    {
        if ($this->app->auth->userId() !== null) {
            return Response::redirect('/');
        }
        return $this->app->page('login', ['title' => 'Вход', 'next' => $req->str('next'), 'error' => null, 'username' => '']);
    }

    public function login(Request $req): Response
    {
        $username = $req->str('username');
        $result = $this->app->auth->attempt($username, (string) ($req->post['password'] ?? ''), $req->ip);
        if (!$result['ok']) {
            $this->app->audit->record($username, 'login_failed', null, null, [], $req->ip);
            return $this->app->page('login', [
                'title' => 'Вход', 'next' => $req->str('next'), 'error' => $result['error'] ?? 'Грешка', 'username' => $username,
            ], 401);
        }
        $this->app->audit->record($this->app->actor(), 'login', null, null, [], $req->ip);
        $this->app->auth->pruneAttempts();
        return Response::redirect($req->str('next') ?: '/');
    }

    public function logout(Request $req): Response
    {
        $this->app->audit->record($this->app->actor(), 'logout', null, null, [], $req->ip);
        $this->app->auth->logout();
        return Response::redirect('/login');
    }
}
