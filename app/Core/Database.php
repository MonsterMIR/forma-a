<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = require BASE_PATH . '/config/database.php';

            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $config['driver'],
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $config['username'],
                    $config['password'],
                    $config['options']
                );
                // ИСПРАВЛЕНО (14.08.2026, п.4): выравниваем часовой пояс сессии
                // MySQL с часовым поясом PHP (Asia/Tashkent, см. public/index.php),
                // иначе NOW() в БД (используется в FlightDocument::getPendingOverdue)
                // и время в PHP (DeadlineService) расходятся, из-за чего контроль
                // сроков документооборота считает опоздания/остаток времени неверно.
                self::$instance->exec("SET time_zone = '+05:00'");
            } catch (PDOException $e) {
                $logPath = BASE_PATH . '/storage/logs/error-' . date('Y-m-d') . '.log';
                @file_put_contents(
                    $logPath,
                    '[' . date('Y-m-d H:i:s') . '] DB Connection failed: ' . $e->getMessage() . PHP_EOL,
                    FILE_APPEND
                );
                throw new RuntimeException('Database connection failed.');
            }
        }

        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
}