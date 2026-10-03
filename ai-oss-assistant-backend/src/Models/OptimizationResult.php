<?php

namespace AiOssAssistant\Models;

use AiOssAssistant\Database;
use PDO;

class OptimizationResult
{
    public static function findByFixId(int $fixId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM optimization_results WHERE fix_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$fixId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO optimization_results 
            (fix_id, complexity_tool, complexity_before, complexity_after, test_suite_duration_ms_before, test_suite_duration_ms_after, runtime_delta_pct, summary)
            VALUES (:fix_id, :complexity_tool, :complexity_before, :complexity_after, :test_suite_duration_ms_before, :test_suite_duration_ms_after, :runtime_delta_pct, :summary)
        ");

        $before = isset($data['test_suite_duration_ms_before']) ? (int)$data['test_suite_duration_ms_before'] : null;
        $after  = isset($data['test_suite_duration_ms_after']) ? (int)$data['test_suite_duration_ms_after'] : null;
        $delta  = self::calculateRuntimeDeltaPct($before, $after);

        $stmt->execute([
            'fix_id'                        => $data['fix_id'],
            'complexity_tool'               => $data['complexity_tool'] ?? 'lizard',
            'complexity_before'             => $data['complexity_before'] ?? null,
            'complexity_after'              => $data['complexity_after'] ?? null,
            'test_suite_duration_ms_before' => $before,
            'test_suite_duration_ms_after'  => $after,
            'runtime_delta_pct'             => $delta,
            'summary'                       => $data['summary'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Calculates percentage change in test suite wall-clock runtime.
     * Returns negative for faster, positive for slower, null if timing unavailable.
     */
    public static function calculateRuntimeDeltaPct(?int $beforeMs, ?int $afterMs): ?float
    {
        if ($beforeMs === null || $afterMs === null || $beforeMs <= 0) {
            return null;
        }

        $delta = (($afterMs - $beforeMs) / $beforeMs) * 100.0;
        return round($delta, 2);
    }

    /**
     * Senior-Engineer Complexity-vs-Benefit Tradeoff Enforcer:
     * ACCEPT if runtime_delta_pct <= -5% AND (complexity_delta_pct <= 0 OR abs(runtime_delta_pct) >= complexity_delta_pct * 2).
     * REJECT (should_revert = true) if speedup < 5% or complexity gain is not matched by 2x runtime gain.
     */
    public static function evaluateComplexityVsBenefitTradeoff(?int $compBefore, ?int $compAfter, ?float $runtimeDeltaPct): array
    {
        if ($runtimeDeltaPct === null) {
            return [
                'accepted' => false,
                'should_revert' => true,
                'reason' => 'No runtime benchmark timing measurement available.'
            ];
        }

        $compDeltaPct = 0.0;
        if ($compBefore !== null && $compAfter !== null && $compBefore > 0) {
            $compDeltaPct = (($compAfter - $compBefore) / $compBefore) * 100.0;
        }

        // Criterion 1: Speedup must clear at least 5% (runtime_delta_pct <= -5.0)
        $hasSufficientSpeedup = ($runtimeDeltaPct <= -5.0);

        // Criterion 2: If complexity increased, speedup must be at least 2x the complexity cost
        $hasAcceptableComplexity = ($compDeltaPct <= 0.0) || (abs($runtimeDeltaPct) >= ($compDeltaPct * 2.0));

        $accepted = $hasSufficientSpeedup && $hasAcceptableComplexity;

        $reason = $accepted
            ? "Accepted optimization: Runtime improved by " . abs($runtimeDeltaPct) . "% (clears 5% threshold) with acceptable complexity delta (" . round($compDeltaPct, 2) . "%)."
            : "REJECTED optimization (reverting commit): Speedup of " . abs($runtimeDeltaPct) . "% failed 5% threshold or failed 2x complexity-to-benefit ratio.";

        return [
            'accepted' => $accepted,
            'should_revert' => !$accepted,
            'complexity_delta_pct' => round($compDeltaPct, 2),
            'runtime_delta_pct' => $runtimeDeltaPct,
            'reason' => $reason,
        ];
    }
}
