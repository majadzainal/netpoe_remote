<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/RouterosAPI.php';

checkUser();

$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';
$testMessage = '';
$testSuccess = false;

$stmt = $pdo->prepare(
    'SELECT id, ip_address, api_user, api_pass, api_port, public_host, remote_port, remote_nat_comment
     FROM routers
     WHERE user_id = :user_id
     ORDER BY id ASC
     LIMIT 1'
);
$stmt->execute(['user_id' => $userId]);
$router = $stmt->fetch();

$ipAddress = $router['ip_address'] ?? '';
$apiUser = $router['api_user'] ?? '';
$apiPass = $router['api_pass'] ?? '';
$apiPort = (string) ($router['api_port'] ?? 8728);
$publicHost = $router['public_host'] ?? '';
$remotePort = (string) ($router['remote_port'] ?? 8080);
$remoteNatComment = $router['remote_nat_comment'] ?? 'DYNAMIC_REMOTE_MODEM';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ipAddress = trim($_POST['ip_address'] ?? '');
    $apiUser = trim($_POST['api_user'] ?? '');
    $apiPass = $_POST['api_pass'] ?? '';
    $apiPort = trim($_POST['api_port'] ?? '8728');
    $publicHost = trim($_POST['public_host'] ?? '');
    $remotePort = trim($_POST['remote_port'] ?? '8080');
    $remoteNatComment = trim($_POST['remote_nat_comment'] ?? 'DYNAMIC_REMOTE_MODEM');
    $apiPortNumber = filter_var($apiPort, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $remotePortNumber = filter_var($remotePort, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);

    if (
        $ipAddress === ''
        || $apiUser === ''
        || $apiPass === ''
        || $remoteNatComment === ''
        || $apiPortNumber === false
        || $remotePortNumber === false
    ) {
        $error = 'IP Address, API User, API Password, API Port, Port Remote, dan Comment NAT wajib diisi dengan benar.';
    } elseif ($action === 'save') {
        if ($router) {
            $stmt = $pdo->prepare(
                'UPDATE routers
                 SET router_name = :router_name,
                     ip_address = :ip_address,
                     api_user = :api_user,
                     api_pass = :api_pass,
                     api_port = :api_port,
                     public_host = :public_host,
                     remote_port = :remote_port,
                     remote_nat_comment = :remote_nat_comment
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'router_name' => 'Router MikroTik',
                'ip_address' => $ipAddress,
                'api_user' => $apiUser,
                'api_pass' => $apiPass,
                'api_port' => $apiPortNumber,
                'public_host' => $publicHost !== '' ? $publicHost : null,
                'remote_port' => $remotePortNumber,
                'remote_nat_comment' => $remoteNatComment,
                'id' => (int) $router['id'],
                'user_id' => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO routers
                    (user_id, router_name, ip_address, api_user, api_pass, api_port, public_host, remote_port, remote_nat_comment)
                 VALUES
                    (:user_id, :router_name, :ip_address, :api_user, :api_pass, :api_port, :public_host, :remote_port, :remote_nat_comment)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'router_name' => 'Router MikroTik',
                'ip_address' => $ipAddress,
                'api_user' => $apiUser,
                'api_pass' => $apiPass,
                'api_port' => $apiPortNumber,
                'public_host' => $publicHost !== '' ? $publicHost : null,
                'remote_port' => $remotePortNumber,
                'remote_nat_comment' => $remoteNatComment,
            ]);
        }

        $message = 'Pengaturan router berhasil disimpan.';
    } elseif ($action === 'test') {
        $api = new RouterosAPI();
        $api->timeout = 5;

        try {
            $testSuccess = $api->connect($ipAddress, $apiUser, $apiPass, $apiPortNumber);
            $api->disconnect();
        } catch (Throwable $exception) {
            $testSuccess = false;
            $api->error = $exception->getMessage();
        }

        if ($testSuccess) {
            $testMessage = 'Koneksi API MikroTik berhasil.';
        } else {
            $testMessage = 'Koneksi API MikroTik gagal: ' . ($api->error ?? 'Periksa IP, port, username, password, dan service API MikroTik.');
        }
    }
}
$pageTitle  = 'Pengaturan Router';
$activePage = 'router';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.panel { padding: 28px; background: var(--clr-surface); border: 1px solid var(--clr-border); border-radius: var(--radius); box-shadow: var(--shadow); max-width: 720px; }
.actions { display: flex; gap: 12px; flex-wrap: wrap; }
.secondary { background: rgba(255,255,255,0.08) !important; border: 1px solid var(--clr-border) !important; }
.info { background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.25); color: #93c5fd; border-radius: 8px; padding: 12px 16px; font-size: 13px; margin-bottom: 18px; }
</style>
<div class="page-wrap">
<p class="page-heading">Pengaturan Router</p>
<p class="page-sub">Konfigurasi koneksi API MikroTik untuk manajemen router.</p>
        <section class="panel">

            <?php if ($message !== ''): ?>
                <div class="alert success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($testMessage !== ''): ?>
                <div class="alert <?= $testSuccess ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($testMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <label for="ip_address">IP Address</label>
                <input
                    type="text"
                    id="ip_address"
                    name="ip_address"
                    value="<?= htmlspecialchars($ipAddress, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="192.168.88.1"
                    required
                >

                <label for="api_user">API User</label>
                <input
                    type="text"
                    id="api_user"
                    name="api_user"
                    value="<?= htmlspecialchars($apiUser, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    required
                >

                <label for="api_pass">API Password</label>
                <input
                    type="password"
                    id="api_pass"
                    name="api_pass"
                    value="<?= htmlspecialchars($apiPass, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="current-password"
                    required
                >

                <label for="api_port">API Port</label>
                <input
                    type="number"
                    id="api_port"
                    name="api_port"
                    value="<?= htmlspecialchars($apiPort, ENT_QUOTES, 'UTF-8') ?>"
                    min="1"
                    max="65535"
                    required
                >

                <label for="public_host">IP/Host Public Router</label>
                <input
                    type="text"
                    id="public_host"
                    name="public_host"
                    value="<?= htmlspecialchars($publicHost, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="contoh: 203.0.113.10 atau router.example.com"
                >

                <label for="remote_port">Port Remote Modem</label>
                <input
                    type="number"
                    id="remote_port"
                    name="remote_port"
                    value="<?= htmlspecialchars($remotePort, ENT_QUOTES, 'UTF-8') ?>"
                    min="1"
                    max="65535"
                    required
                >

                <label for="remote_nat_comment">Comment NAT Remote Modem</label>
                <input
                    type="text"
                    id="remote_nat_comment"
                    name="remote_nat_comment"
                    value="<?= htmlspecialchars($remoteNatComment, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >

                <div class="actions">
                    <button type="submit" name="action" value="save">Simpan Pengaturan</button>
                    <button class="secondary" type="submit" name="action" value="test">Test Connection</button>
                </div>
            </form>
        </section>
</div>
</body>
</html>
