<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Models\OptimizationResult;
use AiOssAssistant\Services\LLMService;
use PHPUnit\Framework\TestCase;

class OptimizationTest extends TestCase
{
    public function testRuntimeDeltaPctCalculationSlower(): void
    {
        $delta = OptimizationResult::calculateRuntimeDeltaPct(1000, 1200);
        $this->assertEquals(20.0, $delta);
    }

    public function testRuntimeDeltaPctCalculationFaster(): void
    {
        $delta = OptimizationResult::calculateRuntimeDeltaPct(1000, 800);
        $this->assertEquals(-20.0, $delta);
    }

    public function testRuntimeDeltaPctCalculationZeroChange(): void
    {
        $delta = OptimizationResult::calculateRuntimeDeltaPct(1000, 1000);
        $this->assertEquals(0.0, $delta);
    }

    public function testRuntimeDeltaPctNullHandling(): void
    {
        $this->assertNull(OptimizationResult::calculateRuntimeDeltaPct(null, 1000));
        $this->assertNull(OptimizationResult::calculateRuntimeDeltaPct(1000, null));
        $this->assertNull(OptimizationResult::calculateRuntimeDeltaPct(0, 1000));
    }

    public function testLlmOptimizationPromptContainsNumbersAndAntiBigOInstruction(): void
    {
        $llm = new LLMService('mock_pro_key', 'mock_flash_key');
        $llm->setCurrentSpendUsd(0.00);
        
        $metrics = [
            'complexity_before'             => 14,
            'complexity_after'              => 7,
            'test_suite_duration_ms_before' => 1200,
            'test_suite_duration_ms_after'  => 1150,
            'runtime_delta_pct'             => -4.17,
        ];

        $summary = $llm->generateOptimizationSummary($metrics);
        
        $this->assertNotEmpty($summary);
        $this->assertStringContainsString('Cyclomatic complexity', $summary);
    }
}
