<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Services\WebhookVerifier;
use AiOssAssistant\Database;

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
$action = $data['action'] ?? '';
$prUrl  = $data['pull_request']['html_url'] ?? null;

if (!empty($prUrl)) {
    $pdo = Database::getConnection();
    $status = match ($action) {
        'closed' => ($data['pull_request']['merged'] ?? false) ? 'merged' : 'closed',
        'opened' => 'open',
        default  => 'open',
    };

    $stmt = $pdo->prepare("UPDATE pull_requests SET status = ? WHERE pr_url = ?");
    $stmt->execute([$status, $prUrl]);
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'pr_status' => $status ?? 'updated']);
