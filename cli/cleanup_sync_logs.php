<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

require_once __DIR__ . '/../config/database.php';

function cleanupOldSyncLogs(PDO $pdo, int $days = 3): int
{
    $days = max(1, $days);

    $stmt = $pdo->prepare(
        'DELETE FROM sync_logs WHERE created_at < (NOW() - INTERVAL :days DAY)'
    );
    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
}

try {
    $deletedRows = cleanupOldSyncLogs($pdo, 3);

    echo "[ " . date('Y-m-d H:i:s') . " ] Cleanup sync_logs completed. Deleted {$deletedRows} old log(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ " . date('Y-m-d H:i:s') . " ] Cleanup sync_logs failed: " . $e->getMessage() . "\n");
    exit(1);
}
