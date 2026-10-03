<?php

namespace AiOssAssistant\Services;

class DecisionEngineService
{
    /**
     * Determines whether the Decision Engine should trigger multi-option comparison.
     */
    public function shouldTrigger(string $findingType, bool $hasMultipleApproaches = false, bool $isOptimization = false): bool
    {
        // Always trigger for optimization targets
        if ($isOptimization || $findingType === 'optimization') {
            return true;
        }

        // Trigger if finding involves structural choice or explicitly multi-approach reasoning
        if ($hasMultipleApproaches || in_array($findingType, ['structural', 'suggestion', 'refactor'], true)) {
            return true;
        }

        // Skip trivial bug fixes (e.g. one-line null checks, missing await)
        return false;
    }

    /**
     * Evaluates 2-3 distinct implementation options against the 7 weighted criteria:
     * - Correctness (30%)
     * - Performance (20%)
     * - Maintainability (15%)
     * - Simplicity (10%)
     * - Scalability (10%)
     * - Security (10%)
     * - Testability (5%)
     *
     * Tiebreaker Rule: If top two options score within 5 points margin, select the simpler option.
     */
    public function evaluateAndSelect(string $problemStatement, array $options): array
    {
        $scoredOptions = [];

        foreach ($options as $index => $opt) {
            $summary     = $opt['option_summary'] ?? $opt['name'] ?? ("Option " . ($index + 1));
            $correctness = min(100, max(0, (int)($opt['scores']['correctness'] ?? $opt['correctness'] ?? 90)));
            $performance = min(100, max(0, (int)($opt['scores']['performance'] ?? $opt['performance'] ?? 80)));
            $maint       = min(100, max(0, (int)($opt['scores']['maintainability'] ?? $opt['maintainability'] ?? 85)));
            $simplicity  = min(100, max(0, (int)($opt['scores']['simplicity'] ?? $opt['simplicity'] ?? 80)));
            $scalability = min(100, max(0, (int)($opt['scores']['scalability'] ?? $opt['scalability'] ?? 85)));
            $security    = min(100, max(0, (int)($opt['scores']['security'] ?? $opt['security'] ?? 90)));
            $testability = min(100, max(0, (int)($opt['scores']['testability'] ?? $opt['testability'] ?? 85)));

            $weightedScore = ($correctness * 0.30)
                           + ($performance * 0.20)
                           + ($maint       * 0.15)
                           + ($simplicity  * 0.10)
                           + ($scalability * 0.10)
                           + ($security    * 0.10)
                           + ($testability * 0.05);

            $scoredOptions[] = [
                'option_summary' => $summary,
                'scores'         => [
                    'correctness'     => $correctness,
                    'performance'     => $performance,
                    'maintainability' => $maint,
                    'simplicity'      => $simplicity,
                    'scalability'     => $scalability,
                    'security'        => $security,
                    'testability'     => $testability,
                ],
                'total_score'    => round($weightedScore, 2),
                'selected'       => false,
            ];
        }

        // Rank by highest total_score
        usort($scoredOptions, fn($a, $b) => $b['total_score'] <=> $a['total_score']);

        // Tiebreaker: If top 2 options score within 5.0 points margin, prefer simpler option
        if (count($scoredOptions) >= 2) {
            $margin = abs($scoredOptions[0]['total_score'] - $scoredOptions[1]['total_score']);
            if ($margin <= 5.0) {
                if ($scoredOptions[1]['scores']['simplicity'] > $scoredOptions[0]['scores']['simplicity']) {
                    // Swap to simpler option
                    $temp = $scoredOptions[0];
                    $scoredOptions[0] = $scoredOptions[1];
                    $scoredOptions[1] = $temp;
                }
            }
        }

        // Mark winning option as selected
        $scoredOptions[0]['selected'] = true;

        return [
            'problem'         => $problemStatement,
            'selected_option' => $scoredOptions[0],
            'options_json'    => json_encode($scoredOptions),
            'scored_options'  => $scoredOptions,
        ];
    }
}
