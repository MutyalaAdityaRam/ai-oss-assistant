<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Database;
use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Services\JobProcessorService;
use AiOssAssistant\Services\ChangelogService;
use AiOssAssistant\Controllers\PullRequestController;
use PHPUnit\Framework\TestCase;

class MultiFindingTriagePipelineTest extends TestCase
{
    private int $repoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoId = Repo::create([
            'full_name'    => 'test-org/multi-finding-test-' . rand(1000, 9999),
            'stars'        => 750,
            'resume_score' => 91.0,
            'status'       => 'analyzing',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $pdo = Database::getConnection();
            $pdo->prepare("DELETE FROM repos WHERE id = ?")->execute([$this->repoId]);
        } catch (\Throwable $e) {
            // Ignore teardown error
        }
        parent::tearDown();
    }

    /**
     * Requirement: Integration test: a seeded cycle with 3 primary and 2 secondary
     * findings processes ALL 5 (not just the first), with primary findings
     * completing before any secondary one starts — verify by checking actual
     * processing timestamps/order, not just that all 5 eventually show a terminal state
     */
    public function testProcessesAllFindingsWithPrimaryBeforeSecondaryOrder(): void
    {
        $findings = [
            // 2 Secondary findings interleaved in the input
            [
                'tool'     => 'eslint',
                'severity' => 'LOW',
                'path'     => 'src/style.js',
                'message'  => 'Secondary: Indentation fix',
            ],
            // 3 Primary findings (Security, Hot-path Crash, Public API)
            [
                'tool'     => 'semgrep',
                'severity' => 'HIGH',
                'rule_id'  => 'security.sql_injection',
                'path'     => 'src/search.py',
                'message'  => 'Primary 1: Critical SQL Injection in user search endpoint',
            ],
            [
                'tool'     => 'cleanup',
                'severity' => 'LOW',
                'rule_id'  => 'dead_code',
                'path'     => 'src/unused.js',
                'message'  => 'Secondary: Unused variable removed',
            ],
            [
                'tool'         => 'runtime',
                'severity'     => 'HIGH',
                'reproduction' => true,
                'path'         => 'src/events/dispatcher.js',
                'function'     => 'dispatchEvent',
                'message'      => 'Primary 2: CUDA kernel crash on odd batch sizes',
            ],
            [
                'tool'        => 'refactor',
                'symbol_name' => 'publicExportFn',
                'path'        => 'src/api.js',
                'message'     => 'Primary 3: Bug affecting public API surface',
            ],
        ];

        $context = [
            'hot_paths' => [
                ['file' => 'src/events/dispatcher.js', 'function' => 'dispatchEvent']
            ],
            'repo_manifest' => [
                'public_exports' => ['publicExportFn']
            ]
        ];

        $result = JobProcessorService::processAllFindings($this->repoId, $findings, 5, $context);

        // 1. Confirm ALL 5 were attempted (the original "only fixes one" gap is confirmed closed)
        $this->assertEquals(5, $result['total_findings']);
        $this->assertEquals(3, $result['primary_attempted']);
        $this->assertEquals(2, $result['secondary_attempted']);
        $this->assertCount(5, $result['fixes_created']);

        // 2. Verify that PRIMARY findings completed before ANY secondary one started
        $executionLog = $result['execution_log'];
        $this->assertCount(5, $executionLog);

        $lastPrimaryTimestamp = 0.0;
        $firstSecondaryTimestamp = 0.0;

        foreach ($executionLog as $idx => $entry) {
            if ($idx < 3) {
                $this->assertEquals('primary', $entry['tier'], "Step {$idx} must be a PRIMARY finding");
                $lastPrimaryTimestamp = $entry['timestamp'];
            } else {
                $this->assertEquals('secondary', $entry['tier'], "Step {$idx} must be a SECONDARY finding");
                if ($firstSecondaryTimestamp === 0.0) {
                    $firstSecondaryTimestamp = $entry['timestamp'];
                }
            }
        }

        $this->assertGreaterThanOrEqual($lastPrimaryTimestamp, $firstSecondaryTimestamp, "All PRIMARY findings must process before any SECONDARY finding starts");
    }

