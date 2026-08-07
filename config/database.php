<?php

declare(strict_types=1);

$dbHost = '127.0.0.1';
$dbPort = 3307;
$dbName = 'netpoe_remote';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

date_default_timezone_set('Asia/Jakarta');

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}


/*
 * Gunakan prepared statement untuk seluruh query database.
 *
 * Contoh:
 * $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
 * $stmt->execute(['username' => $username]);
 * $user = $stmt->fetch();
 */
