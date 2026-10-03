<?php

namespace AiOssAssistant\Controllers;

use AiOssAssistant\Database;
use AiOssAssistant\Models\Fix;
use PDO;
use RuntimeException;

class FixController
{
    /**
     * Dedicated diff viewing endpoint (Addendum §1 & Decision Engine Addendum)
     * GET /api/fixes/{fixId}/diff
     */
    public function getDiff(array $params): array
    {
        $fixId = (int) ($params['fixId'] ?? 0);
        if ($fixId <= 0) {
            throw new RuntimeException("Invalid Fix ID provided", 400);
        }

        $result = Fix::fetchLiveDiff($fixId);
        $fixRecord = Fix::findById($fixId);

        $decisionOptions = null;
        if (!empty($fixRecord['decision_options'])) {
            $decisionOptions = json_decode($fixRecord['decision_options'], true);
        }

        return [
            'status'           => 'success',
            'fix_id'           => $fixId,
            'explanation'      => $result['explanation'] ?? '',
            'diff'             => $result['diff'] ?? null,
            'decision_options' => $decisionOptions,
            'error'            => $result['error'] ?? null,
        ];
    }

    /**
     * Public Read-Only Fix View Endpoint (Feature C10 & Item 8 Scoping Enforcer)
     * GET /public/fix/{fixId}
     * Must ONLY work for fixes associated with an open or merged Pull Request.
     */
    public function getPublicFix(array $params): array
    {
        $fixId = (int) ($params['fixId'] ?? 0);
        if ($fixId <= 0) {
            throw new RuntimeException("Invalid Fix ID provided", 400);
        }

        $fixRecord = Fix::findById($fixId);
        if (!$fixRecord) {
            throw new RuntimeException("Fix record not found", 404);
        }

        // Verify fix is attached to an open or merged PR in pull_requests table
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT pr.id, pr.status 
            FROM pull_requests pr
            WHERE pr.repo_id = ? AND pr.status IN ('open', 'merged')
        ");
        $stmt->execute([$fixRecord['repo_id']]);
        $prRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prRow) {
            throw new RuntimeException("Access Denied: Public fix view is restricted to fixes associated with an active open or merged pull request.", 403);
        }

        $result = Fix::fetchLiveDiff($fixId);

        $decisionOptions = null;
        if (!empty($fixRecord['decision_options'])) {
            $decisionOptions = json_decode($fixRecord['decision_options'], true);
        }

        return [
            'status'           => 'success',
            'fix_id'           => $fixId,
            'is_public'        => true,
            'pr_status'        => $prRow['status'],
            'explanation'      => $result['explanation'] ?? '',
            'diff'             => $result['diff'] ?? null,
            'decision_options' => $decisionOptions,
        ];
    }
}
