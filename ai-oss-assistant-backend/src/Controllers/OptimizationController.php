<?php

namespace AiOssAssistant\Controllers;

use AiOssAssistant\Models\OptimizationResult;
use AiOssAssistant\Models\Fix;
use RuntimeException;

class OptimizationController
{
    /**
     * Optimization & Complexity Endpoint (Addendum §3)
     * GET /api/fixes/{fixId}/optimization
     *
     * Returns measured cyclomatic complexity (Lizard) and wall-clock test suite runtime deltas.
     */
    public function getOptimization(array $params): array
    {
        $fixId = (int) ($params['fixId'] ?? 0);
        if ($fixId <= 0) {
            throw new RuntimeException("Invalid Fix ID provided", 400);
        }

        $fix = Fix::findById($fixId);
        if (!$fix) {
            throw new RuntimeException("Fix record not found", 404);
        }

        $opt = OptimizationResult::findByFixId($fixId);

        if (!$opt) {
            return [
                'status' => 'success',
                'data'   => [
                    'fix_id'                         => $fixId,
                    'complexity_tool'                => 'lizard',
                    'complexity_before'              => null,
                    'complexity_after'               => null,
                    'test_suite_duration_ms_before'  => null,
                    'test_suite_duration_ms_after'   => null,
                    'runtime_delta_pct'              => null,
                    'summary'                        => 'No optimization measurements captured for this fix.',
                ],
            ];
        }

        return [
            'status' => 'success',
            'data'   => $opt,
        ];
    }
}
