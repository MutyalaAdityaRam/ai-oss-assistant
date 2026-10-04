<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Router;
use AiOssAssistant\Controllers\AutomationController;
use AiOssAssistant\Services\AutomationControlService;
use PHPUnit\Framework\TestCase;

class AutomationEndpointTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dev_secret_token_12345';

        $this->router = new Router();
        $this->router->get('/api/automation/status', [AutomationController::class, 'getStatus']);
        $this->router->post('/api/automation/pause', [AutomationController::class, 'pause']);
        $this->router->post('/api/automation/resume', [AutomationController::class, 'resume']);
        $this->router->post('/api/automation/run-once', [AutomationController::class, 'runOnce']);

        try {
            \AiOssAssistant\Database::getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database connection offline');
        }

        // Reset to active state before tests
        AutomationControlService::resume();
    }

    protected function tearDown(): void
    {
        try {
            AutomationControlService::resume();
        } catch (\Throwable $e) {
            // Ignore if offline
        }
        parent::tearDown();
    }

    public function testGetAutomationStatusEndpoint(): void
    {
        ob_start();
        $this->router->dispatch('GET', '/api/automation/status');
        $output = ob_get_clean();

        $res = json_decode($output, true);
        $this->assertEquals('success', $res['status'] ?? null);
        $this->assertArrayHasKey('automation_status', $res['data']);
        $this->assertArrayHasKey('is_paused', $res['data']);
        $this->assertArrayHasKey('can_search_and_scan', $res['data']);
    }

    public function testPauseEndpointSuspendsAutomation(): void
    {
        ob_start();
        $this->router->dispatch('POST', '/api/automation/pause');
        $output = ob_get_clean();

        $res = json_decode($output, true);
        $this->assertEquals('success', $res['status'] ?? null);
        $this->assertEquals('paused', $res['data']['automation_status']);
        $this->assertTrue($res['data']['is_paused']);
        $this->assertFalse($res['data']['can_search_and_scan']);
        $this->assertFalse(AutomationControlService::shouldSearchAndScan());
    }

    public function testResumeEndpointRestoresAutomation(): void
    {
        ob_start();
        $this->router->dispatch('POST', '/api/automation/pause');
        ob_end_clean();
        $this->assertFalse(AutomationControlService::shouldSearchAndScan());

        ob_start();
        $this->router->dispatch('POST', '/api/automation/resume');
        $output = ob_get_clean();

        $res = json_decode($output, true);
        $this->assertEquals('success', $res['status'] ?? null);
        $this->assertEquals('active', $res['data']['automation_status']);
        $this->assertFalse($res['data']['is_paused']);
        $this->assertTrue($res['data']['can_search_and_scan']);
        $this->assertTrue(AutomationControlService::shouldSearchAndScan());
    }

    public function testRunOnceEndpointSetsSinglePassMode(): void
    {
        ob_start();
        $this->router->dispatch('POST', '/api/automation/run-once');
        $output = ob_get_clean();

        $res = json_decode($output, true);
        $this->assertEquals('success', $res['status'] ?? null);
        $this->assertEquals('run_once', $res['data']['automation_status']);
        $this->assertTrue($res['data']['is_run_once']);
        $this->assertTrue($res['data']['can_search_and_scan']);
    }
}
