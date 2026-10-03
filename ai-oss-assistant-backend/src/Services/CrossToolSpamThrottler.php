<?php

namespace AiOssAssistant\Services;

class CrossToolSpamThrottler
{
    private GitHubService $githubService;

    public function __construct(?GitHubService $githubService = null)
    {
        $this->githubService = $githubService ?? new GitHubService();
    }

    /**
     * Hard skip check: returns true if the repo shows signs of AI-PR saturation (> 3 AI-attributed PRs in 14 days).
     */
    public function isSaturated(string $fullName): bool
    {
        [$owner, $repo] = explode('/', $fullName);

        try {
            $prs = $this->githubService->request('GET', "/repos/{$owner}/{$repo}/pulls?state=all&per_page=30");
            if (!is_array($prs)) return false;

            $aiPrCount = 0;
            $fourteenDaysAgo = time() - (14 * 86400);

            foreach ($prs as $pr) {
                $created = strtotime($pr['created_at'] ?? '');
                if ($created < $fourteenDaysAgo) continue;

                $title = strtolower($pr['title'] ?? '');
                $body = strtolower($pr['body'] ?? '');

                if (str_contains($title, '[ai]') ||
                    str_contains($body, 'ai-assisted') ||
                    str_contains($body, 'openhands') ||
                    str_contains($body, 'coderabbit') ||
                    str_contains($body, 'sweep.dev')) {
                    $aiPrCount++;
                }
            }

            return $aiPrCount >= 3;

        } catch (\Throwable $e) {
            return false;
        }
    }
}
