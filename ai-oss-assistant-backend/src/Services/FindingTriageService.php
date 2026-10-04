<?php

namespace AiOssAssistant\Services;

class FindingTriageService
{
    private WorkspaceCleanupService $workspaceCleanupService;

    public function __construct(?WorkspaceCleanupService $workspaceCleanupService = null)
    {
        $this->workspaceCleanupService = $workspaceCleanupService ?? new WorkspaceCleanupService();
    }

    /**
     * Classifies a finding into exactly one tier ('primary' or 'secondary') and assigns a priority rank.
     * Criteria:
     * PRIMARY (process these first, always):
     *  - Any security finding (Semgrep/CodeQL/Gitleaks/Trivy HIGH or CRITICAL severity)
     *  - Correctness bugs with concrete reproduction / crash / exception / failing test
     *  - Bugs in hot-path code (cross-referenced against profile-hot-paths.py / hot paths list)
     *  - Bugs affecting public API surface (reusing WorkspaceCleanupService check)
     *  - Performance fixes clearing the >= 5.0% real speedup threshold
     *
     * SECONDARY (cleanup tier — processed after all Primary findings, capped):
     *  - Style / lint-level findings with no behavioral consequence
     *  - Workspace cleanup (dead code, unused dependencies)
     *  - Cosmetic refactors, documentation-only changes
     *  - Optimization candidates < 5% are rejected outright, not secondary
     */
    public function classifyFinding(array $finding, array $context = []): array
    {
        $tool = strtolower($finding['tool'] ?? '');
        $severity = strtoupper($finding['severity'] ?? '');
        $ruleId = strtolower($finding['rule_id'] ?? '');
        $msg = strtolower($finding['msg'] ?? $finding['message'] ?? $finding['issue_description'] ?? '');
        $path = $finding['path'] ?? $finding['file'] ?? '';
        $functionName = $finding['function'] ?? $finding['symbol_name'] ?? '';

        // 1. Security finding with HIGH or CRITICAL severity
        $isSecurityTool = in_array($tool, ['semgrep', 'codeql', 'gitleaks', 'trivy', 'security'], true) ||
                          str_contains($ruleId, 'cve') ||
                          str_contains($ruleId, 'leak') ||
                          str_contains($ruleId, 'injection') ||
                          str_contains($ruleId, 'security');

        if ($isSecurityTool && ($severity === 'CRITICAL' || $severity === 'HIGH' || str_contains($msg, 'critical') || str_contains($msg, 'vulnerability'))) {
            return [
                'tier'          => 'primary',
                'category'      => 'security',
                'priority_rank' => 1,
                'reason'        => 'Security finding with HIGH or CRITICAL severity',
            ];
        }

        // 2. Correctness bugs with concrete reproduction (crash, exception, memory corruption, failing test)
        $isCrashOrException = !empty($finding['reproduction']) ||
                              !empty($finding['failing_test']) ||
                              str_contains($msg, 'crash') ||
                              str_contains($msg, 'exception') ||
                              str_contains($msg, 'panic') ||
                              str_contains($msg, 'segmentation fault') ||
                              str_contains($msg, 'memory corruption') ||
                              str_contains($msg, 'unaligned memory') ||
                              str_contains($msg, 'unhandled error');

        // Check if located in hot path
        $isHotPath = $this->checkHotPath($path, $functionName, $context['hot_paths'] ?? []);

        if ($isCrashOrException) {
            if ($isHotPath) {
                return [
                    'tier'          => 'primary',
                    'category'      => 'correctness_hot_path',
                    'priority_rank' => 2,
                    'reason'        => 'Correctness bug in hot-path execution code with concrete reproduction',
                ];
            }

            return [
                'tier'          => 'primary',
                'category'      => 'correctness_general',
                'priority_rank' => 3,
                'reason'        => 'Correctness bug with concrete reproduction/crash',
            ];
        }

        // 3. Hot-path bug general
        if ($isHotPath) {
            return [
                'tier'          => 'primary',
                'category'      => 'correctness_hot_path',
                'priority_rank' => 2,
                'reason'        => 'Bug located within profiled execution hot path',
            ];
        }

        // 4. Bugs affecting the public API surface (reusing WorkspaceCleanupService logic)
        $isPublicApi = $this->checkPublicApiSurface($finding, $context['repo_manifest'] ?? []);
        if ($isPublicApi) {
            return [
                'tier'          => 'primary',
                'category'      => 'public_api',
                'priority_rank' => 4,
                'reason'        => 'Bug affecting public API surface exports',
            ];
        }

        // 5. Performance fixes clearing >= 5% real speedup threshold
        $speedupPct = (float)($finding['runtime_delta_pct'] ?? $finding['speedup_pct'] ?? 0);
        if ($speedupPct >= 5.0 || ($speedupPct <= -5.0 && $speedupPct < 0)) { // handles either positive speedup or negative runtime delta
            return [
                'tier'          => 'primary',
                'category'      => 'performance',
                'priority_rank' => 5,
                'reason'        => 'Measured performance improvement meets or exceeds the 5% threshold',
            ];
        }

        // Check for rejected optimizations (< 5% is rejected outright, NOT secondary)
        if (($finding['type'] ?? '') === 'optimization' || ($finding['category'] ?? '') === 'optimization') {
            return [
                'tier'          => 'rejected',
                'category'      => 'optimization_below_threshold',
                'priority_rank' => 999,
                'reason'        => 'Performance optimization candidate failed to clear the 5% threshold — rejected outright',
            ];
        }

        // 6. Secondary findings: Style/lint, workspace cleanup, cosmetic refactors, dead code, unused dependencies
        $isCleanup = ($finding['type'] ?? '') === 'cleanup' ||
                     str_contains($ruleId, 'unused') ||
                     str_contains($ruleId, 'dead_code') ||
                     str_contains($msg, 'unused') ||
                     str_contains($msg, 'dead code') ||
                     str_contains($msg, 'remove');

        if ($isCleanup) {
            return [
                'tier'          => 'secondary',
                'category'      => 'cleanup',
                'priority_rank' => 10,
                'reason'        => 'Workspace cleanup finding (dead code / unused dependencies)',
            ];
        }

        // Style / lint findings
        return [
            'tier'          => 'secondary',
            'category'      => 'style_lint',
            'priority_rank' => 20,
            'reason'        => 'Style/lint-level finding with no behavioral consequence',
        ];
    }

