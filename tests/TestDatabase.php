<?php

declare(strict_types=1);

namespace BarberLead\Tests;

use BarberLead\Database;
use BarberLead\Migrator;
use PDO;

/** Disposable test database. Refuses to run against anything not named *_test. */
final class TestDatabase
{
    /** @return array{host:string,name:string,user:string,pass:string} */
    public static function config(): array
    {
        $name = (string) getenv('BL_TEST_DB_NAME');
        if (!str_ends_with($name, '_test')) {
            throw new \RuntimeException('Test database name must end with _test');
        }
        return [
            'host' => (string) getenv('BL_TEST_DB_HOST'),
            'name' => $name,
            'user' => (string) getenv('BL_TEST_DB_USER'),
            'pass' => (string) getenv('BL_TEST_DB_PASS'),
        ];
    }

    public static function fresh(bool $migrate = true): PDO
    {
        $db = Database::connect(self::config());
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $db->exec("DROP TABLE `$t`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        if ($migrate) {
            (new Migrator($db, dirname(__DIR__) . '/migrations'))->migrate();
        }
        return $db;
    }
}
