<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Router;
use AiOssAssistant\Controllers\RepoController;
use AiOssAssistant\Controllers\FixController;
use AiOssAssistant\Controllers\OptimizationController;
use AiOssAssistant\Controllers\SuggestionController;
use AiOssAssistant\Controllers\PullRequestController;
use AiOssAssistant\Controllers\ChatController;
use AiOssAssistant\Controllers\AutomationController;

// Enable CORS for frontend requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Hub-Signature-256");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

Config::load();

$router = new Router();

// Automation Control Endpoints (Pause, Resume, Run-Once)
$router->get('/api/automation/status', [AutomationController::class, 'getStatus']);
$router->post('/api/automation/pause', [AutomationController::class, 'pause']);
$router->post('/api/automation/resume', [AutomationController::class, 'resume']);
$router->post('/api/automation/run-once', [AutomationController::class, 'runOnce']);

// Repo Endpoints
$router->get('/api/repos', [RepoController::class, 'index']);
$router->get('/api/repos/{id}', [RepoController::class, 'show']);
$router->get('/api/repos/{id}/report', [RepoController::class, 'report']);
$router->post('/api/settings', [RepoController::class, 'updateSettings']);
$router->get('/api/notifications/pending', [RepoController::class, 'pendingNotifications']);

// Research Suggestion Endpoints (Addendum §1-3)
$router->get('/api/repos/{id}/suggestions', [SuggestionController::class, 'index']);
$router->post('/api/repos/{id}/suggestions/{suggestionId}/select', [SuggestionController::class, 'select']);
$router->post('/api/repos/{id}/suggestions/custom', [SuggestionController::class, 'custom']);
$router->post('/api/repos/{id}/suggestions/skip', [SuggestionController::class, 'skip']);

// PR Endpoints
$router->post('/api/repos/{id}/approve-pr', [PullRequestController::class, 'approve']);
$router->post('/api/repos/{id}/decline-pr', [PullRequestController::class, 'decline']);

// Fix, Diff & Optimization Endpoints
$router->get('/api/fixes/{fixId}/diff', [FixController::class, 'getDiff']);
$router->get('/api/fixes/{fixId}/optimization', [OptimizationController::class, 'getOptimization']);

// Public Read-Only Fix Endpoint (Item 8: Scoped to fixes attached to open/merged PRs)
$router->get('/public/fix/{fixId}', [FixController::class, 'getPublicFix'], false);

// Chat Agent Endpoints
$router->post('/api/chat/{repoId}', [ChatController::class, 'message']);
$router->post('/api/chat/{repoId}/fix', [ChatController::class, 'requestFix']);

// Webhook Endpoints (unauthenticated, HMAC verification in handler)
$router->post('/webhooks/github.php', function() {
    require __DIR__ . '/webhooks/github.php';
}, false);
$router->post('/public/webhooks/github.php', function() {
    require __DIR__ . '/webhooks/github.php';
}, false);

$router->post('/webhooks/github-pr-status.php', function() {
    require __DIR__ . '/webhooks/github-pr-status.php';
}, false);
$router->post('/public/webhooks/github-pr-status.php', function() {
    require __DIR__ . '/webhooks/github-pr-status.php';
}, false);

// Dispatch request
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
