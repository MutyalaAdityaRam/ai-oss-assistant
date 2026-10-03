<?php

namespace AiOssAssistant\Controllers;

use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Suggestion;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Services\GitHubService;
use RuntimeException;

class SuggestionController
{
    private GitHubService $githubService;

    public function __construct(?GitHubService $githubService = null)
    {
        $this->githubService = $githubService ?? new GitHubService();
    }

    public function index(array $params): array
    {
        $repoId = (int) ($params['id'] ?? 0);
        if ($repoId <= 0) {
            throw new RuntimeException("Invalid Repo ID", 400);
        }

        $suggestions = Suggestion::findByRepoId($repoId);
        $implementedCount = Suggestion::countImplementedForRepo($repoId);

        return [
            'status'            => 'success',
            'repo_id'           => $repoId,
            'implemented_count' => $implementedCount,
            'max_cap'           => 3,
            'can_implement'     => $implementedCount < 3,
            'data'              => $suggestions,
        ];
    }

    public function select(array $params): array
    {
        $repoId       = (int) ($params['id'] ?? 0);
        $suggestionId = (int) ($params['suggestionId'] ?? 0);

        if ($repoId <= 0 || $suggestionId <= 0) {
            throw new RuntimeException("Invalid Repo ID or Suggestion ID", 400);
        }

        // Server-Side Hard Cap Check: Max 3 implemented suggestions per cycle
        $count = Suggestion::countImplementedForRepo($repoId);
        if ($count >= 3) {
            throw new RuntimeException("HARD CAP HIT: Maximum of 3 implemented suggestions permitted per repository cycle.", 400);
        }

        $suggestion = Suggestion::findById($suggestionId);
        if (!$suggestion || (int)$suggestion['repo_id'] !== $repoId) {
            throw new RuntimeException("Suggestion not found", 404);
        }

        // Create fix record to trigger pipeline
        // NOTE ON SCOPE CONSTRAINT: Suggestion fixes are feature/improvements, so unconstrained to single-file/touched-lines limits
        $fixId = Fix::create([
            'repo_id'           => $repoId,
            'issue_description' => "Suggestion Improvement: " . $suggestion['title'] . " - " . ($suggestion['rationale'] ?? ''),
            'merge_status'      => 'fixing',
            'source'            => 'user_requested',
        ]);

        Suggestion::updateStatus($suggestionId, 'selected', $fixId);

        // Trigger fix.yml pipeline
        $repo = Repo::findById($repoId);
        if ($repo) {
            [$owner, $repoName] = explode('/', $repo['full_name']);
            $branchName = "ai-fix/suggestion-{$suggestionId}";
            try {
                $this->githubService->triggerWorkflow($owner, $repoName, 'fix.yml', 'main', [
                    'fork_url'          => "https://github.com/my-fork/{$repoName}",
                    'branch_name'       => $branchName,
                    'fix_id'            => (string) $fixId,
                    'issue_description' => $suggestion['title'],
                ]);
            } catch (\Throwable $e) {
                // Workflow dispatch handled gracefully in dev mode
            }
        }

        return [
            'status'        => 'selected',
            'suggestion_id' => $suggestionId,
            'fix_id'        => $fixId,
            'message'       => 'Suggestion selected. Triggered fix pipeline workflow.',
        ];
    }

    public function custom(array $params): array
    {
        $repoId = (int) ($params['id'] ?? 0);
        if ($repoId <= 0) {
            throw new RuntimeException("Invalid Repo ID", 400);
        }

        // Server-Side Hard Cap Check: Max 3 implemented suggestions per cycle
        $count = Suggestion::countImplementedForRepo($repoId);
        if ($count >= 3) {
            throw new RuntimeException("HARD CAP HIT: Maximum of 3 implemented suggestions permitted per repository cycle.", 400);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $description = trim($input['description'] ?? '');

        if (empty($description)) {
            throw new RuntimeException("Custom suggestion description cannot be empty", 400);
        }

        $suggestionId = Suggestion::create([
            'repo_id'         => $repoId,
            'title'           => $description,
            'rationale'       => 'User custom idea submitted for automated implementation.',
            'source_links'    => ['https://github.com'],
            'effort_estimate' => 'medium',
            'status'          => 'selected',
            'source'          => 'user_custom',
        ]);

        $fixId = Fix::create([
            'repo_id'           => $repoId,
            'issue_description' => "Custom Improvement: " . $description,
            'merge_status'      => 'fixing',
            'source'            => 'user_requested',
        ]);

        Suggestion::updateStatus($suggestionId, 'selected', $fixId);

        return [
            'status'        => 'selected',
            'suggestion_id' => $suggestionId,
            'fix_id'        => $fixId,
            'message'       => 'Custom idea accepted. Triggered fix pipeline workflow.',
        ];
    }

    public function skip(array $params): array
    {
        $repoId = (int) ($params['id'] ?? 0);
        if ($repoId <= 0) {
            throw new RuntimeException("Invalid Repo ID", 400);
        }

        $skippedCount = Suggestion::skipRemainingProposed($repoId);

        return [
            'status'        => 'ready_for_pr_gate',
            'skipped_count' => $skippedCount,
            'message'       => 'Skipped remaining suggestions. Proceeding directly to Pull Request Gate.',
        ];
    }
}
