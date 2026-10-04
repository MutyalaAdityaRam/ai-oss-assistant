<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\FindingTriageService;
use AiOssAssistant\Services\WorkspaceCleanupService;
use PHPUnit\Framework\TestCase;

class FindingTriageServiceTest extends TestCase
{
    private FindingTriageService $triage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->triage = new FindingTriageService();
    }

    /**
     * Requirement: Unit test: the tier-classification logic correctly assigns PRIMARY
     * to a seeded security finding and a seeded hot-path crash bug, and
     * SECONDARY to a seeded style-only finding and a seeded dead-code finding
     */
    public function testTierClassificationAssignsPrimaryToSecurityFinding(): void
    {
        $finding = [
            'tool'      => 'semgrep',
            'rule_id'   => 'python.security.sql_injection',
            'severity'  => 'HIGH',
            'path'      => 'src/search.py',
            'line'      => 45,
            'message'   => 'CRITICAL: SQL injection vulnerability via unparameterized string formatting',
        ];

        $res = $this->triage->classifyFinding($finding);

        $this->assertEquals('primary', $res['tier']);
        $this->assertEquals('security', $res['category']);
        $this->assertEquals(1, $res['priority_rank']);
    }

    public function testTierClassificationAssignsPrimaryToHotPathCrashBug(): void
    {
        $finding = [
            'tool'         => 'runtime',
            'rule_id'      => 'unaligned_memory_crash',
            'severity'     => 'HIGH',
            'path'         => 'src/events/dispatcher.js',
            'function'     => 'dispatchEvent',
            'reproduction' => 'Reproduction test crashes with memory exception on odd batch dimensions',
            'message'      => 'CRITICAL: CUDA kernel crash on odd batch sizes',
        ];

        $context = [
            'hot_paths' => [
                ['file' => 'src/events/dispatcher.js', 'function' => 'dispatchEvent']
            ]
        ];

        $res = $this->triage->classifyFinding($finding, $context);

        $this->assertEquals('primary', $res['tier']);
        $this->assertEquals('correctness_hot_path', $res['category']);
        $this->assertEquals(2, $res['priority_rank']);
    }

    public function testTierClassificationAssignsSecondaryToStyleOnlyFinding(): void
    {
        $finding = [
            'tool'     => 'eslint',
            'rule_id'  => 'style.indent',
            'severity' => 'LOW',
            'path'     => 'src/components/Header.jsx',
            'line'     => 12,
            'message'  => 'Expected indentation of 4 spaces but found 2 spaces',
        ];

        $res = $this->triage->classifyFinding($finding);

        $this->assertEquals('secondary', $res['tier']);
        $this->assertEquals('style_lint', $res['category']);
        $this->assertGreaterThan(5, $res['priority_rank']);
    }

    public function testTierClassificationAssignsSecondaryToDeadCodeFinding(): void
    {
        $finding = [
            'tool'     => 'cleanup',
            'rule_id'  => 'dead_code.unused_function',
            'severity' => 'LOW',
            'path'     => 'src/utils/legacy.py',
            'line'     => 22,
            'message'  => 'Removed unused legacy helper function',
        ];

        $res = $this->triage->classifyFinding($finding);

        $this->assertEquals('secondary', $res['tier']);
        $this->assertEquals('cleanup', $res['category']);
        $this->assertGreaterThan(5, $res['priority_rank']);
    }

    /**
     * Requirement: Unit test: the public-API-surface and hot-path checks used for
     * classification correctly reuse the existing services (no duplicate
     * logic reimplementing what workspace-cleanup and profiling already do)
     */
    public function testPublicApiSurfaceCheckReusesWorkspaceCleanupService(): void
    {
        $mockCleanupService = $this->createMock(WorkspaceCleanupService::class);
        $mockCleanupService->expects($this->once())
            ->method('evaluateCleanupFinding')
            ->with(
                $this->equalTo(['symbol_name' => 'executeGlobalQuery']),
                $this->anything()
            )
            ->willReturn([
                'action'       => 'suggestion_only',
                'hard_blocked' => true,
                'reason'       => "HARD BLOCK: Symbol 'executeGlobalQuery' is reachable from declared public API surface exports.",
            ]);

        $triageService = new FindingTriageService($mockCleanupService);

        $finding = [
            'tool'        => 'refactor',
            'symbol_name' => 'executeGlobalQuery',
            'path'        => 'src/index.ts',
            'message'     => 'Signature discrepancy in public API query handler',
        ];

        $context = [
            'repo_manifest' => [
                'public_exports' => ['executeGlobalQuery']
            ]
        ];

        $res = $triageService->classifyFinding($finding, $context);

        $this->assertEquals('primary', $res['tier']);
        $this->assertEquals('public_api', $res['category']);
        $this->assertEquals(4, $res['priority_rank']);
    }

    public function testHotPathCheckMatchesProfileHotPathsOutput(): void
    {
        $hotPaths = [
            ['file' => 'src/events/dispatcher.js', 'function' => 'dispatchEvent'],
            ['file' => 'src/db/user_repository.py', 'function' => 'fetchUserRecordsInLoop'],
        ];

        $this->assertTrue($this->triage->checkHotPath('src/events/dispatcher.js', 'dispatchEvent', $hotPaths));
        $this->assertTrue($this->triage->checkHotPath('src/other.js', 'fetchUserRecordsInLoop', $hotPaths));
        $this->assertFalse($this->triage->checkHotPath('src/rare_utility.py', 'rareHelper', $hotPaths));
    }

    public function testOptimizationBelowFivePercentThresholdIsRejectedOutright(): void
    {
        $finding = [
            'type'            => 'optimization',
            'runtime_delta_pct'=> -2.5, // 2.5% speedup — below 5% threshold
            'message'         => 'Micro-optimization in string loop',
        ];

        $res = $this->triage->classifyFinding($finding);

        $this->assertEquals('rejected', $res['tier']);
        $this->assertEquals('optimization_below_threshold', $res['category']);
    }

    public function testOptimizationMeetingFivePercentThresholdIsPrimary(): void
    {
        $finding = [
            'type'            => 'optimization',
            'runtime_delta_pct'=> -8.5, // 8.5% speedup — exceeds 5% threshold
            'message'         => 'Optimized batch tensor memory allocation',
        ];

        $res = $this->triage->classifyFinding($finding);

        $this->assertEquals('primary', $res['tier']);
        $this->assertEquals('performance', $res['category']);
        $this->assertEquals(5, $res['priority_rank']);
    }
}
