<?php

namespace AiOssAssistant\Tests\Integration;

use PHPUnit\Framework\TestCase;

class DiffStorageVerificationTest extends TestCase
{
    public function testNoDiffColumnsInFixesTableSchema(): void
    {
        $schemaFile = __DIR__ . '/../../migrations/004_create_fixes.sql';
        $sql = file_get_contents($schemaFile);

        $this->assertStringNotContainsString('diff_content', strtolower($sql));
        $this->assertStringNotContainsString('diff_artifact_url', strtolower($sql));
        $this->assertStringContainsString('base_sha', strtolower($sql));
        $this->assertStringContainsString('head_sha', strtolower($sql));
        $this->assertStringContainsString('explanation', strtolower($sql));
    }

    public function testFixModelDoesNotPersistDiffContent(): void
    {
        $fixModelFile = __DIR__ . '/../../src/Models/Fix.php';
        $code = file_get_contents($fixModelFile);

        // Verify that Fix model does NOT bind or store diff content in database queries
        $this->assertStringNotContainsString(':diff', $code);
        $this->assertStringNotContainsString(':diff_artifact_url', $code);
        $this->assertStringContainsString('fetchLiveDiff', $code);
        $this->assertStringContainsString('compareShas', $code);
    }
}
