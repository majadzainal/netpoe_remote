<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/OltTelnet.php';

checkUser();
set_time_limit(300); // Allow enough time for OLT fetching

$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';

$stmt = $pdo->prepare('SELECT * FROM olts WHERE user_id = :user_id ORDER BY id ASC LIMIT 1');
$stmt->execute(['user_id' => $userId]);
$olt = $stmt->fetch();

if (!$olt) {
    die('Pengaturan OLT belum tersedia. Silakan isi Pengaturan OLT terlebih dahulu.');
}

// Reuse the exact logic from olt_monitor.php to fetch ONU List
function parseOnuList(string $output): array {
    $rows = [];
    $lines = preg_split('/\R/', $output) ?: [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '=') || str_starts_with($line, '-') || preg_match('/^(pon|onu|onuid|index|id|pon\/onu)\b/i', $line) === 1) {
            continue;
        }

        // Format HA7302CST EPON
        if (preg_match('/^(\d+)\/(\d+)_(\d+)\s+([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})\s+\S+\s+(\d+)\s+\d+\s+\d+\s+(\d+)\b/', $line, $match) === 1) {
            $slot = $match[1];
            $pon  = $match[2];
            $onu  = $match[3];
            $rows[] = ['pon_onu' => '0/' . $slot . '/' . $pon . ':' . $onu, 'mac' => $match[4]];
            continue;
        }

        // Format HSGQ G02
        if (preg_match('/^(\d+\/\d+)\s+(\S+)\s+\d+\s+C\s+[\d.]+\s+V\s+[\d.]+\s+mA\s+([-+]?[\d.]+)\s+dBm\s+([-+]?[\d.]+)\s+dBm\s*(.*)$/', $line, $match) === 1) {
            $rows[] = ['pon_onu' => $match[1], 'mac' => $match[2]];
            continue;
        }

        // Format Hioso EPON
        if (preg_match('/^(\d+\/\d+:\d+)\s+([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})\s+(\S+)\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(\S+)\s+\S+\s+\S+\s+(\S+)(?:\s+(.*))?$/', $line, $match) === 1) {
            $rows[] = ['pon_onu' => str_replace(':', ' ', $match[1]), 'mac' => $match[2]];
            continue;
        }
    }
    return $rows;
}

function splitOltCommands(string $commands): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $commands) ?: []), static function (string $command): bool {
        return $command !== '';
    }));
}

function splitOltCommandSequence(string $command): array {
    return array_values(array_filter(array_map('trim', explode('|', $command)), static function (string $part): bool {
        return $part !== '';
    }));
}

