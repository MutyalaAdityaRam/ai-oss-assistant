<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Database;

Config::load();

$logFile = __DIR__ . '/../logs/ranking-weight-adjustments.log';
@mkdir(dirname($logFile), 0777, true);

echo "[" . date('Y-m-d H:i:s') . "] Starting weekly ranking weight recomputation...\n";

try {
    $pdo = Database::getConnection();

    // Query aggregated outcomes from pr_outcomes
    $stmt = $pdo->query("
        SELECT 
            repo_id,
            COUNT(*) AS total_prs,
            SUM(CASE WHEN outcome = 'merged' THEN 1 ELSE 0 END) AS merged_prs,
            AVG(days_to_resolution) AS avg_resolution_days
        FROM pr_outcomes
        GROUP BY repo_id
    ");

    $outcomes = $stmt->fetchAll();

    foreach ($outcomes as $row) {
        $repoId = (int) $row['repo_id'];
        $total = (int) $row['total_prs'];
        $merged = (int) $row['merged_prs'];
        $mergeRate = $total > 0 ? ($merged / $total) : 0.5;

        // Calculate responsiveness penalty/bonus
        $score = round($mergeRate * 100, 2);

        $stmtUp = $pdo->prepare("UPDATE repos SET maintainer_responsiveness_score = ? WHERE id = ?");
        $stmtUp->execute([$score, $repoId]);

        $logMsg = sprintf(
            "[%s] REPO_ID #%d: Merge rate %d/%d (%.1f%%) -> Updated maintainer_responsiveness_score to %.2f\n",
            date('Y-m-d H:i:s'),
            $repoId,
            $merged,
            $total,
            $mergeRate * 100,
            $score
        );

        file_put_contents($logFile, $logMsg, FILE_APPEND);
        echo $logMsg;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Weekly weight recomputation complete. Log saved to: {$logFile}\n";

} catch (\Throwable $e) {
    echo "[ERROR] Recomputation failed: " . $e->getMessage() . "\n";
    exit(1);
}
