<?php

namespace AiOssAssistant\Models;

use AiOssAssistant\Database;
use AiOssAssistant\Services\GitHubService;
use PDO;
use RuntimeException;
use Throwable;

class Fix
{
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM fixes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByRepoId(int $repoId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM fixes WHERE repo_id = ? ORDER BY created_at DESC");
        $stmt->execute([$repoId]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO fixes (repo_id, issue_description, branch_id, base_sha, head_sha, explanation, test_status, security_status, retry_count, merge_status, source, decision_options, critic_notes)
            VALUES (:repo_id, :issue_description, :branch_id, :base_sha, :head_sha, :explanation, :test_status, :security_status, :retry_count, :merge_status, :source, :decision_options, :critic_notes)
        ");

        $stmt->execute([
            'repo_id'           => $data['repo_id'],
            'issue_description' => $data['issue_description'] ?? null,
            'branch_id'         => $data['branch_id'] ?? null,
            'base_sha'          => $data['base_sha'] ?? null,
            'head_sha'          => $data['head_sha'] ?? null,
            'explanation'       => $data['explanation'] ?? null,
            'test_status'       => $data['test_status'] ?? 'pending',
            'security_status'   => $data['security_status'] ?? 'pending',
            'retry_count'       => $data['retry_count'] ?? 0,
            'merge_status'      => $data['merge_status'] ?? 'fixing',
            'source'            => $data['source'] ?? 'automated',
            'decision_options' => $data['decision_options'] ?? null,
            'critic_notes'     => $data['critic_notes'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function updateResult(int $id, array $data): bool
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = [];

        foreach (['base_sha', 'head_sha', 'explanation', 'test_status', 'security_status', 'retry_count', 'merge_status', 'decision_options', 'critic_notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params['id'] = $id;
        $sql = "UPDATE fixes SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Dedicated live diff fetcher (Addendum §1)
     * Fetches fresh diff from GitHub Compare API using base_sha and head_sha.
     * Does NOT store or cache raw diff content in MySQL.
     */
    public static function fetchLiveDiff(int $fixId, ?GitHubService $githubService = null): array
    {
        $fix = self::findById($fixId);
        if (!$fix) {
            throw new RuntimeException("Fix record not found", 404);
        }

        if (empty($fix['base_sha']) || empty($fix['head_sha'])) {
            return [
                'diff'        => null,
                'explanation' => $fix['explanation'] ?? 'No commits associated with this fix yet.',
                'status'      => 'pending',
            ];
        }

        $repo = Repo::findById($fix['repo_id']);
        if (!$repo) {
            throw new RuntimeException("Repository not found for fix", 404);
        }

        [$owner, $repoName] = explode('/', $repo['full_name']);
        $gh = $githubService ?? new GitHubService();

        try {
            $compare = $gh->compareShas($owner, $repoName, $fix['base_sha'], $fix['head_sha']);
            
            // Build unified diff string from compare files array
            $diffText = "";
            if (!empty($compare['files'])) {
                foreach ($compare['files'] as $file) {
                    $diffText .= "diff --git a/{$file['filename']} b/{$file['filename']}\n";
                    $diffText .= $file['patch'] ?? "Binary file or no patch content\n";
                    $diffText .= "\n";
                }
            } else {
                $diffText = "No file changes found between {$fix['base_sha']} and {$fix['head_sha']}.";
            }

            return [
                'diff'        => $diffText,
                'explanation' => $fix['explanation'] ?? 'LLM generated summary unavailable.',
                'status'      => 'success',
                'base_sha'    => $fix['base_sha'],
                'head_sha'    => $fix['head_sha'],
            ];
        } catch (Throwable $e) {
            return [
                'diff'        => null,
                'explanation' => $fix['explanation'] ?? '',
                'error'       => 'GitHub Compare API unavailable or commits no longer exist.',
                'status'      => 'commits_deleted',
            ];
        }
    }
}
