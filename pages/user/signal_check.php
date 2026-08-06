<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/OltTelnet.php';
require_once __DIR__ . '/../../libs/OltProfiles.php';

checkUser();

header('Content-Type: application/json; charset=UTF-8');
set_time_limit(15);  // max 15 detik total, hindari nginx timeout
ignore_user_abort(false);

$userId    = (int) $_SESSION['user_id'];
$pppoeName = trim($_GET['pppoe'] ?? '');

if ($pppoeName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'PPPoE name tidak boleh kosong.']);
    exit;
}

// ── Ambil pengaturan OLT user ──────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM olts WHERE user_id = :uid ORDER BY id ASC LIMIT 1');
$stmt->execute(['uid' => $userId]);
$olt = $stmt->fetch();

if (!$olt) {
    echo json_encode(['ok' => false, 'error' => 'Pengaturan OLT belum tersedia. Silakan isi Pengaturan OLT terlebih dahulu.']);
    exit;
}

// ── Cari mapping PPPoE → ONU ──────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT pppoe_name, pon_onu, mac_address, customer_name
     FROM olt_pppoe_mappings
     WHERE user_id = :uid AND olt_id = :olt_id AND pppoe_name = :pppoe_name
     LIMIT 1'
);
$stmt->execute([
    'uid'        => $userId,
    'olt_id'     => (int) $olt['id'],
    'pppoe_name' => $pppoeName,
]);
$mapping = $stmt->fetch();

if (!$mapping) {
    echo json_encode([
        'ok'    => false,
        'error' => "PPPoE \"{$pppoeName}\" belum di-mapping ke ONU. Buka Monitoring OLT untuk menambahkan mapping.",
    ]);
    exit;
}

$ponOnu = $mapping['pon_onu'];
$brand  = strtolower((string) ($olt['brand'] ?? ''));

// ── Helper: split command string ───────────────────────────────────────────
function scSplitCommands(string $commands): array
{
    return array_values(array_filter(
        array_map('trim', preg_split('/\R/', $commands) ?: []),
        static fn (string $c): bool => $c !== ''
    ));
}

function scSplitSequence(string $command): array
{
    return array_values(array_filter(
        array_map('trim', explode('|', $command)),
        static fn (string $p): bool => $p !== ''
    ));
}

function scApplyMode(array $olt, array $seq): array
{
    $brand = strtolower((string) ($olt['brand'] ?? ''));
    if ($brand === 'hioso' && strtolower($seq[0] ?? '') !== 'enable') {
        array_unshift($seq, 'enable');
    }
    return $seq;
}

function scIsUseful(string $output): bool
{
    $n = strtolower($output);
    return trim($output) !== ''
        && !str_contains($n, 'unknown command')
        && !str_contains($n, 'invalid command')
        && !str_contains($n, 'incomplete command');
}

