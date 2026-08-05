<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/OltTelnet.php';

checkUser();
set_time_limit(120);

$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';
$selectedPppoe = trim($_GET['pppoe'] ?? '');
$showOnuList = ($_GET['view'] ?? '') === 'onu_list';
$forceOnuRefresh = isset($_GET['refresh_onu']);
$onuListFromCache = false;
$opticalData = null;
$rawOutput = '';
$onuListOutput = '';
$onuListCommandUsed = '';
$opticalCommandUsed = '';
$manualCommand = trim($_GET['command'] ?? '');
$manualOutput = '';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS olt_pppoe_mappings (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      olt_id INT UNSIGNED NOT NULL,
      pppoe_name VARCHAR(100) NOT NULL,
      pon_onu VARCHAR(100) NOT NULL,
      customer_name VARCHAR(100) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_mapping_user_pppoe (user_id, pppoe_name),
      INDEX idx_mapping_olt_id (olt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS olts (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      brand VARCHAR(100) NOT NULL,
      model VARCHAR(100) NOT NULL,
      olt_name VARCHAR(100) NOT NULL,
      ip_address VARCHAR(45) NOT NULL,
      telnet_user VARCHAR(100) NOT NULL,
      telnet_pass VARCHAR(255) NOT NULL,
      telnet_port SMALLINT UNSIGNED NOT NULL DEFAULT 23,
      optical_command VARCHAR(255) NOT NULL,
      onu_list_command VARCHAR(255) NOT NULL DEFAULT 'show onu all',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_olts_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

try {
    $pdo->exec("ALTER TABLE olts ADD COLUMN onu_list_command VARCHAR(255) NOT NULL DEFAULT 'show onu all' AFTER optical_command");
} catch (Throwable $exception) {
    // Column already exists or the table has not been created by an older schema.
}

try {
    $pdo->exec("ALTER TABLE olts ADD COLUMN pon_port_count TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER onu_list_command");
} catch (Throwable $exception) {
    // Column already exists.
}

$stmt = $pdo->prepare('SELECT * FROM olts WHERE user_id = :user_id ORDER BY id ASC LIMIT 1');
$stmt->execute(['user_id' => $userId]);
$olt = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_mapping' && $olt) {
        $pppoeName = trim($_POST['pppoe_name'] ?? '');
        $ponOnu = trim($_POST['pon_onu'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');

        if ($pppoeName === '' || $ponOnu === '') {
            $error = 'PPPoE name dan PON/ONU wajib diisi.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO olt_pppoe_mappings (user_id, olt_id, pppoe_name, pon_onu, customer_name)
                 VALUES (:user_id, :olt_id, :pppoe_name, :pon_onu, :customer_name)
                 ON DUPLICATE KEY UPDATE
                    olt_id = VALUES(olt_id),
                    pon_onu = VALUES(pon_onu),
                    customer_name = VALUES(customer_name)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'olt_id'  => (int) $olt['id'],
                'pppoe_name'   => $pppoeName,
                'pon_onu'      => $ponOnu,
                'customer_name' => $customerName !== '' ? $customerName : null,
            ]);
            // PRG: redirect agar tidak reload ulang halaman berat
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
            exit;
        }
    }

    if ($action === 'delete_mapping' && $olt) {
        $mappingId = (int) ($_POST['mapping_id'] ?? 0);
        if ($mappingId > 0) {
            $stmt = $pdo->prepare(
                'DELETE FROM olt_pppoe_mappings
                 WHERE id = :id AND user_id = :user_id AND olt_id = :olt_id'
            );
            $stmt->execute([
                'id'      => $mappingId,
                'user_id' => $userId,
                'olt_id'  => (int) $olt['id'],
            ]);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?deleted=1');
        exit;
    }

    if ($action === 'update_mapping' && $olt) {
        $mappingId    = (int) ($_POST['mapping_id'] ?? 0);
        $pppoeName    = trim($_POST['pppoe_name'] ?? '');
        $ponOnu       = trim($_POST['pon_onu'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');

        if ($mappingId > 0 && $pppoeName !== '' && $ponOnu !== '') {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE olt_pppoe_mappings
                     SET pppoe_name = :pppoe_name,
                         pon_onu = :pon_onu,
                         customer_name = :customer_name
                     WHERE id = :id AND user_id = :user_id AND olt_id = :olt_id'
                );
                $stmt->execute([
                    'pppoe_name'    => $pppoeName,
                    'pon_onu'       => $ponOnu,
                    'customer_name' => $customerName !== '' ? $customerName : null,
                    'id'            => $mappingId,
                    'user_id'       => $userId,
                    'olt_id'        => (int) $olt['id'],
                ]);
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1');
                exit;
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'PPPoE name sudah dipakai di mapping lain.';
                } else {
                    throw $exception;
                }
            }
        } else {
            $error = 'PPPoE name dan PON/ONU wajib diisi untuk update.';
        }
    }
}

// Tampilkan pesan dari redirect
if (isset($_GET['saved']))   $message = 'Mapping PPPoE ke OLT berhasil disimpan.';
if (isset($_GET['deleted'])) $message = 'Mapping berhasil dihapus.';
if (isset($_GET['updated'])) $message = 'Mapping berhasil diperbarui.';

$mappings = [];
if ($olt) {
    $stmt = $pdo->prepare(
        'SELECT id, pppoe_name, pon_onu, customer_name
         FROM olt_pppoe_mappings
         WHERE user_id = :user_id AND olt_id = :olt_id
         ORDER BY pppoe_name ASC'
    );
    $stmt->execute(['user_id' => $userId, 'olt_id' => (int) $olt['id']]);
    $mappings = $stmt->fetchAll();
} elseif ($error === '') {
    $error = 'Pengaturan OLT belum tersedia. Silakan isi Pengaturan OLT terlebih dahulu.';
}

function parseOpticalPower(string $output): array
{
    $tx = null;
    $rx = null;

    if (preg_match('/\btx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $match) === 1) {
        $tx = (float) $match[1];
    }

    if (preg_match('/\brx(?:power)?\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $match) === 1) {
        $rx = (float) $match[1];
    }

    if ($rx === null && preg_match('/receive[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $match) === 1) {
        $rx = (float) $match[1];
    }

    if ($tx === null && preg_match('/transmit[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $output, $match) === 1) {
        $tx = (float) $match[1];
    }

    return ['tx' => $tx, 'rx' => $rx];
}

/**
 * Parse TX/RX optical dari output 'show ont-optical all' (HSGQ format)
 * dengan mencari baris yang ONU ID-nya cocok dengan $ponOnu.
 *
 * Format baris HSGQ:
 *   1/0 HWTCdf648270 43 C 3.24 V  14.58 mA  1.4840 dBm  -19.4700 dBm  001-03-aina
 *
 * @return array{tx: float|null, rx: float|null, found: bool}
 */
function parseOpticalPowerFromAllOutput(string $output, string $ponOnu): array
{
    $ponOnu = trim($ponOnu);
    $lines  = preg_split('/\R/', $output) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Pastikan baris dimulai dengan ONU ID yang tepat (contoh: "1/0 " atau "1/10 ")
        if (!preg_match('/^' . preg_quote($ponOnu, '/') . '\s/', $trimmed)) {
            continue;
        }

        // Parse format HSGQ: PON/ONU  SN  Temp C  Voltage V  Bias mA  TX dBm  RX dBm  [Name]
        if (preg_match(
            '/^\d+\/\d+\s+\S+\s+\d+\s+C\s+[\d.]+\s+V\s+[\d.]+\s+mA\s+([-+]?[\d.]+)\s+dBm\s+([-+]?[\d.]+)\s+dBm/',
            $trimmed,
            $m
        ) === 1) {
            return ['tx' => (float) $m[1], 'rx' => (float) $m[2], 'found' => true];
        }
    }

    return ['tx' => null, 'rx' => null, 'found' => false];
}

function parseOnuList(string $output): array
{
    $rows = [];
    $lines = preg_split('/\R/', $output) ?: [];
    $currentPonOnu = '';
    $currentTx = null;
    $currentRx = null;

    $flushOpticalRow = static function () use (&$rows, &$currentPonOnu, &$currentTx, &$currentRx): void {
        if ($currentPonOnu === '') {
            return;
        }

        $statusParts = [];

        if ($currentTx !== null) {
            $statusParts[] = 'TxPower: ' . $currentTx . ' dBm';
        }

        if ($currentRx !== null) {
            $statusParts[] = 'RxPower: ' . $currentRx . ' dBm';
        }

        $rows[] = [
            'pon_onu'  => $currentPonOnu,
            'mac'      => '',
            'status'   => $statusParts !== [] ? implode(', ', $statusParts) : 'Optical data tersedia',
            'uptime'   => '',
            'name'     => '',
            'raw'      => $currentPonOnu,
        ];

        $currentPonOnu = '';
        $currentTx     = null;
        $currentRx     = null;
    };

    foreach ($lines as $line) {
        $line = trim($line);

        if (preg_match('/show\s+onu\s+optical-ddm\s+(epon\s+\d+\/\d+\s+\d+)/i', $line, $match) === 1) {
            $flushOpticalRow();
            $currentPonOnu = $match[1];
            continue;
        }

        if ($currentPonOnu !== '' && preg_match('/\bTxPower\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $line, $match) === 1) {
            $currentTx = $match[1];
            continue;
        }

        if ($currentPonOnu !== '' && preg_match('/\bRxPower\b[^-+0-9]*([-+]?\d+(?:\.\d+)?)/i', $line, $match) === 1) {
            $currentRx = $match[1];
            continue;
        }

        if ($line === '' || str_starts_with($line, '=') || str_starts_with($line, '-') || preg_match('/^(pon|onu|onuid|index|id|pon\/onu)\b/i', $line) === 1) {
            continue;
        }

        // ----------------------------------------------------------------
        // Format HA7302CST EPON: show pon 1/1 link bw
        // Contoh: 1/1_1 14:6b:9a:80:e9:05 ... 1000000 ...
        // Mapping optical memakai format: 0/1/1:1
        // ----------------------------------------------------------------
        if (preg_match(
            '/^(\d+)\/(\d+)_(\d+)\s+([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})\s+\S+\s+(\d+)\s+\d+\s+\d+\s+(\d+)\b/',
            $line,
            $match
        ) === 1) {
            $slot = $match[1];
            $pon  = $match[2];
            $onu  = $match[3];
            $upMax = (int) $match[5];
            $downMax = (int) $match[6];

            $rows[] = [
                'pon_onu' => '0/' . $slot . '/' . $pon . ':' . $onu,
                'mac'     => $match[4],
                'status'  => ($upMax > 0 || $downMax > 0) ? 'Up' : 'Down',
                'uptime'  => 'UpMax: ' . $upMax . ' | DownMax: ' . $downMax,
                'name'    => '',
                'raw'     => $line,
            ];
            continue;
        }
        // ----------------------------------------------------------------
        // Format HSGQ G02: show ont-optical all
        // Contoh: 1/0 HWTCdf648270 43 C 3.24 V   14.58 mA 1.4840 dBm   -19.4700 dBm 001-03-aina
        // Kolom: PON/ONU  ONT-SN  Temp C  Voltage V  Bias mA  TxPower dBm  RxPower dBm  ONT-Name
        // ----------------------------------------------------------------
        if (preg_match(
            '/^(\d+\/\d+)\s+(\S+)\s+\d+\s+C\s+[\d.]+\s+V\s+[\d.]+\s+mA\s+([-+]?[\d.]+)\s+dBm\s+([-+]?[\d.]+)\s+dBm\s*(.*)$/',
            $line,
            $match
        ) === 1) {
            $onuId = $match[1];
            $sn    = $match[2];
            $txDbm = $match[3];
            $rxDbm = $match[4];
            $name  = trim($match[5]);

            $rows[] = [
                'pon_onu' => $onuId,
                'mac'     => $sn,           // ONT Serial Number
                'status'  => 'Up',
                'uptime'  => 'Tx: ' . $txDbm . ' dBm | Rx: ' . $rxDbm . ' dBm',
                'name'    => $name,
                'raw'     => $line,
            ];
            continue;
        }

        // ----------------------------------------------------------------
        // Format Hioso EPON: show onu info epon 0/X all
        // Contoh: 0/1:42 b4:e4:6b:73:07:81 Up 101 9127 1 3 1 CtcNegDone 30 Yes 15H14M17S [name]
        // ----------------------------------------------------------------
        if (preg_match(
            '/^(\d+\/\d+:\d+)\s+([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})\s+(\S+)\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(\S+)\s+\S+\s+\S+\s+(\S+)(?:\s+(.*))?$/',
            $line,
            $match
        ) === 1) {
            // Ubah 0/1:42 → 0/1 42 untuk dipakai di command telnet
            $onuId     = str_replace(':', ' ', $match[1]);
            $mac       = $match[2];
            $status    = $match[3];
            $ctcStatus = $match[4];  // CtcNegDone atau --
            $uptime    = $match[5];
            $name      = trim($match[6] ?? '');

            $rows[] = [
                'pon_onu' => $onuId,
                'mac'     => $mac,
                'status'  => ($ctcStatus !== '--' ? $status . ' (' . $ctcStatus . ')' : $status),
                'uptime'  => $uptime,
                'name'    => $name,
                'raw'     => $line,
            ];
            continue;
        }

        // ----------------------------------------------------------------
        // Fallback: format generik lain
        // ----------------------------------------------------------------
        if (preg_match('/((?:gpon-)?onu[_-]?\S+|\d+\/\d+(?:\/\d+)?(?::\d+)?|\d+)\s+(.+)/i', $line, $match) === 1) {
            $rows[] = [
                'pon_onu' => str_replace(':', ' ', $match[1]),
                'mac'     => '',
                'status'  => trim($match[2]),
                'uptime'  => '',
                'name'    => '',
                'raw'     => $line,
            ];
        }
    }

    $flushOpticalRow();

    return $rows;
}

function splitOltCommands(string $commands): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $commands) ?: []), static function (string $command): bool {
        return $command !== '';
    }));
}

