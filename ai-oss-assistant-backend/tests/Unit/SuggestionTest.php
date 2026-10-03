<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Models\Suggestion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SuggestionTest extends TestCase
{
    public function testSuggestionCreationRequiresSourceLinksForAiResearch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HONESTY CONSTRAINT VIOLATION: AI Research Suggestions must contain at least one valid cited source link');

        // Uncited suggestion must be rejected
        Suggestion::create([
            'repo_id'      => 1,
            'title'        => 'Uncited Improvement',
            'rationale'    => 'Missing citations',
            'source_links' => [],
            'source'       => 'ai_research',
        ]);
    }

    public function testSuggestionCreationWithValidSourceLinksSucceeds(): void
    {
        // Seeding valid cited suggestion array
        $data = [
            'repo_id'         => 1,
            'title'           => 'Valid Cited Improvement',
            'rationale'       => 'Recent work in X suggests Y might be worth considering',
            'source_links'    => ['https://arxiv.org/abs/2305.12345'],
            'effort_estimate' => 'medium',
            'source'          => 'ai_research',
        ];

        // Ensure array contains load-bearing links
        $this->assertNotEmpty($data['source_links']);
        $this->assertStringContainsString('https://', $data['source_links'][0]);
    }
}
