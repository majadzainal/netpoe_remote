<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/RouterosAPI.php';
require_once __DIR__ . '/../libs/OltTelnet.php';
require_once __DIR__ . '/../libs/OltProfiles.php';

// Allow long execution time
set_time_limit(0);

// Ensure sync_logs table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS sync_logs (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      olt_id INT UNSIGNED NULL,
      source ENUM('cron', 'web') NOT NULL DEFAULT 'cron',
      status ENUM('success', 'error', 'warning', 'info') NOT NULL DEFAULT 'info',
      message VARCHAR(255) NOT NULL,
      details TEXT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_sync_logs_user_date (user_id, created_at),
      INDEX idx_sync_logs_olt (olt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

function addSyncLog(PDO $pdo, int $userId, ?int $oltId, string $source, string $status, string $message, ?string $details = null): void {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO sync_logs (user_id, olt_id, source, status, message, details)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $oltId, $source, $status, $message, $details]);
    } catch (Throwable $e) {
        // Silently handle log insertion failures
    }
}

echo "[ " . date('Y-m-d H:i:s') . " ] Starting Background Sync...\n";

// ---------------------------------------------------------
// 1. Sync PPPoE Data (MikroTik)
// ---------------------------------------------------------
echo "\n--- Syncing PPPoE Data ---\n";
$routers = $pdo->query('SELECT * FROM routers')->fetchAll();

