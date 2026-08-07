<?php
require 'config/database.php';
$sql = file_get_contents('database.sql');
$pdo->exec($sql);
echo "Tables created successfully.\n";
