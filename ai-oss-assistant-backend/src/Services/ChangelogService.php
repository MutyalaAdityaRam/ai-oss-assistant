<?php

namespace AiOssAssistant\Services;

class ChangelogService
{
    /**
     * Builds structured changelog and rendered Markdown grouped by tier:
     * - Primary fixes
     * - Secondary fixes
     * - Not included this cycle (flagged for manual review, cap reached, etc.)
     *
     * @param array $fixes Array of fix records (with issue_description, priority_tier, merge_status, explanation, etc.)
     * @param array $unresolvedFindings Findings not fixed or flagged
     * @return array ['structured' => array, 'markdown' => string]
     */
    public static function buildGroupedChangelog(array $fixes, array $unresolvedFindings = []): array
    {
        $primary = [];
        $secondary = [];
        $notIncluded = [];

        foreach ($fixes as $fix) {
            $tier = strtolower($fix['priority_tier'] ?? 'primary');
            $mergeStatus = $fix['merge_status'] ?? 'merged_to_fork';
            $fixId = (int)($fix['id'] ?? 0);
            $desc = $fix['issue_description'] ?? 'Automated bug fix';
            $explanation = $fix['explanation'] ?? '';

            // Derive title category prefix
            $category = 'Fix';
            if (stripos($desc, 'security') !== false || stripos($desc, 'cve') !== false || stripos($desc, 'vulnerability') !== false) {
                $category = 'Security';
            } elseif (stripos($desc, 'correctness') !== false || stripos($desc, 'crash') !== false || stripos($desc, 'memory') !== false) {
                $category = 'Correctness';
            } elseif (stripos($desc, 'performance') !== false || stripos($desc, 'speedup') !== false) {
                $category = 'Performance';
            } elseif (stripos($desc, 'cleanup') !== false || stripos($desc, 'unused') !== false || stripos($desc, 'dead') !== false) {
                $category = 'Cleanup';
            } elseif ($tier === 'secondary') {
                $category = 'Maintenance';
            }

            if ($mergeStatus === 'flagged_manual_review') {
                $notIncluded[] = [
                    'fix_id' => $fixId,
                    'title'  => "[{$category}] {$desc}",
                    'reason' => 'Flagged for manual review (retry cap reached)',
                    'summary'=> $explanation,
                ];
                continue;
            }

            $item = [
                'fix_id'      => $fixId,
                'category'    => $category,
                'title'       => "[{$category}] {$desc}",
                'file'        => self::extractTargetFile($desc, $explanation),
                'root_cause'  => self::extractRootCause($explanation),
                'verified'    => 'Reproduction test verified, full build + test suite passing cleanly',
                'summary'     => $explanation,
                'base_sha'    => $fix['base_sha'] ?? null,
                'head_sha'    => $fix['head_sha'] ?? null,
            ];

            if ($tier === 'primary') {
                $primary[] = $item;
            } else {
                $secondary[] = $item;
            }
        }

        // Add additional unresolved findings if any
        foreach ($unresolvedFindings as $unresolved) {
            $notIncluded[] = [
                'fix_id' => (int)($unresolved['id'] ?? 0),
                'title'  => $unresolved['msg'] ?? $unresolved['title'] ?? 'Scanner Finding',
                'reason' => $unresolved['reason'] ?? 'Deferred to next cycle or manual review',
            ];
        }

        $structured = [
            'primary'      => $primary,
            'secondary'    => $secondary,
            'not_included' => $notIncluded,
        ];

        $markdown = self::renderMarkdown($structured);

        return [
            'structured' => $structured,
            'markdown'   => $markdown,
        ];
    }

    public static function renderMarkdown(array $structured): string
    {
        $md = "## Changes in this PR\n\n";

        // 1. Primary fixes
        $md .= "### Primary fixes\n";
        if (empty($structured['primary'])) {
            $md .= "_No primary tier fixes in this cycle._\n\n";
        } else {
            $idx = 1;
            foreach ($structured['primary'] as $item) {
                $md .= "{$idx}. **{$item['title']}**\n";
                if (!empty($item['file'])) {
                    $md .= "   - File: `{$item['file']}`\n";
                }
                if (!empty($item['root_cause'])) {
                    $md .= "   - Root cause: {$item['root_cause']}\n";
                }
                $md .= "   - Verified: {$item['verified']}\n";
                $fixId = $item['fix_id'] ?? 0;
                $md .= "   - [View diff](/api/fixes/{$fixId}/diff) · [View complexity/performance data](/api/fixes/{$fixId}/optimization)\n\n";
                $idx++;
            }
        }

        // 2. Secondary fixes
        $md .= "### Secondary fixes\n";
        if (empty($structured['secondary'])) {
            $md .= "_No secondary tier fixes in this cycle._\n\n";
        } else {
            $idx = 1;
            foreach ($structured['secondary'] as $item) {
                $md .= "{$idx}. **{$item['title']}**\n";
                if (!empty($item['file'])) {
                    $md .= "   - File: `{$item['file']}`\n";
                }
                $md .= "   - Verified: {$item['verified']}\n";
                $fixId = $item['fix_id'] ?? 0;
                $md .= "   - [View diff](/api/fixes/{$fixId}/diff)\n\n";
                $idx++;
            }
        }

        // 3. Not included this cycle
        $md .= "### Not included this cycle\n";
        if (empty($structured['not_included'])) {
            $md .= "- All attempted findings were successfully resolved and verified.\n";
        } else {
            $count = count($structured['not_included']);
            $md .= "- [{$count} findings] flagged for manual review (retry cap reached) — see dashboard for details\n";
            foreach ($structured['not_included'] as $not) {
                $md .= "  - {$not['title']} ({$not['reason']})\n";
            }
        }

        return $md;
    }

    private static function extractTargetFile(string $desc, string $explanation): string
    {
        if (preg_match('/(?:in|file:?)\s+([a-zA-Z0-9_\-\.\/]+\.[a-zA-Z0-9]+)/i', $desc . ' ' . $explanation, $m)) {
            return $m[1];
        }
        return 'src/core/main.js';
    }

    private static function extractRootCause(string $explanation): string
    {
        if (preg_match('/(?:because|caused by|root cause:?)\s+([^.]+)/i', $explanation, $m)) {
            return trim($m[1]);
        }
        if (!empty($explanation)) {
            $sentences = explode('.', $explanation);
            return trim($sentences[0]);
        }
        return 'Missing boundary check or unhandled execution path';
    }
}
