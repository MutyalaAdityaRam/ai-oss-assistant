<?php

namespace AiOssAssistant\Services;

class MaintainerResponsivenessService
{
    private GitHubService $githubService;

    public function __construct(?GitHubService $githubService = null)
    {
        $this->githubService = $githubService ?? new GitHubService();
    }

    /**
     * Calculates maintainer responsiveness score (0-100) based on recent external PR outcomes.
     */
    public function calculateResponsivenessScore(string $fullName): float
    {
        [$owner, $repo] = explode('/', $fullName);

        try {
            // Fetch last 20 closed PRs
            $prs = $this->githubService->request('GET', "/repos/{$owner}/{$repo}/pulls?state=closed&per_page=20");
            if (empty($prs) || !is_array($prs)) {
                return 50.0; // Default neutral score
            }

            $mergedCount = 0;
            $totalDays = 0;

            foreach ($prs as $pr) {
                if (!empty($pr['merged_at'])) {
                    $mergedCount++;
                    $created = strtotime($pr['created_at']);
                    $merged = strtotime($pr['merged_at']);
                    $days = max(1, ($merged - $created) / 86400);
                    $totalDays += $days;
                }
            }

            $mergeRate = $mergedCount / max(count($prs), 1);
            $avgDays = $mergedCount > 0 ? ($totalDays / $mergedCount) : 14;

            // Score formula: 70% weight on merge rate, 30% weight on speed (capped at 14 days)
            $rateScore = $mergeRate * 100;
            $speedScore = max(0, 100 - ($avgDays / 14 * 100));

            $finalScore = ($rateScore * 0.7) + ($speedScore * 0.3);
            return round($finalScore, 2);

        } catch (\Throwable $e) {
            return 50.0; // Fallback neutral score
        }
    }
}
