<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/RouterosAPI.php';

checkUser();

$userId = (int) $_SESSION['user_id'];
$error = '';
$search = trim($_GET['search'] ?? '');
$clientsList = [];
$totalSecrets = 0;
$totalActive  = 0;
$totalOffline = 0;

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

if (!function_exists('addWebSyncLog')) {
    function addWebSyncLog(PDO $pdo, int $userId, ?int $oltId, string $source, string $status, string $message, ?string $details = null): void {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO sync_logs (user_id, olt_id, source, status, message, details)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$userId, $oltId, $source, $status, $message, $details]);
        } catch (Throwable $e) {}
    }
}

$stmt = $pdo->prepare('SELECT pppoe_name, pon_onu FROM olt_pppoe_mappings WHERE user_id = :user_id');
$stmt->execute(['user_id' => $userId]);
$mappings = [];
foreach ($stmt->fetchAll() as $row) {
    $mappings[$row['pppoe_name']] = $row['pon_onu'];
}

$stmt = $pdo->prepare('SELECT id, ip_address, api_user, api_pass, api_port FROM routers WHERE user_id = :user_id ORDER BY id ASC LIMIT 1');
$stmt->execute(['user_id' => $userId]);
$router = $stmt->fetch();

function formatUptime(string $uptime): string {
    return trim(preg_replace('/([A-Za-z]+)/', '$1 ', $uptime));
}

$lastSyncTime = '-';
$isSyncing = isset($_GET['action']) && $_GET['action'] === 'sync';

