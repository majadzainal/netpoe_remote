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
$pageTitle  = 'Remote Modem';
$activePage = '';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.remote-card {
    max-width: 480px;
    margin: 80px auto;
    background: var(--clr-surface);
    border: 1px solid rgba(99,102,241,0.3);
    border-radius: 18px;
    padding: 36px 32px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    text-align: center;
}
.success-icon {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg,#10b981,#059669);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px;
    margin: 0 auto 20px;
    box-shadow: 0 4px 18px rgba(16,185,129,0.35);
}
.remote-card h1 { font-size: 20px; margin-bottom: 12px; }
.remote-card p { color: var(--clr-muted); font-size: 14px; line-height: 1.6; margin-bottom: 10px; }
.remote-card code { color: #a5b4fc; }
.btn-open {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 24px; border-radius: 10px;
    background: linear-gradient(135deg,#6366f1,#8b5cf6);
    color: #fff; font-weight: 700; text-decoration: none;
    font-size: 14px; margin-top: 16px;
    box-shadow: 0 4px 14px rgba(99,102,241,0.35);
    transition: transform .15s, opacity .15s;
}
.btn-open:hover { transform: translateY(-2px); opacity: .92; }
.countdown { font-size: 12px; color: var(--clr-muted); margin-top: 12px; }
</style>
<div class="remote-card">
    <div class="success-icon">✓</div>
    <h1>NAT Berhasil Diupdate</h1>
    <p>Remote modem diarahkan ke IP client <strong><?= htmlspecialchars($remoteIp, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
    <p>Membuka: <code><?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?></code></p>
    <a class="btn-open" href="<?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?>">🔗 Buka Remote Modem</a>
    <p class="countdown" id="countdown">Otomatis redirect dalam <span id="secs">3</span> detik…</p>
</div>
<script>
    let n = 3;
    const el = document.getElementById('secs');
    const t = setInterval(() => {
        n--;
        el.textContent = n;
        if (n <= 0) {
            clearInterval(t);
            window.location.href = <?= json_encode($targetUrl, JSON_UNESCAPED_SLASHES) ?>;
        }
    }, 1000);
</script>
</body>
</html>
