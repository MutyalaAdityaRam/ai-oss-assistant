<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Models\OptimizationResult;
use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Database;
use PHPUnit\Framework\TestCase;

class OptimizationStorageTest extends TestCase
{
    private function createSeededFix(): int
    {
        $repoId = Repo::create([
            'full_name'    => 'test/optimization-repo-' . rand(1000, 9999),
            'stars'        => 100,
            'resume_score' => 75.0,
            'status'       => 'bugs_found',
        ]);

        return Fix::create([
            'repo_id'           => $repoId,
            'issue_description' => 'Fix memory leak in event loop',
            'merge_status'      => 'merged_to_fork',
        ]);
    }

    public function testOptimizationResultPersistence(): void
    {
        try {
            $pdo = Database::getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection offline during unit test run');
        }

        $fixId = $this->createSeededFix();
        $data = [
            'fix_id'                        => $fixId,
            'complexity_tool'               => 'lizard',
            'complexity_before'             => 10,
            'complexity_after'              => 5,
            'test_suite_duration_ms_before' => 2000,
            'test_suite_duration_ms_after'  => 1900,
            'summary'                       => 'Cyclomatic complexity reduced from 10 to 5.',
        ];

        $optId = OptimizationResult::create($data);
        $this->assertGreaterThan(0, $optId);

        $saved = OptimizationResult::findByFixId($fixId);
        $this->assertNotNull($saved);
        $this->assertEquals(10, $saved['complexity_before']);
        $this->assertEquals(5, $saved['complexity_after']);
        $this->assertEquals(-5.0, (float)$saved['runtime_delta_pct']);
    }

    public function testNullTestSuiteDurationHandling(): void
    {
        try {
            $pdo = Database::getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection offline during unit test run');
        }

        $fixId = $this->createSeededFix();
        $data = [
            'fix_id'                        => $fixId,
            'complexity_before'             => 8,
            'complexity_after'              => 4,
            'test_suite_duration_ms_before' => null,
            'test_suite_duration_ms_after'  => null,
            'summary'                       => 'No test suite timing available.',
        ];

        $optId = OptimizationResult::create($data);
        $saved = OptimizationResult::findByFixId($fixId);
        
        $this->assertNotNull($saved);
        $this->assertNull($saved['runtime_delta_pct']);
        $this->assertNull($saved['test_suite_duration_ms_before']);
    }
}