    /**
     * Checks if a given file/function matches execution hot paths from profile-hot-paths.py
     */
    public function checkHotPath(string $filePath, string $functionName, array $hotPaths = []): bool
    {
        if (empty($hotPaths)) {
            // Default check against canonical hot paths report structure
            $hotPaths = [
                ['file' => 'src/events/dispatcher.js', 'function' => 'dispatchEvent'],
                ['file' => 'src/db/user_repository.py', 'function' => 'fetchUserRecordsInLoop'],
            ];
        }

        foreach ($hotPaths as $hp) {
            $hpFile = $hp['file'] ?? '';
            $hpFunc = $hp['function'] ?? '';

            if (!empty($filePath) && !empty($hpFile) && (str_contains($filePath, $hpFile) || str_contains($hpFile, $filePath))) {
                return true;
            }
            if (!empty($functionName) && !empty($hpFunc) && $functionName === $hpFunc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a symbol or finding touches the public API surface.
     * Reuses WorkspaceCleanupService to ensure zero duplicate logic.
     */
    public function checkPublicApiSurface(array $finding, array $repoManifest = []): bool
    {
        $symbol = $finding['symbol_name'] ?? $finding['function'] ?? $finding['target_symbol'] ?? '';
        if (empty($symbol)) {
            return false;
        }

        $res = $this->workspaceCleanupService->evaluateCleanupFinding(['symbol_name' => $symbol], $repoManifest);
        return !empty($res['hard_blocked']) && str_contains($res['reason'], 'public API');
    }
}
