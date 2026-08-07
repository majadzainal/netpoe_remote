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

echo "[ " . date('Y-m-d H:i:s') . " ] Starting Background Sync...\n";

// ---------------------------------------------------------
// 1. Sync PPPoE Data (MikroTik)
// ---------------------------------------------------------
echo "\n--- Syncing PPPoE Data ---\n";
$routers = $pdo->query('SELECT * FROM routers')->fetchAll();

foreach ($routers as $router) {
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

        $inserted = 0;
        $updated = 0;
        
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
            
            // Upsert
            $stmt = $pdo->prepare('
                INSERT INTO pppoe_clients_cache 
                (user_id, router_id, name, service, caller_id, address, uptime, last_active, status, mapped)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                service=VALUES(service), caller_id=VALUES(caller_id), address=VALUES(address), 
                uptime=VALUES(uptime), last_active=VALUES(last_active), status=VALUES(status), mapped=VALUES(mapped)
            ');
            $stmt->execute([
                $router['user_id'], $router['id'], $name, $service, $callerId, $address, $uptime, $lastActive, $status, $mappedOnu
            ]);
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
                uptime=VALUES(uptime), last_active=VALUES(last_active), status=VALUES(status), mapped=VALUES(mapped)
            ');
            $stmt->execute([
                $router['user_id'], $router['id'], $name, $service, $callerId, $address, $uptime, $lastActive, $status, $mappedOnu
            ]);
        }
        
        // Remove old cache entries that no longer exist
        $currentNames = array_merge(array_column($secretRaw, 'name'), array_keys($activeMap));
        if (!empty($currentNames)) {
            $placeholders = implode(',', array_fill(0, count($currentNames), '?'));
            $delStmt = $pdo->prepare("DELETE FROM pppoe_clients_cache WHERE router_id = ? AND name NOT IN ($placeholders)");
            $delStmt->execute(array_merge([$router['id']], $currentNames));
        }

        echo "  -> Synced successfully.\n";
        $api->disconnect();
    } else {
        echo "  -> Failed to connect: " . ($api->error ?? 'Unknown error') . "\n";
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
    echo "OLT: {$olt['olt_name']} (IP: {$olt['ip_address']})\n";
    $telnet = new OltTelnet();
    $profile = null;
    foreach ($profiles as $p) {
        if (strcasecmp($p['brand'], $olt['brand']) === 0 && strcasecmp($p['model'], $olt['model']) === 0) {
            $profile = $p; break;
        }
    }
    
    // We only fetch signals for ONUs that are MAPPED to PPPoE to save time and prevent overloading the OLT, 
    // OR we can fetch for all. Fetching for mapped is safer for sequential polling.
    // If the OLT has a bulk command (like HSGQ), we can do it all at once.
    
    $brand = strtolower($olt['brand']);
    
    if ($brand === 'hsgq') {
        // Bulk fetch
        $raw = runOltCommands($telnet, $olt, ['enable', 'configure', 'show ont-optical all'], 20);
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(\d+\/\d+)\s+\S+\s+\d+\s+C\s+[\d.]+\s+V\s+[\d.]+\s+mA\s+([-+]?[\d.]+)\s+dBm\s+([-+]?[\d.]+)\s+dBm/', $line, $m)) {
                $ponOnu = $m[1];
                $tx = (float) $m[2];
                $rx = (float) $m[3];
                
                $stmt = $pdo->prepare('
                    INSERT INTO olt_signals_cache (user_id, olt_id, pon_onu, tx_power, rx_power)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE tx_power=VALUES(tx_power), rx_power=VALUES(rx_power)
                ');
                $stmt->execute([$olt['user_id'], $olt['id'], $ponOnu, $tx, $rx]);
            }
        }
        echo "  -> HSGQ Bulk sync done.\n";
    } else {
        // Fetch only for mapped ONUs to save time
        $stmt = $pdo->prepare('SELECT DISTINCT pon_onu FROM olt_pppoe_mappings WHERE olt_id = ?');
        $stmt->execute([$olt['id']]);
        $mappedOnus = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $optCmdBase = getCommand($profile, 'optical_power', $olt['optical_command']);
        if (!$optCmdBase) continue;
        
        $count = 0;
        foreach ($mappedOnus as $ponOnu) {
            $cmd = str_replace('{pon_onu}', $ponOnu, $optCmdBase);
            $raw = runOltCommands($telnet, $olt, array_map('trim', explode('|', $cmd)), 5);
            
            $tx = null; $rx = null;
            if (preg_match('/\btx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $raw, $m)) $tx = (float) $m[1];
            if (preg_match('/\brx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $raw, $m)) $rx = (float) $m[1];
            if ($rx === null && preg_match('/receive[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $raw, $m)) $rx = (float) $m[1];
            if ($tx === null && preg_match('/transmit[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $raw, $m)) $tx = (float) $m[1];
            
            if ($tx !== null || $rx !== null) {
                $stmt2 = $pdo->prepare('
                    INSERT INTO olt_signals_cache (user_id, olt_id, pon_onu, tx_power, rx_power)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE tx_power=VALUES(tx_power), rx_power=VALUES(rx_power)
                ');
                $stmt2->execute([$olt['user_id'], $olt['id'], $ponOnu, $tx, $rx]);
                $count++;
            }
        }
        echo "  -> Synced $count mapped ONUs.\n";
    }
}

echo "\n[ " . date('Y-m-d H:i:s') . " ] Background Sync Completed.\n";
