<?php

declare(strict_types=1);

namespace BarberLead;

use PDO;
use RuntimeException;

/**
 * Pure-PHP logical backup (shared hosting often disables exec(), so mysqldump is not assumed).
 * Output: gzip-compressed SQL with CREATE TABLE + INSERT statements, restorable with restore().
 */
final class Backup
{
    public function __construct(private PDO $db, private string $dir)
    {
    }

    public function create(string $label = 'manual'): string
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create backup directory {$this->dir}");
        }
        $label = preg_replace('/[^a-z0-9_-]/i', '', $label) ?: 'manual';
        $file = sprintf('%s/barberlead-%s-%s.sql.gz', $this->dir, date('Ymd-His'), $label);
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Cannot write $file");
        }
        gzwrite($gz, "-- BarberLead backup " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $this->db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "\nDROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n");
            $stmt = $this->db->query("SELECT * FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values = array_map(fn ($v) => $v === null ? 'NULL' : $this->db->quote((string) $v), $row);
                $cols = implode('`,`', array_keys($row));
                gzwrite($gz, "INSERT INTO `$table` (`$cols`) VALUES (" . implode(',', $values) . ");\n");
            }
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        chmod($file, 0600);
        return $file;
    }

    /** Replaces the whole database content with the backup. Caller must take a fresh backup first. */
    public function restore(string $file): void
    {
        $sql = gzdecode((string) file_get_contents($file));
        if ($sql === false || !str_starts_with($sql, '-- BarberLead backup')) {
            throw new RuntimeException('Not a BarberLead backup file');
        }
        // Statements are one per line except CREATE TABLE, which spans lines and ends with ";\n".
        $buffer = '';
        foreach (explode("\n", $sql) as $line) {
            if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), ';') && self::isComplete($buffer)) {
                $this->db->exec($buffer);
                $buffer = '';
            }
        }
    }

    /** A statement is complete when it is not inside an open quoted string. */
    private static function isComplete(string $sql): bool
    {
        $inQuote = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c === '\\' && $inQuote) {
                $i++;
                continue;
            }
            if ($c === "'") {
                $inQuote = !$inQuote;
            }
        }
        return !$inQuote;
    }

    /** Deletes old backups, keeping the newest $keep files. */
    public function prune(int $keep = 30): int
    {
        $files = glob($this->dir . '/barberlead-*.sql.gz') ?: [];
        rsort($files);
        $removed = 0;
        foreach (array_slice($files, $keep) as $old) {
            if (unlink($old)) {
                $removed++;
            }
        }
        return $removed;
    }
}