    /**
     * Requirement: Integration test: the secondary-tier cap is enforced — seed 8
     * secondary findings with a cap of 5, confirm exactly 5 are processed
     * and 3 are left for the next cycle, not silently dropped or all 8
     * force-processed
     */
    public function testSecondaryTierCapIsStrictlyEnforced(): void
    {
        $findings = [];
        for ($i = 1; $i <= 8; $i++) {
            $findings[] = [
                'tool'     => 'eslint',
                'severity' => 'LOW',
                'path'     => "src/file_{$i}.js",
                'message'  => "Secondary style finding #{$i}",
            ];
        }

        // Run with cap = 5
        $result = JobProcessorService::processAllFindings($this->repoId, $findings, 5);

        // Exactly 5 processed, 3 deferred
        $this->assertEquals(8, $result['total_findings']);
        $this->assertEquals(0, $result['primary_attempted']);
        $this->assertEquals(5, $result['secondary_attempted']);
        $this->assertEquals(3, $result['secondary_deferred']);
        $this->assertCount(5, $result['fixes_created']);
    }

    /**
     * Requirement: Integration test: the consolidated changelog correctly groups a
     * mixed set of fixes into primary/secondary/not-included sections, and
     * the PR comment (C10) renders this grouped structure, not a flat list
     */
    public function testConsolidatedChangelogAndPrCommentRenderGroupedStructure(): void
    {
        $fixes = [
            [
                'id'                => 501,
                'priority_tier'     => 'primary',
                'issue_description' => 'Security: Fixed SQL injection in user search endpoint in src/search.py',
                'explanation'       => 'Root cause: unparameterized query construction. Verified: new test confirms parameterized query, existing tests pass.',
                'merge_status'      => 'merged_to_fork',
            ],
            [
                'id'                => 502,
                'priority_tier'     => 'primary',
                'issue_description' => 'Correctness: Fixed CUDA kernel crash on odd batch sizes in kernels/fast_lora.py',
                'explanation'       => 'Root cause: unaligned memory allocation, no shape validation. Verified: reproduction test added, now passing.',
                'merge_status'      => 'merged_to_fork',
            ],
            [
                'id'                => 503,
                'priority_tier'     => 'secondary',
                'issue_description' => 'Cleanup: Removed 2 unused dev dependencies in package.json',
                'explanation'       => 'Routine cleanup: Removed unused dev dependencies to reduce build size.',
                'merge_status'      => 'merged_to_fork',
            ],
            [
                'id'                => 504,
                'priority_tier'     => 'primary',
                'issue_description' => 'Complex concurrency deadlock in background worker',
                'explanation'       => 'Hit max retry cap after 5 attempts.',
                'merge_status'      => 'flagged_manual_review',
            ]
        ];

        $changelog = ChangelogService::buildGroupedChangelog($fixes);

        $structured = $changelog['structured'];
        $markdown = $changelog['markdown'];

        // Verify structured object
        $this->assertCount(2, $structured['primary']);
        $this->assertCount(1, $structured['secondary']);
        $this->assertCount(1, $structured['not_included']);

        // Verify Markdown rendering contains all 3 distinct grouped headings
        $this->assertStringContainsString('## Changes in this PR', $markdown);
        $this->assertStringContainsString('### Primary fixes', $markdown);
        $this->assertStringContainsString('### Secondary fixes', $markdown);
        $this->assertStringContainsString('### Not included this cycle', $markdown);

        // Verify specific fixes appear in the right groups
        $this->assertStringContainsString('SQL injection in user search endpoint', $markdown);
        $this->assertStringContainsString('CUDA kernel crash on odd batch sizes', $markdown);
        $this->assertStringContainsString('Removed 2 unused dev dependencies', $markdown);
        $this->assertStringContainsString('flagged for manual review (retry cap reached)', $markdown);

        // Verify PR Controller renders grouped changelog into PR record and response
        $prController = new PullRequestController();
        $prRes = $prController->approve(['id' => $this->repoId]);

        $this->assertEquals('open', $prRes['status']);
        $this->assertArrayHasKey('changelog', $prRes);
        $this->assertArrayHasKey('primary', $prRes['changelog']);
        $this->assertArrayHasKey('secondary', $prRes['changelog']);
        $this->assertArrayHasKey('not_included', $prRes['changelog']);
    }
}