function splitOltCommandSequence(string $command): array
{
    return array_values(array_filter(array_map('trim', explode('|', $command)), static function (string $part): bool {
        return $part !== '';
    }));
}

function applyOltCommandMode(array $olt, array $sequence): array
{
    $brand = strtolower((string) ($olt['brand'] ?? ''));

    if ($brand === 'hioso' && strtolower($sequence[0] ?? '') !== 'enable') {
        array_unshift($sequence, 'enable');
    }

    if ($brand === 'ha7302cst') {
        $prefix = ['enable', 'configure terminal', 'epon'];
        $existing = array_map('strtolower', $sequence);
        foreach (array_reverse($prefix) as $command) {
            if (!in_array(strtolower($command), $existing, true)) {
                array_unshift($sequence, $command);
            }
        }
    }

    return $sequence;
}

function isUsefulTelnetOutput(string $output): bool
{
    $normalized = strtolower($output);

    return trim($output) !== ''
        && !str_contains($normalized, 'unknown command')
        && !str_contains($normalized, 'invalid command')
        && !str_contains($normalized, 'incomplete command');
}

function runFirstUsefulOltCommand(OltTelnet $telnet, array $olt, array $commands, int $timeout = 8): array
{
    $lastOutput = '';
    $lastCommand = '';

    foreach ($commands as $command) {
        $lastCommand = $command;
        $sequence = applyOltCommandMode($olt, splitOltCommandSequence($command));
        $lastOutput = $telnet->runCommands(
            $olt['ip_address'],
            (int) $olt['telnet_port'],
            $olt['telnet_user'],
            $olt['telnet_pass'],
            $sequence,
            $timeout
        );

        if (isUsefulTelnetOutput($lastOutput)) {
            return [$lastOutput, $command];
        }
    }

    return [$lastOutput, $lastCommand];
}

