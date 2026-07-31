<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/database.php';

checkSuperUser();

$message = '';
$error = '';
$allowedRoles = ['superuser', 'user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';

        if ($username === '' || $password === '' || !in_array($role, $allowedRoles, true)) {
            $error = 'Username, password, dan role wajib diisi dengan benar.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
            $stmt->execute(['username' => $username]);
            $usernameExists = (int) $stmt->fetchColumn() > 0;

            if ($usernameExists) {
                $error = 'Username sudah digunakan.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password, role) VALUES (:username, :password, :role)'
                );
                $stmt->execute([
                    'username' => $username,
                    'password' => $hashedPassword,
                    'role' => $role,
                ]);

                $message = 'User baru berhasil ditambahkan.';
            }
        }
    }

    if ($action === 'delete') {
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            $error = 'User tidak valid.';
        } elseif ($userId === (int) $_SESSION['user_id']) {
            $error = 'Anda tidak bisa menghapus akun yang sedang digunakan.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);

            $message = 'User berhasil dihapus.';
        }
    }
}

$stmt = $pdo->prepare('SELECT id, username, role, created_at FROM users ORDER BY id ASC');
$stmt->execute();
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen User - NetPoe Remote</title>
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
            width: min(100% - 32px, 1080px);
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

        .account {
            color: #6b7280;
            font-size: 14px;
        }

        .logout {
            display: inline-block;
            margin-left: 12px;
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        main {
            padding: 28px 0;
        }

        .panel {
            margin-bottom: 24px;
            padding: 22px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        h2 {
            margin: 0 0 18px;
            font-size: 18px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            align-items: end;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 700;
            font-size: 14px;
        }

        input,
        select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            background: #ffffff;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        button {
            padding: 11px 14px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }

        .delete-button {
            background: #dc2626;
        }

        .delete-button:hover:not(:disabled) {
            background: #b91c1c;
        }

        .alert {
            margin-bottom: 18px;
            padding: 11px 12px;
            border-radius: 6px;
            font-size: 14px;
        }

        .success {
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
        }

        .error {
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
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

        .role {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 12px;
            font-weight: 700;
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

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="topbar">
            <h1>Manajemen User</h1>
            <div class="account">
                <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?>
                <a class="logout" href="../logout.php">Logout</a>
            </div>
        </div>
    </header>

    <main>
        <?php if ($message !== ''): ?>
            <div class="alert success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="panel">
            <h2>Tambah User</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div>
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" autocomplete="username" required>
                    </div>

                    <div>
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" autocomplete="new-password" required>
                    </div>

                    <div>
                        <label for="role">Role</label>
                        <select id="role" name="role" required>
                            <option value="user">user</option>
                            <option value="superuser">superuser</option>
                        </select>
                    </div>

                    <button type="submit">Tambah User</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Daftar User</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Dibuat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users === []): ?>
                            <tr>
                                <td class="empty" colspan="5">Belum ada user.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= (int) $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="role"><?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td><?= htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <form method="post" action="" onsubmit="return confirm('Hapus user ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <button
                                            class="delete-button"
                                            type="submit"
                                            <?= (int) $user['id'] === (int) $_SESSION['user_id'] ? 'disabled' : '' ?>
                                        >
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