function scParseOptical(string $output): array
{
    $tx = null;
    $rx = null;

    if (preg_match('/\btx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $m) === 1) {
        $tx = (float) $m[1];
    }
    if (preg_match('/\brx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $m) === 1) {
        $rx = (float) $m[1];
    }
    if ($rx === null && preg_match('/receive[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $m) === 1) {
        $rx = (float) $m[1];
    }
    if ($tx === null && preg_match('/transmit[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $m) === 1) {
        $tx = (float) $m[1];
    }

    return ['tx' => $tx, 'rx' => $rx];
}

function scParseFromAllOutput(string $output, string $ponOnu): array
{
    $ponOnu = trim($ponOnu);
    $lines  = preg_split('/\R/', $output) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (!preg_match('/^' . preg_quote($ponOnu, '/') . '\s/', $line)) {
            continue;
        }
        // Format HSGQ: PON/ONU SN Temp C Voltage V Bias mA TX dBm RX dBm [Name]
        if (preg_match(
            '/^\d+\/\d+\s+\S+\s+\d+\s+C\s+[\d.]+\s+V\s+[\d.]+\s+mA\s+([-+]?[\d.]+)\s+dBm\s+([-+]?[\d.]+)\s+dBm/',
            $line,
            $m
        ) === 1) {
            return ['tx' => (float) $m[1], 'rx' => (float) $m[2], 'found' => true];
        }
    }
    return ['tx' => null, 'rx' => null, 'found' => false];
}

// ── Pre-check: coba connect TCP dulu (timeout 5 detik) ────────────────────
$preSocket = @fsockopen($olt['ip_address'], (int) $olt['telnet_port'], $preErrno, $preErrStr, 5);
if (!is_resource($preSocket)) {
    $errMsg = $preErrStr ?: 'Connection refused';
    echo json_encode([
        'ok'    => false,
        'error' => "Gagal terhubung ke OLT ({$olt['ip_address']}:{$olt['telnet_port']}): {$errMsg}. Pastikan OLT online dan port telnet terbuka.",
    ]);
    exit;
}
fclose($preSocket);

$telnet  = new OltTelnet();

// ── Resolve MAC Address ke ONU ID terbaru ─────────────────────────────────
$macAddress = $mapping['mac_address'] ?? '';
if ($macAddress !== '') {
    $cacheKey = 'onu_list_' . $userId . '_' . (int) $olt['id'];
    $cachedOnuList = $_SESSION[$cacheKey] ?? null;
    $onuListOutput = '';

    if (is_array($cachedOnuList) && ($cachedOnuList['expires_at'] ?? 0) >= time()) {
        $onuListOutput = (string) ($cachedOnuList['output'] ?? '');
    } else {
        // Fetch ONU List
        $ponPortCount = max(1, (int) ($olt['pon_port_count'] ?? 4));
        if ($brand === 'hioso') {
            $ponCommands = [];
            for ($port = 1; $port <= $ponPortCount; $port++) $ponCommands[] = "show onu info epon 0/{$port} all";
            $rawAll = $telnet->runCommands($olt['ip_address'], (int) $olt['telnet_port'], $olt['telnet_user'], $olt['telnet_pass'], scApplyMode($olt, $ponCommands), 15);
            if (scIsUseful($rawAll)) $onuListOutput = $rawAll;
        } elseif ($brand === 'ha7302cst') {
            $ponCommands = [];
            for ($port = 1; $port <= $ponPortCount; $port++) $ponCommands[] = "show pon 1/{$port} link bw";
            $rawAll = $telnet->runCommands($olt['ip_address'], (int) $olt['telnet_port'], $olt['telnet_user'], $olt['telnet_pass'], scApplyMode($olt, $ponCommands), 15);
            if (scIsUseful($rawAll)) $onuListOutput = $rawAll;
        } else {
            $commands = scSplitCommands($olt['onu_list_command'] ?: 'show onu');
            $outputs = [];
            foreach ($commands as $cmd) {
                $lastOutput = $telnet->runCommands($olt['ip_address'], (int) $olt['telnet_port'], $olt['telnet_user'], $olt['telnet_pass'], scApplyMode($olt, scSplitSequence($cmd)), 15);
                if (scIsUseful($lastOutput)) $outputs[] = $lastOutput;
            }
            if ($outputs !== []) $onuListOutput = implode("\n", $outputs);
        }

        if ($onuListOutput !== '') {
            $_SESSION[$cacheKey] = [
                'output' => $onuListOutput,
                'expires_at' => time() + 60,
            ];
        }
    }

    if ($onuListOutput !== '') {
        // Parse ONU list
        $lines = preg_split('/\R/', $onuListOutput) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '=') || str_starts_with($line, '-') || preg_match('/^(pon|onu|onuid|index|id|pon\/onu)\b/i', $line) === 1) continue;

            $parsedPon = null;
            $parsedMac = null;

            if (preg_match('/^(\d+)\/(\d+)_(\d+)\s+([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})/', $line, $match) === 1) {
                $parsedPon = '0/' . $match[1] . '/' . $match[2] . ':' . $match[3];
                $parsedMac = $match[4];
            } elseif (preg_match('/^(\d+\/\d+)\s+(\S+)\s+\d+\s+C\s+[\d.]+\s+V/', $line, $match) === 1) {
                $parsedPon = $match[1];
                $parsedMac = $match[2];
            } elseif (preg_match('/^(\d+\/\d+:\d+)\s+([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})/', $line, $match) === 1) {
                $parsedPon = str_replace(':', ' ', $match[1]);
                $parsedMac = $match[2];
            }

            if ($parsedMac !== null && strcasecmp($parsedMac, $macAddress) === 0) {
                $ponOnu = $parsedPon; // Override ponOnu from database!
                break;
            }
        }
    }
}

// ── Ambil optical power dari OLT ──────────────────────────────────────────
$tx      = null;
$rx      = null;
$cmdUsed = '';
$rawOut  = '';