function runAllUsefulOltCommands(OltTelnet $telnet, array $olt, array $commands, int $timeout = 8): array
{
    $outputs = [];
    $usedCommands = [];
    $lastOutput = '';
    $lastCommand = '';

    foreach ($commands as $command) {
        $lastCommand = $command;
        $lastOutput = $telnet->runCommands(
            $olt['ip_address'],
            (int) $olt['telnet_port'],
            $olt['telnet_user'],
            $olt['telnet_pass'],
            applyOltCommandMode($olt, splitOltCommandSequence($command)),
            $timeout
        );

        if (isUsefulTelnetOutput($lastOutput)) {
            $usedCommands[] = $command;
            $outputs[] = "===== {$command} =====\n" . trim($lastOutput);
        }
    }

    if ($outputs !== []) {
        return [implode("\n\n", $outputs), implode(', ', $usedCommands)];
    }

    return [$lastOutput, $lastCommand];
}

if ($olt && $showOnuList) {
    $cacheKey = 'onu_list_' . $userId . '_' . (int) $olt['id'];
    $cachedOnuList = $_SESSION[$cacheKey] ?? null;

    if ($forceOnuRefresh === false
        && is_array($cachedOnuList)
        && ($cachedOnuList['expires_at'] ?? 0) >= time()
    ) {
        $onuListOutput = (string) ($cachedOnuList['output'] ?? '');
        $onuListCommandUsed = (string) ($cachedOnuList['command'] ?? 'cache');
        $onuListFromCache = $onuListOutput !== '';
    }

    $ponPortCount = max(1, (int) ($olt['pon_port_count'] ?? 4));
    $brand        = strtolower((string) ($olt['brand'] ?? ''));
    $telnet       = new OltTelnet();

    // -------------------------------------------------------------------
    // Hioso EPON: loop per port dengan show onu info epon 0/X all
    // -------------------------------------------------------------------
    if ($onuListOutput === '' && $brand === 'hioso') {
        $ponCommands = [];
        for ($port = 1; $port <= $ponPortCount; $port++) {
            $ponCommands[] = "show onu info epon 0/{$port} all";
        }

        $sequence = applyOltCommandMode($olt, $ponCommands);
        $rawAll   = $telnet->runCommands(
            $olt['ip_address'],
            (int) $olt['telnet_port'],
            $olt['telnet_user'],
            $olt['telnet_pass'],
            $sequence,
            15
        );

        if (isUsefulTelnetOutput($rawAll)) {
            $onuListOutput      = $rawAll;
            $onuListCommandUsed = 'show onu info epon 0/1~0/' . $ponPortCount . ' all';
        }
    }

    // -------------------------------------------------------------------
    // HA7302CST EPON: loop per PON dengan show pon 1/X link bw
    // -------------------------------------------------------------------
    if ($onuListOutput === '' && $brand === 'ha7302cst') {
        $ponCommands = [];
        for ($port = 1; $port <= $ponPortCount; $port++) {
            $ponCommands[] = "show pon 1/{$port} link bw";
        }

        $sequence = applyOltCommandMode($olt, $ponCommands);
        $rawAll   = $telnet->runCommands(
            $olt['ip_address'],
            (int) $olt['telnet_port'],
            $olt['telnet_user'],
            $olt['telnet_pass'],
            $sequence,
            15
        );

        if (isUsefulTelnetOutput($rawAll)) {
            $onuListOutput      = $rawAll;
            $onuListCommandUsed = 'show pon 1/1~1/' . $ponPortCount . ' link bw';
        }
    }
    // -------------------------------------------------------------------
    // Brand lain (HSGQ, ZTE, Huawei, dll): gunakan onu_list_command
    // dari pengaturan OLT langsung tanpa mencoba EPON loop
    // -------------------------------------------------------------------
    if ($onuListOutput === '') {
        [$onuListOutput, $onuListCommandUsed] = runAllUsefulOltCommands(
            $telnet,
            $olt,
            splitOltCommands($olt['onu_list_command'] ?: 'show onu'),
            15
        );
    }

    if ($onuListOutput === '' && $onuListFromCache === false) {
        $error = $telnet->getError() ?: 'Tidak ada output list ONU dari OLT.';
    } elseif (!isUsefulTelnetOutput($onuListOutput)) {
        $error = 'Semua kandidat command list ONU gagal. Coba command manual: ?, show ?, show onu ?, lalu update config/olt_profiles.json.';
    }

    if ($onuListOutput !== '' && $onuListFromCache === false) {
        $_SESSION[$cacheKey] = [
            'output' => $onuListOutput,
            'command' => $onuListCommandUsed,
            'expires_at' => time() + 60,
        ];
    }
}

