<?php

declare(strict_types=1);

namespace BarberLead;

use PDO;
use RuntimeException;

/**
 * Migrations are data-only PHP files `NNNN_name.php` returning ['up' => steps, 'down' => steps].
 * A step is either an SQL string or ['task', '<name>'] for a named data task in Seeds.
 * (Data-only on purpose: the hosting's deploy checker refuses .sql files and dynamic calls.)
 *
 * MariaDB commits DDL implicitly, so a failed migration can be partially applied: bin/migrate.php
 * always writes a backup first. Rollbacks that drop tables refuse to run without force.
 */
final class Migrator
{
    public function __construct(private PDO $db, private string $dir)
    {
    }

    public function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    public function available(): array
    {
        $versions = [];
        foreach (glob($this->dir . '/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $file) {
            $versions[] = basename($file, '.php');
        }
        sort($versions);
        return $versions;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /** @return list<string> versions applied in this run */
    public function migrate(): array
    {
        $done = [];
        foreach ($this->pending() as $version) {
            $this->runSteps($this->load($version)['up']);
            $this->db->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $done[] = $version;
        }
        return $done;
    }

    public function rollback(bool $force = false): ?string
    {
        $applied = $this->applied();
        $version = end($applied);
        if ($version === false) {
            return null;
        }
        $down = $this->load($version)['down'];
        if (!$force) {
            foreach ($down as $step) {
                if (is_string($step) && preg_match('/\bDROP\s+TABLE\b/i', $step)) {
                    throw new RuntimeException("Rollback of $version drops tables (data loss). Re-run with --force after checking the backup.");
                }
            }
        }
        $this->runSteps($down);
        $this->db->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$version]);
        return $version;
    }

    /** @return array{up: list<mixed>, down: list<mixed>} */
    private function load(string $version): array
    {
        $file = "{$this->dir}/{$version}.php";
        $data = is_file($file) ? require $file : null;
        if (!is_array($data) || !isset($data['up'], $data['down']) || !is_array($data['up']) || !is_array($data['down'])) {
            throw new RuntimeException("Migration $version must return ['up' => [...], 'down' => [...]]");
        }
        return ['up' => array_values($data['up']), 'down' => array_values($data['down'])];
    }

    /** @param list<mixed> $steps */
    private function runSteps(array $steps): void
    {
        foreach ($steps as $step) {
            if (is_string($step)) {
                $this->db->exec($step);
                continue;
            }
            if (!is_array($step) || ($step[0] ?? null) !== 'task' || !is_string($step[1] ?? null)) {
                throw new RuntimeException('Invalid migration step');
            }
            $this->db->beginTransaction();
            try {
                $this->runTask($step[1]);
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }
    }

    private function runTask(string $name): void
    {
        match ($name) {
            'seed_areas' => Seeds::seedAreas($this->db),
            'unseed_areas' => Seeds::unseedAreas($this->db),
            'import_prime' => Seeds::importPrime($this->db),
            'remove_prime' => Seeds::removePrime($this->db),
            default => throw new RuntimeException("Unknown migration task $name"),
        };
    }
}
