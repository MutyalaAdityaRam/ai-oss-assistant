<?php

namespace AiOssAssistant\Services;

class DuplicateWorkDetector
{
    private GitHubService $githubService;

    public function __construct(?GitHubService $githubService = null)
    {
        $this->githubService = $githubService ?? new GitHubService();
    }

    /**
     * Checks if an open PR or issue already addresses the same finding/title.
     */
    public function isDuplicate(string $fullName, string $findingTitle): bool
    {
        [$owner, $repo] = explode('/', $fullName);

        try {
            // Check open PRs
            $prs = $this->githubService->request('GET', "/repos/{$owner}/{$repo}/pulls?state=open&per_page=15");
            if (is_array($prs)) {
                foreach ($prs as $pr) {
                    $title = strtolower($pr['title'] ?? '');
                    if ($this->hasStrongSimilarity($title, strtolower($findingTitle))) {
                        return true;
                    }
                }
            }

            // Check open issues
            $issues = $this->githubService->request('GET', "/repos/{$owner}/{$repo}/issues?state=open&per_page=15");
            if (is_array($issues)) {
                foreach ($issues as $issue) {
                    $title = strtolower($issue['title'] ?? '');
                    if ($this->hasStrongSimilarity($title, strtolower($findingTitle))) {
                        return true;
                    }
                }
            }

            return false;

        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasStrongSimilarity(string $t1, string $t2): bool
    {
        $words1 = array_filter(explode(' ', re_sub('/[^a-z0-9 ]/', '', $t1)));
        $words2 = array_filter(explode(' ', re_sub('/[^a-z0-9 ]/', '', $t2)));

        if (empty($words1) || empty($words2)) return false;

        $intersection = array_intersect($words1, $words2);
        $minLen = min(count($words1), count($words2));

        return (count($intersection) / max($minLen, 1)) >= 0.6;
    }
}

function re_sub($pattern, $replacement, $subject) {
    return preg_replace($pattern, $replacement, $subject);
}
