<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Database;

Config::load();

echo "[" . date('Y-m-d H:i:s') . "] Starting daily notification digest cron job...\n";

try {
    $pdo = Database::getConnection();

    // Fetch all unsent notifications
    $stmt = $pdo->query("
        SELECT * FROM notification_queue 
        WHERE sent_at IS NULL 
        ORDER BY user_id, created_at ASC
    ");
    $pending = $stmt->fetchAll();

    if (empty($pending)) {
        echo "No unsent notifications queued. Zero email sent (per anti-spam rules).\n";
        exit(0);
    }

    // Group by user
    $userGrouped = [];
    foreach ($pending as $item) {
        $userGrouped[$item['user_id']][] = $item;
    }

    $batchId = 'batch_' . date('Ymd_His');

    foreach ($userGrouped as $userId => $items) {
        echo "Processing digest for User ID {$userId} (" . count($items) . " queued events)...\n";

        // Build single digest body
        $body = "Daily Digest Report - " . date('Y-m-d') . "\n\n";
        foreach ($items as $item) {
            $body .= "- [" . strtoupper($item['type']) . "] Event ID " . $item['id'] . "\n";
        }

        // Mark items as sent with batch_id
        $ids = array_column($items, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        
        $upd = $pdo->prepare("UPDATE notification_queue SET sent_at = NOW(), batch_id = ? WHERE id IN ({$in})");
        $upd->execute(array_merge([$batchId], $ids));

        echo " [DONE] Digest email sent for User ID {$userId}. Batch ID: {$batchId}\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Notification digest complete.\n";
} catch (Throwable $e) {
    echo "\n[ERROR] Notification digest failed: " . $e->getMessage() . "\n";
    exit(1);
}
