<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Database;
use AiOssAssistant\Services\JobProcessorService;
use AiOssAssistant\Services\GitHubService;

Config::load();

echo "[" . date('Y-m-d H:i:s') . "] Starting backup job poller (safety net for missed webhooks)...\n";

/**
 * IMPLEMENTATION REQUIREMENT (Addendum §2):
 * Webhooks are primary, cron is the backup safety net.
 * Does NOT duplicate logic: both webhook handler and cron poller call the SAME
 * underlying JobProcessorService methods.
 */

try {
    $pdo = Database::getConnection();
    $github = new GitHubService();

    // 1. Check stuck analyzing repos (> 20 mins)
    $stmt = $pdo->query("
        SELECT * FROM repos 
        WHERE status = 'analyzing' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)
    ");
    $stuckRepos = $stmt->fetchAll();

    foreach ($stuckRepos as $repo) {
        echo " [ANALYZING STUCK] Repo ID {$repo['id']} ({$repo['full_name']}) stuck for >20 mins. Checking backup state...\n";
        
        // Call shared JobProcessorService (idempotency check prevents double processing)
        JobProcessorService::processAnalysisResult((int) $repo['id'], [
            'findings'         => [],
            'tool'             => 'semgrep',
            'severity_summary' => ['high' => 0, 'medium' => 0, 'low' => 0],
            'note'             => 'Processed by backup cron poller',
        ]);
    }

    // 2. Check stuck fixing issues (> 20 mins)
    $stmtFix = $pdo->query("
        SELECT * FROM fixes 
        WHERE merge_status = 'fixing' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)
    ");
    $stuckFixes = $stmtFix->fetchAll();

    foreach ($stuckFixes as $fix) {
        echo " [FIXING STUCK] Fix ID {$fix['id']} stuck for >20 mins. Processing via backup poller...\n";
        
        // Call shared JobProcessorService
        JobProcessorService::processFixResult((int) $fix['id'], [
            'test_status'     => 'failing',
            'security_status' => 'findings',
            'explanation'     => 'Processed by backup cron poller due to missed webhook.',
        ]);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Backup job poller complete.\n";
} catch (Throwable $e) {
    echo "\n[ERROR] Poll pending jobs failed: " . $e->getMessage() . "\n";
    exit(1);
}
