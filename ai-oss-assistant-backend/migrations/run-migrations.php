<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AiOssAssistant\Config;
use AiOssAssistant\Database;

Config::load();

try {
    $pdo = Database::getConnection();

    // Create schema_migrations table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");

    $stmt = $pdo->query("SELECT migration FROM schema_migrations");
    $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $allFiles = scandir(__DIR__) ?: [];
    echo "Found " . count($allFiles) . " directory entries in " . __DIR__ . ": " . implode(', ', $allFiles) . "\n";
    $files = [];
    foreach ($allFiles as $f) {
        if (str_ends_with(strtolower($f), '.sql')) {
            $files[] = __DIR__ . '/' . $f;
        }
    }
    sort($files);

    echo "Running database migrations...\n";

    foreach ($files as $file) {
        $filename = basename($file);
        if (in_array($filename, $executed, true)) {
            echo " [SKIP] {$filename} (already executed)\n";
            continue;
        }

        $sql = file_get_contents($file);
        echo " [RUN]  {$filename} (" . strlen($sql) . " bytes)... ";
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        $executedCount = 0;
        foreach ($queries as $query) {
            if (!empty($query)) {
                try {
                    $pdo->exec($query);
                    $executedCount++;
                } catch (Throwable $e) {
                    echo "\n[QUERY ERROR in {$filename}]: " . $e->getMessage() . "\nQuery: " . $query . "\n";
                    throw $e;
                }
            }
        }
        
        $ins = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
        $ins->execute([$filename]);

        echo "DONE ({$executedCount} queries)\n";
    }

    echo "All migrations completed successfully!\n";
} catch (Throwable $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
