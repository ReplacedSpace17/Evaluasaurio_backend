<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Cargar variables de entorno desde .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

return [
    'email' => [
        'host' => $_ENV['EMAIL_HOST'],
        'port' => $_ENV['EMAIL_PORT'],
        'user' => $_ENV['EMAIL_USERNAME'],
        'pass' => $_ENV['EMAIL_PASSWORD'],
    ]
];
