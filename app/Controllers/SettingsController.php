<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Backup;
use BarberLead\Migrator;
use BarberLead\Http\Request;
use BarberLead\Http\Response;

final class SettingsController
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $req): Response
    {
        $dir = $this->backupDir();
        $files = glob($dir . '/barberlead-*.sql.gz') ?: [];
        rsort($files);
        $backups = array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], array_slice($files, 0, 10));
        return $this->app->page('settings', [
            'title' => 'Настройки',
            'template' => $this->app->settings->get('outreach_template', ''),
            'myName' => $this->app->settings->get('my_name', ''),
            'followUpDays' => $this->app->settings->get('follow_up_days', '5'),
            'backups' => $backups,
            'audit' => $this->app->audit->recent(20),
            'googleKeySuffix' => ($k = (string) $this->app->settings->get('google_api_key', '')) !== '' ? substr($k, -4) : null,
            'googleCap' => $this->app->settings->get('google_monthly_cap', '900'),
            'googleUsage' => (int) $this->app->settings->get('google_usage_' . date('Ym'), '0'),
            'pending' => (new Migrator($this->app->db, BL_ROOT . '/migrations'))->pending(),
        ]);
    }

    public function save(Request $req): Response
    {
        $template = (string) ($req->post['outreach_template'] ?? '');
        $days = $req->str('follow_up_days');
        if (mb_strlen($template) > 5000 || !ctype_digit($days) || (int) $days > 60) {
            $this->app->session->flash('err', 'Шаблонът е твърде дълъг или дните не са между 0 и 60.');
            return Response::redirect('/settings');
        }
        $this->app->settings->set('outreach_template', str_replace("\r\n", "\n", $template));
        $this->app->settings->set('my_name', mb_substr($req->str('my_name'), 0, 80));
        $this->app->settings->set('follow_up_days', $days);
        $this->app->audit->record($this->app->actor(), 'settings_update', null, null, [], $req->ip);
        $this->app->session->flash('ok', 'Настройките са записани.');
        return Response::redirect('/settings');
    }

    public function password(Request $req): Response
    {
        $uid = $this->app->auth->userId();
        $new = (string) ($req->post['new_password'] ?? '');
        if ($new !== (string) ($req->post['new_password2'] ?? '')) {
            $this->app->session->flash('err', 'Новите пароли не съвпадат.');
            return Response::redirect('/settings#password');
        }
        $err = $uid ? $this->app->auth->changePassword($uid, (string) ($req->post['current_password'] ?? ''), $new) : 'Не си влязъл.';
        if ($err) {
            $this->app->session->flash('err', $err);
        } else {
            $this->app->audit->record($this->app->actor(), 'password_change', null, null, [], $req->ip);
            $this->app->session->flash('ok', 'Паролата е сменена.');
        }
        return Response::redirect('/settings#password');
    }

    public function backup(Request $req): Response
    {
        $file = (new Backup($this->app->db, $this->backupDir()))->create('manual');
        $this->app->audit->record($this->app->actor(), 'backup', null, null, ['file' => basename($file)], $req->ip);
        $this->app->session->flash('ok', 'Направен е бекъп: ' . basename($file));
        return Response::redirect('/settings#backups');
    }

    private function backupDir(): string
    {
        $dir = (string) ($this->app->config['backup_dir'] ?? 'storage/backups');
        return str_starts_with($dir, '/') ? $dir : BL_ROOT . '/' . $dir;
    }

    public function google(Request $req): Response
    {
        $key = trim((string) ($req->post['google_api_key'] ?? ''));
        $cap = $req->str('google_monthly_cap');
        if ($cap !== '' && (!ctype_digit($cap) || (int) $cap > 100000)) {
            $this->app->session->flash('err', 'Лимитът трябва да е цяло число.');
            return Response::redirect('/settings#google');
        }
        if ($req->str('remove_key') === '1') {
            $this->app->settings->set('google_api_key', null);
            $this->app->session->flash('ok', 'Google ключът е изтрит.');
        } elseif ($key !== '') {
            if (!preg_match('/^[A-Za-z0-9_\-]{30,60}$/', $key)) {
                $this->app->session->flash('err', 'Това не прилича на Google API ключ.');
                return Response::redirect('/settings#google');
            }
            $this->app->settings->set('google_api_key', $key);
            $this->app->session->flash('ok', 'Google ключът е записан.');
        } else {
            $this->app->session->flash('ok', 'Записано.');
        }
        if ($cap !== '') {
            $this->app->settings->set('google_monthly_cap', $cap);
        }
        $this->app->audit->record($this->app->actor(), 'google_settings', null, null, [], $req->ip);
        return Response::redirect('/settings#google');
    }

    /** Applies pending database migrations (no SSH on the hosting). Always backs up first. */
    public function migrate(Request $req): Response
    {
        $migrator = new Migrator($this->app->db, BL_ROOT . '/migrations');
        if (!$migrator->pending()) {
            $this->app->session->flash('ok', 'Базата вече е обновена.');
            return Response::redirect('/settings');
        }
        @set_time_limit(120);
        $file = (new Backup($this->app->db, $this->backupDir()))->create('pre-migrate');
        $done = $migrator->migrate();
        $this->app->audit->record($this->app->actor(), 'migrate', null, null, ['applied' => $done, 'backup' => basename($file)], $req->ip);
        $this->app->session->flash('ok', 'Базата е обновена (' . implode(', ', $done) . '). Бекъп: ' . basename($file));
        return Response::redirect('/');
    }
}
