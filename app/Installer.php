<?php

declare(strict_types=1);

namespace BarberLead;

use BarberLead\Http\Request;
use BarberLead\Http\Response;
use BarberLead\Http\Session;

/**
 * One-time web installer. Active only while config/config.php does NOT exist and a setup token
 * hash (config/setup_token.hash) was deployed. The owner types the database password and his
 * admin password directly into this form, so no secret ever passes through chat or Git.
 * On success it writes config/config.php (0600), runs migrations, creates the owner account
 * and deletes the token hash, which disables the installer permanently.
 */
final class Installer
{
    private string $configFile;
    private string $tokenFile;

    public function __construct(private string $root, private Session $session)
    {
        $this->configFile = $root . '/config/config.php';
        $this->tokenFile = $root . '/config/setup_token.hash';
    }

    public function handle(Request $req): Response
    {
        if (is_file($this->configFile)) {
            return Response::redirect('/');
        }
        if (!is_file($this->tokenFile)) {
            return new Response($this->page('<p>Системата още не е настроена, а инсталацията не е активирана (липсва setup token).</p>'), 503);
        }
        if ($req->method !== 'POST') {
            return new Response($this->page($this->form([], [])));
        }
        if (!$this->session->checkCsrf($req->str('_csrf'))) {
            return new Response($this->page('<p>Формата е изтекла. Презареди страницата.</p>'), 419);
        }
        $in = [
            'token' => $req->str('token'),
            'db_host' => $req->str('db_host', 'localhost') ?: 'localhost',
            'db_name' => $req->str('db_name'),
            'db_user' => $req->str('db_user'),
            'db_pass' => (string) ($req->post['db_pass'] ?? ''),
            'username' => $req->str('username'),
            'password' => (string) ($req->post['password'] ?? ''),
            'password2' => (string) ($req->post['password2'] ?? ''),
            'base_url' => $req->str('base_url'),
        ];
        $errors = $this->validate($in);
        if ($errors) {
            return new Response($this->page($this->form($in, $errors)), 422);
        }
        try {
            $this->install($in);
        } catch (\Throwable $e) {
            return new Response($this->page($this->form($in, ['db' => 'Връзката с базата или миграцията не успя: ' . $e->getMessage()])), 500);
        }
        return new Response($this->page('<h2>Готово</h2><p>Системата е настроена. Инсталаторът е изключен.</p><p><a class="btn btn-k" href="/login">Към входа</a></p>'));
    }

    /** @param array<string,string> $in @return array<string,string> */
    private function validate(array $in): array
    {
        $errors = [];
        $hash = trim((string) file_get_contents($this->tokenFile));
        if (!password_verify($in['token'], $hash)) {
            $errors['token'] = 'Грешен код за инсталация.';
        }
        foreach (['db_name' => 'Име на базата', 'db_user' => 'Потребител на базата', 'username' => 'Потребителско име'] as $k => $label) {
            if ($in[$k] === '') {
                $errors[$k] = "$label е задължително.";
            }
        }
        if (!preg_match('/^[a-z0-9_.-]{3,64}$/i', $in['username'])) {
            $errors['username'] = 'Само латински букви, цифри, точка, тире и долна черта (3–64).';
        }
        if ($err = Auth::validatePassword($in['password'])) {
            $errors['password'] = $err;
        } elseif ($in['password'] !== $in['password2']) {
            $errors['password2'] = 'Паролите не съвпадат.';
        }
        return $errors;
    }

    /** @param array<string,string> $in */
    private function install(array $in): void
    {
        $config = [
            'db' => ['host' => $in['db_host'], 'port' => 3306, 'name' => $in['db_name'], 'user' => $in['db_user'], 'pass' => $in['db_pass']],
            'app' => ['env' => 'production', 'base_url' => $in['base_url'], 'secure_cookies' => true],
            'backup_dir' => 'storage/backups',
        ];
        $db = Database::connect($config['db']);
        $migrator = new Migrator($db, $this->root . '/migrations');
        if ($migrator->applied()) {
            // Existing data: take a backup before touching anything.
            (new Backup($db, $this->root . '/storage/backups'))->create('pre-install');
        }
        $migrator->migrate();
        $auth = new Auth($db, $this->session);
        if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $auth->createUser($in['username'], $in['password']);
        }
        $php = "<?php\n// Written by the BarberLead installer on " . date('c') . ". Keep out of Git.\nreturn " . var_export($config, true) . ";\n";
        $tmp = $this->configFile . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $this->configFile)) {
            throw new \RuntimeException('Не мога да запиша config/config.php (права за писане?)');
        }
        unlink($this->tokenFile);
        (new Repositories\AuditLog($db))->record($in['username'], 'installed');
    }

    /** @param array<string,string> $v @param array<string,string> $errors */
    private function form(array $v, array $errors): string
    {
        $err = fn (string $k) => isset($errors[$k]) ? '<span class="field-err">' . e($errors[$k]) . '</span>' : '';
        $val = fn (string $k, string $d = '') => e($v[$k] ?? $d);
        $base = 'https://' . e($_SERVER['HTTP_HOST'] ?? 'crm.testvane.org');
        return '<h2>Първоначална настройка</h2>
<p class="muted">Попълва се веднъж. Паролите отиват само в config.php на сървъра.</p>
' . (isset($errors['db']) ? '<div class="flash err">' . e($errors['db']) . '</div>' : '') . '
<form method="post" class="form">
<input type="hidden" name="_csrf" value="' . e($this->session->csrfToken()) . '">
<input type="hidden" name="base_url" value="' . $base . '">
<label>Код за инсталация<input name="token" required autocomplete="off" value="' . $val('token') . '">' . $err('token') . '</label>
<fieldset><legend>База данни (от cPanel → MySQL Databases)</legend>
<label>Сървър<input name="db_host" value="' . $val('db_host', 'localhost') . '"></label>
<label>Име на базата<input name="db_name" required value="' . $val('db_name', 'martest_barberlead') . '">' . $err('db_name') . '</label>
<label>Потребител<input name="db_user" required value="' . $val('db_user', 'martest_barberlead') . '">' . $err('db_user') . '</label>
<label>Парола на базата<input name="db_pass" type="password" autocomplete="off"></label>
</fieldset>
<fieldset><legend>Твоят вход в CRM-а</legend>
<label>Потребителско име<input name="username" required value="' . $val('username', 'martin') . '" autocapitalize="off">' . $err('username') . '</label>
<label>Парола (поне ' . Auth::MIN_PASSWORD_LENGTH . ' символа)<input name="password" type="password" required autocomplete="new-password">' . $err('password') . '</label>
<label>Паролата още веднъж<input name="password2" type="password" required autocomplete="new-password">' . $err('password2') . '</label>
</fieldset>
<button class="btn btn-k">Настрой</button>
</form>';
    }

    private function page(string $body): string
    {
        return '<!DOCTYPE html><html lang="bg"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>Настройка · BarberLead</title>'
            . '<link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><main class="auth-box">'
            . '<div class="brand-lg">BARBER<b>LEAD</b></div>' . $body . '</main></body></html>';
    }
}
