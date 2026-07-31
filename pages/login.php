<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    if ($_SESSION['role'] === 'superuser') {
        header('Location: superuser/users.php');
        exit;
    }

    if ($_SESSION['role'] === 'user') {
        header('Location: user/dashboard.php');
        exit;
    }
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] === 'superuser') {
                header('Location: superuser/users.php');
                exit;
            }

            header('Location: user/dashboard.php');
            exit;
        }

        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - NetPoe Remote</title>
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

        .login-box {
            width: min(100% - 32px, 380px);
            padding: 28px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        h1 {
            margin: 0 0 6px;
            font-size: 24px;
            line-height: 1.2;
        }

        .subtitle {
            margin: 0 0 24px;
            color: #6b7280;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px 13px;
            margin-bottom: 16px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        .error {
            margin-bottom: 16px;
            padding: 11px 12px;
            border: 1px solid #fecaca;
            border-radius: 6px;
            background: #fef2f2;
            color: #991b1b;
            font-size: 14px;
        }

        button {
            width: 100%;
            padding: 12px 14px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>
    <main class="login-box">
        <h1>NetPoe Remote</h1>
        <p class="subtitle">Masuk untuk mengelola router Anda.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="username"
                required
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Login</button>
        </form>
    </main>
</body>
</html>
