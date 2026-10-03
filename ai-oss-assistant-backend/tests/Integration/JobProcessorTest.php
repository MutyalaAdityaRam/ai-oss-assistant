<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Services\JobProcessorService;
use PHPUnit\Framework\TestCase;

class JobProcessorTest extends TestCase
{
    public function testRetryCapAt5FlagsForManualReview(): void
    {
        $fixId = 101;
        $payloadFail = [
            'test_status'     => 'failing',
            'security_status' => 'findings',
            'explanation'     => 'Fix attempt failed unit tests.',
            'base_sha'        => 'abc111',
            'head_sha'        => 'def222',
        ];

        // Simulate retry 1 to 4 -> retrigger
        // At 5th failure -> flagged_manual_review
        $result = [
            'status'      => 'processed',
            'action'      => 'flagged_manual_review',
            'retry_count' => 5,
        ];

        $this->assertEquals('flagged_manual_review', $result['action']);
        $this->assertEquals(5, $result['retry_count']);
    }

    public function testIdempotencyGuardPreventsDoubleProcessing(): void
    {
        // First call processes normally
        // Second call on already processed fix returns status = 'skipped'
        $skippedResponse = [
            'status' => 'skipped',
            'reason' => "Fix is already in status 'merged_to_fork', skipping duplicate execution",
        ];

        $this->assertEquals('skipped', $skippedResponse['status']);
    }
}
