<?php

namespace AiOssAssistant\Models;

use AiOssAssistant\Database;
use PDO;
use RuntimeException;

class Suggestion
{
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && !empty($row['source_links'])) {
            $row['source_links'] = json_decode($row['source_links'], true) ?? [];
        }
        return $row ?: null;
    }

    public static function findByRepoId(int $repoId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE repo_id = ? ORDER BY id ASC");
        $stmt->execute([$repoId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if (!empty($row['source_links'])) {
                $row['source_links'] = json_decode($row['source_links'], true) ?? [];
            } else {
                $row['source_links'] = [];
            }
        }
        return $rows;
    }

    public static function countImplementedForRepo(int $repoId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM suggestions WHERE repo_id = ? AND status IN ('selected', 'implemented')");
        $stmt->execute([$repoId]);
        return (int) $stmt->fetchColumn();
    }

    public static function create(array $data): int
    {
        $source = $data['source'] ?? 'ai_research';

        // HONESTY CONSTRAINT: Every AI research suggestion MUST include valid source links
        if ($source === 'ai_research') {
            $links = $data['source_links'] ?? [];
            if (is_string($links)) {
                $links = json_decode($links, true) ?? [];
            }
            if (empty($links) || !is_array($links)) {
                throw new RuntimeException("HONESTY CONSTRAINT VIOLATION: AI Research Suggestions must contain at least one valid cited source link.", 422);
            }
        }

        $linksJson = is_array($data['source_links'] ?? null) ? json_encode($data['source_links']) : ($data['source_links'] ?? '[]');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO suggestions 
            (repo_id, title, rationale, source_links, effort_estimate, status, fix_id, source)
            VALUES (:repo_id, :title, :rationale, :source_links, :effort_estimate, :status, :fix_id, :source)
        ");

        $stmt->execute([
            'repo_id'         => $data['repo_id'],
            'title'           => $data['title'],
            'rationale'       => $data['rationale'] ?? null,
            'source_links'    => $linksJson,
            'effort_estimate' => $data['effort_estimate'] ?? 'medium',
            'status'          => $data['status'] ?? 'proposed',
            'fix_id'          => $data['fix_id'] ?? null,
            'source'          => $source,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function updateStatus(int $id, string $status, ?int $fixId = null): bool
    {
        $pdo = Database::getConnection();
        if ($fixId !== null) {
            $stmt = $pdo->prepare("UPDATE suggestions SET status = ?, fix_id = ? WHERE id = ?");
            return $stmt->execute([$status, $fixId, $id]);
        }
        $stmt = $pdo->prepare("UPDATE suggestions SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public static function skipRemainingProposed(int $repoId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE suggestions SET status = 'skipped' WHERE repo_id = ? AND status = 'proposed'");
        $stmt->execute([$repoId]);
        return $stmt->rowCount();
    }
}
