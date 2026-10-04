<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\AutomationControlService;
use AiOssAssistant\Database;
use PHPUnit\Framework\TestCase;

class AutomationControlServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset to active before each test
        AutomationControlService::resume();
    }

    protected function tearDown(): void
    {
        // Reset to active after tests
        AutomationControlService::resume();
        parent::tearDown();
    }

    public function testDefaultStatusIsActive(): void
    {
        $status = AutomationControlService::getStatus();
        $this->assertEquals(AutomationControlService::STATUS_ACTIVE, $status);
        $this->assertTrue(AutomationControlService::shouldSearchAndScan());
    }

    public function testPauseAutomationHaltsScanningAndSetsPausedState(): void
    {
        $details = AutomationControlService::pause();

        $this->assertEquals(AutomationControlService::STATUS_PAUSED, $details['automation_status']);
        $this->assertTrue($details['is_paused']);
        $this->assertFalse($details['can_search_and_scan']);
        $this->assertNotNull($details['paused_at']);
        $this->assertFalse(AutomationControlService::shouldSearchAndScan());
    }

    public function testResumeAutomationRestoresActiveState(): void
    {
        AutomationControlService::pause();
        $this->assertFalse(AutomationControlService::shouldSearchAndScan());

        $details = AutomationControlService::resume();

        $this->assertEquals(AutomationControlService::STATUS_ACTIVE, $details['automation_status']);
        $this->assertFalse($details['is_paused']);
        $this->assertTrue($details['can_search_and_scan']);
        $this->assertNull($details['paused_at']);
        $this->assertTrue(AutomationControlService::shouldSearchAndScan());
    }

    public function testRunOnceModeSetsRunOnceState(): void
    {
        $details = AutomationControlService::runOnce(false);

        $this->assertEquals(AutomationControlService::STATUS_RUN_ONCE, $details['automation_status']);
        $this->assertTrue($details['is_run_once']);
        $this->assertTrue($details['can_search_and_scan']); // Can scan for today's run
        $this->assertNotNull($details['run_once_at']);
        $this->assertTrue(AutomationControlService::shouldSearchAndScan());
    }

    public function testMarkRunCompletedTransitionsRunOnceToPaused(): void
    {
        AutomationControlService::runOnce(false);
        $this->assertEquals(AutomationControlService::STATUS_RUN_ONCE, AutomationControlService::getStatus());

        // When the cycle finishes, it should mark run completed and automatically transition to paused
        AutomationControlService::markRunCompleted();

        $this->assertEquals(AutomationControlService::STATUS_PAUSED, AutomationControlService::getStatus());
        $this->assertFalse(AutomationControlService::shouldSearchAndScan());

        $details = AutomationControlService::getDetails();
        $this->assertTrue($details['is_paused']);
        $this->assertNotNull($details['last_run_at']);
    }

    public function testMarkRunCompletedKeepsActiveModeActive(): void
    {
        AutomationControlService::resume();
        $this->assertEquals(AutomationControlService::STATUS_ACTIVE, AutomationControlService::getStatus());

        AutomationControlService::markRunCompleted();

        $this->assertEquals(AutomationControlService::STATUS_ACTIVE, AutomationControlService::getStatus());
        $this->assertTrue(AutomationControlService::shouldSearchAndScan());

        $details = AutomationControlService::getDetails();
        $this->assertNotNull($details['last_run_at']);
    }
}
