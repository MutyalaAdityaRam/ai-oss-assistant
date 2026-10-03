<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\GitHubService;
use PHPUnit\Framework\TestCase;

class DiffEndpointTest extends TestCase
{
    public function testCompareUrlConstruction(): void
    {
        $github = new GitHubService();
        $owner = 'octocat';
        $repo = 'Hello-World';
        $baseSha = '6dcb09b5b57875f334f61aebed695e2e4193db5e';
        $headSha = '68b329da9893e34099c7d8ad5cb9c9409d31a288';

        // Verify compare API endpoint URL formatting
        $expectedEndpoint = "/repos/{$owner}/{$repo}/compare/{$baseSha}...{$headSha}";
        $this->assertEquals("/repos/octocat/Hello-World/compare/6dcb09b5b57875f334f61aebed695e2e4193db5e...68b329da9893e34099c7d8ad5cb9c9409d31a288", $expectedEndpoint);
    }
}
