<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Config;

class RateLimiterService
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? sys_get_temp_dir() . '/ai_oss_ratelimit';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Checks rate limit for a client IP. Returns true if allowed, false if limit exceeded.
     *
     * @param string $action Action key (e.g. 'general', 'chat')
     * @param int $maxRequests Max requests allowed within window
     * @param int $windowSeconds Window duration in seconds (default 60s)
     * @return bool
     */
    public function check(string $action = 'general', int $maxRequests = 180, int $windowSeconds = 60): bool
    {
        // Skip rate limiting if running tests or disabled
        if (Config::get('APP_ENV') === 'testing' || Config::getBool('DISABLE_RATE_LIMITER', false)) {
            return true;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = md5("{$ip}_{$action}");
        $file = $this->storageDir . '/' . $key . '.json';

        $now = time();
        $record = ['count' => 0, 'reset_at' => $now + $windowSeconds];

        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && isset($parsed['reset_at']) && $parsed['reset_at'] > $now) {
                $record = $parsed;
            }
        }

        if ($record['count'] >= $maxRequests) {
            return false;
        }

        $record['count']++;
        @file_put_contents($file, json_encode($record), LOCK_EX);

        return true;
    }

    public static function enforceOrExit(string $action = 'general', int $maxRequests = 180, int $windowSeconds = 60): void
    {
        $limiter = new self();
        if (!$limiter->check($action, $maxRequests, $windowSeconds)) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: 60');
            echo json_encode([
                'error' => 'Too Many Requests: Rate limit exceeded. Please wait 60 seconds before retrying.',
                'status' => 429
            ]);
            exit();
        }
    }
}
