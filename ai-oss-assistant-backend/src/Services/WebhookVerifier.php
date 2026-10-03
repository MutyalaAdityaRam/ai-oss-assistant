<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Config;

class WebhookVerifier
{
    /**
     * Verify GitHub HMAC SHA256 signature
     */
    public static function verify(string $payload, ?string $signatureHeader, ?string $secret = null): bool
    {
        $secret = $secret ?? Config::get('GITHUB_WEBHOOK_SECRET', '');

        if (empty($signatureHeader) || empty($secret)) {
            return false;
        }

        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signatureHeader);
    }
}