foreach ($routers as $router) {
    try {
        echo "Router: {$router['router_name']} (IP: {$router['ip_address']})\n";
        $api = new RouterosAPI();
        $api->timeout = 10;
        
        if ($api->connect($router['ip_address'], $router['api_user'], $router['api_pass'], (int) $router['api_port'])) {
            $activeRaw = $api->comm('/ppp/active/print');
            $secretRaw = $api->comm('/ppp/secret/print');
            
            $activeMap = [];
            foreach ($activeRaw as $act) {
                if (isset($act['!re'])) {
                    $activeMap[$act['name']] = $act;
                }
            }

            // Get OLT Mappings for this user
            $stmt = $pdo->prepare('SELECT pppoe_name, pon_onu FROM olt_pppoe_mappings WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $router['user_id']]);
            $mappings = [];
            foreach ($stmt->fetchAll() as $row) {
                $mappings[$row['pppoe_name']] = $row['pon_onu'];
            }

            $countSynced = 0;
            
            foreach ($secretRaw as $sec) {
                if (!isset($sec['!re'])) continue;
                $name = $sec['name'] ?? '';
                if ($name === '') continue;
                
                $isActive = isset($activeMap[$name]);
                $mappedOnu = $mappings[$name] ?? null;
                
                $service = $sec['service'] ?? 'pppoe';
                $callerId = $sec['caller-id'] ?? '-';
                $address = $sec['remote-address'] ?? '-';
                $uptime = 'Offline';
                $lastActive = $sec['last-logged-out'] ?? '-';
                $status = 'offline';
                
                if ($isActive) {
                    $act = $activeMap[$name];
                    $callerId = $act['caller-id'] ?? '-';
                    $address = $act['address'] ?? '-';
                    $uptime = trim(preg_replace('/([A-Za-z]+)/', '$1 ', $act['uptime'] ?? ''));
                    $lastActive = '-';
                    $status = 'active';
                    unset($activeMap[$name]);
                }
                
                // Upsert with updated_at=NOW()
                $stmt = $pdo->prepare('
                    INSERT INTO pppoe_clients_cache 
                    (user_id, router_id, name, service, caller_id, address, uptime, last_active, status, mapped)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    service=VALUES(service), caller_id=VALUES(caller_id), address=VALUES(address), 
                    uptime=VALUES(uptime), last_active=VALUES(last_active), status=VALUES(status), mapped=VALUES(mapped), updated_at=NOW()
                ');
                $stmt->execute([
                    $router['user_id'], $router['id'], $name, $service, $callerId, $address, $uptime, $lastActive, $status, $mappedOnu
                ]);
                $countSynced++;
            }
            
            // Active clients without secret
            foreach ($activeMap as $name => $act) {
                $service = $act['service'] ?? 'pppoe';
                $callerId = $act['caller-id'] ?? '-';
                $address = $act['address'] ?? '-';
                $uptime = trim(preg_replace('/([A-Za-z]+)/', '$1 ', $act['uptime'] ?? ''));
                $lastActive = '-';
                $status = 'active';
                $mappedOnu = $mappings[$name] ?? null;
                
                $stmt = $pdo->prepare('
                    INSERT INTO pppoe_clients_cache 
                    (user_id, router_id, name, service, caller_id, address, uptime, last_active, status, mapped)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    service=VALUES(service), caller_id=VALUES(caller_id), address=VALUES(address), 
                    uptime=VALUES(uptime), last_active=VALUES(last_active), status=VALUES(status), mapped=VALUES(mapped), updated_at=NOW()
                ');
                $stmt->execute([
                    $router['user_id'], $router['id'], $name, $service, $callerId, $address, $uptime, $lastActive, $status, $mappedOnu
                ]);
                $countSynced++;
            }
            
            // Remove old cache entries that no longer exist
            $currentNames = array_merge(array_column($secretRaw, 'name'), array_keys($activeMap));
            if (!empty($currentNames)) {
                $placeholders = implode(',', array_fill(0, count($currentNames), '?'));
                $delStmt = $pdo->prepare("DELETE FROM pppoe_clients_cache WHERE router_id = ? AND name NOT IN ($placeholders)");
                $delStmt->execute(array_merge([$router['id']], $currentNames));
            }

            echo "  -> Synced successfully ($countSynced clients).\n";
            $api->disconnect();

            addSyncLog($pdo, (int)$router['user_id'], null, 'cron', 'success', "Sync PPPoE MikroTik '{$router['router_name']}' berhasil ($countSynced client).");
        } else {
            $err = $api->error ?? 'Koneksi gagal/timeout ke MikroTik API';
            echo "  -> Failed to connect: {$err}\n";
            addSyncLog($pdo, (int)$router['user_id'], null, 'cron', 'error', "Gagal konek ke MikroTik '{$router['router_name']}': {$err}");
        }
    } catch (Throwable $e) {
        echo "  -> Error: " . $e->getMessage() . "\n";
        addSyncLog($pdo, (int)$router['user_id'], null, 'cron', 'error', "Error sync MikroTik '{$router['router_name']}': " . $e->getMessage());
    }
}

// ---------------------------------------------------------
// 2. Sync OLT Signals
// ---------------------------------------------------------
echo "\n--- Syncing OLT Signals ---\n";
$olts = $pdo->query('SELECT * FROM olts')->fetchAll();
$profiles = loadOltProfiles();

function getCommand($profile, $cmdName, $fallback = '') {
    if (!$profile || !isset($profile['commands'][$cmdName])) return $fallback;
    $val = $profile['commands'][$cmdName];
    if (is_array($val)) return trim((string) $val[0]);
    return trim((string) $val);
}

function runOltCommands($telnet, $olt, $sequence, $timeout = 10) {
    $brand = strtolower($olt['brand']);
    if ($brand === 'hioso' && strtolower($sequence[0] ?? '') !== 'enable') {
        array_unshift($sequence, 'enable');
    }
    if ($brand === 'ha7302cst') {
        $prefix = ['enable', 'configure terminal', 'epon'];
        $existing = array_map('strtolower', $sequence);
        foreach (array_reverse($prefix) as $cmd) {
            if (!in_array($cmd, $existing, true)) array_unshift($sequence, $cmd);
        }
    }
    return $telnet->runCommands($olt['ip_address'], (int)$olt['telnet_port'], $olt['telnet_user'], $olt['telnet_pass'], $sequence, $timeout);
}

foreach ($olts as $olt) {
    try {
        echo "OLT: {$olt['olt_name']} (IP: {$olt['ip_address']})\n";
        $telnet = new OltTelnet();
        $profile = null;
        foreach ($profiles as $p) {
            if (strcasecmp($p['brand'], $olt['brand']) === 0 && strcasecmp($p['model'], $olt['model']) === 0) {
                $profile = $p; break;
            }
        }
        
        $brand = strtolower($olt['brand']);
        
        if ($brand === 'hsgq') {
            // Bulk fetch
            $raw = runOltCommands($telnet, $olt, ['enable', 'configure', 'show ont-optical all'], 20);
            if (!$raw && $telnet->getError()) {
                addSyncLog($pdo, (int)$olt['user_id'], (int)$olt['id'], 'cron', 'error', "Gagal sync OLT '{$olt['olt_name']}': " . $telnet->getError());
                echo "  -> Failed: " . $telnet->getError() . "\n";
                continue;
            }

            $lines = explode("\n", (string)$raw);
            $count = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^(\d+\/\d+)\s+\S+\s+\d+\s+C\s+[\d.]+\s+V\s+[\d.]+\s+mA\s+([-+]?[\d.]+)\s+dBm\s+([-+]?[\d.]+)\s+dBm/', $line, $m)) {
                    $ponOnu = $m[1];
                    $tx = (float) $m[2];
                    $rx = (float) $m[3];
                    
                    $stmt = $pdo->prepare('
                        INSERT INTO olt_signals_cache (user_id, olt_id, pon_onu, tx_power, rx_power)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE tx_power=VALUES(tx_power), rx_power=VALUES(rx_power), updated_at=NOW()
                    ');
                    $stmt->execute([$olt['user_id'], $olt['id'], $ponOnu, $tx, $rx]);
                    $count++;
                }
            }
            echo "  -> HSGQ Bulk sync done ($count ONUs).\n";
            addSyncLog($pdo, (int)$olt['user_id'], (int)$olt['id'], 'cron', 'success', "Sync OLT '{$olt['olt_name']}' (HSGQ) berhasil ($count ONU ter-update).");
        } else {
            // Fetch only for mapped ONUs
            $stmt = $pdo->prepare('SELECT DISTINCT pon_onu FROM olt_pppoe_mappings WHERE olt_id = ?');
            $stmt->execute([$olt['id']]);
            $mappedOnus = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($mappedOnus)) {
                echo "  -> No mapped ONUs found for this OLT.\n";
                addSyncLog($pdo, (int)$olt['user_id'], (int)$olt['id'], 'cron', 'info', "Sync OLT '{$olt['olt_name']}' skipped: Belum ada mapping ONU.");
                continue;
            }

            $optCmdBase = getCommand($profile, 'optical_power', $olt['optical_command']);
            if (!$optCmdBase) {
                echo "  -> Optical command not defined.\n";
                addSyncLog($pdo, (int)$olt['user_id'], (int)$olt['id'], 'cron', 'warning', "Sync OLT '{$olt['olt_name']}': Perintah optical command belum dikonfigurasi.");
                continue;
            }
            
            $count = 0;
            $failedOnus = [];
            foreach ($mappedOnus as $ponOnu) {
                $cmd = str_replace('{pon_onu}', $ponOnu, $optCmdBase);
                $raw = runOltCommands($telnet, $olt, array_map('trim', explode('|', $cmd)), 5);
                
                $tx = null; $rx = null;
                if (preg_match('/\btx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $tx = (float) $m[1];
                if (preg_match('/\brx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $rx = (float) $m[1];
                if ($rx === null && preg_match('/receive[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $rx = (float) $m[1];
                if ($tx === null && preg_match('/transmit[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $tx = (float) $m[1];
                
                if ($tx !== null || $rx !== null) {
                    $stmt2 = $pdo->prepare('
                        INSERT INTO olt_signals_cache (user_id, olt_id, pon_onu, tx_power, rx_power)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE tx_power=VALUES(tx_power), rx_power=VALUES(rx_power), updated_at=NOW()
                    ');
                    $stmt2->execute([$olt['user_id'], $olt['id'], $ponOnu, $tx, $rx]);
                    $count++;
                } else {
                    $failedOnus[] = $ponOnu;
                }
            }
            
            echo "  -> Synced $count mapped ONUs.\n";
            $status = ($count > 0) ? 'success' : 'warning';
            $details = !empty($failedOnus) ? ("ONU gagal/tanpa sinyal: " . implode(', ', $failedOnus)) : null;
            addSyncLog($pdo, (int)$olt['user_id'], (int)$olt['id'], 'cron', $status, "Sync OLT '{$olt['olt_name']}' ({$olt['brand']}) selesai ($count/" . count($mappedOnus) . " ONU mapped ter-update).", $details);
        }
    } catch (Throwable $e) {
        echo "  -> Error: " . $e->getMessage() . "\n";
        addSyncLog($pdo, (int)$olt['user_id'], (int)$olt['id'], 'cron', 'error', "Error sync OLT '{$olt['olt_name']}': " . $e->getMessage());
    }
}

echo "\n[ " . date('Y-m-d H:i:s') . " ] Background Sync Completed.\n";

