<?php

declare(strict_types=1);

namespace BarberLead;

use PDO;

final class Database
{
    /** @param array{host:string,port?:int,name:string,user:string,pass:string} $cfg */
    public static function connect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'] ?? 3306,
            $cfg['name']
        );
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Bulgarian local time for DEFAULT CURRENT_TIMESTAMP values.
        $pdo->exec("SET time_zone = '" . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Sofia')))->format('P') . "'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }
}
