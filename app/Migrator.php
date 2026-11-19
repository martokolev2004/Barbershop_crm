<?php

declare(strict_types=1);

namespace BarberLead;

use PDO;
use RuntimeException;

/**
 * Forward-only by default. Each migration NNNN_name has an .up.sql or .up.php file and a matching
 * .down.(sql|php) rollback. A migration whose down step drops data refuses to run without force.
 *
 * Note: MariaDB commits DDL implicitly, so a failed SQL migration may be partially applied.
 * That is why bin/migrate.php always writes a backup before applying anything.
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

    /** @return list<string> all migration versions found on disk, sorted */
    public function available(): array
    {
        $versions = [];
        foreach (glob($this->dir . '/*.up.{sql,php}', GLOB_BRACE) ?: [] as $file) {
            $versions[] = (string) preg_replace('/\.up\.(sql|php)$/', '', basename($file));
        }
        sort($versions);
        return array_values(array_unique($versions));
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
            $this->run($version, 'up');
            $this->db->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $done[] = $version;
        }
        return $done;
    }

    /** Rolls back the most recent migration. */
    public function rollback(bool $force = false): ?string
    {
        $applied = $this->applied();
        $version = end($applied);
        if ($version === false) {
            return null;
        }
        $down = $this->file($version, 'down');
        if (!$force && str_ends_with($down, '.sql') && preg_match('/\bDROP\s+TABLE\b/i', (string) file_get_contents($down))) {
            throw new RuntimeException("Rollback of $version drops tables (data loss). Re-run with --force after checking the backup.");
        }
        $this->run($version, 'down');
        $this->db->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$version]);
        return $version;
    }

    private function file(string $version, string $direction): string
    {
        foreach (['sql', 'php'] as $ext) {
            $path = "{$this->dir}/{$version}.{$direction}.{$ext}";
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException("Missing $direction file for migration $version");
    }

    private function run(string $version, string $direction): void
    {
        $path = $this->file($version, $direction);
        if (str_ends_with($path, '.php')) {
            $fn = require $path;
            if (!is_callable($fn)) {
                throw new RuntimeException("$path must return a callable(PDO)");
            }
            $this->db->beginTransaction();
            try {
                $fn($this->db);
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
            return;
        }
        foreach (self::splitSql((string) file_get_contents($path)) as $statement) {
            $this->db->exec($statement);
        }
    }

    /** @return list<string> */
    public static function splitSql(string $sql): array
    {
        // Strip "-- comment" lines, then split on semicolons that end a line (no procedures used here).
        $lines = array_filter(explode("\n", $sql), fn ($l) => !preg_match('/^\s*--/', $l));
        $parts = preg_split('/;\s*(\n|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