if (!$router) {
    $error = 'Pengaturan router belum tersedia. Silakan isi pengaturan router terlebih dahulu.';
} else {
    // -----------------------------------------------------------------
    // 1. Jika Action = Sync, Ambil dari Perangkat & Update Cache
    // -----------------------------------------------------------------
    if ($isSyncing) {
        $pppoeCount = 0;
        $onuCount = 0;
        $api = new RouterosAPI();
        $api->timeout = 8;
        if ($api->connect($router['ip_address'], $router['api_user'], $router['api_pass'], (int) $router['api_port'])) {
            $activeRaw = $api->comm('/ppp/active/print');
            $secretRaw = $api->comm('/ppp/secret/print');
            $activeMap = [];
            foreach ($activeRaw as $act) {
                if (isset($act['!re'])) {
                    $activeMap[$act['name']] = $act;
                }
            }

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
                
                $stmt = $pdo->prepare('
                    INSERT INTO pppoe_clients_cache (user_id, router_id, name, service, caller_id, address, uptime, last_active, status, mapped)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    service=VALUES(service), caller_id=VALUES(caller_id), address=VALUES(address), 
                    uptime=VALUES(uptime), last_active=VALUES(last_active), status=VALUES(status), mapped=VALUES(mapped), updated_at=NOW()
                ');
                $stmt->execute([$userId, $router['id'], $name, $service, $callerId, $address, $uptime, $lastActive, $status, $mappedOnu]);
                $pppoeCount++;
            }
            
            foreach ($activeMap as $name => $act) {
                $service = $act['service'] ?? 'pppoe';
                $callerId = $act['caller-id'] ?? '-';
                $address = $act['address'] ?? '-';
                $uptime = trim(preg_replace('/([A-Za-z]+)/', '$1 ', $act['uptime'] ?? ''));
                $lastActive = '-';
                $status = 'active';
                $mappedOnu = $mappings[$name] ?? null;
                
                $stmt = $pdo->prepare('
                    INSERT INTO pppoe_clients_cache (user_id, router_id, name, service, caller_id, address, uptime, last_active, status, mapped)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    service=VALUES(service), caller_id=VALUES(caller_id), address=VALUES(address), 
                    uptime=VALUES(uptime), last_active=VALUES(last_active), status=VALUES(status), mapped=VALUES(mapped), updated_at=NOW()
                ');
                $stmt->execute([$userId, $router['id'], $name, $service, $callerId, $address, $uptime, $lastActive, $status, $mappedOnu]);
                $pppoeCount++;
            }
            
            $currentNames = array_merge(array_column($secretRaw, 'name'), array_keys($activeMap));
            if (!empty($currentNames)) {
                $placeholders = implode(',', array_fill(0, count($currentNames), '?'));
                $delStmt = $pdo->prepare("DELETE FROM pppoe_clients_cache WHERE router_id = ? AND name NOT IN ($placeholders)");
                $delStmt->execute(array_merge([$router['id']], $currentNames));
            }
            $api->disconnect();

            addWebSyncLog($pdo, $userId, null, 'web', 'success', "Sync Manual Web: MikroTik berhasil ($pppoeCount client synced).");

            // -------------------------------------------------------------
            // Sync OLT Signals for this user
            // -------------------------------------------------------------
            require_once __DIR__ . '/../../libs/OltTelnet.php';
            require_once __DIR__ . '/../../libs/OltProfiles.php';
            
            $stmtOlt = $pdo->prepare('SELECT * FROM olts WHERE user_id = ?');
            $stmtOlt->execute([$userId]);
            $olts = $stmtOlt->fetchAll();
            $profiles = loadOltProfiles();
            
            if (!function_exists('scApplyModePPPoE')) {
                function scApplyModePPPoE(array $olt, array $seq): array {
                    $brand = strtolower((string) ($olt['brand'] ?? ''));
                    if ($brand === 'hioso' && strtolower($seq[0] ?? '') !== 'enable') {
                        array_unshift($seq, 'enable');
                    }
                    if ($brand === 'ha7302cst') {
                        $prefix = ['enable', 'configure terminal', 'epon'];
                        $existing = array_map('strtolower', $seq);
                        foreach (array_reverse($prefix) as $cmd) {
                            if (!in_array($cmd, $existing, true)) array_unshift($seq, $cmd);
                        }
                    }
                    return $seq;
                }
            }

            foreach ($olts as $o) {
                try {
                    $telnet = new OltTelnet();
                    $profile = null;
                    foreach ($profiles as $p) {
                        if (strcasecmp($p['brand'], $o['brand']) === 0 && strcasecmp($p['model'], $o['model']) === 0) {
                            $profile = $p; break;
                        }
                    }
                    
                    $brand = strtolower($o['brand']);
                    $singleOltCount = 0;

                    if ($brand === 'hsgq') {
                        $raw = $telnet->runCommands($o['ip_address'], (int)$o['telnet_port'], $o['telnet_user'], $o['telnet_pass'], ['enable', 'configure', 'show ont-optical all'], 20);
                        if ($raw) {
                            $lines = explode("\n", $raw);
                            foreach ($lines as $line) {
                                $line = trim($line);
                                if (preg_match('/^(\d+\/\d+)\s+\S+\s+\d+\s+C\s+[\d.]+\s+V\s+[\d.]+\s+mA\s+([-+]?[\d.]+)\s+dBm\s+([-+]?[\d.]+)\s+dBm/', $line, $m)) {
                                    $ponOnu = $m[1];
                                    $tx = (float) $m[2];
                                    $rx = (float) $m[3];
                                    $stmtSync = $pdo->prepare('INSERT INTO olt_signals_cache (user_id, olt_id, pon_onu, tx_power, rx_power) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE tx_power=VALUES(tx_power), rx_power=VALUES(rx_power), updated_at=NOW()');
                                    $stmtSync->execute([$userId, $o['id'], $ponOnu, $tx, $rx]);
                                    $onuCount++;
                                    $singleOltCount++;
                                }
                            }
                        }
                        addWebSyncLog($pdo, $userId, (int)$o['id'], 'web', 'success', "Sync Manual Web: OLT '{$o['olt_name']}' (HSGQ) berhasil ($singleOltCount ONU).");
                    } else {
                        $stmtMapped = $pdo->prepare('SELECT DISTINCT pon_onu FROM olt_pppoe_mappings WHERE olt_id = ?');
                        $stmtMapped->execute([$o['id']]);
                        $mappedOnus = $stmtMapped->fetchAll(PDO::FETCH_COLUMN);
                        
                        $optCmdBase = '';
                        if ($profile && isset($profile['commands']['optical_power'])) {
                            $val = $profile['commands']['optical_power'];
                            $optCmdBase = is_array($val) ? trim((string)$val[0]) : trim((string)$val);
                        } else {
                            $optCmdBase = $o['optical_command'];
                        }
                        
                        if (!$optCmdBase) {
                            addWebSyncLog($pdo, $userId, (int)$o['id'], 'web', 'warning', "Sync Manual Web: OLT '{$o['olt_name']}': Optical command belum dikonfigurasi.");
                            continue;
                        }
                        
                        foreach ($mappedOnus as $ponOnu) {
                            $cmd = str_replace('{pon_onu}', $ponOnu, $optCmdBase);
                            $seq = scApplyModePPPoE($o, array_map('trim', explode('|', $cmd)));
                            $raw = $telnet->runCommands($o['ip_address'], (int)$o['telnet_port'], $o['telnet_user'], $o['telnet_pass'], $seq, 5);
                            
                            $tx = null; $rx = null;
                            if (preg_match('/\btx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $tx = (float) $m[1];
                            if (preg_match('/\brx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $rx = (float) $m[1];
                            if ($rx === null && preg_match('/receive[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $rx = (float) $m[1];
                            if ($tx === null && preg_match('/transmit[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', (string)$raw, $m)) $tx = (float) $m[1];
                            
                            if ($tx !== null || $rx !== null) {
                                $stmtSync = $pdo->prepare('INSERT INTO olt_signals_cache (user_id, olt_id, pon_onu, tx_power, rx_power) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE tx_power=VALUES(tx_power), rx_power=VALUES(rx_power), updated_at=NOW()');
                                $stmtSync->execute([$userId, $o['id'], $ponOnu, $tx, $rx]);
                                $onuCount++;
                                $singleOltCount++;
                            }
                        }
                        addWebSyncLog($pdo, $userId, (int)$o['id'], 'web', $singleOltCount > 0 ? 'success' : 'warning', "Sync Manual Web: OLT '{$o['olt_name']}' ({$o['brand']}) selesai ($singleOltCount/" . count($mappedOnus) . " ONU mapped).");
                    }
                } catch (Throwable $e) {
                    addWebSyncLog($pdo, $userId, (int)$o['id'], 'web', 'error', "Sync Manual Web Error OLT '{$o['olt_name']}': " . $e->getMessage());
                }
            }

            header('Location: pppoe.php?synced=1&pppoe=' . $pppoeCount . '&onu=' . $onuCount);
            exit;
        } else {
            $error = 'Gagal sinkronisasi ke MikroTik: ' . ($api->error ?? 'Periksa pengaturan router.');
            addWebSyncLog($pdo, $userId, null, 'web', 'error', "Sync Manual Web Gagal: " . ($api->error ?? 'Gagal koneksi ke MikroTik API'));
        }
    }

    // -----------------------------------------------------------------
    // 2. Load dari Database (Cepat) & Load Logs
    // -----------------------------------------------------------------
    $signalsMap = [];
    $stmtSig = $pdo->prepare('
        SELECT m.pppoe_name, s.tx_power, s.rx_power, s.updated_at
        FROM olt_pppoe_mappings m
        JOIN olt_signals_cache s ON m.olt_id = s.olt_id AND m.pon_onu = s.pon_onu
        WHERE m.user_id = ?
    ');
    $stmtSig->execute([$userId]);
    foreach ($stmtSig->fetchAll() as $sig) {
        $signalsMap[$sig['pppoe_name']] = $sig;
    }

    $stmt = $pdo->prepare('SELECT * FROM pppoe_clients_cache WHERE router_id = ? ORDER BY name ASC');
    $stmt->execute([$router['id']]);
    $cachedData = $stmt->fetchAll();
    
    if (empty($cachedData)) {
        if (!$isSyncing) {
            $error = "Data belum tersedia di database. Klik tombol 'Sync Now' untuk mengambil data dari MikroTik.";
        }
    } else {
        foreach ($cachedData as $row) {
            $totalSecrets++;
            if ($row['status'] === 'active') {
                $totalActive++;
            } else {
                $totalOffline++;
            }
            
            $row['caller-id'] = $row['caller_id'];
            $row['signal'] = $signalsMap[$row['name']] ?? null;
            $clientsList[] = $row;
            
            if ($lastSyncTime === '-' && !empty($row['updated_at'])) {
                $lastSyncTime = date('d M Y H:i:s', strtotime($row['updated_at']));
            }
        }
    }

    // Fetch latest sync log time to display as last sync time if available
    $stmtLatestLog = $pdo->prepare('SELECT created_at FROM sync_logs WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmtLatestLog->execute([$userId]);
    $latestLogTime = $stmtLatestLog->fetchColumn();
    if ($latestLogTime) {
        $lastSyncTime = date('d M Y H:i:s', strtotime((string)$latestLogTime));
    }
}

// Fetch Today's Logs for current user
$stmtLogs = $pdo->prepare('
    SELECT l.*, o.olt_name, o.brand 
    FROM sync_logs l
    LEFT JOIN olts o ON l.olt_id = o.id
    WHERE l.user_id = ? AND DATE(l.created_at) = CURDATE()
    ORDER BY l.id DESC
    LIMIT 50
');
$stmtLogs->execute([$userId]);
$todayLogs = $stmtLogs->fetchAll();


if ($search !== '') {
    $keyword = strtolower($search);
    $clientsList = array_values(array_filter($clientsList, static function (array $client) use ($keyword): bool {
        $haystack = implode(' ', [
            $client['name'] ?? '',
            $client['caller-id'] ?? '',
            $client['address'] ?? '',
            $client['mapped'] ?? ''
        ]);
        return str_contains(strtolower($haystack), $keyword);
    }));
}

$pageTitle  = 'PPPoE Active';
$activePage = 'pppoe';
require_once __DIR__ . '/partials/header.php';
?>
<style>
/* ── Panel & layout ── */
.panel { padding: 24px; background: var(--clr-surface); border: 1px solid var(--clr-border); border-radius: var(--radius); box-shadow: var(--shadow); }
.panel h2 { font-size:16px; margin-bottom:16px; }
.search-box { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
.search-box input { flex: 1; min-width: 200px; margin-bottom: 0; }
.result-count { color: var(--clr-muted); font-size: 14px; }
.search-button { flex-shrink: 0; }
.table-wrap { overflow-x: auto; }
.remote-link {
    display:inline-flex; align-items:center; padding:6px 12px; border-radius:7px;
    background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:700;
    text-decoration:none; font-size:12px; white-space:nowrap;
}
.btn-signal {
    display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:7px;
    background: rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.35);
    color:#4ade80; font-weight:700; font-size:12px; cursor:pointer;
    font-family:inherit; white-space:nowrap; transition: background .15s, transform .12s;
}
.btn-signal:hover { background: rgba(16,185,129,0.25); transform:translateY(-1px); }
.empty { color: var(--clr-muted); text-align: center; }
.aksi-cell { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

/* ── Modal overlay ── */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    backdrop-filter: blur(6px);
    z-index: 999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }

.modal-box {
    background: #1a1d2e;
    border: 1px solid rgba(99,102,241,0.3);
    border-radius: 20px;
    padding: 32px 28px;
    width: min(100% - 32px, 480px);
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    position: relative;
    animation: modalIn .22s ease;
}

@keyframes modalIn {
    from { transform: scale(.92) translateY(20px); opacity: 0; }
    to   { transform: scale(1) translateY(0); opacity: 1; }
}

.modal-close {
    position: absolute; top: 16px; right: 18px;
    background: none; border: none;
    color: #64748b; font-size: 22px; cursor: pointer; line-height: 1;
    transition: color .15s;
}
.modal-close:hover { color: #e2e8f0; }

.modal-header { margin-bottom: 24px; }
.modal-header h2 { font-size: 18px; font-weight: 800; margin-bottom: 4px; color: #e2e8f0; }
.modal-header .modal-sub { font-size: 13px; color: #64748b; }

.modal-meta {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 10px; margin-bottom: 22px;
}
.meta-box {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px; padding: 12px 14px;
}
.meta-box .lbl { font-size: 11px; color: #475569; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 3px; }
.meta-box .val { font-size: 14px; color: #cbd5e1; font-weight: 600; }

/* ── Signal meters ── */
.signal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 22px; }

.signal-card {
    border-radius: 14px;
    padding: 20px 16px;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.signal-card::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(circle at 50% 0%, var(--glow,rgba(99,102,241,.25)), transparent 70%);
}
.signal-card .sig-type { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; opacity: .7; margin-bottom: 8px; }
.signal-card .sig-val  { font-size: 32px; font-weight: 900; letter-spacing: -1px; margin-bottom: 6px; }
.signal-card .sig-unit { font-size: 13px; opacity: .6; margin-bottom: 10px; }
.signal-card .sig-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;
    background: rgba(255,255,255,0.1);
}

.sig-tx { background: linear-gradient(145deg, rgba(99,102,241,0.2), rgba(99,102,241,0.08)); border: 1px solid rgba(99,102,241,0.3); --glow: rgba(99,102,241,0.3); }
.sig-rx { background: linear-gradient(145deg, rgba(16,185,129,0.2), rgba(16,185,129,0.08)); border: 1px solid rgba(16,185,129,0.3); --glow: rgba(16,185,129,0.3); }

/* ── Loading state ── */
.modal-loading {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 40px 20px; gap: 16px;
}
.spinner {
    width: 44px; height: 44px;
    border: 3px solid rgba(99,102,241,0.2);
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: spin .75s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.spinner-text { font-size: 14px; color: #64748b; }

/* ── Error state ── */
.modal-error {
    text-align: center; padding: 30px 10px;
    display: none;
}
.modal-error .err-icon { font-size: 42px; margin-bottom: 14px; }
.modal-error p { font-size: 14px; color: #f87171; line-height: 1.6; }

/* ── Command info ── */
.cmd-info {
    background: rgba(0,0,0,0.2);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: #475569;
}
.cmd-info code { color: #a5b4fc; font-size: 11px; }

/* ── Summary Card Filter ── */
.summary-card { cursor: pointer; transition: all 0.2s; }
.summary-card:hover { transform: translateY(-3px); filter: brightness(1.2); }

/* ── Sortable Table ── */
.sortable { cursor: pointer; user-select: none; transition: background 0.15s; position: relative; padding-right: 22px !important; }
.sortable:hover { background: rgba(255,255,255,0.05); }
.sort-icon { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 14px; text-align: center; color: #64748b; font-size: 11px; }
.sortable:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '↕'; opacity: 0.3; }
.sort-asc .sort-icon::after { content: '▲'; color: #a5b4fc; }
.sort-desc .sort-icon::after { content: '▼'; color: #a5b4fc; }
</style>

<div class="page-wrap">
<div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <p class="page-heading" style="margin-bottom: 4px;">Data PPPoE Client</p>
        <p class="page-sub">Daftar klien PPPoE beserta status koneksi dan mapping ONU.</p>
    </div>
    <div style="text-align: right;">
        <div style="font-size: 12px; color: var(--clr-muted); margin-bottom: 6px;">
            Terakhir di-sinkronisasi: <strong style="color: #e2e8f0;"><?= htmlspecialchars($lastSyncTime, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <div style="display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <button type="button" onclick="openSyncLogModal()" class="btn" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; padding: 8px 14px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15); color: #e2e8f0; border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y1="13"/><line x1="16" y1="17" x2="8" y1="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Log Hari Ini <span style="background: rgba(99,102,241,0.25); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.4); border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 600;"><?= count($todayLogs) ?></span>
            </button>
            <a href="?action=sync" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; padding: 8px 16px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21v-5h5"/></svg>
                Sync MikroTik & OLT Sekarang
            </a>
        </div>
    </div>
</div>

<!-- Sync Log Pop Up Modal -->
<div id="syncLogModalOverlay" class="modal-overlay" onclick="if(event.target === this) closeSyncLogModal()">
    <div class="modal-box" style="width: min(100% - 32px, 640px); max-height: 85vh; display: flex; flex-direction: column; padding: 24px; border: 1px solid rgba(99,102,241,0.4);">
        <button type="button" class="modal-close" onclick="closeSyncLogModal()">&times;</button>
        
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid rgba(255,255,255,0.08);">
            <div style="background: rgba(99,102,241,0.2); border: 1px solid rgba(99,102,241,0.3); border-radius: 10px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; color: #a5b4fc;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y1="13"/><line x1="16" y1="17" x2="8" y1="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <div>
                <h2 style="margin: 0; font-size: 17px; font-weight: 700; color: #f8fafc;">Log Sinkronisasi Hari Ini</h2>
                <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">Tanggal: <strong style="color: #cbd5e1;"><?= date('d M Y') ?></strong> • Khusus Perangkat Akun Anda</div>
            </div>
        </div>

        <div style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; padding-right: 4px;">
            <?php if (empty($todayLogs)): ?>
                <div style="text-align: center; padding: 40px 15px; color: #94a3b8; font-size: 13px; background: rgba(0,0,0,0.18); border-radius: 10px; border: 1px dashed rgba(255,255,255,0.1);">
                    Belum ada aktivitas sinkronisasi hari ini.<br>Log akan tercatat otomatis saat cron berjalan atau saat Anda memicu Sync Manual.
                </div>
            <?php else: ?>
                <?php foreach ($todayLogs as $log): ?>
                    <?php 
                    $bg = 'rgba(255,255,255,0.02)';
                    $borderColor = 'rgba(255,255,255,0.06)';
                    $badgeBg = 'rgba(148,163,184,0.15)';
                    $badgeClr = '#94a3b8';
                    $badgeText = strtoupper($log['status']);
                    
                    if ($log['status'] === 'success') {
                        $badgeBg = 'rgba(16,185,129,0.18)';
                        $badgeClr = '#34d399';
                        $borderColor = 'rgba(16,185,129,0.25)';
                    } elseif ($log['status'] === 'error') {
                        $badgeBg = 'rgba(239,68,68,0.18)';
                        $badgeClr = '#f87171';
                        $borderColor = 'rgba(239,68,68,0.25)';
                    } elseif ($log['status'] === 'warning') {
                        $badgeBg = 'rgba(245,158,11,0.18)';
                        $badgeClr = '#fbbf24';
                        $borderColor = 'rgba(245,158,11,0.25)';
                    } elseif ($log['status'] === 'info') {
                        $badgeBg = 'rgba(59,130,246,0.18)';
                        $badgeClr = '#60a5fa';
                        $borderColor = 'rgba(59,130,246,0.25)';
                    }
                    
                    $sourceLabel = $log['source'] === 'cron' ? '⏰ CRON' : '🌐 MANUAL WEB';
                    $sourceBg = $log['source'] === 'cron' ? 'rgba(168,85,247,0.15)' : 'rgba(14,165,233,0.15)';
                    $sourceClr = $log['source'] === 'cron' ? '#c084fc' : '#38bdf8';
                    ?>
                    <div style="background: <?= $bg ?>; border: 1px solid <?= $borderColor ?>; border-radius: 10px; padding: 12px 14px; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span style="font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px; background: <?= $badgeBg ?>; color: <?= $badgeClr ?>;"><?= $badgeText ?></span>
                                <span style="font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 4px; background: <?= $sourceBg ?>; color: <?= $sourceClr ?>;"><?= $sourceLabel ?></span>
                                <?php if (!empty($log['olt_name'])): ?>
                                    <span style="font-size: 11px; color: #a5b4fc; font-weight: 500; background: rgba(99,102,241,0.1); padding: 1px 6px; border-radius: 4px; border: 1px solid rgba(99,102,241,0.2);"><?= htmlspecialchars($log['olt_name']) ?> (<?= htmlspecialchars($log['brand']) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <span style="font-size: 11px; color: #64748b; font-family: monospace; font-weight: 600;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                        </div>
                        <div style="color: #e2e8f0; font-weight: 500; line-height: 1.5;"><?= htmlspecialchars($log['message']) ?></div>
                        <?php if (!empty($log['details'])): ?>
                            <div style="margin-top: 8px; font-size: 11px; color: #cbd5e1; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.05); padding: 8px 12px; border-radius: 6px; font-family: monospace; white-space: pre-wrap; word-break: break-word;">
                                <?= htmlspecialchars($log['details']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.08); text-align: right;">
            <button type="button" onclick="closeSyncLogModal()" class="btn btn-secondary" style="font-size: 12px; padding: 6px 18px;">Tutup Modal</button>
        </div>
    </div>
</div>

<script>
function openSyncLogModal() {
    var m = document.getElementById('syncLogModalOverlay');
    if (m) m.classList.add('active');
}
function closeSyncLogModal() {
    var m = document.getElementById('syncLogModalOverlay');
    if (m) m.classList.remove('active');
}
</script>


    <section class="panel">

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['synced']) && $_GET['synced'] == '1'): ?>
                <?php 
                $pCount = (int)($_GET['pppoe'] ?? 0);
                $oCount = (int)($_GET['onu'] ?? 0);
                ?>
                <div class="alert alert-success" style="background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); color: #4ade80; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; line-height: 1.6;">
                    ✅ <strong>Sinkronisasi Perangkat Berhasil!</strong><br>
                    • Data PPPoE (MikroTik): <strong><?= $pCount ?></strong> item tersimpan.<br>
                    • Data Sinyal ONU (OLT): <strong><?= $oCount ?></strong> modem tersimpan.
                </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <div class="summary-card" onclick="filterPppoe('all')" style="background: rgba(139, 92, 246, 0.15); border: 1px solid rgba(139, 92, 246, 0.3); padding: 16px; border-radius: 12px; text-align: center;">
                    <div style="font-size: 12px; font-weight: 700; color: #c4b5fd; text-transform: uppercase; margin-bottom: 4px;">Total Secret PPPoE</div>
                    <div style="font-size: 28px; font-weight: 800; color: #fff;"><?= $totalSecrets ?></div>
                </div>
                <div class="summary-card" onclick="filterPppoe('active')" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); padding: 16px; border-radius: 12px; text-align: center;">
                    <div style="font-size: 12px; font-weight: 700; color: #6ee7b7; text-transform: uppercase; margin-bottom: 4px;">PPPoE Active</div>
                    <div style="font-size: 28px; font-weight: 800; color: #fff;"><?= $totalActive ?></div>
                </div>
                <div class="summary-card" onclick="filterPppoe('offline')" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); padding: 16px; border-radius: 12px; text-align: center;">
                    <div style="font-size: 12px; font-weight: 700; color: #fca5a5; text-transform: uppercase; margin-bottom: 4px;">PPPoE Offline</div>
                    <div style="font-size: 28px; font-weight: 800; color: #fff;"><?= $totalOffline ?></div>
                </div>
            </div>

            <form class="toolbar" method="get" action="">
                <div class="search-box">
                    <label for="pppoe-search">Search PPPoE</label>
                    <input
                        type="search"
                        id="pppoe-search"
                        name="search"
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Cari name, caller ID, address....."
                        autocomplete="off"
                    >
                </div>
                <button class="search-button btn btn-primary" type="submit">🔍 Cari</button>
                <div class="result-count" id="pppoe-result-count">
                    Menampilkan: <?= count($clientsList) ?> client
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="sortable" onclick="sortTable(0)">Name & Status <span class="sort-icon"></span></th>
                            <th class="sortable" onclick="sortTable(1)">Service & Mapping <span class="sort-icon"></span></th>
                            <th class="sortable" onclick="sortTable(2)">Sinyal ONU <span class="sort-icon"></span></th>
                            <th class="sortable" onclick="sortTable(3)">MAC / IP Address <span class="sort-icon"></span></th>
                            <th class="sortable" onclick="sortTable(4)">Uptime / Last Active <span class="sort-icon"></span></th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="pppoe-table-body">
                        <?php if ($clientsList === []): ?>
                            <tr class="pppoe-row empty-row">
                                <td class="empty" colspan="6">Tidak ada data PPPoE client.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($clientsList as $client): ?>
                            <?php $clientIp   = $client['address'] ?? ''; ?>
                            <?php $clientName = $client['name'] ?? ''; ?>
                            <?php $isOffline = $client['status'] === 'offline'; ?>
                            <tr class="pppoe-row" data-status="<?= $isOffline ? 'offline' : 'active' ?>" <?= $isOffline ? 'style="background: rgba(239,68,68,0.05);"' : '' ?>>
                                <td>
                                    <strong style="color: <?= $isOffline ? '#fca5a5' : '#c4b5fd' ?>;"><?= htmlspecialchars($clientName ?: '-', ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <span style="font-size: 11px; color: <?= $isOffline ? '#ef4444' : '#4ade80' ?>; font-weight: 600; text-transform: uppercase;"><?= $isOffline ? 'Offline' : 'Active' ?></span>
                                </td>
                                <td>
                                    <span style="color: var(--clr-muted); font-size: 12px;"><?= htmlspecialchars($client['service'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span><br>
                                    <?php if ($client['mapped']): ?>
                                        <span style="font-size: 11px; background: rgba(99,102,241,0.2); color: #a5b4fc; padding: 2px 6px; border-radius: 4px;">Mapped: <?= htmlspecialchars($client['mapped'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: #94a3b8;">Belum di-mapping</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $sig = $client['signal'] ?? null;
                                    if ($sig && $sig['rx_power'] !== null):
                                        $rx = (float)$sig['rx_power'];
                                        $color = '#22c55e';
                                        $statusText = 'Normal';
                                        if ($rx < -27 && $rx >= -30) {
                                            $color = '#f59e0b';
                                            $statusText = 'Lemah';
                                        } elseif ($rx < -30) {
                                            $color = '#ef4444';
                                            $statusText = 'Sangat Lemah';
                                        }
                                    ?>
                                        <strong style="color: <?= $color ?>; font-size: 13px;">📶 <?= number_format($rx, 2) ?> dBm</strong><br>
                                        <span style="font-size: 10px; color: <?= $color ?>; text-transform: uppercase; font-weight: 600;"><?= $statusText ?></span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color: #e2e8f0; font-size: 13px;"><?= htmlspecialchars($client['caller-id'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span><br>
                                    <span style="color: var(--clr-muted); font-size: 12px;"><?= htmlspecialchars($clientIp !== '' ? $clientIp : '-', ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <?php if (!$isOffline): ?>
                                        <span style="color: #e2e8f0; font-size: 13px;">⏱️ <?= htmlspecialchars($client['uptime'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">Last Active:</span><br>
                                        <span style="color: #fca5a5; font-size: 12px;"><?= htmlspecialchars($client['last_active'] !== '' ? $client['last_active'] : '-', ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="aksi-cell">
                                        <?php if ($clientIp !== '' && $clientIp !== '-' && !$isOffline): ?>
                                            <a
                                                class="remote-link"
                                                href="remote_action.php?ip=<?= urlencode($clientIp) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >🔗 Remote</a>
                                        <?php endif; ?>
                                        <?php if ($clientName !== '' && !$isOffline): ?>
                                            <button
                                                class="btn-signal"
                                                onclick="openSignalModal(<?= htmlspecialchars(json_encode($clientName), ENT_QUOTES, 'UTF-8') ?>, true)"
                                                title="Tembak langsung ke OLT sekarang"
                                            >📶 Cek ke OLT Sekarang</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="search-empty-row" style="display: none;">
                            <td class="empty" colspan="6">Data tidak ditemukan.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
</div>

<!-- ══════════════════════════════════════════════
     SIGNAL MODAL
══════════════════════════════════════════════ -->
<div class="modal-overlay" id="signalModal" onclick="closeModalOnBg(event)">
    <div class="modal-box">
        <button class="modal-close" onclick="closeSignalModal()">✕</button>

        <!-- Loading state -->
        <div class="modal-loading" id="modalLoading">
            <div class="spinner"></div>
            <span class="spinner-text">Menghubungi OLT…</span>
        </div>

        <!-- Error state -->
        <div class="modal-error" id="modalError">
            <div class="err-icon">⚠️</div>
            <p id="modalErrorText"></p>
        </div>

        <!-- Result state -->
        <div id="modalResult" style="display:none">
            <div class="modal-header">
                <h2 id="modalTitle">Kualitas Sinyal OLT</h2>
                <div class="modal-sub" id="modalSub"></div>
            </div>

            <div class="modal-meta">
                <div class="meta-box">
                    <div class="lbl">PPPoE Name</div>
                    <div class="val" id="rPppoeName">—</div>
                </div>
                <div class="meta-box">
                    <div class="lbl">PON/ONU</div>
                    <div class="val" id="rPonOnu">—</div>
                </div>
                <div class="meta-box">
                    <div class="lbl">OLT</div>
                    <div class="val" id="rOltName">—</div>
                </div>
                <div class="meta-box">
                    <div class="lbl">Pelanggan</div>
                    <div class="val" id="rCustomer">—</div>
                </div>
            </div>

            <div class="signal-grid">
                <!-- TX Card -->
                <div class="signal-card sig-tx">
                    <div class="sig-type">TX Power</div>
                    <div class="sig-val" id="rTxVal">—</div>
                    <div class="sig-unit">dBm</div>
                    <div class="sig-badge" id="rTxBadge">—</div>
                </div>
                <!-- RX Card -->
                <div class="signal-card sig-rx">
                    <div class="sig-type">RX Power</div>
                    <div class="sig-val" id="rRxVal">—</div>
                    <div class="sig-unit">dBm</div>
                    <div class="sig-badge" id="rRxBadge">—</div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div class="cmd-info" style="margin-bottom: 0;">
                    Command: <code id="rCmd">—</code>
                </div>
                <button id="btnForceRefresh" class="btn btn-primary" style="font-size: 12px; padding: 6px 12px; display: none; background: #6366f1; border: none; border-radius: 6px; color: #fff; cursor: pointer;">Refresh Langsung OLT</button>
            </div>
        </div>
    </div>
</div>

<script>
    /* ─── Live search ─────────────────────────────────────── */
    const searchInput = document.getElementById('pppoe-search');
    const rows = Array.from(document.querySelectorAll('.pppoe-row'));
    const emptyRow = document.getElementById('search-empty-row');
    const resultCount = document.getElementById('pppoe-result-count');

    function filterPppoeRows() {
        const keyword = searchInput.value.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach((row) => {
            const isMatch = row.textContent.toLowerCase().includes(keyword);
            row.style.display = isMatch ? '' : 'none';
            if (isMatch) visibleCount += 1;
        });

        if (emptyRow) {
            emptyRow.style.display = rows.length > 0 && visibleCount === 0 ? '' : 'none';
        }
        resultCount.textContent = `Total: ${visibleCount} client`;
    }

    searchInput.addEventListener('input', filterPppoeRows);

    /* ─── Signal Modal ────────────────────────────────────── */
    const modal      = document.getElementById('signalModal');
    const elLoading  = document.getElementById('modalLoading');
    const elError    = document.getElementById('modalError');
    const elErrorTxt = document.getElementById('modalErrorText');
    const elResult   = document.getElementById('modalResult');

    function showLoading() {
        elLoading.style.display = 'flex';
        elError.style.display   = 'none';
        elResult.style.display  = 'none';
    }

    function showError(msg) {
        elLoading.style.display = 'none';
        elError.style.display   = 'block';
        elResult.style.display  = 'none';
        elErrorTxt.textContent  = msg;
    }

    function showResult(d) {
        elLoading.style.display = 'none';
        elError.style.display   = 'none';
        elResult.style.display  = 'block';

        document.getElementById('rPppoeName').textContent = d.pppoe_name || '—';
        document.getElementById('rPonOnu').textContent    = d.pon_onu    || '—';
        document.getElementById('rOltName').textContent   = d.olt_name   || '—';
        document.getElementById('rCustomer').textContent  = d.customer_name || '-';
        document.getElementById('rCmd').textContent       = d.command_used || '—';
        document.getElementById('modalSub').textContent   = d.brand + ' ' + d.model;

        // TX
        const txVal = d.tx !== null ? d.tx.toFixed(2) : '—';
        document.getElementById('rTxVal').textContent    = txVal;
        document.getElementById('rTxBadge').textContent  = (d.tx_cat.emoji + ' ' + d.tx_cat.label);
        document.getElementById('rTxBadge').style.color  = d.tx_cat.color;
        document.getElementById('rTxVal').style.color    = d.tx_cat.color;

        // RX
        const rxVal = d.rx !== null ? d.rx.toFixed(2) : '—';
        document.getElementById('rRxVal').textContent    = rxVal;
        document.getElementById('rRxBadge').textContent  = (d.rx_cat.emoji + ' ' + d.rx_cat.label);
        document.getElementById('rRxBadge').style.color  = d.rx_cat.color;
        document.getElementById('rRxVal').style.color    = d.rx_cat.color;

        const btnRefresh = document.getElementById('btnForceRefresh');
        if (d.is_cached) {
            document.getElementById('rCmd').textContent = 'Cache (' + d.cached_updated_at + ')';
            btnRefresh.style.display = 'block';
            btnRefresh.onclick = () => openSignalModal(d.pppoe_name, true);
        } else {
            document.getElementById('rCmd').textContent = d.command_used || '—';
            btnRefresh.style.display = 'none';
        }
    }

    async function openSignalModal(pppoeName, forceRefresh = false) {
        if (!forceRefresh) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        showLoading();

        // AbortController: batalkan request jika > 35 detik (hindari nginx timeout)
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 35000);

        try {
            const url = `signal_check.php?pppoe=${encodeURIComponent(pppoeName)}&force_refresh=${forceRefresh ? '1' : '0'}`;
            const res = await fetch(url, { signal: controller.signal });
            clearTimeout(timeoutId);
            const body = await res.text();
            let data;

            try {
                data = JSON.parse(body);
            } catch (parseErr) {
                const detail = body.trim().replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 180);
                showError(detail || `Server mengembalikan response tidak valid (HTTP ${res.status}).`);
                return;
            }

            if (!res.ok || !data.ok) {
                showError(data.error || `Request gagal (HTTP ${res.status}).`);
            } else {
                showResult(data);
            }
        } catch (err) {
            clearTimeout(timeoutId);
            if (err.name === 'AbortError') {
                showError('⏱️ Koneksi ke OLT timeout (>35 detik). OLT tidak merespons atau jaringan lambat. Silakan coba lagi.');
            } else {
                showError('Gagal menghubungi server: ' + err.message);
            }
        }
    }

    function closeSignalModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function closeModalOnBg(e) {
        if (e.target === modal) closeSignalModal();
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSignalModal();
    });

    function filterPppoe(status) {
        const rows = document.querySelectorAll('.pppoe-row');
        let count = 0;
        rows.forEach(row => {
            if (row.classList.contains('empty-row') || row.id === 'search-empty-row') return;
            const rowStatus = row.getAttribute('data-status');
            if (status === 'all' || rowStatus === status) {
                row.style.display = '';
                count++;
            } else {
                row.style.display = 'none';
            }
        });
        document.getElementById('pppoe-result-count').textContent = 'Menampilkan: ' + count + ' client';
    }

    let sortOrders = [1, 1, 1, 1, 1]; // 1 for asc, -1 for desc

    function sortTable(colIndex) {
        const tbody = document.getElementById('pppoe-table-body');
        const rows = Array.from(tbody.querySelectorAll('tr.pppoe-row:not(.empty-row)'));
        if (rows.length === 0) return;

        // Reset icon arah panah di kolom lain
        document.querySelectorAll('th.sortable').forEach((th, i) => {
            if (i !== colIndex) {
                th.classList.remove('sort-asc', 'sort-desc');
                sortOrders[i] = 1;
            }
        });

        const header = document.querySelectorAll('th.sortable')[colIndex];
        const isAsc = sortOrders[colIndex] === 1;
        
        header.classList.remove('sort-asc', 'sort-desc');
        header.classList.add(isAsc ? 'sort-asc' : 'sort-desc');

        rows.sort((a, b) => {
            let valA = a.cells[colIndex].textContent.trim().toLowerCase();
            let valB = b.cells[colIndex].textContent.trim().toLowerCase();
            return valA.localeCompare(valB, undefined, {numeric: true}) * sortOrders[colIndex];
        });

        sortOrders[colIndex] *= -1; // toggle untuk klik berikutnya
        
        // Render ulang
        rows.forEach(row => tbody.appendChild(row));
    }
</script>
</body>
</html>
