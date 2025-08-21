<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class SubjectRequestController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Obtener todas las solicitudes
    public function getAll(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT * FROM subject_requests ORDER BY created_at DESC");
        $requests = $stmt->fetchAll();
        $payload = json_encode($requests, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Crear nueva solicitud
    public function create(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);

        $stmt = $this->pdo->prepare("
            INSERT INTO subject_requests (name, description)
            VALUES (:name, :description)
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null
        ]);

        $response->getBody()->write(json_encode(['status' => 'success', 'id' => $this->pdo->lastInsertId()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
