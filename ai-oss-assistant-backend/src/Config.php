<?php

namespace AiOssAssistant;

use Dotenv\Dotenv;

class Config
{
    private static bool $loaded = false;

    public static function load(string $dir = __DIR__ . '/..'): void
    {
        if (self::$loaded) {
            return;
        }

        if (file_exists($dir . '/.env')) {
            $dotenv = Dotenv::createImmutable($dir);
            $dotenv->safeLoad();
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return $default;
        }
        return $val;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key, null);
        if ($val === null) {
            return $default;
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }
}