$onuRows = $onuListOutput !== '' ? parseOnuList($onuListOutput) : [];

if ($olt && $manualCommand !== '') {
    $telnet = new OltTelnet();
    $manualOutput = $telnet->runCommand(
        $olt['ip_address'],
        (int) $olt['telnet_port'],
        $olt['telnet_user'],
        $olt['telnet_pass'],
        $manualCommand,
        8
    );

    if ($manualOutput === '') {
        $error = $telnet->getError() ?: 'Tidak ada output dari command manual.';
    }
}

if ($olt && $selectedPppoe !== '') {
    $selectedMapping = null;

    foreach ($mappings as $mapping) {
        if ($mapping['pppoe_name'] === $selectedPppoe) {
            $selectedMapping = $mapping;
            break;
        }
    }

    if ($selectedMapping) {
        $brand  = strtolower((string) ($olt['brand'] ?? ''));
        $telnet = new OltTelnet();

        if ($brand === 'hsgq') {
            // ---------------------------------------------------------------
            // HSGQ: jalankan show ont-optical all, lalu cari baris ONU ID
            // yang cocok dengan mapping pon_onu (contoh: 1/0, 1/5, dst.)
            // ---------------------------------------------------------------
            $rawOutput = $telnet->runCommands(
                $olt['ip_address'],
                (int) $olt['telnet_port'],
                $olt['telnet_user'],
                $olt['telnet_pass'],
                ['enable', 'configure', 'show ont-optical all'],
                15
            );
            $opticalCommandUsed = 'enable → configure → show ont-optical all';

            if ($rawOutput === '') {
                $error = $telnet->getError() ?: 'Tidak ada output dari OLT.';
            } else {
                $found = parseOpticalPowerFromAllOutput($rawOutput, $selectedMapping['pon_onu']);
                if ($found['found']) {
                    $opticalData = ['tx' => $found['tx'], 'rx' => $found['rx']];
                } else {
                    $error = 'ONU ID "' . htmlspecialchars($selectedMapping['pon_onu'], ENT_QUOTES, 'UTF-8')
                           . '" tidak ditemukan dalam output show ont-optical all.';
                }
            }
        } else {
            // ---------------------------------------------------------------
            // Brand lain (Hioso, ZTE, Huawei, dll): gunakan optical_command
            // dari pengaturan dengan {pon_onu} diganti nilai mapping
            // ---------------------------------------------------------------
            $commands = array_map(static function (string $command) use ($selectedMapping): string {
                return str_replace('{pon_onu}', $selectedMapping['pon_onu'], $command);
            }, splitOltCommands($olt['optical_command']));

            [$rawOutput, $opticalCommandUsed] = runFirstUsefulOltCommand($telnet, $olt, $commands, 8);

            if ($rawOutput === '') {
                $error = $telnet->getError() ?: 'Tidak ada output dari OLT.';
            } elseif (!isUsefulTelnetOutput($rawOutput)) {
                $error = 'Semua kandidat command optical power gagal. Cek Raw Output lalu update config/olt_profiles.json.';
            } else {
                $opticalData = parseOpticalPower($rawOutput);
            }
        }
    }
}

