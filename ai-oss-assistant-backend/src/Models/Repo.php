<?php

namespace AiOssAssistant\Models;

use AiOssAssistant\Database;
use PDO;

class Repo
{
    public static function findAll(?string $status = null): array
    {
        $pdo = Database::getConnection();
        if ($status !== null) {
            $stmt = $pdo->prepare("SELECT * FROM repos WHERE status = ? ORDER BY resume_score DESC, stars DESC");
            $stmt->execute([$status]);
        } else {
            $stmt = $pdo->query("SELECT * FROM repos ORDER BY resume_score DESC, stars DESC");
        }
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM repos WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && !empty($row['devcontainer_config'])) {
            $row['devcontainer_config'] = json_decode($row['devcontainer_config'], true);
        }
        return $row ?: null;
    }

    public static function findByFullName(string $fullName): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM repos WHERE full_name = ?");
        $stmt->execute([$fullName]);
        $row = $stmt->fetch();
        if ($row && !empty($row['devcontainer_config'])) {
            $row['devcontainer_config'] = json_decode($row['devcontainer_config'], true);
        }
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO repos (full_name, stars, last_activity, resume_score, status, devcontainer_config)
            VALUES (:full_name, :stars, :last_activity, :resume_score, :status, :devcontainer_config)
            ON DUPLICATE KEY UPDATE 
                stars = VALUES(stars),
                last_activity = VALUES(last_activity),
                resume_score = VALUES(resume_score)
        ");

        $devConfig = isset($data['devcontainer_config']) ? json_encode($data['devcontainer_config']) : null;

        $stmt->execute([
            'full_name'           => $data['full_name'],
            'stars'               => $data['stars'] ?? 0,
            'last_activity'       => $data['last_activity'] ?? date('Y-m-d'),
            'resume_score'        => $data['resume_score'] ?? 0.00,
            'status'              => $data['status'] ?? 'candidate',
            'devcontainer_config' => $devConfig,
        ]);

        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $existing = self::findByFullName($data['full_name']);
            return $existing ? (int) $existing['id'] : 0;
        }
        return $id;
    }

    public static function updateStatus(int $id, string $status): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE repos SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public static function updateDevcontainerConfig(int $id, array $config): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE repos SET devcontainer_config = ? WHERE id = ?");
        return $stmt->execute([json_encode($config), $id]);
    }
}