function applyOltCommandMode(array $olt, array $sequence): array {
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

function isUsefulTelnetOutput(string $output): bool {
    $normalized = strtolower($output);
    return trim($output) !== ''
        && !str_contains($normalized, 'unknown command')
        && !str_contains($normalized, 'invalid command')
        && !str_contains($normalized, 'incomplete command');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remap'])) {
    $telnet = new OltTelnet();
    $ponPortCount = max(1, (int) ($olt['pon_port_count'] ?? 4));
    $brand = strtolower((string) ($olt['brand'] ?? ''));
    $onuListOutput = '';

    // Same logic to fetch ONU list
    if ($brand === 'hioso') {
        $ponCommands = [];
        for ($port = 1; $port <= $ponPortCount; $port++) {
            $ponCommands[] = "show onu info epon 0/{$port} all";
        }
        $sequence = applyOltCommandMode($olt, $ponCommands);
        $rawAll = $telnet->runCommands($olt['ip_address'], (int) $olt['telnet_port'], $olt['telnet_user'], $olt['telnet_pass'], $sequence, 15);
        if (isUsefulTelnetOutput($rawAll)) $onuListOutput = $rawAll;
    } elseif ($brand === 'ha7302cst') {
        $ponCommands = [];
        for ($port = 1; $port <= $ponPortCount; $port++) {
            $ponCommands[] = "show pon 1/{$port} link bw";
        }
        $sequence = applyOltCommandMode($olt, $ponCommands);
        $rawAll = $telnet->runCommands($olt['ip_address'], (int) $olt['telnet_port'], $olt['telnet_user'], $olt['telnet_pass'], $sequence, 15);
        if (isUsefulTelnetOutput($rawAll)) $onuListOutput = $rawAll;
    } else {
        $commands = splitOltCommands($olt['onu_list_command'] ?: 'show onu');
        $outputs = [];
        foreach ($commands as $command) {
            $lastOutput = $telnet->runCommands($olt['ip_address'], (int) $olt['telnet_port'], $olt['telnet_user'], $olt['telnet_pass'], applyOltCommandMode($olt, splitOltCommandSequence($command)), 15);
            if (isUsefulTelnetOutput($lastOutput)) {
                $outputs[] = $lastOutput;
            }
        }
        if ($outputs !== []) {
            $onuListOutput = implode("\n", $outputs);
        }
    }

    if ($onuListOutput !== '') {
        $onuRows = parseOnuList($onuListOutput);
        
        $stmt = $pdo->prepare('SELECT * FROM olt_pppoe_mappings WHERE user_id = :user_id AND (mac_address IS NULL OR mac_address = "")');
        $stmt->execute(['user_id' => $userId]);
        $mappingsToUpdate = $stmt->fetchAll();

        $updatedCount = 0;
        $notFoundCount = 0;

        foreach ($mappingsToUpdate as $mapping) {
            $foundMac = null;
            foreach ($onuRows as $row) {
                // Match the old pon_onu
                if (trim($row['pon_onu']) === trim($mapping['pon_onu']) && trim($row['mac']) !== '') {
                    $foundMac = trim($row['mac']);
                    break;
                }
            }

            if ($foundMac) {
                $updateStmt = $pdo->prepare('UPDATE olt_pppoe_mappings SET mac_address = :mac WHERE id = :id');
                $updateStmt->execute(['mac' => $foundMac, 'id' => $mapping['id']]);
                $updatedCount++;
            } else {
                $notFoundCount++;
            }
        }
        
        $message = "Berhasil update MAC untuk $updatedCount mapping. Gagal menemukan MAC untuk $notFoundCount mapping (mungkin ONU offline).";
    } else {
        $error = "Gagal mengambil ONU List dari OLT.";
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) as total FROM olt_pppoe_mappings WHERE user_id = :user_id AND (mac_address IS NULL OR mac_address = "")');
$stmt->execute(['user_id' => $userId]);
$missingMacCount = $stmt->fetch()['total'] ?? 0;

$pageTitle = 'Migrasi Mapping ke MAC Address';
$activePage = 'olt_monitor';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.panel { padding: 22px; background: var(--clr-surface); border: 1px solid var(--clr-border); border-radius: var(--radius); box-shadow: var(--shadow); max-width: 600px; margin: 0 auto; }
.panel h2 { font-size: 18px; margin-bottom: 16px; color: var(--clr-text); }
.button { display:inline-flex; align-items:center; padding:9px 16px; border-radius:8px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:700; text-decoration:none; font-size:13px; border:none; cursor:pointer; }
</style>
<div class="page-wrap">
    <p class="page-heading">Migrasi Mapping ONU</p>
    <p class="page-sub">Alat untuk mencari dan menyimpan MAC Address dari mapping yang masih menggunakan ONU ID.</p>
    
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <section class="panel">
        <h2>Status Data Mapping</h2>
        <p>Total mapping yang belum memiliki MAC Address: <strong><?= $missingMacCount ?></strong></p>
        
        <?php if ($missingMacCount > 0): ?>
            <p style="margin-bottom: 20px; color: var(--clr-muted);">Klik tombol di bawah untuk menarik List ONU dari OLT secara live, dan mencocokkan ONU ID yang ada di database dengan MAC Address/Serial Number saat ini.</p>
            <form method="post" action="" onsubmit="return confirm('Proses ini akan memakan waktu hingga 30 detik untuk membaca OLT. Lanjutkan?')">
                <input type="hidden" name="remap" value="1">
                <button type="submit" class="button">Mulai Sinkronisasi MAC Address</button>
            </form>
        <?php else: ?>
            <p style="color: #10b981; font-weight: 700; margin-top: 10px;">Semua mapping sudah memiliki MAC Address! Tidak perlu sinkronisasi.</p>
        <?php endif; ?>
        
        <p style="margin-top: 20px;"><a href="olt_monitor.php" style="color: #6366f1; text-decoration: none;">&larr; Kembali ke Monitoring OLT</a></p>
    </section>
</div>
</body>
</html>
