<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Suggestion;
use AiOssAssistant\Controllers\SuggestionController;
use AiOssAssistant\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SuggestionFlowTest extends TestCase
{
    private int $repoId;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->repoId = Repo::create([
                'full_name'    => 'test/suggestion-repo-' . rand(1000, 9999),
                'stars'        => 250,
                'resume_score' => 82.0,
                'status'       => 'bugs_found',
            ]);
        } catch (\Throwable $e) {
            $this->repoId = 1;
        }
    }

    public function testServerSideThreeImplementedSuggestionsCapEnforcement(): void
    {
        try {
            Database::getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection offline');
        }

        // Seed 3 implemented/selected suggestions to hit the hard cap
        for ($i = 1; $i <= 3; $i++) {
            Suggestion::create([
                'repo_id'      => $this->repoId,
                'title'        => "Implemented Suggestion #{$i}",
                'rationale'    => "Proposal #{$i}",
                'source_links' => ["https://arxiv.org/abs/2305.{$i}"],
                'status'       => 'selected',
                'source'       => 'ai_research',
            ]);
        }

        $controller = new SuggestionController();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HARD CAP HIT: Maximum of 3 implemented suggestions permitted per repository cycle');

        // 4th custom submission MUST be rejected by API
        $controller->custom(['id' => $this->repoId]);
    }

    public function testSkipSuggestionsFlow(): void
    {
        try {
            Database::getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection offline');
        }

        $sugId = Suggestion::create([
            'repo_id'      => $this->repoId,
            'title'        => 'Proposed Suggestion',
            'rationale'    => 'Proposed rationale',
            'source_links' => ['https://arxiv.org/abs/2305.100'],
            'status'       => 'proposed',
            'source'       => 'ai_research',
        ]);

        $controller = new SuggestionController();
        $res = $controller->skip(['id' => $this->repoId]);

        $this->assertEquals('ready_for_pr_gate', $res['status']);
        $saved = Suggestion::findById($sugId);
        $this->assertEquals('skipped', $saved['status']);
    }
}
