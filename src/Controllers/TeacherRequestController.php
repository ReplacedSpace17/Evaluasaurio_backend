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
    // Leer el cuerpo de la petición
    $body = $request->getBody()->getContents();
    $data = json_decode($body, true);

    // Log del contenido recibido
    error_log("📥 Datos recibidos para TeacherRequest: " . print_r($data, true));

    if (!$data) {
        $payload = [
            'status' => 'error',
            'message' => 'No se recibió un JSON válido'
        ];
        $response->getBody()->write(json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }

    try {
        $stmt = $this->pdo->prepare("
            INSERT INTO teacher_requests (name, apellido_paterno, apellido_materno, sexo, department, email)
            VALUES (:name, :apellido_paterno, :apellido_materno, :sexo, :department, :email)
        ");

        $stmt->execute([
            ':name' => $data['name'] ?? null,
            ':apellido_paterno' => $data['apellido_paterno'] ?? null,
            ':apellido_materno' => $data['apellido_materno'] ?? null,
            ':sexo' => $data['sexo'] ?? null,
            ':department' => $data['department'] ?? null,
            ':email' => $data['email'] ?? null
        ]);

        // Log del ID insertado
        $lastId = $this->pdo->lastInsertId();
        error_log("✅ TeacherRequest insertada con ID: $lastId");

        $payload = [
            'status' => 'success',
            'message' => 'Solicitud creada correctamente',
            'id' => $lastId
        ];

        $response->getBody()->write(json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (\PDOException $e) {
        // Log del error
        error_log("❌ Error al insertar TeacherRequest: " . $e->getMessage());

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
