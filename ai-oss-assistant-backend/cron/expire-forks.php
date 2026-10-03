<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Database;
use AiOssAssistant\Services\GitHubService;
use AiOssAssistant\Models\Repo;

Config::load();

echo "[" . date('Y-m-d H:i:s') . "] Starting fork auto-expiry cleanup cron job...\n";

try {
    $pdo = Database::getConnection();
    $github = new GitHubService();

    // Query forks past 14 days without PR
    $stmt = $pdo->query("
        SELECT f.*, r.full_name, r.id as repo_id 
        FROM forks f 
        JOIN repos r ON f.repo_id = r.id 
        WHERE f.created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
        AND r.status NOT IN ('pr_open', 'clean_deleted', 'expired')
    ");
    $expiredForks = $stmt->fetchAll();

    echo "Found " . count($expiredForks) . " expired forks past 14-day window.\n";

    foreach ($expiredForks as $fork) {
        [$owner, $repoName] = explode('/', $fork['full_name']);
        echo " [EXPIRING] Deleting fork {$fork['fork_url']}...\n";

        try {
            $github->deleteFork($owner, $repoName);
        } catch (Throwable $e) {
            echo "  [WARN] GitHub API delete error: " . $e->getMessage() . "\n";
        }

        Repo::updateStatus((int) $fork['repo_id'], 'expired');
    }

    echo "[" . date('Y-m-d H:i:s') . "] Fork expiry cleanup complete.\n";
} catch (Throwable $e) {
    echo "\n[ERROR] Fork expiry job failed: " . $e->getMessage() . "\n";
    exit(1);
}
