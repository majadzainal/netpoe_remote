<?php

declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';

checkUser();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard User - NetPoe Remote</title>
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
            width: min(100% - 32px, 520px);
            padding: 28px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
            line-height: 1.2;
        }

        p {
            margin: 0 0 22px;
            color: #6b7280;
        }

        a {
            display: inline-block;
            margin-right: 10px;
            padding: 11px 14px;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
        }

        .logout {
            background: #374151;
        }
    </style>
</head>
<body>
    <main>
        <h1>Dashboard User</h1>
        <p>Selamat datang, <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?>.</p>
        <a href="pppoe.php">PPPoE Active</a>
        <a href="router_setting.php">Pengaturan Router</a>
        <a class="logout" href="../logout.php">Logout</a>
    </main>
</body>
</html>
