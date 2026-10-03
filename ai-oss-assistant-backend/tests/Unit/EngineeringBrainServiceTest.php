<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\EngineeringBrainService;
use PHPUnit\Framework\TestCase;

class EngineeringBrainServiceTest extends TestCase
{
    public function testEvaluateTradeoffsRanksHighestScoreOptionFirst(): void
    {
        $brain = new EngineeringBrainService();

        $options = [
            [
                'name' => 'Option A: Quick Patch',
                'description' => 'Fast single line edit',
                'correctness' => 80,
                'performance' => 70,
                'maintainability' => 60,
                'simplicity' => 90,
                'scalability' => 60,
                'security' => 75,
                'testability' => 70,
            ],
            [
                'name' => 'Option B: Staff Engineer Refactor',
                'description' => 'Extract Strategy pattern with index and 1-hop context',
                'correctness' => 98,
                'performance' => 95,
                'maintainability' => 95,
                'simplicity' => 85,
                'scalability' => 95,
                'security' => 95,
                'testability' => 95,
            ]
        ];

        $res = $brain->evaluateTradeoffs('Optimize event dispatcher latency', $options);

        $this->assertEquals('Option B: Staff Engineer Refactor', $res['winning_option']['name']);
        $this->assertGreaterThan(90.0, $res['winning_option']['score']);
        $this->assertCount(2, $res['all_options']);
    }

    public function testBuildArchitectureGraphReturnsSystemLayers(): void
    {
        $brain = new EngineeringBrainService();
        $graph = $brain->buildArchitectureGraph();

        $this->assertArrayHasKey('layers', $graph);
        $this->assertArrayHasKey('call_graph', $graph);
        $this->assertArrayHasKey('Business Services', $graph['layers']);
    }

    public function testRunSelfReflectionReturnsReflectionsAndScorecard(): void
    {
        $brain = new EngineeringBrainService();
        $metrics = [
            'complexity_before' => 12,
            'complexity_after' => 5,
            'runtime_delta_pct' => -15.4
        ];

        $reflection = $brain->runSelfReflection("diff ...", $metrics);

        $this->assertTrue($reflection['is_acceptable']);
        $this->assertGreaterThanOrEqual(90.0, $reflection['scorecard']);
        $this->assertCount(4, $reflection['reflections']);
    }
}
