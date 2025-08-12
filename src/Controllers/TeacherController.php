<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class TeacherController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Obtener todos los maestros
    public function getAll(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT 
                t.id, t.name, t.apellido_paterno, t.apellido_materno, t.sexo, 
                t.department_id, d.name as department_name, t.created_at
            FROM teachers t
            LEFT JOIN departments d ON t.department_id = d.id
        ");

        $teachers = $stmt->fetchAll();

        $payload = json_encode($teachers, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Obtener maestro por ID
    public function getById(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $stmt = $this->pdo->prepare("
            SELECT 
                t.id, t.name, t.apellido_paterno, t.apellido_materno, t.sexo, 
                t.department_id, d.name as department_name, t.created_at
            FROM teachers t
            LEFT JOIN departments d ON t.department_id = d.id
            WHERE t.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $teacher = $stmt->fetch();

        if (!$teacher) {
            $response->getBody()->write(json_encode(['error' => 'Teacher not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($teacher, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Crear un nuevo maestro
    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();

        $sql = "INSERT INTO teachers (name, apellido_paterno, apellido_materno, sexo, department_id) 
                VALUES (:name, :apellido_paterno, :apellido_materno, :sexo, :department_id)";

        $stmt = $this->pdo->prepare($sql);

        try {
            $stmt->execute([
                'name' => $data['name'],
                'apellido_paterno' => $data['apellido_paterno'],
                'apellido_materno' => $data['apellido_materno'],
                'sexo' => $data['sexo'],
                'department_id' => $data['department_id'] ?? null,
            ]);
            $id = $this->pdo->lastInsertId();
            $response->getBody()->write(json_encode(['message' => 'Teacher created', 'id' => $id]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\PDOException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    // Actualizar maestro existente
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = (array)$request->getParsedBody();

        $sql = "UPDATE teachers SET 
                    name = :name, 
                    apellido_paterno = :apellido_paterno, 
                    apellido_materno = :apellido_materno, 
                    sexo = :sexo, 
                    department_id = :department_id
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);

        try {
            $stmt->execute([
                'id' => $id,
                'name' => $data['name'],
                'apellido_paterno' => $data['apellido_paterno'],
                'apellido_materno' => $data['apellido_materno'],
                'sexo' => $data['sexo'],
                'department_id' => $data['department_id'] ?? null,
            ]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Teacher not found or no changes made']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'Teacher updated']));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\PDOException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    // Eliminar maestro
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $stmt = $this->pdo->prepare("DELETE FROM teachers WHERE id = :id");
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            $response->getBody()->write(json_encode(['error' => 'Teacher not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['message' => 'Teacher deleted']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
