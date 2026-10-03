<?php

namespace AiOssAssistant\Models;

use AiOssAssistant\Database;
use PDO;

class ScanResult
{
    public static function findByRepoId(int $repoId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scan_results WHERE repo_id = ? ORDER BY created_at DESC");
        $stmt->execute([$repoId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if (!empty($row['severity_summary'])) {
                $row['severity_summary'] = json_decode($row['severity_summary'], true);
            }
        }
        return $rows;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO scan_results (repo_id, tool, finding_count, severity_summary, artifact_url)
            VALUES (:repo_id, :tool, :finding_count, :severity_summary, :artifact_url)
        ");

        $severitySummary = isset($data['severity_summary']) ? json_encode($data['severity_summary']) : null;

        $stmt->execute([
            'repo_id'          => $data['repo_id'],
            'tool'             => $data['tool'],
            'finding_count'    => $data['finding_count'] ?? 0,
            'severity_summary' => $severitySummary,
            'artifact_url'     => $data['artifact_url'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
