<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class SubjectController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Obtener todos los subjects
    public function getAll(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT id, name, created_at FROM subjects ORDER BY created_at DESC");
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = json_encode($subjects, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Obtener un subject por ID
    public function getById(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;
        if (!$id) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                            ->write(json_encode(['status'=>'error','message'=>'Falta el parámetro ID']));
        }

        $stmt = $this->pdo->prepare("SELECT id, name, created_at FROM subjects WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$subject) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                            ->write(json_encode(['status'=>'error','message'=>"No se encontró el subject con ID $id"]));
        }

        $response->getBody()->write(json_encode($subject, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Crear nuevo subject
    public function create(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        if (empty($data['name'])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                            ->write(json_encode(['status'=>'error','message'=>'Falta el nombre del subject']));
        }

        $stmt = $this->pdo->prepare("INSERT INTO subjects (name) VALUES (:name)");
        $stmt->execute([':name' => $data['name']]);
        $lastId = $this->pdo->lastInsertId();

        $response->getBody()->write(json_encode(['status'=>'success','id'=>$lastId]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    // Actualizar un subject
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;
        $data = json_decode($request->getBody()->getContents(), true);

        if (!$id || empty($data['name'])) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                            ->write(json_encode(['status'=>'error','message'=>'Falta ID o nombre']));
        }

        $stmt = $this->pdo->prepare("UPDATE subjects SET name = :name WHERE id = :id");
        $stmt->execute([':name' => $data['name'], ':id' => $id]);

        $response->getBody()->write(json_encode(['status'=>'success','message'=>"Subject con ID $id actualizado"]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Eliminar un subject
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;
        if (!$id) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                            ->write(json_encode(['status'=>'error','message'=>'Falta el parámetro ID']));
        }

        $stmt = $this->pdo->prepare("DELETE FROM subjects WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $response->getBody()->write(json_encode(['status'=>'success','message'=>"Subject con ID $id eliminado"]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
