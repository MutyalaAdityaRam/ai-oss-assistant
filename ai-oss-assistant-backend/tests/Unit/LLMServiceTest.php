<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\LLMService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LLMServiceTest extends TestCase
{
    public function testSpendCapCheckEnforcedBeforeApiCall(): void
    {
        $llm = new LLMService('mock_pro_key', 'mock_flash_key');
        
        // Simulate spend exceeding $50.00 spend cap
        $llm->setCurrentSpendUsd(55.50);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/spend cap/i');

        // Should throw HTTP 402 spend cap exception BEFORE API call fires
        $llm->generateText("Test prompt");
    }

    public function testNormalGenerationWithinSpendCap(): void
    {
        $llm = new LLMService('mock_pro_key', 'mock_flash_key');
        $llm->setCurrentSpendUsd(1.00);

        $response = $llm->generateText("Summarize repository");
        $this->assertNotEmpty($response);
    }

    public function testProModelGeneration(): void
    {
        $llm = new LLMService('mock_pro_key', 'mock_flash_key');
        $llm->setCurrentSpendUsd(0.00);
        $response = $llm->generateText("Deep code analysis", true);
        $this->assertNotEmpty($response);
    }
}
