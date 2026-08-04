<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/RouterosAPI.php';

checkUser();

$userId = (int) $_SESSION['user_id'];
$error = '';
$activeClients = [];
$search = trim($_GET['search'] ?? '');

$stmt = $pdo->prepare(
    'SELECT id, ip_address, api_user, api_pass, api_port FROM routers WHERE user_id = :user_id ORDER BY id ASC LIMIT 1'
);
$stmt->execute(['user_id' => $userId]);
$router = $stmt->fetch();

if (!$router) {
    $error = 'Pengaturan router belum tersedia. Silakan isi pengaturan router terlebih dahulu.';
} else {
    $api = new RouterosAPI();
    $api->timeout = 5;

    try {
        if ($api->connect($router['ip_address'], $router['api_user'], $router['api_pass'], (int) $router['api_port'])) {
            $response = $api->comm('/ppp/active/print');

            foreach ($response as $item) {
                if (isset($item['!re'])) {
                    $activeClients[] = $item;
                }
            }

            $api->disconnect();
        } else {
            $error = 'Gagal terhubung ke API MikroTik: ' . ($api->error ?? 'Periksa pengaturan router.');
        }
    } catch (Throwable $exception) {
        $api->disconnect();
        $error = 'Gagal mengambil data PPPoE: ' . $exception->getMessage();
    }
}

if ($search !== '') {
    $keyword = strtolower($search);
    $activeClients = array_values(array_filter($activeClients, static function (array $client) use ($keyword): bool {
        $haystack = implode(' ', [
            $client['name'] ?? '',
            $client['service'] ?? '',
            $client['caller-id'] ?? '',
            $client['address'] ?? '',
            $client['uptime'] ?? '',
        ]);

        return str_contains(strtolower($haystack), $keyword);
    }));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPPoE Active - NetPoe Remote</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #1f2937;
        }

        header {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
        }

        .topbar,
        main {
            width: min(100% - 32px, 1120px);
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 0;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.2;
        }

        .nav a {
            margin-left: 12px;
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
            font-size: 14px;
        }

        main {
            padding: 28px 0;
        }

        .panel {
            padding: 22px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .alert {
            margin-bottom: 18px;
            padding: 11px 12px;
            border: 1px solid #fecaca;
            border-radius: 6px;
            background: #fef2f2;
            color: #991b1b;
            font-size: 14px;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .search-box {
            width: min(100%, 360px);
        }

        .search-box label {
            display: block;
            margin-bottom: 7px;
            color: #374151;
            font-weight: 700;
            font-size: 14px;
        }

        .search-box input {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        .result-count {
            color: #6b7280;
            font-size: 14px;
        }

        .search-button {
            align-self: end;
            padding: 11px 14px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            cursor: pointer;
        }

        .search-button:hover {
            background: #1d4ed8;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 13px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 14px;
            vertical-align: middle;
        }

        th {
            background: #f9fafb;
            color: #374151;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .remote-link {
            display: inline-block;
            padding: 9px 11px;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .remote-link:hover {
            background: #1d4ed8;
        }

        .empty {
            color: #6b7280;
            text-align: center;
        }

        @media (max-width: 760px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .nav a {
                display: inline-block;
                margin: 0 12px 8px 0;
            }

            .search-box {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="topbar">
            <h1>PPPoE Client Active</h1>
            <nav class="nav">
                <a href="dashboard.php">Dashboard</a>
                <a href="router_setting.php">Router</a>
                <a href="olt_monitor.php">OLT</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="panel">
            <?php if ($error !== ''): ?>
                <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

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
                <button class="search-button" type="submit">Cari</button>
                <div class="result-count" id="pppoe-result-count">
                    Total: <?= count($activeClients) ?> client
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Service</th>
                            <th>Caller ID</th>
                            <th>Address</th>
                            <th>Uptime</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="pppoe-table-body">
                        <?php if ($activeClients === []): ?>
                            <tr class="pppoe-row">
                                <td class="empty" colspan="6">Tidak ada PPPoE client active.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($activeClients as $client): ?>
                            <?php $clientIp = $client['address'] ?? ''; ?>
                            <tr class="pppoe-row">
                                <td><?= htmlspecialchars($client['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($client['service'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($client['caller-id'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($clientIp !== '' ? $clientIp : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($client['uptime'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($clientIp !== ''): ?>
                                        <a
                                            class="remote-link"
                                            href="remote_action.php?ip=<?= urlencode($clientIp) ?>"
                                            target="_blank"
                                            rel="noopener"
                                        >🔗 Remote Modem</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
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
    </main>
    <script>
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

                if (isMatch) {
                    visibleCount += 1;
                }
            });

            if (emptyRow) {
                emptyRow.style.display = rows.length > 0 && visibleCount === 0 ? '' : 'none';
            }

            resultCount.textContent = `Total: ${visibleCount} client`;
        }

        searchInput.addEventListener('input', filterPppoeRows);
    </script>
</body>
</html>
