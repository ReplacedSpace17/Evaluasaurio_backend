<?php
$settings = require __DIR__ . '/config/settings.php';

$host = $settings['db']['host'];
$db   = $settings['db']['dbname'];
$user = $settings['db']['user'];
$pass = $settings['db']['pass'];
$charset = $settings['db']['charset'];

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo "Error de conexión: " . $e->getMessage();
    exit;
}
