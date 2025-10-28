<?php
namespace App\Controllers;

use App\Utils\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class CalificationToTeacherController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT * FROM califications_to_teacher");
        $califications = $stmt->fetchAll();

        $payload = json_encode($califications, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
    // En CalificationToTeacherController.php
public function getCount(Request $request, Response $response): Response
{
    // Contar todas las calificaciones
    $stmt = $this->pdo->query("SELECT COUNT(*) AS total FROM califications_to_teacher");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);

    $payload = json_encode(['publications' => (int)$count['total']], JSON_UNESCAPED_UNICODE);

    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json');
}


    //obtener las calificaciones puntuadaas de todos
    public function getPublications(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $teacherFind = $queryParams["teacher"] ?? null;
        $subjectFind = $queryParams["subject"] ?? null;
        $despartamentFind = $queryParams["departament"] ?? null;
        $scoreFind = $queryParams["score"] ?? null;
        $initDate = $queryParams["initDate"] ?? null;
        $endDate = $queryParams["endDate"] ?? null;
        $paramsSql = [];
        $conditions = [];

        $query = "
        SELECT
            t.id,
            t.name AS nombre_docente,
            t.apellido_paterno,
            t.apellido_materno,
            t.sexo,
            d.name AS departamento,
            s.name AS materia,
            c.created_at AS fecha_evaluacion,
            c.score AS puntaje,
            c.opinion
        FROM califications_to_teacher c
        LEFT JOIN teachers t ON c.teacher_id = t.id
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN subjects s ON c.materia_id = s.id
        ";

        if ($teacherFind) {
            $conditions[] = "t.id = :teacherId";
            $paramsSql[":teacherId"] = $teacherFind;
        }
        if ($subjectFind) {
            $conditions[] = "s.id = :subjectId";
            $paramsSql[":subjectId"] = $subjectFind;
        }
        if ($despartamentFind) {
            $conditions[] = "d.id = :departamentId";
            $paramsSql[":departamentId"] = $despartamentFind;
        }
        if ($scoreFind) {
            $conditions[] = "FLOOR(c.score) = :score";
            $paramsSql[":score"] = $scoreFind;
        }
        if($initDate && $endDate){
            $conditions[] = "DATE(c.created_at) BETWEEN :initDate AND :endDate";
            $paramsSql[":initDate"] = $initDate;
            $paramsSql[":endDate"] = $endDate;
            
        }
        if (!empty($conditions)) {
            $whereClause = " WHERE " . implode(" AND ", $conditions);
            
            $query .= $whereClause;
        }

        $query .= " ORDER BY c.created_at DESC";
        $paginator = new Paginator($request, $this->pdo);
        $dataPagination = $paginator->paginate($query, $paramsSql);

        $payload = json_encode($dataPagination, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    
  public function create($request, $response, $args) {
    // Obtener raw body (JSON)
    $rawInput = $request->getBody()->getContents();
    $data = json_decode($rawInput, true);

    // Log para depuración
    error_log("Raw recibido: " . $rawInput);
    error_log("Array parseado: " . print_r($data, true));

    // Validar datos obligatorios
    if (
        empty($data['teacher_id']) ||
        empty($data['score']) ||
        empty($data['opinion']) ||
        empty($data['materia_id']) ||
        empty($data['user_fingerprint'])
    ) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Faltan datos obligatorios"
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    // 🔎 Verificar si ya existe una calificación para ese fingerprint con ese teacher y materia
    $checkStmt = $this->pdo->prepare("
        SELECT COUNT(*) as total
        FROM califications_to_teacher
        WHERE teacher_id = :teacher_id
          AND materia_id = :materia_id
          AND user_fingerprint = :user_fingerprint
    ");
    $checkStmt->execute([
        ':teacher_id' => $data['teacher_id'],
        ':materia_id' => $data['materia_id'],
        ':user_fingerprint' => $data['user_fingerprint']
    ]);
    $exists = $checkStmt->fetchColumn();

    if ($exists > 0) {
        // 🚫 Ya votó
        $response->getBody()->write(json_encode([
            "status" => "error",
            "code" => "ALREADY_VOTED",
            "message" => "No puedes puntuar dos veces este docente en la misma materia"
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
    }

    // ✅ Insertar en la base de datos
    $stmt = $this->pdo->prepare("
        INSERT INTO califications_to_teacher 
        (teacher_id, opinion, keywords, score, materia_id, user_fingerprint) 
        VALUES (:teacher_id, :opinion, :keywords, :score, :materia_id, :user_fingerprint)
    ");

    $stmt->execute([
        ':teacher_id' => $data['teacher_id'],
        ':opinion' => $data['opinion'],
        ':keywords' => isset($data['keywords']) ? implode(',', $data['keywords']) : '',
        ':score' => $data['score'],
        ':materia_id' => $data['materia_id'],
        ':user_fingerprint' => $data['user_fingerprint']
    ]);

    $response->getBody()->write(json_encode([
        "status" => "success",
        "message" => "Calificación creada correctamente"
    ]));

    return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
}



}
