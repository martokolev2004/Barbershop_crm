<?php

declare(strict_types=1);

namespace BarberLead;

use BarberLead\Http\Session;
use PDO;

final class Auth
{
    public const MAX_FAILS_PER_USER = 5;
    public const MAX_FAILS_PER_IP = 20;
    public const WINDOW_MINUTES = 15;
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(private PDO $db, private Session $session)
    {
    }

    /** @return array{ok:bool, error?:string} */
    public function attempt(string $username, string $password, string $ip): array
    {
        $username = mb_strtolower(trim($username));
        if ($this->isLockedOut($username, $ip)) {
            return ['ok' => false, 'error' => 'Твърде много неуспешни опити. Опитай пак след ' . self::WINDOW_MINUTES . ' минути.'];
        }
        $stmt = $this->db->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        // Verify against a dummy hash when the user does not exist, so timing does not reveal valid usernames.
        $hash = $user['password_hash'] ?? '$2y$10$vjZ4./8wyuiZo4X4RRgJvuHSOrUc0B62prktjqsrDWH5oM1ktR.Ye';
        $ok = password_verify($password, $hash) && $user !== false;

        $this->db->prepare('INSERT INTO login_attempts (ip, username, success) VALUES (?, ?, ?)')
            ->execute([$ip, $username, $ok ? 1 : 0]);

        if (!$ok) {
            return ['ok' => false, 'error' => 'Грешно потребителско име или парола.'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('username', $user['username']);
        return ['ok' => true];
    }

    public function isLockedOut(string $username, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at >= ?');
        $stmt->execute([$username, $since]);
        if ((int) $stmt->fetchColumn() >= self::MAX_FAILS_PER_USER) {
            return true;
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= ?');
        $stmt->execute([$ip, $since]);
        return (int) $stmt->fetchColumn() >= self::MAX_FAILS_PER_IP;
    }

    public function userId(): ?int
    {
        $id = $this->session->get('user_id');
        return is_int($id) ? $id : null;
    }

    public function username(): ?string
    {
        $u = $this->session->get('username');
        return is_string($u) ? $u : null;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    public static function validatePassword(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Паролата трябва да е поне ' . self::MIN_PASSWORD_LENGTH . ' символа.';
        }
        return null;
    }

    public function createUser(string $username, string $password): int
    {
        $this->db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([mb_strtolower(trim($username)), password_hash($password, PASSWORD_DEFAULT)]);
        return (int) $this->db->lastInsertId();
    }

    /** @return string|null error message */
    public function changePassword(int $userId, string $current, string $new): ?string
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || !password_verify($current, $hash)) {
            return 'Текущата парола е грешна.';
        }
        if ($err = self::validatePassword($new)) {
            return $err;
        }
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        $this->session->regenerate();
        return null;
    }

    /** Housekeeping: login attempts older than 30 days are not needed. */
    public function pruneAttempts(): void
    {
        $this->db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
    }
}
