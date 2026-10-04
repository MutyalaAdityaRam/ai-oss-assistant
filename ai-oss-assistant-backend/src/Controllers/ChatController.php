<?php

namespace AiOssAssistant\Controllers;

use AiOssAssistant\Services\ChatAgentService;
use RuntimeException;

class ChatController
{
    private ChatAgentService $chatService;

    public function __construct(?ChatAgentService $chatService = null)
    {
        $this->chatService = $chatService ?? new ChatAgentService();
    }

    public function message(array $params): array
    {
        // Enforce chat-specific rate limiting (30 requests/min per IP)
        \AiOssAssistant\Services\RateLimiterService::enforceOrExit('chat', 30, 60);

        $repoId = (int) ($params['repoId'] ?? 0);
        if ($repoId <= 0) {
            throw new RuntimeException("Invalid Repo ID", 400);
        }

        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
        $userMessage = trim($input['message'] ?? '');

        if (empty($userMessage)) {
            throw new RuntimeException("Message cannot be empty", 400);
        }

        if (mb_strlen($userMessage) > 2000) {
            throw new RuntimeException("Message exceeds maximum allowed length of 2000 characters", 400);
        }

        $response = $this->chatService->processMessage($repoId, $userMessage);

        return [
            'status' => 'success',
            'data'   => $response,
        ];
    }

    public function getContext(array $params): array
    {
        $repoId = (int) ($params['repoId'] ?? 0);
        if ($repoId <= 0) {
            throw new RuntimeException("Invalid Repo ID", 400);
        }

        $context = $this->chatService->getRepoContext($repoId);

        return [
            'status' => 'success',
            'data'   => $context,
        ];
    }

    public function requestFix(array $params): array
    {
        return $this->message($params);
    }
}
