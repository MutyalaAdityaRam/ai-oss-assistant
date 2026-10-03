<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Router;
use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Models\OptimizationResult;
use AiOssAssistant\Database;
use AiOssAssistant\Services\WebhookVerifier;
use PHPUnit\Framework\TestCase;

class AcceptanceEndpointTest extends TestCase
{
    private Router $router;
    private int $repoId;
    private int $fixId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();

        // Register all application routes
        $this->router->get('/api/repos', [\AiOssAssistant\Controllers\RepoController::class, 'index']);
        $this->router->get('/api/repos/{id}', [\AiOssAssistant\Controllers\RepoController::class, 'show']);
        $this->router->get('/api/repos/{id}/report', [\AiOssAssistant\Controllers\RepoController::class, 'report']);
        $this->router->post('/api/settings', [\AiOssAssistant\Controllers\RepoController::class, 'updateSettings']);
        $this->router->get('/api/notifications/pending', [\AiOssAssistant\Controllers\RepoController::class, 'pendingNotifications']);
        $this->router->post('/api/repos/{id}/approve-pr', [\AiOssAssistant\Controllers\PullRequestController::class, 'approve']);
        $this->router->post('/api/repos/{id}/decline-pr', [\AiOssAssistant\Controllers\PullRequestController::class, 'decline']);
        $this->router->get('/api/fixes/{fixId}/diff', [\AiOssAssistant\Controllers\FixController::class, 'getDiff']);
        $this->router->get('/api/fixes/{fixId}/optimization', [\AiOssAssistant\Controllers\OptimizationController::class, 'getOptimization']);
        $this->router->post('/api/chat/{repoId}', [\AiOssAssistant\Controllers\ChatController::class, 'message']);
        $this->router->post('/api/chat/{repoId}/fix', [\AiOssAssistant\Controllers\ChatController::class, 'requestFix']);

        // Seed test repo & fix in database if MySQL is online
        try {
            $this->repoId = Repo::create([
                'full_name'    => 'octocat/Hello-World-' . rand(100, 999),
                'stars'        => 500,
                'resume_score' => 88.5,
                'status'       => 'bugs_found',
            ]);

            $this->fixId = Fix::create([
                'repo_id'           => $this->repoId,
                'issue_description' => 'Fix seeded null pointer dereference',
                'base_sha'          => '6dcb09b5b57875f334f61aebed695e2e4193db5e',
                'head_sha'          => '68b329da9893e34099c7d8ad5cb9c9409d31a288',
                'explanation'       => 'Replaced raw dispatch call with non-null guard check.',
                'test_status'       => 'passed',
                'security_status'   => 'passed',
                'merge_status'      => 'merged_to_fork',
            ]);
        } catch (\Throwable $e) {
            $this->repoId = 1;
            $this->fixId = 1;
        }
    }

    public function testGetReposEndpoint(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dev_secret_token_12345';
        ob_start();
        $this->router->dispatch('GET', '/api/repos');
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
        $decoded = json_decode($output, true);
        $this->assertEquals('success', $decoded['status'] ?? null);
    }

    public function testGetSingleRepoEndpoint(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dev_secret_token_12345';
        ob_start();
        $this->router->dispatch('GET', "/api/repos/{$this->repoId}");
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('success', $decoded['status'] ?? null);
        $this->assertNotNull($decoded['data']['repo'] ?? null);
    }

    public function testGetRepoReportEndpoint(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dev_secret_token_12345';
        ob_start();
        $this->router->dispatch('GET', "/api/repos/{$this->repoId}/report");
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('success', $decoded['status'] ?? null);
        $this->assertArrayHasKey('bugs_fixed', $decoded['data']);
    }

    public function testApprovePrEndpoint(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dev_secret_token_12345';
        ob_start();
        $this->router->dispatch('POST', "/api/repos/{$this->repoId}/approve-pr");
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertNotEmpty($output);
        $this->assertIsArray($decoded);
    }

    public function testDeclinePrEndpoint(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dev_secret_token_12345';
        ob_start();
        $this->router->dispatch('POST', "/api/repos/{$this->repoId}/decline-pr");
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('chat_available', $decoded['status'] ?? null);
    }
}
