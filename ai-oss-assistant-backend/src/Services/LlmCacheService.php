<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Database;
use PDO;
use Throwable;

class LlmCacheService
{
    private static ?array $inMemoryCache = [];

    /**
     * Calculates SHA256 content hash key.
     */
    public static function generateCacheKey(string $content): string
    {
        return hash('sha256', trim($content));
    }

    /**
     * Looks up cached LLM result by content hash and type.
     */
    public static function get(string $content, string $type): ?string
    {
        $key = self::generateCacheKey($content);

        // Check memory cache first
        if (isset(self::$inMemoryCache["{$key}:{$type}"])) {
            return self::$inMemoryCache["{$key}:{$type}"];
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT result_ref FROM llm_cache WHERE cache_key = ? AND cache_type = ? LIMIT 1");
            $stmt->execute([$key, $type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && isset($row['result_ref'])) {
                self::$inMemoryCache["{$key}:{$type}"] = $row['result_ref'];
                return $row['result_ref'];
            }
        } catch (Throwable $e) {
            // DB fallback
        }

        return null;
    }

    /**
     * Stores LLM result in cache keyed by SHA256 content hash and type.
     */
    public static function set(string $content, string $type, string $result): void
    {
        $key = self::generateCacheKey($content);
        self::$inMemoryCache["{$key}:{$type}"] = $result;

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO llm_cache (cache_key, cache_type, result_ref)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE result_ref = VALUES(result_ref), created_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$key, $type, $result]);
        } catch (Throwable $e) {
            // DB fallback
        }
    }

    public static function clearInMemory(): void
    {
        self::$inMemoryCache = [];
    }
}
