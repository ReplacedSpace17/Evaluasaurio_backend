<?php
namespace App\Controllers;

use App\Utils\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class DepartmentController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $textFind = $queryParams["find"] ?? null;
        $paramsSql = [];
        $conditions = [];

        $query = "SELECT id, name, created_at FROM departments";
        
        if($textFind){
            $searchTerm = "%".strtolower(trim($textFind))."%";
            
            $conditions[] = " LOWER(name) LIKE :find_name ";
            $paramsSql[":find_name"] = $searchTerm;
        }
        if (!empty($conditions)) {
            $whereClause = " WHERE " . implode(" AND ", $conditions);
            
            $query .= $whereClause;
        }

        $paginator = new Paginator($request, $this->pdo);
        $dataPagination = $paginator->paginate($query, $paramsSql);

        $payload = json_encode($dataPagination, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

     // Devuelve solo id y nombre (ideal para listas o select)
    public function getBasic(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT id, name FROM departments ORDER BY name ASC");
        $departments = $stmt->fetchAll();

        $payload = json_encode($departments, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function addEvaluationDepartament(Request $request, Response $response): Response
{
    try {
        // Obtener datos del cuerpo de la petición
        $data = json_decode($request->getBody()->getContents(), true);

        // Validar campos requeridos
        if (
            empty($data['id_department']) ||
            empty($data['score']) ||
            empty($data['opinion'])
        ) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Faltan campos obligatorios: id_department, score u opinion."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Insertar en la tabla department_evaluations
        $stmt = $this->pdo->prepare("
            INSERT INTO department_evaluations (id_department, score, opinion, keyword)
            VALUES (:id_department, :score, :opinion, :keyword)
        ");

        $stmt->execute([
            ':id_department' => $data['id_department'],
            ':score' => $data['score'],
            ':opinion' => $data['opinion'],
            ':keyword' => isset($data['keyword']) ? $data['keyword'] : null
        ]);

        // Respuesta de éxito
        $response->getBody()->write(json_encode([
            "status" => "success",
            "message" => "Evaluación registrada correctamente."
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        // Error de base de datos
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error en la base de datos: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);

    } catch (\Exception $e) {
        // Error genérico
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error general: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

//buscar informacion sobre un departamento por id
public function getDepartmentEvaluationById(Request $request, Response $response, array $args): Response
{
    try {
        $id = $args['id'] ?? null;

        if (empty($id)) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Falta el parámetro 'id' del departamento."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // 🔹 Obtener datos básicos del departamento y su jefatura
        $stmt = $this->pdo->prepare("
            SELECT 
                d.id,
                d.name,
                CONCAT(a.name, ' ', a.apellido_paterno, ' ', a.apellido_materno) AS jefatura
            FROM departments d
            LEFT JOIN leadership l ON l.department_id = d.id
            LEFT JOIN administrative_staff a ON a.id = l.staff_id
            WHERE d.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $department = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$department) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Departamento no encontrado."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 🔹 Obtener promedio de calificaciones
        $stmt = $this->pdo->prepare("
            SELECT ROUND(AVG(score), 2) AS score_mean
            FROM department_evaluations
            WHERE id_department = :id
        ");
        $stmt->execute([':id' => $id]);
        $scoreData = $stmt->fetch(PDO::FETCH_ASSOC);
        $scoreMean = $scoreData['score_mean'] ?? null;

        // 🔹 Obtener opiniones del departamento
        $stmt = $this->pdo->prepare("
            SELECT 
                opinion AS opiniones,
                keyword AS keywords,
                score AS calificacion,
                evaluation_date AS fecha
            FROM department_evaluations
            WHERE id_department = :id
            ORDER BY evaluation_date DESC
        ");
        $stmt->execute([':id' => $id]);
        $opiniones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 🔹 Formatear keywords como arreglo si son múltiples
        foreach ($opiniones as &$op) {
            if (!empty($op['keywords'])) {
                $op['keywords'] = array_map('trim', explode(',', $op['keywords']));
            } else {
                $op['keywords'] = [];
            }
        }

        // 🔹 Armar respuesta final
        $result = [
            "id" => (int)$department['id'],
            "name" => $department['name'],
            "jefatura" => $department['jefatura'] ?? "No asignado",
            "score_mean" => $scoreMean ? (float)$scoreMean : 0.0,
            "opiniones" => $opiniones
        ];

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error en la base de datos: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error general: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

public function getAllDepartmentOpinions(Request $request, Response $response): Response
{
    try {
        $stmt = $this->pdo->query("
            SELECT 
                e.id_department,
                d.name AS departamento,
                CONCAT(a.name, ' ', a.apellido_paterno, ' ', a.apellido_materno) AS jefatura,
                e.score AS score,
                e.opinion AS opinion,
                e.keyword AS keywords,
                e.evaluation_date AS fecha
            FROM department_evaluations e
            LEFT JOIN departments d ON d.id = e.id_department
            LEFT JOIN leadership l ON l.department_id = d.id
            LEFT JOIN administrative_staff a ON a.id = l.staff_id
            ORDER BY e.evaluation_date DESC
        ");

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 🔹 Formatear keywords en arreglo
        foreach ($data as &$row) {
            if (!empty($row['keywords'])) {
                $row['keywords'] = array_map('trim', explode(',', $row['keywords']));
            } else {
                $row['keywords'] = [];
            }
        }

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error en la base de datos: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error general: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}


public function getTopDepartments(Request $request, Response $response): Response
{
    try {
        $stmt = $this->pdo->query("
            SELECT 
                d.id,
                d.name AS nombre,
                ROUND(AVG(e.score), 2) AS score_mean
            FROM departments d
            LEFT JOIN department_evaluations e ON d.id = e.id_department
            GROUP BY d.id, d.name
            ORDER BY score_mean DESC
            LIMIT 5
        ");

        $topDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($topDepartments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error en la base de datos: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error general: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

}
