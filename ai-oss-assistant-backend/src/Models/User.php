<?php

namespace AiOssAssistant\Models;

use AiOssAssistant\Database;
use PDO;
use Throwable;

class User
{
    private static array $testSpendMap = [];

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByInstallationId(string $installationId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE github_installation_id = ?");
        $stmt->execute([$installationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO users (github_installation_id, email, spend_cap_usd, digest_frequency)
            VALUES (:github_installation_id, :email, :spend_cap_usd, :digest_frequency)
        ");
        $stmt->execute([
            'github_installation_id' => $data['github_installation_id'],
            'email'                  => $data['email'],
            'spend_cap_usd'          => $data['spend_cap_usd'] ?? 5.00,
            'digest_frequency'       => $data['digest_frequency'] ?? 'daily',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function setTestSpendUsd(int $userId, float $spend): void
    {
        self::$testSpendMap[$userId] = $spend;
    }

    public static function getSpendCapUsd(int $userId): float
    {
        if (isset(self::$testSpendMap[$userId])) {
            return self::$testSpendMap[$userId];
        }

        try {
            $user = self::findById($userId);
            return $user ? (float) ($user['spend_cap_usd'] ?? 0.0) : 0.0;
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}
