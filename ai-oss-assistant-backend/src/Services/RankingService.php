<?php

namespace AiOssAssistant\Services;

class RankingService
{
    /**
     * Scores a repository based on resume relevance, maintainer responsiveness, and target skill matches.
     *
     * @param array $repoData GitHub repo API object or associative array
     * @param array $targetSkills User target skills array (e.g. ["Rust", "distributed-systems"])
     * @return float Score between 0.00 and 100.00
     */
    public static function calculateScore(array $repoData, array $targetSkills = []): float
    {
        $stars = max(0, (int) ($repoData['stargazers_count'] ?? $repoData['stars'] ?? 0));
        
        // Stars Weight (log scale, cap at 50 points)
        $starsScore = $stars > 0 ? min(50.0, log($stars, 10) * 12.5) : 0.0;

        // Recency / Activity Weight (cap at 20 points)
        $lastCommitDate = $repoData['pushed_at'] ?? $repoData['last_activity'] ?? null;
        $activityScore = 0.0;
        if ($lastCommitDate) {
            $daysOld = max(0, (time() - strtotime($lastCommitDate)) / 86400);
            if ($daysOld <= 30) {
                $activityScore = 20.0;
            } elseif ($daysOld <= 180) {
                $activityScore = 20.0 * (1.0 - (($daysOld - 30) / 150));
            } else {
                $activityScore = 2.0;
            }
        }

        // Documentation Quality (cap at 15 points)
        $docScore = 0.0;
        if (!empty($repoData['has_readme'])) {
            $docScore += 5.0;
        }
        if (!empty($repoData['has_contributing'])) {
            $docScore += 5.0;
        }
        if (!empty($repoData['license'])) {
            $docScore += 5.0;
        }

        // Good First Issues (cap at 10 points)
        $goodFirstIssues = (int) ($repoData['good_first_issue_count'] ?? 0);
        $issueScore = min(10.0, $goodFirstIssues * 2.0);

        // Tech Relevance (cap at 5 points)
        $language = strtolower($repoData['language'] ?? '');
        $targetLanguages = ['javascript', 'typescript', 'python', 'go', 'java', 'rust', 'php'];
        $techScore = in_array($language, $targetLanguages, true) ? 5.0 : 2.0;

        // B5: Maintainer Responsiveness Score (cap at 10 points when present)
        $respScore = 0.0;
        if (isset($repoData['maintainer_responsiveness_score'])) {
            $respScore = min(10.0, ($repoData['maintainer_responsiveness_score'] / 100.0) * 10.0);
        }

        // C9: Skill-Gap-Aware Bonus (cap at 10 points when present)
        $skillBonus = 0.0;
        if (!empty($targetSkills)) {
            $topics = array_map('strtolower', $repoData['topics'] ?? []);
            foreach ($targetSkills as $skill) {
                $s = strtolower($skill);
                if ($s === $language || in_array($s, $topics, true)) {
                    $skillBonus += 5.0;
                }
            }
            $skillBonus = min(10.0, $skillBonus);
        }

        $totalScore = $starsScore + $activityScore + $docScore + $issueScore + $techScore + $respScore + $skillBonus;

        return round(min(100.0, max(0.0, $totalScore)), 2);
    }
}
