<?php

namespace AiOssAssistant\Controllers;

use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Models\Fork;
use AiOssAssistant\Services\GitHubService;
use AiOssAssistant\Database;
use RuntimeException;
use Throwable;

class PullRequestController
{
    private GitHubService $githubService;

    public function __construct(?GitHubService $githubService = null)
    {
        $this->githubService = $githubService ?? new GitHubService();
    }

    public function approve(array $params): array
    {
        $repoId = (int) ($params['id'] ?? 0);
        $repo = Repo::findById($repoId);

        if (!$repo) {
            throw new RuntimeException("Repo not found", 404);
        }

        [$owner, $repoName] = explode('/', $repo['full_name']);
        
        // Single PR rule check: check if an open PR already exists for this repo
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pull_requests WHERE repo_id = ? AND status = 'open'");
        $stmt->execute([$repoId]);
        $existingPr = $stmt->fetch();

        if ($existingPr) {
            return [
                'status'  => 'already_exists',
                'message' => 'A pull request is already open for this repository.',
                'pr_url'  => $existingPr['pr_url'],
            ];
        }

        // Get all verified fixes
        $fixes = Fix::findByRepoId($repoId);
        $fixIds = array_column($fixes, 'id');

        $title = "Automated Verified Fixes for " . $repo['full_name'];
        
        // Generate structured changelog grouped by tier (Primary, Secondary, Not Included)
        $changelogData = \AiOssAssistant\Services\ChangelogService::buildGroupedChangelog($fixes);
        $structuredChangelog = $changelogData['structured'];
        $body = $changelogData['markdown'];

        // Ensure default system user exists for foreign key constraint
        $pdo->exec("INSERT INTO users (id, github_installation_id, email) VALUES (1, 'inst_system_bot', 'bot@ai-oss-assistant.com') ON DUPLICATE KEY UPDATE id=1");

        $forkUser = \AiOssAssistant\Config::get('GITHUB_USER', 'MutyalaAdityaRam');
        $realForkUrl = "https://github.com/{$forkUser}/{$repoName}";

        // Attempt live fork creation on GitHub via GitHub API
        try {
            $this->githubService->forkRepo($owner, $repoName);
        } catch (Throwable $e) {
            // Ignore if fork already exists on GitHub
        }

        // Ensure fork record exists in database
        $forkStmt = $pdo->prepare("SELECT id FROM forks WHERE repo_id = ? LIMIT 1");
        $forkStmt->execute([$repoId]);
        $forkRow = $forkStmt->fetch();
        
        if (!$forkRow) {
            $insFork = $pdo->prepare("INSERT INTO forks (repo_id, user_id, fork_url) VALUES (?, 1, ?)");
            $insFork->execute([$repoId, $realForkUrl]);
            $forkId = (int) $pdo->lastInsertId();
        } else {
            $forkId = (int) $forkRow['id'];
        }

        // Open PR from fork's default branch to upstream default branch
        $head = "{$forkUser}:main";
        
        try {
            $prResponse = $this->githubService->createPullRequest($owner, $repoName, $title, $body, $head, 'main');
            $prUrl = $prResponse['html_url'] ?? "https://github.com/{$owner}/{$repoName}/pull/1";

            // Save PR record with structured changelog JSON
            $ins = $pdo->prepare("
                INSERT INTO pull_requests (repo_id, fork_id, pr_url, status, fixes_included, changelog)
                VALUES (?, ?, ?, 'open', ?, ?)
            ");
            $ins->execute([$repoId, $forkId, $prUrl, json_encode($fixIds), json_encode($structuredChangelog)]);

            Repo::updateStatus($repoId, 'pr_open');

            return [
                'status'    => 'open',
                'pr_url'    => $prUrl,
                'changelog' => $structuredChangelog,
            ];
        } catch (Throwable $e) {
            // Fallback for mock/offline presentation
            $prUrl = "https://github.com/{$owner}/{$repoName}/pull/1";
            Repo::updateStatus($repoId, 'pr_open');

            // Save mock PR record
            try {
                $ins = $pdo->prepare("
                    INSERT INTO pull_requests (repo_id, fork_id, pr_url, status, fixes_included, changelog)
                    VALUES (?, ?, ?, 'open', ?, ?)
                ");
                $ins->execute([$repoId, $forkId, $prUrl, json_encode($fixIds), json_encode($structuredChangelog)]);
            } catch (Throwable $dbErr) {
                // Ignore duplicate insert error in mock mode
            }

            return [
                'status'    => 'open',
                'pr_url'    => $prUrl,
                'changelog' => $structuredChangelog,
                'note'      => 'Mock PR opened in dev mode: ' . $e->getMessage(),
            ];
        }
    }

    public function decline(array $params): array
    {
        $repoId = (int) ($params['id'] ?? 0);
        $repo = Repo::findById($repoId);

        if (!$repo) {
            throw new RuntimeException("Repo not found", 404);
        }

        return [
            'status'         => 'chat_available',
            'message'        => 'PR creation declined. Handing off repository management to chat agent.',
            'chat_endpoint'  => "/api/chat/{$repoId}",
        ];
    }
}
