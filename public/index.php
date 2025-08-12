<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../connection.php';

use Slim\Factory\AppFactory;
use App\Controllers\AlumnoController;
use App\Models\Alumno;

$app = AppFactory::create();

// Instancia modelo y controlador con la conexión PDO
$model = new Alumno($pdo);
$controller = new AlumnoController($model);

// Ruta principal simple
$app->get('/', function ($request, $response) {
    $response->getBody()->write("¡Hola desde Slim Framework organizado!");
    return $response;
});

// Ruta para obtener alumnos (delegada al controlador)
$app->get('/usuarios', [$controller, 'listar']);

$app->run();
