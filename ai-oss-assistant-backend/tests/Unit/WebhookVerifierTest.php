<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\WebhookVerifier;
use PHPUnit\Framework\TestCase;

class WebhookVerifierTest extends TestCase
{
    private string $secret = 'my_secret_key_123';

    public function testValidSignatureAccepted(): void
    {
        $payload = json_encode(['event' => 'test']);
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $this->secret);

        $isValid = WebhookVerifier::verify($payload, $signature, $this->secret);
        $this->assertTrue($isValid);
    }

    public function testInvalidSignatureRejected(): void
    {
        $payload = json_encode(['event' => 'test']);
        $signature = 'sha256=invalid_hash_string';

        $isValid = WebhookVerifier::verify($payload, $signature, $this->secret);
        $this->assertFalse($isValid);
    }

    public function testMissingSignatureHeaderRejected(): void
    {
        $payload = json_encode(['event' => 'test']);

        $isValid = WebhookVerifier::verify($payload, null, $this->secret);
        $this->assertFalse($isValid);
    }
}
