<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\RankingService;
use PHPUnit\Framework\TestCase;

class RankingServiceTest extends TestCase
{
    public function testScoreWithHighStarsAndActiveCommit(): void
    {
        $repoData = [
            'stargazers_count'     => 1000,
            'pushed_at'            => date('Y-m-d H:i:s', strtotime('-5 days')),
            'has_readme'           => true,
            'has_contributing'     => true,
            'license'              => ['key' => 'mit'],
            'good_first_issue_count' => 3,
            'language'             => 'TypeScript',
        ];

        $score = RankingService::calculateScore($repoData);
        $this->assertGreaterThanOrEqual(70.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    public function testEdgeCaseZeroStarsAndNoActivity(): void
    {
        $repoData = [
            'stargazers_count' => 0,
            'pushed_at'        => null,
            'has_readme'       => false,
            'language'         => 'UnknownLang',
        ];

        $score = RankingService::calculateScore($repoData);
        $this->assertEquals(2.0, $score); // Only tech relevance fallback
    }

    public function testMissingReadmeAndDocs(): void
    {
        $repoData = [
            'stargazers_count' => 100,
            'pushed_at'        => date('Y-m-d H:i:s'),
            'has_readme'       => false,
            'has_contributing' => false,
            'license'          => null,
            'language'         => 'Python',
        ];

        $score = RankingService::calculateScore($repoData);
        $this->assertGreaterThan(20.0, $score);
        $this->assertLessThan(60.0, $score);
    }
}
