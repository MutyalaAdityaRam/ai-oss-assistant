<?php

namespace AiOssAssistant\Controllers;

use AiOssAssistant\Services\AutomationControlService;

class AutomationController
{
    /**
     * GET /api/automation/status
     */
    public function getStatus(): array
    {
        $details = AutomationControlService::getDetails();
        return [
            'status' => 'success',
            'data'   => $details,
        ];
    }

    /**
     * POST /api/automation/pause
     */
    public function pause(): array
    {
        $details = AutomationControlService::pause();
        return [
            'status'  => 'success',
            'message' => 'Automation paused. Repository searching, cloning, scanning, and bug fixing have been suspended. All interactive services (PR approval, chat, deletion) remain active.',
            'data'    => $details,
        ];
    }

    /**
     * POST /api/automation/resume
     */
    public function resume(): array
    {
        $details = AutomationControlService::resume();
        return [
            'status'  => 'success',
            'message' => 'Automation resumed. Daily repository discovery and autonomous fixing pipeline is active.',
            'data'    => $details,
        ];
    }

    /**
     * POST /api/automation/run-once
     */
    public function runOnce(): array
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
        $triggerNow = isset($input['trigger_now']) ? (bool) $input['trigger_now'] : true;

        $details = AutomationControlService::runOnce($triggerNow);
        return [
            'status'  => 'success',
            'message' => 'Run-once triggered for today. The pipeline will execute one discovery, scan, and fix cycle, then automatically pause all future scheduled runs.',
            'data'    => $details,
        ];
    }
}
