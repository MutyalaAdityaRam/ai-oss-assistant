<?php

namespace AiOssAssistant;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(?PDO $pdoOverride = null): PDO
    {
        if ($pdoOverride !== null) {
            self::$instance = $pdoOverride;
            return self::$instance;
        }

        if (self::$instance === null) {
            $host = Config::get('DB_HOST', 'localhost');
            $db   = Config::get('DB_NAME', 'ai_oss_assistant');
            $user = Config::get('DB_USER', 'root');
            $pass = Config::get('DB_PASS', '');
            $port = Config::get('DB_PORT', '3306');

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new RuntimeException("Database Connection Error: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return self::$instance;
    }

    public static function resetConnection(): void
    {
        self::$instance = null;
    }
}