try {
    if ($brand === 'hsgq') {
        // HSGQ: enable → configure → show ont-optical all  →  cari baris ONU ID
        $rawOut  = $telnet->runCommands(
            $olt['ip_address'],
            (int) $olt['telnet_port'],
            $olt['telnet_user'],
            $olt['telnet_pass'],
            ['enable', 'configure', 'show ont-optical all'],
            10   // timeout 10s per step
        );
        $cmdUsed = 'enable → configure → show ont-optical all';

        if ($rawOut === '') {
            echo json_encode(['ok' => false, 'error' => $telnet->getError() ?: 'Tidak ada output dari OLT.']);
            exit;
        }

        $found = scParseFromAllOutput($rawOut, $ponOnu);
        if (!$found['found']) {
            echo json_encode([
                'ok'    => false,
                'error' => "ONU ID \"{$ponOnu}\" tidak ditemukan dalam output OLT.",
            ]);
            exit;
        }

        $tx = $found['tx'];
        $rx = $found['rx'];

    } else {
        // Brand lain (Hioso, ZTE, Huawei, dll): pakai optical_command dari DB
        $optCmds = array_map(
            static fn (string $cmd): string => str_replace('{pon_onu}', $ponOnu, $cmd),
            scSplitCommands($olt['optical_command'])
        );

        foreach ($optCmds as $cmd) {
            $seq     = scApplyMode($olt, scSplitSequence($cmd));
            $rawOut  = $telnet->runCommands(
                $olt['ip_address'],
                (int) $olt['telnet_port'],
                $olt['telnet_user'],
                $olt['telnet_pass'],
                $seq,
                8   // timeout 8s per step
            );
            $cmdUsed = $cmd;
            if (scIsUseful($rawOut)) {
                break;
            }
        }

        if ($rawOut === '') {
            echo json_encode(['ok' => false, 'error' => $telnet->getError() ?: 'Tidak ada output dari OLT.']);
            exit;
        }

        if (!scIsUseful($rawOut)) {
            echo json_encode(['ok' => false, 'error' => 'Semua command optical gagal. Periksa konfigurasi OLT.']);
            exit;
        }

        $parsed = scParseOptical($rawOut);
        $tx     = $parsed['tx'];
        $rx     = $parsed['rx'];
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Error: ' . $e->getMessage()]);
    exit;
}

// ── Kategori kualitas sinyal ──────────────────────────────────────────────
function scSignalCategory(?float $dbm, string $type): array
{
    if ($dbm === null) {
        return ['label' => 'N/A', 'color' => '#64748b', 'emoji' => '❓'];
    }

    if ($type === 'tx') {
        // TX normal: -5 s/d +2 dBm
        if ($dbm >= -5 && $dbm <= 2)  return ['label' => 'Normal',  'color' => '#22c55e', 'emoji' => '✅'];
        if ($dbm < -5)                 return ['label' => 'Lemah',   'color' => '#f59e0b', 'emoji' => '⚠️'];
        return ['label' => 'Terlalu Kuat', 'color' => '#ef4444', 'emoji' => '🔴'];
    }

    // RX threshold typical: -8 to -28 dBm acceptable
    if ($dbm >= -27 && $dbm <= -8)    return ['label' => 'Normal',  'color' => '#22c55e', 'emoji' => '✅'];
    if ($dbm < -27 && $dbm >= -30)    return ['label' => 'Lemah',   'color' => '#f59e0b', 'emoji' => '⚠️'];
    if ($dbm < -30)                    return ['label' => 'Sangat Lemah', 'color' => '#ef4444', 'emoji' => '🔴'];
    return ['label' => 'Kuat',         'color' => '#6366f1', 'emoji' => '📶'];
}

echo json_encode([
    'ok'           => true,
    'pppoe_name'   => $pppoeName,
    'customer_name'=> $mapping['customer_name'] ?? '',
    'pon_onu'      => $ponOnu,
    'olt_name'     => $olt['olt_name'],
    'brand'        => $olt['brand'],
    'model'        => $olt['model'],
    'command_used' => $cmdUsed,
    'tx'           => $tx,
    'rx'           => $rx,
    'tx_cat'       => scSignalCategory($tx, 'tx'),
    'rx_cat'       => scSignalCategory($rx, 'rx'),
]);
