<?php

declare(strict_types=1);

namespace BarberLead\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public function set(string $name, ?string $value): void
    {
        $this->db->prepare(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute([$name, $value]);
    }
}
