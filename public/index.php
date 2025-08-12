<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use App\Database;
use App\Controllers\TeacherController;

use App\Controllers\DepartmentController;
use App\Controllers\SubjectController;
use App\Controllers\CalificationToTeacherController;

// Cargar configuración
$settings = require __DIR__ . '/../config/settings.php';

// Obtener conexión PDO
$pdo = Database::getConnection($settings['db']);

$app = AppFactory::create();

// Instancia del controlador con PDO
$teacherController = new TeacherController($pdo);
$departmentController = new DepartmentController($pdo);
$subjectController = new SubjectController($pdo);
$calificationController = new CalificationToTeacherController($pdo);

// Rutas
$app->get('/', function ($request, $response) {
    $response->getBody()->write("¡Bienvenido al API evaluadoc!");
    return $response;
});

//-- obtener los profesores
$app->get('/teachers', [$teacherController, 'getAll']);
$app->get('/teachers/{id}', [$teacherController, 'getById']);
$app->post('/teachers', [$teacherController, 'create']);
$app->put('/teachers/{id}', [$teacherController, 'update']);
$app->delete('/teachers/{id}', [$teacherController, 'delete']);

$app->get('/departments', [$departmentController, 'getAll']);
$app->get('/subjects', [$subjectController, 'getAll']);
$app->get('/califications', [$calificationController, 'getAll']);

$app->run();
