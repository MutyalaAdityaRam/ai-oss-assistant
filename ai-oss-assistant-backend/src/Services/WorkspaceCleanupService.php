<?php

namespace AiOssAssistant\Services;

class WorkspaceCleanupService
{
    /**
     * Evaluates a workspace cleanup finding (unused dependency, dead code) using deterministic hard blocks.
     */
    public function evaluateCleanupFinding(array $finding, array $repoManifest = []): array
    {
        $symbolName = $finding['symbol_name'] ?? $finding['dependency_name'] ?? '';
        $isPublicApiReachable = $this->checkPublicApiSurface($symbolName, $repoManifest);
        $hasDynamicUsage = $this->checkDynamicUsage($symbolName, $repoManifest);

        // Deterministic Hard Block 1: Public API reachability
        if ($isPublicApiReachable) {
            return [
                'action' => 'suggestion_only',
                'hard_blocked' => true,
                'reason' => "HARD BLOCK: Symbol '{$symbolName}' is reachable from declared public API surface exports.",
            ];
        }

        // Deterministic Hard Block 2: Dynamic usage / string-based reference
        if ($hasDynamicUsage) {
            return [
                'action' => 'suggestion_only',
                'hard_blocked' => true,
                'reason' => "HARD BLOCK: Symbol '{$symbolName}' has dynamic/string-based usage references in codebase.",
            ];
        }

        // Tiered Confidence Evaluation
        $confidence = $finding['confidence_score'] ?? 85;
        if ($confidence >= 80) {
            return [
                'action' => 'auto_fix',
                'hard_blocked' => false,
                'reason' => "Confirmed safe: High confidence item with no public API reachability or dynamic usage.",
            ];
        }

        return [
            'action' => 'suggestion_only',
            'hard_blocked' => false,
            'reason' => "Low confidence item (score: {$confidence}). Routed to suggestions table for maintainer review.",
        ];
    }

    private function checkPublicApiSurface(string $symbol, array $manifest): bool
    {
        $publicExports = $manifest['public_exports'] ?? [];
        return in_array($symbol, $publicExports, true) || !empty($manifest['is_library_public_export']);
    }

    private function checkDynamicUsage(string $symbol, array $manifest): bool
    {
        $dynamicReferences = $manifest['dynamic_references'] ?? [];
        return in_array($symbol, $dynamicReferences, true);
    }
}