$pageTitle  = 'Monitoring OLT';
$activePage = 'olt_monitor';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.layout { display: grid; grid-template-columns: 380px 1fr; gap: 18px; align-items: start; }
.panel { padding: 22px; background: var(--clr-surface); border: 1px solid var(--clr-border); border-radius: var(--radius); box-shadow: var(--shadow); }
.panel h2 { font-size: 16px; margin-bottom: 16px; color: var(--clr-text); }
.meta { margin: 0 0 14px; color: var(--clr-muted); font-size: 13px; line-height: 1.6; }
.metric-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
.metric { padding: 16px; border: 1px solid var(--clr-border); border-radius: 10px; background: rgba(255,255,255,0.04); }
.metric span { display: block; color: var(--clr-muted); font-size: 13px; }
.metric strong { display: block; margin-top: 8px; font-size: 24px; color: var(--clr-text); }
.button { display:inline-flex; align-items:center; padding:9px 16px; border-radius:8px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:700; text-decoration:none; font-size:13px; border:none; cursor:pointer; }
.btn-sm { padding:5px 10px; font-size:12px; border-radius:6px; }
.btn-danger { background:linear-gradient(135deg,#ef4444,#b91c1c); }
.btn-warning { background:linear-gradient(135deg,#f59e0b,#d97706); }
.inline-form { display: flex; gap: 10px; align-items: end; margin-bottom: 18px; }
.inline-form > div { flex: 1; }
.inline-form input { margin-bottom: 0; }
canvas { border-radius: 8px; }
/* Modal edit mapping */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.active { display:flex; }
.modal-box { background:var(--clr-surface); border:1px solid var(--clr-border); border-radius:14px; padding:28px; width:100%; max-width:400px; box-shadow:0 20px 60px rgba(0,0,0,0.5); animation:modalIn .2s ease; }
@keyframes modalIn { from { transform:translateY(-20px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal-box h3 { margin:0 0 18px; font-size:16px; color:var(--clr-text); }
.modal-box .modal-actions { display:flex; gap:10px; margin-top:18px; justify-content:flex-end; }
.btn-secondary { background:rgba(255,255,255,0.08); color:var(--clr-text); border:1px solid var(--clr-border); }
.table-scroll { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; margin-top:18px; }
.table-scroll table { margin-top:0; }
.map-table { min-width:620px; table-layout:fixed; }
.map-table th, .map-table td { vertical-align:middle; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.map-table th:nth-child(1), .map-table td:nth-child(1) { width:28%; }
.map-table th:nth-child(2), .map-table td:nth-child(2) { width:24%; }
.map-table th:nth-child(3), .map-table td:nth-child(3) { width:24%; }
.map-table th:nth-child(4), .map-table td:nth-child(4) { width:118px; overflow:visible; }
.map-table .actions { display:flex; gap:6px; flex-wrap:nowrap; }
.map-table .actions form { flex:0 0 auto; }
.map-table .actions .button { flex:0 0 auto; }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } .inline-form { flex-direction: column; } }
</style>
<div class="page-wrap">
<p class="page-heading">Monitoring OLT</p>
<p class="page-sub">Monitor status ONU, optical power, dan mapping PPPoE.</p>
<?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="layout">

            <section class="panel">
                <h2>Tambah Mapping</h2>
                <form method="post" action="">
                    <label for="pppoe_name">PPPoE Name</label>
                    <input type="text" id="pppoe_name" name="pppoe_name" placeholder="contoh: user-pppoe" required>

                    <label for="pon_onu">PON/ONU</label>
                    <input type="text" id="pon_onu" name="pon_onu" placeholder="contoh: 1/1/1:12 atau gpon-onu_1/1/1:12" required>

                    <label for="customer_name">Nama Pelanggan</label>
                    <input type="text" id="customer_name" name="customer_name" placeholder="Opsional">

                    <button type="submit" class="button" name="action" value="save_mapping">Simpan Mapping</button>
                </form>

                <div class="table-scroll">
                    <table class="map-table">
                    <thead><tr><th>PPPoE</th><th>PON/ONU</th><th>Pelanggan</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <?php if ($mappings === []): ?>
                            <tr><td colspan="4">Belum ada mapping.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($mappings as $mapping): ?>
                            <tr>
                                <td><?= htmlspecialchars($mapping['pppoe_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><code><?= htmlspecialchars($mapping['pon_onu'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= htmlspecialchars($mapping['customer_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="button btn-sm" href="?pppoe=<?= urlencode($mapping['pppoe_name']) ?>" title="Lihat Graph">&#128202;</a>
                                        <button type="button" class="button btn-sm btn-warning" title="Edit Mapping"
                                            onclick="openEditModal(<?= $mapping['id'] ?>, <?= htmlspecialchars(json_encode($mapping['pppoe_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($mapping['pon_onu']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($mapping['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)">&#9998;</button>
                                        <form method="post" action="" style="display:inline" onsubmit="return confirm('Hapus mapping <?= htmlspecialchars(addslashes($mapping['pppoe_name']), ENT_QUOTES, 'UTF-8') ?>?')">
                                            <input type="hidden" name="action" value="delete_mapping">
                                            <input type="hidden" name="mapping_id" value="<?= $mapping['id'] ?>">
                                            <button type="submit" class="button btn-sm btn-danger" title="Hapus Mapping">&#128465;</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            </section>

            <!-- Modal Edit Mapping -->
            <div class="modal-overlay" id="editMappingModal">
                <div class="modal-box">
                    <h3>&#9998; Edit Mapping PPPoE</h3>
                    <form method="post" action="" id="editMappingForm">
                        <input type="hidden" name="action" value="update_mapping">
                        <input type="hidden" name="mapping_id" id="edit_mapping_id">

                        <label for="edit_pppoe_name">PPPoE Name</label>
                        <input type="text" id="edit_pppoe_name" name="pppoe_name" required>

                        <label for="edit_pon_onu">PON/ONU</label>
                        <input type="text" id="edit_pon_onu" name="pon_onu" required placeholder="contoh: 1/1/1:12">

                        <label for="edit_customer_name">Nama Pelanggan</label>
                        <input type="text" id="edit_customer_name" name="customer_name" placeholder="Opsional">

                        <div class="modal-actions">
                            <button type="button" class="button btn-secondary" onclick="closeEditModal()">Batal</button>
                            <button type="submit" class="button">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>

            <section class="panel">
                <h2>Graph Optical Power</h2>
                <?php if ($olt): ?>
                    <p class="meta">
                        OLT: <strong><?= htmlspecialchars($olt['brand'] . ' ' . $olt['model'], ENT_QUOTES, 'UTF-8') ?></strong>
                        / <?= htmlspecialchars($olt['olt_name'], ENT_QUOTES, 'UTF-8') ?>
                        <br>Command List ONU: <code><?= htmlspecialchars($olt['onu_list_command'] ?: 'show onu', ENT_QUOTES, 'UTF-8') ?></code>
                    </p>
                <?php endif; ?>

                <p>
                    <a class="button" href="?view=onu_list">Ambil List ONU</a>
                    <a class="button btn-secondary" href="?view=onu_list&amp;refresh_onu=1">Refresh dari OLT</a>
                </p>

                <form class="inline-form" method="get" action="">
                    <div>
                        <label for="command">Coba Command Telnet</label>
                        <input type="text" id="command" name="command" value="<?= htmlspecialchars($manualCommand, ENT_QUOTES, 'UTF-8') ?>" placeholder="contoh: ?, show ?, show epon ?">
                    </div>
                    <button type="submit">Jalankan</button>
                </form>

                <?php if ($manualOutput !== ''): ?>
                    <h2>Output Command Manual</h2>
                    <p class="meta">Command: <code><?= htmlspecialchars($manualCommand, ENT_QUOTES, 'UTF-8') ?></code></p>
                    <pre><?= htmlspecialchars($manualOutput, ENT_QUOTES, 'UTF-8') ?></pre>
                <?php endif; ?>

                <?php if ($onuListOutput !== ''): ?>
                    <h2>List ONU</h2>
                    <?php if ($onuListCommandUsed !== ''): ?>
                        <p class="meta">Command dipakai: <code><?= htmlspecialchars($onuListCommandUsed, ENT_QUOTES, 'UTF-8') ?></code><?= $onuListFromCache ? ' <span class="meta">(cache 60 detik)</span>' : '' ?></p>
                    <?php endif; ?>
                    <table>
                        <thead><tr><th>ONU ID</th><th>MAC / Serial</th><th>Status</th><th>Uptime / Optical</th><th>Nama</th></tr></thead>
                        <tbody>
                            <?php if ($onuRows === []): ?>
                                <tr><td colspan="5">Output belum bisa diparse otomatis. Lihat Raw List ONU di bawah.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($onuRows as $onuRow): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($onuRow['pon_onu'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><?= htmlspecialchars($onuRow['mac'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="color: <?= ($onuRow['status'] ?? '') !== '' && str_starts_with($onuRow['status'], 'Up') ? '#166534' : '#991b1b' ?>; font-weight:700"><?= htmlspecialchars($onuRow['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($onuRow['uptime'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($onuRow['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <h2 style="margin-top: 18px;">Raw List ONU</h2>
                    <pre><?= htmlspecialchars($onuListOutput, ENT_QUOTES, 'UTF-8') ?></pre>
                <?php endif; ?>

                <form method="get" action="">
                    <label for="pppoe">Pilih PPPoE</label>
                    <select id="pppoe" name="pppoe" onchange="this.form.submit()">
                        <option value="">Pilih mapping</option>
                        <?php foreach ($mappings as $mapping): ?>
                            <option value="<?= htmlspecialchars($mapping['pppoe_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $selectedPppoe === $mapping['pppoe_name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mapping['pppoe_name'] . ' - ' . $mapping['pon_onu'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <div class="metric-grid">
                    <div class="metric"><span>TX Optical</span><strong id="tx-value"><?= $opticalData && $opticalData['tx'] !== null ? htmlspecialchars((string) $opticalData['tx'], ENT_QUOTES, 'UTF-8') . ' dBm' : '-' ?></strong></div>
                    <div class="metric"><span>RX Optical</span><strong id="rx-value"><?= $opticalData && $opticalData['rx'] !== null ? htmlspecialchars((string) $opticalData['rx'], ENT_QUOTES, 'UTF-8') . ' dBm' : '-' ?></strong></div>
                </div>

                <canvas id="opticalChart" width="700" height="260"></canvas>

                <?php if ($rawOutput !== ''): ?>
                    <h2 style="margin-top: 18px;">Raw Output</h2>
                    <?php if ($opticalCommandUsed !== ''): ?>
                        <p class="meta">Command dipakai: <code><?= htmlspecialchars($opticalCommandUsed, ENT_QUOTES, 'UTF-8') ?></code></p>
                    <?php endif; ?>
                    <pre><?= htmlspecialchars($rawOutput, ENT_QUOTES, 'UTF-8') ?></pre>
                <?php endif; ?>
            </section>
        </div>
</div>

    <script>
        // ── Modal Edit Mapping ──────────────────────────────────────────
        function openEditModal(id, pppoeName, ponOnu, customerName) {
            document.getElementById('edit_mapping_id').value    = id;
            document.getElementById('edit_pppoe_name').value    = pppoeName;
            document.getElementById('edit_pon_onu').value       = ponOnu;
            document.getElementById('edit_customer_name').value = customerName || '';
            document.getElementById('editMappingModal').classList.add('active');
        }
        function closeEditModal() {
            document.getElementById('editMappingModal').classList.remove('active');
        }
        // Tutup modal jika klik overlay
        document.getElementById('editMappingModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        // ── Graph Optical Power ─────────────────────────────────────────
        const tx = <?= json_encode($opticalData['tx'] ?? null) ?>;
        const rx = <?= json_encode($opticalData['rx'] ?? null) ?>;
        const canvas = document.getElementById('opticalChart');
        const ctx = canvas.getContext('2d');

        function drawChart(values) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#1a1d2e';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.strokeStyle = 'rgba(255,255,255,0.08)';
            ctx.lineWidth = 1;

            for (let i = 0; i <= 5; i += 1) {
                const y = 30 + (i * 38);
                ctx.beginPath();
                ctx.moveTo(54, y);
                ctx.lineTo(canvas.width - 24, y);
                ctx.stroke();
            }

            ctx.fillStyle = '#6b7280';
            ctx.font = '13px Arial';
            ctx.fillText('dBm', 18, 22);

            const valid = values.filter((item) => item.value !== null);
            if (valid.length === 0) {
                ctx.fillStyle = '#6b7280';
                ctx.fillText('Pilih PPPoE mapping untuk menampilkan graph.', 72, 132);
                return;
            }

            const min = -40;
            const max = 5;
            const barWidth = 110;
            const gap = 90;
            const baseY = 220;

            values.forEach((item, index) => {
                const x = 135 + (index * (barWidth + gap));
                const normalized = item.value === null ? 0 : (item.value - min) / (max - min);
                const height = Math.max(4, Math.min(180, normalized * 180));
        ctx.fillStyle = item.color;
                ctx.fillRect(x, baseY - height, barWidth, height);
                ctx.fillStyle = '#e2e8f0';
                ctx.font = 'bold 15px Inter, Arial';
                ctx.fillText(item.value === null ? '-' : `${item.value} dBm`, x, baseY - height - 10);
                ctx.fillStyle = '#94a3b8';
                ctx.font = '13px Inter, Arial';
                ctx.fillText(item.label, x + 36, 244);
            });
        }

        drawChart([
            { label: 'TX', value: tx, color: '#6366f1' },
            { label: 'RX', value: rx, color: '#10b981' },
        ]);
    </script>
</div>
</body>
</html>






