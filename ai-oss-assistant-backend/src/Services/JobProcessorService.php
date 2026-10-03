<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Config;
use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\ScanResult;
use AiOssAssistant\Models\Fix;
use RuntimeException;

class JobProcessorService
{
    /**
     * Shared job processing logic called by both webhook handler and cron poller.
     * Idempotency is enforced by verifying current repo/fix status before making state transitions.
     */

    public static function processAnalysisResult(int $repoId, array $payload): array
    {
        $repo = Repo::findById($repoId);
        if (!$repo) {
            throw new RuntimeException("Repo ID {$repoId} not found");
        }

        // Idempotency check: only process if repo is currently in 'analyzing' or 'candidate' state
        if (!in_array($repo['status'], ['analyzing', 'candidate'], true)) {
            return [
                'status'  => 'skipped',
                'reason'  => "Repo state is already '{$repo['status']}', skipping duplicate execution",
                'repo_id' => $repoId,
            ];
        }

        $findings = $payload['findings'] ?? [];
        $findingCount = count($findings);

        // Store compact summary in scan_results (NO raw scanner JSON in MySQL)
        ScanResult::create([
            'repo_id'          => $repoId,
            'tool'             => $payload['tool'] ?? 'semgrep',
            'finding_count'    => $findingCount,
            'severity_summary' => $payload['severity_summary'] ?? ['high' => 0, 'medium' => 0, 'low' => 0],
            'artifact_url'     => $payload['artifact_url'] ?? null,
        ]);

        if ($findingCount === 0) {
            Repo::updateStatus($repoId, 'clean_deleted');
            return [
                'status'       => 'processed',
                'action'       => 'clean_deleted',
                'finding_count'=> 0,
            ];
        }

        // Findings exist -> mark bugs_found
        Repo::updateStatus($repoId, 'bugs_found');

        return [
            'status'        => 'processed',
            'action'        => 'bugs_found',
            'finding_count' => $findingCount,
        ];
    }

    public static function processFixResult(int $fixId, array $payload): array
    {
        $fix = Fix::findById($fixId);
        if (!$fix) {
            throw new RuntimeException("Fix ID {$fixId} not found");
        }

        // Idempotency check: only process if fix is currently in 'fixing' merge_status
        if ($fix['merge_status'] !== 'fixing') {
            return [
                'status' => 'skipped',
                'reason' => "Fix is already in status '{$fix['merge_status']}', skipping duplicate execution",
                'fix_id' => $fixId,
            ];
        }

        $testsPassing = ($payload['test_status'] ?? '') === 'passing';
        $securityClean = ($payload['security_status'] ?? '') === 'clean';
        $baseSha = $payload['base_sha'] ?? $fix['base_sha'] ?? null;
        $headSha = $payload['head_sha'] ?? $fix['head_sha'] ?? null;
        $explanation = $payload['explanation'] ?? $fix['explanation'] ?? null;

        if ($testsPassing && $securityClean) {
            // Clean result -> merge branch to fork's default, mark merged_to_fork
            Fix::updateResult($fixId, [
                'base_sha'        => $baseSha,
                'head_sha'        => $headSha,
                'explanation'     => $explanation,
                'test_status'     => 'passing',
                'security_status' => 'clean',
                'merge_status'    => 'merged_to_fork',
            ]);

            return [
                'status'       => 'processed',
                'action'       => 'merged_to_fork',
                'fix_id'       => $fixId,
                'test_status'  => 'passing',
                'security_clean'=> true,
            ];
        }

        // Failure path: increment retry_count
        $newRetryCount = ((int) $fix['retry_count']) + 1;
        $maxRetries    = Config::getInt('MAX_RETRIES', 5);

        if ($newRetryCount >= $maxRetries) {
            // Retry cap (5) hit -> flag for manual review
            Fix::updateResult($fixId, [
                'base_sha'        => $baseSha,
                'head_sha'        => $headSha,
                'explanation'     => $explanation,
                'test_status'     => $payload['test_status'] ?? 'failing',
                'security_status' => $payload['security_status'] ?? 'findings',
                'retry_count'     => $newRetryCount,
                'merge_status'    => 'flagged_manual_review',
            ]);

            return [
                'status'      => 'processed',
                'action'      => 'flagged_manual_review',
                'fix_id'      => $fixId,
                'retry_count' => $newRetryCount,
            ];
        }

        // Still below cap -> update retries and re-trigger
        Fix::updateResult($fixId, [
            'base_sha'        => $baseSha,
            'head_sha'        => $headSha,
            'explanation'     => $explanation,
            'test_status'     => $payload['test_status'] ?? 'failing',
            'security_status' => $payload['security_status'] ?? 'findings',
            'retry_count'     => $newRetryCount,
            'merge_status'    => 'fixing',
        ]);

        return [
            'status'      => 'processed',
            'action'      => 'retrigger_fix',
            'fix_id'      => $fixId,
            'retry_count' => $newRetryCount,
        ];
    }
}
