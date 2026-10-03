<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Services\GitHubService;
use AiOssAssistant\Services\RankingService;
use AiOssAssistant\Services\SourcegraphService;
use AiOssAssistant\Models\Repo;

Config::load();

echo "[" . date('Y-m-d H:i:s') . "] Starting daily repo discovery cron job...\n";

try {
    $github = new GitHubService();
    $sourcegraph = new SourcegraphService();

    // 1. Search GitHub API for candidate repositories
    $query = "stars:>500 language:typescript language:python language:go language:php sort:updated-desc";
    $searchResult = $github->searchRepos($query, 15);
    $items = $searchResult['items'] ?? [];

    echo "Found " . count($items) . " raw candidate repos from GitHub Search.\n";

    $addedCount = 0;
    foreach ($items as $item) {
        // Calculate deterministic score using RankingService formula
        $score = RankingService::calculateScore($item);

        // Filter: only keep repos with score >= 40.0
        if ($score < 40.0) {
            continue;
        }

        // Save candidate repo to MySQL
        Repo::create([
            'full_name'     => $item['full_name'],
            'stars'         => $item['stargazers_count'] ?? 0,
            'last_activity' => date('Y-m-d', strtotime($item['pushed_at'] ?? 'now')),
            'resume_score'  => $score,
            'status'        => 'candidate',
        ]);

        $addedCount++;
        echo " [+] Candidate saved: {$item['full_name']} (Score: {$score})\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Discovery complete. {$addedCount} candidate repos inserted/updated.\n";
} catch (Throwable $e) {
    echo "\n[ERROR] Discovery cron failed: " . $e->getMessage() . "\n";
    exit(1);
}
