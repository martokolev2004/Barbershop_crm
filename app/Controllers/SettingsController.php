<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Backup;
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
}
