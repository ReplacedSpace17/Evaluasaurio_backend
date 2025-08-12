<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Alumno;

class AlumnoController
{
    private Alumno $model;

    public function __construct(Alumno $model)
    {
        $this->model = $model;
    }

    public function listar(Request $request, Response $response): Response
    {
        $alumnos = $this->model->obtenerTodos();

        $payload = json_encode($alumnos, JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
