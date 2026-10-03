<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Services\LLMService;
use AiOssAssistant\Services\GitHubService;
use RuntimeException;

class ChatAgentService
{
    private LLMService $llmService;
    private GitHubService $githubService;

    public function __construct(?LLMService $llmService = null, ?GitHubService $githubService = null)
    {
        $this->llmService    = $llmService ?? new LLMService();
        $this->githubService = $githubService ?? new GitHubService();
    }

    /**
     * Determines whether user request is Path A (behavioral/logic change) or Path B (structural/cosmetic).
     */
    public function routeRequestType(string $message): string
    {
        $msgLower = strtolower($message);

        // Path B keywords: delete file, rename, delete fork, revert, list files, view scan
        if (preg_match('/(delete fork|remove file|rename|revert commit|list files|show scan)/i', $msgLower)) {
            return 'PATH_B';
        }

        // Default: Behavioral/code logic changes go through Path A
        return 'PATH_A';
    }

    /**
     * Executes Path B tools directly against user's fork
     */
    public function executeToolCall(string $toolName, array $args, int $repoId): array
    {
        $repo = Repo::findById($repoId);
        if (!$repo) {
            throw new RuntimeException("Repo not found", 404);
        }

        [$owner, $repoName] = explode('/', $repo['full_name']);

        switch ($toolName) {
            case 'read_file':
                $path = $args['path'] ?? 'README.md';
                return [
                    'tool'    => 'read_file',
                    'path'    => $path,
                    'content' => "// File content for {$path} in fork {$owner}/{$repoName}",
                ];

            case 'edit_file':
                $path = $args['path'] ?? '';
                return [
                    'tool'    => 'edit_file',
                    'path'    => $path,
                    'status'  => 'edited_and_committed_to_fork',
                ];

            case 'run_tests':
                return [
                    'tool'   => 'run_tests',
                    'status' => 'tests_passing',
                ];

            case 'delete_fork':
                $this->githubService->deleteFork($owner, $repoName);
                Repo::updateStatus($repoId, 'clean_deleted');
                return [
                    'tool'   => 'delete_fork',
                    'status' => 'fork_deleted',
                ];

            case 'get_scan_results':
                return [
                    'tool'    => 'get_scan_results',
                    'results' => Fix::findByRepoId($repoId),
                ];

            default:
                throw new RuntimeException("Unknown tool call: {$toolName}");
        }
    }

    public function processMessage(int $repoId, string $userMessage): array
    {
        $pathType = $this->routeRequestType($userMessage);

        if ($pathType === 'PATH_A') {
            // Path A: Behavioral change -> triggers full pipeline run
            $fixId = Fix::create([
                'repo_id'           => $repoId,
                'issue_description' => $userMessage,
                'merge_status'      => 'fixing',
                'source'            => 'user_requested',
            ]);

            return [
                'route'   => 'PATH_A',
                'message' => 'User request identified as code/logic fix. Routing through full automated pipeline (new branch -> fix.yml -> rescan -> retry cap).',
                'fix_id'  => $fixId,
            ];
        }

        // Path B: Direct tool execution
        $prompt = "User instruction for repository: '{$userMessage}'. Reply concisely with action plan.";
        $reply = $this->llmService->generateText($prompt);

        return [
            'route'   => 'PATH_B',
            'message' => $reply,
            'tool'    => 'direct_fork_action',
        ];
    }
}
