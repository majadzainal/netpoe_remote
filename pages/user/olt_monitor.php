<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/OltTelnet.php';

checkUser();
set_time_limit(25);

$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';
$selectedPppoe = trim($_GET['pppoe'] ?? '');
$showOnuList = ($_GET['view'] ?? '') === 'onu_list';
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
                'olt_id' => (int) $olt['id'],
                'pppoe_name' => $pppoeName,
                'pon_onu' => $ponOnu,
                'customer_name' => $customerName !== '' ? $customerName : null,
            ]);
            $message = 'Mapping PPPoE ke OLT berhasil disimpan.';
            $selectedPppoe = $pppoeName;
        }
    }
}

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
            'pon_onu' => $currentPonOnu,
            'status' => $statusParts !== [] ? implode(', ', $statusParts) : 'Optical data tersedia',
            'raw' => $currentPonOnu,
        ];

        $currentPonOnu = '';
        $currentTx = null;
        $currentRx = null;
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

        if ($line === '' || str_starts_with($line, '-') || preg_match('/^(pon|onu|index|id)\b/i', $line) === 1) {
            continue;
        }

        if (preg_match('/((?:gpon-)?onu[_-]?\S+|\d+\/\d+(?:\/\d+)?(?::\d+)?|\d+)\s+(.+)/i', $line, $match) === 1) {
            $rows[] = [
                'pon_onu' => $match[1],
                'status' => trim($match[2]),
                'raw' => $line,
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
    $telnet = new OltTelnet();
    [$onuListOutput, $onuListCommandUsed] = runAllUsefulOltCommands(
        $telnet,
        $olt,
        splitOltCommands($olt['onu_list_command'] ?: 'show onu'),
        8
    );

    if ($onuListOutput === '') {
        $error = $telnet->getError() ?: 'Tidak ada output list ONU dari OLT.';
    } elseif (!isUsefulTelnetOutput($onuListOutput)) {
        $error = 'Semua kandidat command list ONU gagal. Coba command manual: ?, show ?, show onu ?, lalu update config/olt_profiles.json.';
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
        $commands = array_map(static function (string $command) use ($selectedMapping): string {
            return str_replace('{pon_onu}', $selectedMapping['pon_onu'], $command);
        }, splitOltCommands($olt['optical_command']));
        $telnet = new OltTelnet();
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring OLT - NetPoe Remote</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
        header { background: #ffffff; border-bottom: 1px solid #e5e7eb; }
        .topbar, main { width: min(100% - 32px, 1120px); margin: 0 auto; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 0; }
        h1 { margin: 0; font-size: 24px; line-height: 1.2; }
        .nav a { margin-left: 12px; color: #2563eb; font-weight: 700; text-decoration: none; font-size: 14px; }
        main { padding: 28px 0; }
        .layout { display: grid; grid-template-columns: 360px 1fr; gap: 18px; align-items: start; }
        .panel { padding: 22px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); }
        .alert { margin-bottom: 18px; padding: 11px 12px; border-radius: 6px; font-size: 14px; }
        .success { border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; }
        .error { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; }
        h2 { margin: 0 0 16px; font-size: 18px; }
        label { display: block; margin-bottom: 7px; font-weight: 700; font-size: 14px; }
        input, select { width: 100%; padding: 11px 12px; margin-bottom: 16px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: #ffffff; }
        .inline-form { display: flex; gap: 10px; align-items: end; margin-bottom: 18px; }
        .inline-form div { flex: 1; }
        .inline-form input { margin-bottom: 0; }
        button, .button { display: inline-block; padding: 11px 14px; border: 0; border-radius: 6px; background: #2563eb; color: #ffffff; font-weight: 700; text-decoration: none; cursor: pointer; }
        button:hover, .button:hover { background: #1d4ed8; }
        .meta { margin: 0 0 16px; color: #4b5563; font-size: 14px; line-height: 1.5; }
        .metric-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
        .metric { padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; }
        .metric span { display: block; color: #6b7280; font-size: 13px; }
        .metric strong { display: block; margin-top: 8px; font-size: 26px; }
        canvas { width: 100%; height: 240px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 10px 11px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        th { background: #f9fafb; color: #374151; }
        pre { overflow: auto; max-height: 260px; padding: 14px; border-radius: 8px; background: #111827; color: #e5e7eb; font-size: 12px; }
        @media (max-width: 900px) { .topbar { align-items: flex-start; flex-direction: column; } .layout { grid-template-columns: 1fr; } .nav a { display: inline-block; margin: 0 12px 8px 0; } .inline-form { align-items: stretch; flex-direction: column; } }
    </style>
</head>
<body>
    <header>
        <div class="topbar">
            <h1>Monitoring OLT</h1>
            <nav class="nav">
                <a href="dashboard.php">Dashboard</a>
                <a href="pppoe.php">PPPoE</a>
                <a href="olt_setting.php">Pengaturan OLT</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main>
        <?php if ($message !== ''): ?><div class="alert success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

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

                    <button type="submit" name="action" value="save_mapping">Simpan Mapping</button>
                </form>

                <table>
                    <thead><tr><th>PPPoE</th><th>PON/ONU</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <?php if ($mappings === []): ?>
                            <tr><td colspan="3">Belum ada mapping.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($mappings as $mapping): ?>
                            <tr>
                                <td><?= htmlspecialchars($mapping['pppoe_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($mapping['pon_onu'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><a class="button" href="?pppoe=<?= urlencode($mapping['pppoe_name']) ?>">Graph</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

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
                        <p class="meta">Command dipakai: <code><?= htmlspecialchars($onuListCommandUsed, ENT_QUOTES, 'UTF-8') ?></code></p>
                    <?php endif; ?>
                    <table>
                        <thead><tr><th>PON/ONU</th><th>Status / Data</th></tr></thead>
                        <tbody>
                            <?php if ($onuRows === []): ?>
                                <tr><td colspan="2">Output belum bisa diparse otomatis. Lihat Raw List ONU di bawah.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($onuRows as $onuRow): ?>
                                <tr>
                                    <td><?= htmlspecialchars($onuRow['pon_onu'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($onuRow['status'], ENT_QUOTES, 'UTF-8') ?></td>
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
    </main>

    <script>
        const tx = <?= json_encode($opticalData['tx'] ?? null) ?>;
        const rx = <?= json_encode($opticalData['rx'] ?? null) ?>;
        const canvas = document.getElementById('opticalChart');
        const ctx = canvas.getContext('2d');

        function drawChart(values) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.strokeStyle = '#e5e7eb';
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
                ctx.fillStyle = '#111827';
                ctx.font = 'bold 16px Arial';
                ctx.fillText(item.value === null ? '-' : `${item.value} dBm`, x, baseY - height - 10);
                ctx.fillStyle = '#374151';
                ctx.font = '14px Arial';
                ctx.fillText(item.label, x + 36, 244);
            });
        }

        drawChart([
            { label: 'TX', value: tx, color: '#2563eb' },
            { label: 'RX', value: rx, color: '#16a34a' },
        ]);
    </script>
</body>
</html>
