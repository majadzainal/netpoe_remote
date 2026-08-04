<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/OltTelnet.php';
require_once __DIR__ . '/../../libs/OltProfiles.php';

checkUser();

$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';
$testMessage = '';
$testSuccess = false;
$profiles = loadOltProfiles();

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

$stmt = $pdo->prepare(
    'SELECT id, brand, model, olt_name, ip_address, telnet_user, telnet_pass, telnet_port, pon_port_count, optical_command, onu_list_command
     FROM olts
     WHERE user_id = :user_id
     ORDER BY id ASC
     LIMIT 1'
);
$stmt->execute(['user_id' => $userId]);
$olt = $stmt->fetch();

$brand = $olt['brand'] ?? 'Hioso';
$model = $olt['model'] ?? '4P1GM DC Turbo';
$oltName = $olt['olt_name'] ?? 'OLT Utama';
$ipAddress = $olt['ip_address'] ?? '202.47.185.158';
$telnetUser = $olt['telnet_user'] ?? 'admin';
$telnetPass = $olt['telnet_pass'] ?? 'impjtm2024';
$telnetPort = (string) ($olt['telnet_port'] ?? 8523);
$ponPortCount = (string) ($olt['pon_port_count'] ?? 4);
$activeProfile = findOltProfile($brand, $model);
$opticalCommand = $olt['optical_command'] ?? implode("\n", getOltProfileCommands($activeProfile, 'optical_power', 'enable | show onu optical-ddm {pon_onu}'));
$onuListCommand = $olt['onu_list_command'] ?? implode("\n", getOltProfileCommands($activeProfile, 'onu_list', 'show onu'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $profileKey = trim($_POST['profile_key'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');

    if ($profileKey !== '') {
        foreach ($profiles as $profile) {
            $key = strtolower((string) ($profile['brand'] ?? '') . '|' . (string) ($profile['model'] ?? ''));

            if ($key === $profileKey) {
                $brand = (string) $profile['brand'];
                $model = (string) $profile['model'];
                break;
            }
        }
    }

    $selectedProfile = findOltProfile($brand, $model);
    $oltName = trim($_POST['olt_name'] ?? '');
    $ipAddress = trim($_POST['ip_address'] ?? '');
    $telnetUser = trim($_POST['telnet_user'] ?? '');
    $telnetPass = $_POST['telnet_pass'] ?? '';
    $telnetPort = trim($_POST['telnet_port'] ?? '23');
    $ponPortCount = trim($_POST['pon_port_count'] ?? '4');
    $opticalCommand = implode("\n", getOltProfileCommands($selectedProfile, 'optical_power', trim($_POST['optical_command'] ?? '')));
    $onuListCommand = implode("\n", getOltProfileCommands($selectedProfile, 'onu_list', trim($_POST['onu_list_command'] ?? '')));
    $testCommand = getOltProfileCommand($selectedProfile, 'test', 'show version');
    $telnetPortNumber = filter_var($telnetPort, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $ponPortCountNumber = filter_var($ponPortCount, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 16],
    ]);

    if (
        $brand === ''
        || $model === ''
        || $oltName === ''
        || $ipAddress === ''
        || $telnetUser === ''
        || $telnetPass === ''
        || $opticalCommand === ''
        || $onuListCommand === ''
        || $telnetPortNumber === false
        || $ponPortCountNumber === false
    ) {
        $error = 'Profil OLT, nama OLT, IP, user, password, port, jumlah PON port, command ONU, dan command optical wajib diisi.';
    } elseif ($action === 'save') {
        if ($olt) {
            $stmt = $pdo->prepare(
                'UPDATE olts
                 SET brand = :brand,
                     model = :model,
                     olt_name = :olt_name,
                     ip_address = :ip_address,
                     telnet_user = :telnet_user,
                     telnet_pass = :telnet_pass,
                     telnet_port = :telnet_port,
                     pon_port_count = :pon_port_count,
                     optical_command = :optical_command,
                     onu_list_command = :onu_list_command
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'brand' => $brand,
                'model' => $model,
                'olt_name' => $oltName,
                'ip_address' => $ipAddress,
                'telnet_user' => $telnetUser,
                'telnet_pass' => $telnetPass,
                'telnet_port' => $telnetPortNumber,
                'pon_port_count' => $ponPortCountNumber,
                'optical_command' => $opticalCommand,
                'onu_list_command' => $onuListCommand,
                'id' => (int) $olt['id'],
                'user_id' => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO olts
                    (user_id, brand, model, olt_name, ip_address, telnet_user, telnet_pass, telnet_port, pon_port_count, optical_command, onu_list_command)
                 VALUES
                    (:user_id, :brand, :model, :olt_name, :ip_address, :telnet_user, :telnet_pass, :telnet_port, :pon_port_count, :optical_command, :onu_list_command)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'brand' => $brand,
                'model' => $model,
                'olt_name' => $oltName,
                'ip_address' => $ipAddress,
                'telnet_user' => $telnetUser,
                'telnet_pass' => $telnetPass,
                'telnet_port' => $telnetPortNumber,
                'pon_port_count' => $ponPortCountNumber,
                'optical_command' => $opticalCommand,
                'onu_list_command' => $onuListCommand,
            ]);
        }

        $message = 'Pengaturan OLT berhasil disimpan.';
    } elseif ($action === 'test') {
        $telnet = new OltTelnet();
        $output = $telnet->runCommand($ipAddress, $telnetPortNumber, $telnetUser, $telnetPass, $testCommand, 6);
        $testSuccess = $output !== '';
        $testMessage = $testSuccess ? 'Koneksi telnet OLT berhasil.' : $telnet->getError();
    }
}
$pageTitle  = 'Pengaturan OLT';
$activePage = 'olt_setting';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.panel { padding: 28px; background: var(--clr-surface); border: 1px solid var(--clr-border); border-radius: var(--radius); box-shadow: var(--shadow); max-width: 820px; }
.panel h2 { font-size:17px; margin-bottom:20px; }
.actions { display: flex; gap: 12px; flex-wrap: wrap; }
.secondary { background: rgba(255,255,255,0.08) !important; border: 1px solid var(--clr-border) !important; }
</style>
<div class="page-wrap">
<p class="page-heading">Pengaturan OLT</p>
<p class="page-sub">Konfigurasi koneksi telnet, profil OLT, dan command.</p>
<?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <section class="panel">
            <?php if ($testMessage !== ''): ?><div class="alert <?= $testSuccess ? 'success' : 'error' ?>"><?= htmlspecialchars($testMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <form method="post" action="">
                <label for="profile_key">Profil Merek dan Model OLT</label>
                <select id="profile_key" name="profile_key" required>
                    <?php foreach ($profiles as $profile): ?>
                        <?php
                        $profileBrand = (string) ($profile['brand'] ?? '');
                        $profileModel = (string) ($profile['model'] ?? '');
                        $profileKey = strtolower($profileBrand . '|' . $profileModel);
                        $currentProfileKey = strtolower($brand . '|' . $model);
                        ?>
                        <option value="<?= htmlspecialchars($profileKey, ENT_QUOTES, 'UTF-8') ?>" <?= $profileKey === $currentProfileKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($profile['label'] ?? ($profileBrand . ' ' . $profileModel)), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="grid">
                    <div>
                        <label for="brand">Merek OLT</label>
                        <input type="text" id="brand" name="brand" value="<?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?>" readonly required>
                    </div>
                    <div>
                        <label for="model">Model OLT</label>
                        <input type="text" id="model" name="model" value="<?= htmlspecialchars($model, ENT_QUOTES, 'UTF-8') ?>" readonly required>
                    </div>
                </div>

                <label for="olt_name">Nama OLT</label>
                <input type="text" id="olt_name" name="olt_name" value="<?= htmlspecialchars($oltName, ENT_QUOTES, 'UTF-8') ?>" required>

                <div class="grid">
                    <div>
                        <label for="ip_address">IP Address</label>
                        <input type="text" id="ip_address" name="ip_address" value="<?= htmlspecialchars($ipAddress, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div>
                        <label for="telnet_port">Port Telnet</label>
                        <input type="number" id="telnet_port" name="telnet_port" value="<?= htmlspecialchars($telnetPort, ENT_QUOTES, 'UTF-8') ?>" min="1" max="65535" required>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="telnet_user">User Telnet</label>
                        <input type="text" id="telnet_user" name="telnet_user" value="<?= htmlspecialchars($telnetUser, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
                    </div>
                    <div>
                        <label for="telnet_pass">Password Telnet</label>
                        <input type="password" id="telnet_pass" name="telnet_pass" value="<?= htmlspecialchars($telnetPass, ENT_QUOTES, 'UTF-8') ?>" autocomplete="current-password" required>
                    </div>
                </div>

                <div>
                    <label for="pon_port_count">Jumlah PON Port (EPON)</label>
                    <input type="number" id="pon_port_count" name="pon_port_count" value="<?= htmlspecialchars($ponPortCount, ENT_QUOTES, 'UTF-8') ?>" min="1" max="16" required>
                    <p class="hint">Jumlah port EPON pada OLT Anda. Sistem akan menjalankan <code>show onu info epon 0/1 all</code> sampai <code>show onu info epon 0/N all</code> saat Ambil List ONU.</p>
                </div>

                <label for="onu_list_command">Command List ONU</label>
                <textarea id="onu_list_command" name="onu_list_command" readonly required><?= htmlspecialchars($onuListCommand, ENT_QUOTES, 'UTF-8') ?></textarea>

                <label for="optical_command">Command Optical Power</label>
                <textarea id="optical_command" name="optical_command" readonly required><?= htmlspecialchars($opticalCommand, ENT_QUOTES, 'UTF-8') ?></textarea>
                <p class="hint">Command diambil otomatis dari config/olt_profiles.json sesuai profil merek dan model.</p>

                <div class="actions">
                    <button type="submit" name="action" value="save">Simpan Pengaturan</button>
                    <button class="secondary" type="submit" name="action" value="test">Test Telnet</button>
                </div>
            </form>
        </section>
</div>
    <script>
        const profiles = <?= json_encode($profiles, JSON_UNESCAPED_SLASHES) ?>;
        const profileSelect = document.getElementById('profile_key');
        const brandInput = document.getElementById('brand');
        const modelInput = document.getElementById('model');
        const onuListCommandInput = document.getElementById('onu_list_command');
        const opticalCommandInput = document.getElementById('optical_command');

        function applyProfile() {
            const selected = profiles.find((profile) => `${profile.brand}|${profile.model}`.toLowerCase() === profileSelect.value);

            if (!selected) {
                return;
            }

            brandInput.value = selected.brand;
            modelInput.value = selected.model;
            onuListCommandInput.value = Array.isArray(selected.commands.onu_list) ? selected.commands.onu_list.join("\n") : (selected.commands.onu_list || '');
            opticalCommandInput.value = Array.isArray(selected.commands.optical_power) ? selected.commands.optical_power.join("\n") : (selected.commands.optical_power || '');
        }

        profileSelect.addEventListener('change', applyProfile);
    </script>
</body>
</html>
