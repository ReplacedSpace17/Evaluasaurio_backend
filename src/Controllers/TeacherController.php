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

        $result = [
            'status' => 'success',
            'data' => $teachers
        ];

        $payload = json_encode($result, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
public function getTopTeachers(Request $request, Response $response): Response
{
    // IDs de los departamentos a mostrar (aunque no tengan docentes top)
    $departmentIds = [1, 2, 3, 4, 5, 6, 7];

    // 1. Obtener nombres de todos los departamentos seleccionados
    $sqlDeps = "
        SELECT id, name 
        FROM departments
        WHERE id IN (" . implode(',', $departmentIds) . ")
        ORDER BY id ASC
    ";
    $stmtDeps = $this->pdo->query($sqlDeps);
    $departments = $stmtDeps->fetchAll(PDO::FETCH_ASSOC);

    // 2. Obtener los docentes con promedio de calificación
    $sqlTeachers = "
        SELECT
            d.id AS departamento_id,
            d.name AS departamento,
            t.id AS teacher_id,
            t.name AS nombre,
            t.apellido_paterno,
            t.apellido_materno,
            AVG(c.score) AS promedio_puntuacion,
            COUNT(c.id) AS total_calificaciones
        FROM teachers t
        INNER JOIN departments d ON t.department_id = d.id
        LEFT JOIN califications_to_teacher c ON t.id = c.teacher_id
        WHERE d.id IN (" . implode(',', $departmentIds) . ")
        GROUP BY d.id, d.name, t.id, t.name, t.apellido_paterno, t.apellido_materno
        HAVING total_calificaciones > 0
        ORDER BY d.id, promedio_puntuacion DESC
    ";

    $stmtTeachers = $this->pdo->query($sqlTeachers);
    $rows = $stmtTeachers->fetchAll(PDO::FETCH_ASSOC);

    // 3. Agrupar docentes por departamento
    $grouped = [];
    foreach ($rows as $row) {
        $depName = $row['departamento'];
        if (!isset($grouped[$depName])) {
            $grouped[$depName] = [];
        }

        $grouped[$depName][] = [
            'Nombre' => $row['nombre'],
            'Apellido_paterno' => $row['apellido_paterno'],
            'Apellido_materno' => $row['apellido_materno'],
            'Score_mean' => round((float)$row['promedio_puntuacion'], 2)
        ];
    }

    // 4. Armar respuesta final con todos los departamentos
    $result = [];
    foreach ($departments as $dep) {
        $depName = $dep['name'];
        $profesores = $grouped[$depName] ?? [];

        // Solo los 3 mejores
        $topProfes = array_slice($profesores, 0, 3);

        // Agregar posición (Top: 1, 2, 3)
        foreach ($topProfes as $i => &$p) {
            $p['Top'] = $i + 1;
        }

        $result[] = [
            "Departamento" => $depName,
            "Top" => $topProfes
        ];
    }

    // 5. Devolver JSON
    $payload = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
 // Actualizar maestro existente
public function update(Request $request, Response $response, array $args): Response
{
    $id = (int)$args['id'];
    
    // DEBUG COMPLETO
    error_log("=== UPDATE DEBUG ===");
    error_log("ID: " . $id);
    
    // Obtener todos los métodos posibles de datos
    $parsedBody = $request->getParsedBody();
    $bodyContents = $request->getBody()->getContents();
    
    error_log("Parsed Body: " . print_r($parsedBody, true));
    error_log("Body Contents: " . $bodyContents);
    error_log("Content Type: " . ($request->getHeaderLine('Content-Type') ?? 'No content type'));
    
    // Intentar diferentes formas de parsear
    $data = (array)$parsedBody;
    
    // Si parsedBody está vacío, intentar parsear manualmente
    if (empty($data) || (count($data) === 0)) {
        error_log("ParsedBody vacío, intentando parse manual...");
        parse_str($bodyContents, $manualData);
        $data = (array)$manualData;
        error_log("Datos manuales: " . print_r($data, true));
    }
    
    error_log("Datos finales para update: " . print_r($data, true));
    error_log("=== FIN DEBUG ===");

    // Validar que los datos existen
    if (empty($data['name'])) {
        $response->getBody()->write(json_encode([
            'error' => 'Campo name está vacío o no existe. Datos recibidos: ' . print_r($data, true)
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

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

    //obtener info para el card del maestro
    public function getInfoCard(Request $request, Response $response, array $args): Response
{
    $id = (int)$args['id'];

    // 1. Traemos los datos básicos del docente
    $stmt = $this->pdo->prepare("
        SELECT 
            t.id, t.name, t.apellido_paterno, t.apellido_materno, t.sexo, 
            d.name AS departamento, t.created_at
        FROM teachers t
        LEFT JOIN departments d ON t.department_id = d.id
        WHERE t.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$teacher) {
        $response->getBody()->write(json_encode(['error' => 'Teacher not found'], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // 2. Calculamos promedio y total evaluaciones
    $stmt = $this->pdo->prepare("
        SELECT 
            AVG(score) AS promedio,
            COUNT(*) AS total_evaluaciones
        FROM califications_to_teacher
        WHERE teacher_id = :id
    ");
    $stmt->execute(['id' => $id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $promedio = $stats['promedio'] ? round((float)$stats['promedio'], 2) : 0;
    $totalEvaluaciones = (int) $stats['total_evaluaciones'];

    // 3. Contamos keywords (puedes guardar en JSON o texto separado por comas)
    $stmt = $this->pdo->prepare("
        SELECT keywords
        FROM califications_to_teacher
        WHERE teacher_id = :id
    ");
    $stmt->execute(['id' => $id]);
    $allKeywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $keywordsCount = [];
    foreach ($allKeywords as $keywordsText) {
        // Si keywords está guardado como "amable,dedicado,puntual"
        $words = array_map('trim', explode(',', $keywordsText));
        foreach ($words as $word) {
            if ($word !== '') {
                $keywordsCount[$word] = ($keywordsCount[$word] ?? 0) + 1;
            }
        }
    }

    // Top 5 keywords más usadas
    arsort($keywordsCount);
    $topKeywords = array_slice($keywordsCount, 0, 5, true);

    // Transformamos a formato [{palabra: count}, ...]
    $topKeywordsArray = [];
    foreach ($topKeywords as $word => $count) {
        $topKeywordsArray[] = [ $word => $count ];
    }

    // 4. Construimos la respuesta final
    $profile = [
        'id' => (int) $teacher['id'],
        'name' => $teacher['name'],
        'apellido_paterno' => $teacher['apellido_paterno'],
        'apellido_materno' => $teacher['apellido_materno'],
        'sexo' => $teacher['sexo'],
        'departamento' => $teacher['departamento'],
        'created_at' => $teacher['created_at'],
        'promedio' => $promedio,
        'total_evaluaciones' => $totalEvaluaciones,
        'top_keywords' => $topKeywordsArray,
    ];

    $response->getBody()->write(json_encode($profile, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
}

//obtener calificaciones de un maestro
public function ObtenerPromedioCalificaciones(Request $request, Response $response, array $args): Response
{
    $teacher_id = (int)$args['id'];

    // Consulta: agrupa por score y cuenta cuántas calificaciones hay de cada tipo
    $stmt = $this->pdo->prepare("
        SELECT 
            score AS calificacion,
            COUNT(*) AS cantidad
        FROM califications_to_teacher
        WHERE teacher_id = :teacher_id
        GROUP BY score
        ORDER BY score ASC
    ");
    $stmt->execute(['teacher_id' => $teacher_id]);
    $result = $stmt->fetchAll();

    // Inicializamos el arreglo con todas las calificaciones posibles
    $data = [];
    for ($i = 1; $i <= 5; $i++) {
        $data[$i] = [
            'calificacion' => $i . ' estrella' . ($i > 1 ? 's' : ''),
            'cantidad' => 0
        ];
    }

    // Reemplazamos los valores según los datos obtenidos
    foreach ($result as $row) {
        $score = (int)$row['calificacion'];
        if ($score >= 1 && $score <= 5) {
            $data[$score]['cantidad'] = (int)$row['cantidad'];
        }
    }

    // Reindexamos para que sea un array simple
    $data = array_values($data);

    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
}



//obtener el promedio de calificaciones de un maestro por materia
public function ObtenerPromedioMaterias(Request $request, Response $response, array $args): Response
{
    $teacher_id = (int)$args['id'];
    
    $stmt = $this->pdo->prepare("
        SELECT 
            s.name AS materia,
            ROUND(AVG(c.score), 2) AS promedio
        FROM califications_to_teacher c
        LEFT JOIN subjects s ON c.materia_id = s.id
        WHERE c.teacher_id = :teacher_id
        GROUP BY s.id, s.name
        ORDER BY s.name
    ");
    
    $stmt->execute(['teacher_id' => $teacher_id]);
    $promedios = $stmt->fetchAll();

    if (!$promedios) {
        $response->getBody()->write(json_encode(['error' => 'No califications found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($promedios, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
}
public function ObtenerComportamientoDocente(Request $request, Response $response, array $args): Response
{
    $teacherId = (int)$args['id'];
    $currentYear = (int)date("Y");

    // 1. Obtenemos todas las materias evaluadas por el docente con sus calificaciones
    $stmt = $this->pdo->prepare("
        SELECT 
            s.name AS materia,
            t.name AS docente,
            YEAR(c.created_at) AS anio,
            MONTH(c.created_at) AS mes,
            AVG(c.score) AS calificacion_promedio
        FROM califications_to_teacher c
        JOIN subjects s ON c.materia_id = s.id
        JOIN teachers t ON c.teacher_id = t.id
        WHERE c.teacher_id = :teacherId
        GROUP BY s.id, anio, mes
        ORDER BY s.name, anio, mes
    ");
    $stmt->execute(['teacherId' => $teacherId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay datos, devolvemos un JSON de aviso
    if (empty($rows)) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'No hay datos disponibles para este docente'
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $materias = [];

    // 2. Agrupamos por materia
    foreach ($rows as $row) {
        $mat = $row['materia'];
        if (!isset($materias[$mat])) {
            $materias[$mat] = [
                'nombre' => $mat,
                'docente' => $row['docente'],
                'fecha' => []
            ];
        }

        $anio = (int)$row['anio'];
        $mes = str_pad($row['mes'], 2, "0", STR_PAD_LEFT);
        $calificacion = (float)$row['calificacion_promedio'];

        $anioIndex = array_search($anio, array_column($materias[$mat]['fecha'], 'año'));
        if ($anioIndex === false) {
            $materias[$mat]['fecha'][] = [
                'año' => $anio,
                'puntaje' => [
                    ['fecha' => "$anio-$mes", 'calificacion' => $calificacion]
                ]
            ];
        } else {
            $materias[$mat]['fecha'][$anioIndex]['puntaje'][] = [
                'fecha' => "$anio-$mes", 'calificacion' => $calificacion
            ];
        }
    }

    // 3. Regresión lineal para predecir meses faltantes del año actual
    foreach ($materias as &$mat) {
        foreach ($mat['fecha'] as &$anioData) {
            if ($anioData['año'] == $currentYear) {
                $puntajes = $anioData['puntaje'];
                $mesesExistentes = array_map(fn($p) => (int)substr($p['fecha'], 5, 2), $puntajes);
                $calificaciones = array_map(fn($p) => $p['calificacion'], $puntajes);

                if (count($puntajes) > 1 && count($puntajes) < 12) {
                    $n = count($mesesExistentes);
                    $sumX = array_sum($mesesExistentes);
                    $sumY = array_sum($calificaciones);
                    $sumXY = 0;
                    $sumXX = 0;
                    for ($i = 0; $i < $n; $i++) {
                        $sumXY += $mesesExistentes[$i] * $calificaciones[$i];
                        $sumXX += $mesesExistentes[$i] * $mesesExistentes[$i];
                    }
                    $b = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
                    $a = ($sumY - $b * $sumX) / $n;

                    $prediccion = [];
                    for ($m = 1; $m <= 12; $m++) {
                        if (!in_array($m, $mesesExistentes)) {
                            $calPred = round($a + $b * $m, 2);
                            $calPred = max(0, min(5, $calPred));
                            $mesStr = str_pad($m, 2, "0", STR_PAD_LEFT);
                            $prediccion[] = ['fecha' => "$currentYear-$mesStr", 'calificacion' => $calPred];
                        }
                    }
                    if (count($prediccion) > 0) {
                        $anioData['prediccion'] = $prediccion;
                    }
                }
            }
        }
    }

    $result = array_values($materias);

    $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
}



//obtener publicaciones de docente perfil
public function ObtenerOpinionesDocentePerfil(Request $request, Response $response, array $args): Response
{
    $teacher_id = (int)$args['id'];

    // Consulta para obtener las calificaciones del docente
    $stmt = $this->pdo->prepare("
        SELECT 
            c.id AS calificacion_id,
            c.opinion,
            c.keywords,
            c.score,
            s.name AS materia,
            d.name AS departamento,
            c.created_at AS fecha
        FROM califications_to_teacher c
        LEFT JOIN subjects s ON c.materia_id = s.id
        LEFT JOIN teachers t ON c.teacher_id = t.id
        LEFT JOIN departments d ON t.department_id = d.id
        WHERE c.teacher_id = :teacher_id
        ORDER BY c.created_at DESC
    ");

    $stmt->execute(['teacher_id' => $teacher_id]);
    $opiniones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Transformamos keywords de texto a array
    foreach ($opiniones as &$opinion) {
        $opinion['keywords'] = json_decode($opinion['keywords'], true) ?: [];
    }

    $response->getBody()->write(json_encode($opiniones, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
}




}
