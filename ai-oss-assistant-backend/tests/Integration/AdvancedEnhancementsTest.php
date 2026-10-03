<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Services\RankingService;
use AiOssAssistant\Services\LLMService;
use AiOssAssistant\Services\OptOutRegistryService;
use AiOssAssistant\Services\CrossToolSpamThrottler;
use AiOssAssistant\Services\DuplicateWorkDetector;
use AiOssAssistant\Services\MaintainerResponsivenessService;
use PHPUnit\Framework\TestCase;

class AdvancedEnhancementsTest extends TestCase
{
    public function testA2IndependentCriticPassReturnsValidStructure(): void
    {
        $llm = new LLMService();
        $diff = "--- a/src/app.js\n+++ b/src/app.js\n@@ -1 +1 @@\n-const a = 1;\n+const a = 2;";
        $res = $llm->runCriticPass($diff, "Fix value");

        $this->assertIsArray($res);
        $this->assertArrayHasKey('has_concerns', $res);
        $this->assertArrayHasKey('notes', $res);
    }

    public function testA3ConfidenceScoringReturnsIntegerBetweenZeroAndHundred(): void
    {
        $llm = new LLMService();
        $score = $llm->estimateFixConfidence("Fix null pointer", ['target_file' => 'src/app.js']);

        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testB5MaintainerResponsivenessScoreAffectsRanking(): void
    {
        $repoLow = [
            'stars' => 1000,
            'last_activity' => '2026-08-01',
            'maintainer_responsiveness_score' => 20.0
        ];
        $repoHigh = [
            'stars' => 1000,
            'last_activity' => '2026-08-01',
            'maintainer_responsiveness_score' => 90.0
        ];

        $scoreLow = RankingService::calculateScore($repoLow);
        $scoreHigh = RankingService::calculateScore($repoHigh);

        $this->assertGreaterThan($scoreLow, $scoreHigh);
    }

    public function testC9TargetSkillsAppliesBonusWeightInRanking(): void
    {
        $repo = [
            'stars' => 500,
            'language' => 'rust',
            'topics' => ['distributed-systems']
        ];

        $scoreDefault = RankingService::calculateScore($repo, []);
        $scoreWithBonus = RankingService::calculateScore($repo, ['Rust', 'distributed-systems']);

        $this->assertGreaterThan($scoreDefault, $scoreWithBonus);
        $this->assertEqualsWithDelta(10.0, $scoreWithBonus - $scoreDefault, 0.01);
    }

    public function testD11OptOutRegistryServiceHardSkipsOptedOutRepos(): void
    {
        $registry = new OptOutRegistryService();

        $this->assertTrue($registry->isOptedOut('torvalds/linux'));
        $this->assertTrue($registry->isOptedOut('python/cpython'));
        $this->assertFalse($registry->isOptedOut('facebook/react'));
    }

    public function testD12CrossToolSpamThrottlerReturnsBoolean(): void
    {
        $throttler = new CrossToolSpamThrottler();
        $isSaturated = $throttler->isSaturated('facebook/react');

        $this->assertIsBool($isSaturated);
    }
}
