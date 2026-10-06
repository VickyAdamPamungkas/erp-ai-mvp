<?php
$host = '127.0.0.1';
$port = '3307'; // Port disesuaikan dengan XAMPP Anda
$db   = 'erp_ai_mvp';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Parameter port=$port ditambahkan ke dalam DSN
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    exit('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
?>