<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Database;

Config::load();

$logFile = __DIR__ . '/../logs/platform-self-optimization.log';
@mkdir(dirname($logFile), 0777, true);

echo "[" . date('Y-m-d H:i:s') . "] Starting platform self-optimization database & endpoint audit...\n";

try {
    $pdo = Database::getConnection();

    $queriesToAudit = [
        "SELECT * FROM repos WHERE status = 'candidate' ORDER BY resume_score DESC",
        "SELECT * FROM fixes WHERE repo_id = 1 AND merge_status = 'merged_to_fork'",
        "SELECT * FROM notification_queue WHERE user_id = 1 AND sent_at IS NULL",
    ];

    $auditResults = [];

    foreach ($queriesToAudit as $query) {
        $explainStmt = $pdo->prepare("EXPLAIN " . $query);
        $explainStmt->execute();
        $rows = $explainStmt->fetchAll(PDO::FETCH_ASSOC);

        $usesIndex = false;
        foreach ($rows as $r) {
            if (!empty($r['key'])) {
                $usesIndex = true;
                break;
            }
        }

        $auditResults[] = [
            'query' => $query,
            'uses_index' => $usesIndex,
            'explain' => $rows[0]['type'] ?? 'ALL',
        ];
    }

    $logMsg = sprintf(
        "[%s] PLATFORM AUDIT: Audited %d core production queries. All queries using secondary B-Tree indexes: %s\n",
        date('Y-m-d H:i:s'),
        count($queriesToAudit),
        array_reduce($auditResults, fn($carry, $item) => $carry && $item['uses_index'], true) ? 'YES' : 'NO'
    );

    file_put_contents($logFile, $logMsg, FILE_APPEND);
    echo $logMsg;
    echo "[" . date('Y-m-d H:i:s') . "] Audit complete. Saved to: {$logFile}\n";

} catch (\Throwable $e) {
    echo "[ERROR] Self-optimization audit failed: " . $e->getMessage() . "\n";
    exit(1);
}
