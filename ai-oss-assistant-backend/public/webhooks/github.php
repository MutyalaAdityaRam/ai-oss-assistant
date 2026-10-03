<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Services\WebhookVerifier;
use AiOssAssistant\Services\JobProcessorService;
use AiOssAssistant\Services\LLMService;
use AiOssAssistant\Models\OptimizationResult;

Config::load();

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_SIGNATURE_256'] ?? null;

// HARD CONSTRAINT #10: Webhook signature verification is mandatory on every incoming webhook route
if (!WebhookVerifier::verify($payload, $signature)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or missing HMAC SHA256 signature']);
    exit();
}

$data = json_decode($payload, true) ?? [];
$type = $data['type'] ?? 'analysis';

try {
    if ($type === 'analysis') {
        $repoId = (int) ($data['repo_id'] ?? 0);
        $result = JobProcessorService::processAnalysisResult($repoId, $data);
    } elseif ($type === 'optimization') {
        $llm = new LLMService();
        $summary = $llm->generateOptimizationSummary($data);
        $data['summary'] = $summary;
        $optId = OptimizationResult::create($data);
        $result = ['status' => 'processed', 'action' => 'optimization_recorded', 'id' => $optId];
    } else {
        $fixId = (int) ($data['fix_id'] ?? 0);
        $result = JobProcessorService::processFixResult($fixId, $data);
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'result' => $result]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
