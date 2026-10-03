<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Services\DecisionEngineService;
use AiOssAssistant\Services\LlmCacheService;
use AiOssAssistant\Services\WorkspaceCleanupService;
use AiOssAssistant\Models\OptimizationResult;
use PHPUnit\Framework\TestCase;

class DecisionEngineAndSeniorOptimizationTest extends TestCase
{
    public function testTriggerLogicSkipsTrivialFixAndActivatesForOptimizationTarget(): void
    {
        $engine = new DecisionEngineService();

        $this->assertTrue($engine->shouldTrigger('bug', false, true)); // Optimization target
        $this->assertTrue($engine->shouldTrigger('structural', true, false)); // Structural choice
        $this->assertFalse($engine->shouldTrigger('bug', false, false)); // Trivial fix
    }

    public function testScoringRubricComputesExactWeightedTotal(): void
    {
        $engine = new DecisionEngineService();
        $options = [
            [
                'option_summary' => 'Option 1: In-memory cache',
                'scores' => [
                    'correctness' => 100,
                    'performance' => 100,
                    'maintainability' => 100,
                    'simplicity' => 100,
                    'scalability' => 100,
                    'security' => 100,
                    'testability' => 100,
                ]
            ]
        ];

        $res = $engine->evaluateAndSelect('Cache lookup', $options);
        $this->assertEquals(100.0, $res['selected_option']['total_score']);
    }

    public function testTiebreakerRulePrefersSimplerOptionWithinFivePointMargin(): void
    {
        $engine = new DecisionEngineService();
        $options = [
            [
                'option_summary' => 'Option A: Complex Refactor',
                'scores' => [
                    'correctness' => 90, 'performance' => 90, 'maintainability' => 80,
                    'simplicity' => 60, 'scalability' => 90, 'security' => 90, 'testability' => 80
                ]
            ],
            [
                'option_summary' => 'Option B: Simple Helper',
                'scores' => [
                    'correctness' => 90, 'performance' => 88, 'maintainability' => 85,
                    'simplicity' => 95, 'scalability' => 85, 'security' => 90, 'testability' => 85
                ]
            ]
        ];

        $res = $engine->evaluateAndSelect('Optimize helper', $options);
        $this->assertEquals('Option B: Simple Helper', $res['selected_option']['option_summary']);
    }

    public function testLlmCacheHitReducesApiCallOnUnchangedContent(): void
    {
        LlmCacheService::clearInMemory();
        $content = "function test() { return 42; }";
        $type = "repo_summary";
        $result = "Summary: Test function returning 42";

        LlmCacheService::set($content, $type, $result);
        $cached = LlmCacheService::get($content, $type);

        $this->assertEquals($result, $cached);
    }

    public function testLlmCacheMissOnContentHashDifference(): void
    {
        LlmCacheService::clearInMemory();
        $content1 = "function test() { return 42; }";
        $content2 = "function test() { return 43; }";
        $type = "repo_summary";

        LlmCacheService::set($content1, $type, "Result 1");
        $cached2 = LlmCacheService::get($content2, $type);

        $this->assertNull($cached2);
    }

    public function testComplexityVsBenefitRuleAcceptsTwoXGainAndRejectsInadequateSpeedup(): void
    {
        // 1. Adequate speedup (-25%) & complexity delta (10%): 25% >= 2*10% -> ACCEPTED
        $resAccepted = OptimizationResult::evaluateComplexityVsBenefitTradeoff(10, 11, -25.0);
        $this->assertTrue($resAccepted['accepted']);
        $this->assertFalse($resAccepted['should_revert']);

        // 2. Inadequate speedup (-3.5% < -5.0%) -> REJECTED & REVERTED
        $resRejectedSpeedup = OptimizationResult::evaluateComplexityVsBenefitTradeoff(10, 10, -3.5);
        $this->assertFalse($resRejectedSpeedup['accepted']);
        $this->assertTrue($resRejectedSpeedup['should_revert']);

        // 3. High complexity increase (30%) without 2x speedup (-10% < 60%) -> REJECTED & REVERTED
        $resRejectedComplexity = OptimizationResult::evaluateComplexityVsBenefitTradeoff(10, 13, -10.0);
        $this->assertFalse($resRejectedComplexity['accepted']);
        $this->assertTrue($resRejectedComplexity['should_revert']);
    }

    public function testPublicApiSurfaceHardBlocksAutoFixToSuggestionsOnly(): void
    {
        $cleanup = new WorkspaceCleanupService();
        $finding = ['symbol_name' => 'exportedHelper'];
        $manifest = ['public_exports' => ['exportedHelper']];

        $res = $cleanup->evaluateCleanupFinding($finding, $manifest);
        $this->assertEquals('suggestion_only', $res['action']);
        $this->assertTrue($res['hard_blocked']);
    }

    public function testDynamicUsageHardBlocksAutoFixToSuggestionsOnly(): void
    {
        $cleanup = new WorkspaceCleanupService();
        $finding = ['symbol_name' => 'dynamicMod'];
        $manifest = ['dynamic_references' => ['dynamicMod']];

        $res = $cleanup->evaluateCleanupFinding($finding, $manifest);
        $this->assertEquals('suggestion_only', $res['action']);
        $this->assertTrue($res['hard_blocked']);
    }

    public function testSafeDevDependencyRemovalAutoFixes(): void
    {
        $cleanup = new WorkspaceCleanupService();
        $finding = ['dependency_name' => 'unused-dev-dep', 'confidence_score' => 90];
        $manifest = ['public_exports' => [], 'dynamic_references' => []];

        $res = $cleanup->evaluateCleanupFinding($finding, $manifest);
        $this->assertEquals('auto_fix', $res['action']);
        $this->assertFalse($res['hard_blocked']);
    }
}
