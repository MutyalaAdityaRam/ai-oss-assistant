<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Database;
use PDO;

class AutomationControlService
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_RUN_ONCE = 'run_once';

    /**
     * Get current automation status ('active', 'paused', 'run_once')
     */
    public static function getStatus(): string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT automation_status FROM users WHERE id = 1 LIMIT 1");
        $val = $stmt->fetchColumn();
        return $val ?: self::STATUS_ACTIVE;
    }

    /**
     * Get detailed automation status info
     */
    public static function getDetails(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT automation_status, last_run_at, paused_at, run_once_at FROM users WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $status = $row['automation_status'] ?? self::STATUS_ACTIVE;
        $isPaused = ($status === self::STATUS_PAUSED);
        $isRunOnce = ($status === self::STATUS_RUN_ONCE);

        return [
            'automation_status'    => $status,
            'is_paused'            => $isPaused,
            'is_run_once'          => $isRunOnce,
            'can_search_and_scan'  => !$isPaused,
            'last_run_at'          => $row['last_run_at'] ?? null,
            'paused_at'            => $row['paused_at'] ?? null,
            'run_once_at'          => $row['run_once_at'] ?? null,
            'description'          => match ($status) {
                self::STATUS_PAUSED   => 'Automation is PAUSED. Repository searching, cloning, scanning, and bug fixing are halted. Interactive services (PR accepts, PR declines, chat bot, repo deletion) remain active.',
                self::STATUS_RUN_ONCE => 'Automation is set to RUN ONCE for today. It will execute one cycle and then automatically pause all future scheduled searches and fixes.',
                default               => 'Automation is ACTIVE. Autonomous daily repository discovery, vulnerability scanning, and bug fixing run on schedule.',
            }
        ];
    }

    /**
     * Pause automated discovery, scanning, and fixing
     */
    public static function pause(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE users SET automation_status = ?, paused_at = NOW() WHERE id = 1");
        $stmt->execute([self::STATUS_PAUSED]);

        return self::getDetails();
    }

    /**
     * Resume automated discovery, scanning, and fixing
     */
    public static function resume(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE users SET automation_status = ?, paused_at = NULL, run_once_at = NULL WHERE id = 1");
        $stmt->execute([self::STATUS_ACTIVE]);

        return self::getDetails();
    }

    /**
     * Set to Run Once mode (runs one cycle today, then automatically pauses)
     */
    public static function runOnce(bool $triggerNow = false): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE users SET automation_status = ?, run_once_at = NOW() WHERE id = 1");
        $stmt->execute([self::STATUS_RUN_ONCE]);

        if ($triggerNow) {
            self::triggerBackgroundRun();
        }

        return self::getDetails();
    }

    /**
     * Checks if automated repository discovery, scanning, and fixing should run
     */
    public static function shouldSearchAndScan(): bool
    {
        $status = self::getStatus();
        return $status !== self::STATUS_PAUSED;
    }

    /**
     * Called at the end of the pipeline worker cycle.
     * If the status was 'run_once', transitions to 'paused'.
     */
    public static function markRunCompleted(): void
    {
        $pdo = Database::getConnection();
        $status = self::getStatus();

        if ($status === self::STATUS_RUN_ONCE) {
            // Transition to paused after completing the single run
            $stmt = $pdo->prepare("UPDATE users SET automation_status = ?, last_run_at = NOW(), paused_at = NOW() WHERE id = 1");
            $stmt->execute([self::STATUS_PAUSED]);
        } else {
            // Keep active, record last_run_at
            $stmt = $pdo->prepare("UPDATE users SET last_run_at = NOW() WHERE id = 1");
            $stmt->execute();
        }
    }

    /**
     * Helper to spawn background pipeline process asynchronously without blocking HTTP response
     */
    public static function triggerBackgroundRun(): void
    {
        $scriptPath = realpath(__DIR__ . '/../../cron/auto-process-pipeline.php');
        if (!$scriptPath || !file_exists($scriptPath)) {
            return;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $phpBin = PHP_BINARY ?: ($isWindows ? 'c:\\xampp\\php\\php.exe' : 'php');

        if ($isWindows) {
            pclose(popen("start /B \"\" \"{$phpBin}\" \"{$scriptPath}\"", "r"));
        } else {
            exec("nohup {$phpBin} " . escapeshellarg($scriptPath) . " > /dev/null 2>&1 &");
        }
    }
}
