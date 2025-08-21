<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class TeacherRequestController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Obtener todas las solicitudes
    public function getAll(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT * FROM teacher_requests ORDER BY created_at DESC");
        $requests = $stmt->fetchAll();
        $payload = json_encode($requests, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

public function create(Request $request, Response $response): Response
{
    $data = json_decode($request->getBody()->getContents(), true);

    try {
        $stmt = $this->pdo->prepare("
            INSERT INTO teacher_requests (name, apellido_paterno, apellido_materno, sexo, department_id, email)
            VALUES (:name, :apellido_paterno, :apellido_materno, :sexo, :department_id, :email)
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':apellido_paterno' => $data['apellido_paterno'],
            ':apellido_materno' => $data['apellido_materno'],
            ':sexo' => $data['sexo'],
            ':department_id' => $data['department_id'] ?? null,
            ':email' => $data['email'] ?? null
        ]);

        $payload = [
            'status' => 'success',
            'message' => 'Solicitud creada correctamente',
            'id' => $this->pdo->lastInsertId()
        ];

        $response->getBody()->write(json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200); // Forzamos el 200

    } catch (\PDOException $e) {
        $payload = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
        $response->getBody()->write(json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
}


}
