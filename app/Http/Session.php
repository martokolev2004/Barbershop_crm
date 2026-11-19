<?php

declare(strict_types=1);

namespace BarberLead\Http;

/** Thin wrapper so the session can be faked in tests. */
class Session
{
    public static function start(bool $secure, string $savePath): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0700, true);
        }
        // Own directory: shared-hosting /tmp session GC would log the owner out unpredictably.
        session_save_path($savePath);
        ini_set('session.gc_maxlifetime', (string) (30 * 86400));
        ini_set('session.use_strict_mode', '1');
        session_name('bl_session');
        session_set_cookie_params([
            'lifetime' => 30 * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** One-time message shown on the next page. */
    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = [$type, $message];
    }

    /** @return list<array{string,string}> */
    public function takeFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? array_values($f) : [];
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public function checkCsrf(string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($expected) && $token !== '' && hash_equals($expected, $token);
    }
}
