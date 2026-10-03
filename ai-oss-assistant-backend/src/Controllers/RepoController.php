<?php

namespace AiOssAssistant\Controllers;

use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\ScanResult;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Models\User;
use AiOssAssistant\Database;
use RuntimeException;

class RepoController
{
    public function index(): array
    {
        $repos = Repo::findAll();
        return [
            'status' => 'success',
            'count'  => count($repos),
            'data'   => $repos,
        ];
    }

    public function show(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $repo = Repo::findById($id);

        if (!$repo) {
            throw new RuntimeException("Repo not found", 404);
        }

        $scanResults = ScanResult::findByRepoId($id);
        $fixes       = Fix::findByRepoId($id);

        return [
            'status' => 'success',
            'data'   => [
                'repo'         => $repo,
                'scan_results' => $scanResults,
                'fixes'        => $fixes,
            ],
        ];
    }

    public function report(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $repo = Repo::findById($id);

        if (!$repo) {
            throw new RuntimeException("Repo not found", 404);
        }

        $fixes = Fix::findByRepoId($id);
        $bugsFixed = 0;
        $securityFixed = 0;

        foreach ($fixes as $fix) {
            if ($fix['merge_status'] === 'merged_to_fork') {
                if ($fix['test_status'] === 'passing') $bugsFixed++;
                if ($fix['security_status'] === 'clean') $securityFixed++;
            }
        }

        return [
            'status' => 'success',
            'data'   => [
                'repo_id'        => $id,
                'full_name'      => $repo['full_name'],
                'summary'        => "Automated fix pipeline evaluated {$repo['full_name']}. Total fixes merged to fork: " . count($fixes),
                'bugs_fixed'     => $bugsFixed,
                'security_fixed' => $securityFixed,
            ],
        ];
    }

    public function updateSettings(): array
    {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];

        $spendCap = (float) ($input['spend_cap'] ?? 5.00);
        $frequency = $input['digest_frequency'] ?? 'daily';

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE users SET spend_cap_usd = ?, digest_frequency = ? WHERE id = 1");
        $stmt->execute([$spendCap, $frequency]);

        return [
            'status'  => 'success',
            'updated' => true,
            'settings'=> [
                'spend_cap'        => $spendCap,
                'digest_frequency' => $frequency,
            ],
        ];
    }

    public function pendingNotifications(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM notification_queue WHERE sent_at IS NULL ORDER BY created_at DESC");
        $notifications = $stmt->fetchAll();

        return [
            'status' => 'success',
            'data'   => $notifications,
        ];
    }
}
