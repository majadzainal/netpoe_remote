<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    if ($_SESSION['role'] === 'superuser') {
        header('Location: pages/superuser/users.php');
        exit;
    }

    if ($_SESSION['role'] === 'user') {
        header('Location: pages/user/dashboard.php');
        exit;
    }
}

header('Location: pages/login.php');
exit;
