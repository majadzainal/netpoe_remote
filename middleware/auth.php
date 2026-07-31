<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function appBaseUrl(): string
{
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $pagesPosition = strpos($scriptDir, '/pages');

    if ($pagesPosition !== false) {
        $scriptDir = substr($scriptDir, 0, $pagesPosition);
    }

    return rtrim($scriptDir, '/');
}

function redirectTo(string $path): never
{
    header('Location: ' . appBaseUrl() . '/' . ltrim($path, '/'));
    exit;
}

function checkAuth(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['username']) || empty($_SESSION['role'])) {
        redirectTo('pages/login.php');
    }
}

function checkSuperUser(): void
{
    checkAuth();

    if ($_SESSION['role'] !== 'superuser') {
        redirectTo('pages/login.php');
    }
}

function checkUser(): void
{
    checkAuth();

    if ($_SESSION['role'] !== 'user') {
        redirectTo('pages/login.php');
    }
}
