<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/RouterosAPI.php';

checkUser();

set_time_limit(20);

function findRouterosTrap(array $response): ?string
{
    foreach ($response as $item) {
        if (isset($item['!trap'])) {
            return $item['message'] ?? 'RouterOS API mengembalikan error.';
        }
    }

    return null;
}

$remoteIp = trim($_GET['ip'] ?? '');

if (!filter_var($remoteIp, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    exit('IP remote modem tidak valid.');
}

$stmt = $pdo->prepare(
    'SELECT ip_address, api_user, api_pass, api_port, public_host, remote_port, remote_nat_comment
     FROM routers
     WHERE user_id = :user_id
     ORDER BY id ASC
     LIMIT 1'
);
$stmt->execute(['user_id' => (int) $_SESSION['user_id']]);
$router = $stmt->fetch();

if (!$router) {
    http_response_code(404);
    exit('Pengaturan router belum tersedia.');
}

$api = new RouterosAPI();
$api->timeout = 5;

try {
    if (!$api->connect($router['ip_address'], $router['api_user'], $router['api_pass'], (int) $router['api_port'])) {
        http_response_code(502);
        exit('Gagal terhubung ke API MikroTik: ' . htmlspecialchars($api->error ?? 'Periksa pengaturan router.', ENT_QUOTES, 'UTF-8'));
    }

    $rules = $api->comm('/ip/firewall/nat/print', [
        '?comment' => $router['remote_nat_comment'],
        '.proplist' => '.id,comment,to-addresses',
    ]);

    $trap = findRouterosTrap($rules);

    if ($trap !== null) {
        $api->disconnect();
        http_response_code(502);
        exit('Gagal mencari rule NAT: ' . htmlspecialchars($trap, ENT_QUOTES, 'UTF-8'));
    }

    $natRuleId = null;

    foreach ($rules as $rule) {
        if (isset($rule['!re'], $rule['.id'])) {
            $natRuleId = $rule['.id'];
            break;
        }
    }

    if ($natRuleId === null) {
        $api->disconnect();
        http_response_code(404);
        exit('Rule NAT dengan comment ' . htmlspecialchars($router['remote_nat_comment'], ENT_QUOTES, 'UTF-8') . ' tidak ditemukan.');
    }

    $setResponse = $api->comm('/ip/firewall/nat/set', [
        '.id' => $natRuleId,
        'to-addresses' => $remoteIp,
    ]);

    $trap = findRouterosTrap($setResponse);

    if ($trap !== null) {
        $api->disconnect();
        http_response_code(502);
        exit('Gagal update rule NAT: ' . htmlspecialchars($trap, ENT_QUOTES, 'UTF-8'));
    }

    $api->disconnect();
} catch (Throwable $exception) {
    $api->disconnect();
    http_response_code(500);
    exit('Gagal mengubah rule NAT: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$redirectHost = trim((string) ($router['public_host'] ?? ''));

if ($redirectHost === '') {
    $redirectHost = $router['ip_address'];
}

$redirectHost = preg_replace('#^https?://#i', '', $redirectHost);
$redirectHost = rtrim($redirectHost, '/');

$targetUrl = 'http://' . $redirectHost . ':' . (int) $router['remote_port'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membuka Remote Modem</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #1f2937;
        }

        main {
            width: min(100% - 32px, 480px);
            padding: 28px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 22px;
            line-height: 1.2;
        }

        p {
            margin: 0 0 18px;
            color: #4b5563;
            line-height: 1.5;
        }

        code {
            word-break: break-all;
        }

        a {
            display: inline-block;
            padding: 11px 14px;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main>
        <h1>NAT berhasil diupdate</h1>
        <p>Remote modem diarahkan ke IP client <strong><?= htmlspecialchars($remoteIp, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
        <p>Membuka: <code><?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?></code></p>
        <a href="<?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?>">Buka Remote Modem</a>
    </main>
    <script>
        setTimeout(() => {
            window.location.href = <?= json_encode($targetUrl, JSON_UNESCAPED_SLASHES) ?>;
        }, 700);
    </script>
</body>
</html>
