<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class StatisticsController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

// Obtener estadísticas generales
public function getAll(Request $request, Response $response): Response
{
    try {
        $stats = [];

        // Número total de calificaciones
        $stmt = $this->pdo->query("SELECT COUNT(*) AS total_califications FROM califications_to_teacher");
        $stats['total_califications'] = (int) $stmt->fetch()['total_califications'];

        // Número de maestros registrados
        $stmt = $this->pdo->query("SELECT COUNT(*) AS total_teachers FROM teachers");
        $stats['total_teachers'] = (int) $stmt->fetch()['total_teachers'];

        // Número de materias
        $stmt = $this->pdo->query("SELECT COUNT(*) AS total_subjects FROM subjects");
        $stats['total_subjects'] = (int) $stmt->fetch()['total_subjects'];

        // Número de departamentos
        $stmt = $this->pdo->query("SELECT COUNT(*) AS total_departments FROM departments");
        $stats['total_departments'] = (int) $stmt->fetch()['total_departments'];

        // Solicitudes de maestros
        $stmt = $this->pdo->query("SELECT COUNT(*) AS total_teacher_requests FROM teacher_requests");
        $stats['total_teacher_requests'] = (int) $stmt->fetch()['total_teacher_requests'];

        // Solicitudes de materias
        $stmt = $this->pdo->query("SELECT COUNT(*) AS total_subject_requests FROM subject_requests");
        $stats['total_subject_requests'] = (int) $stmt->fetch()['total_subject_requests'];

        // Última calificación hecha
        $stmt = $this->pdo->query("SELECT MAX(created_at) AS last_calification_date FROM califications_to_teacher");
        $stats['last_calification_date'] = $stmt->fetch()['last_calification_date'] ?? null;

        // ===============================
        // Métricas de analítica diaria
        // Duración promedio de sesión por día
        $stmt = $this->pdo->query("
            SELECT DATE(created_at) AS day, AVG(duration_seconds) AS avg_duration
            FROM analytics_events
            WHERE event_type = 'active_time'
            GROUP BY DATE(created_at)
            ORDER BY day DESC
        ");
        $stats['avg_session_duration_per_day'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Visitas únicas por día
        $stmt = $this->pdo->query("
            SELECT DATE(created_at) AS day, COUNT(DISTINCT ip_address) AS unique_visits
            FROM analytics_events
            GROUP BY DATE(created_at)
            ORDER BY day DESC
        ");
        $stats['unique_visits_per_day'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ===============================
        // Métricas de calificaciones por día
        // Promedio de score por día
        $stmt = $this->pdo->query("
            SELECT DATE(created_at) AS day, AVG(score) AS avg_score, COUNT(*) AS total_scores
            FROM califications_to_teacher
            GROUP BY DATE(created_at)
            ORDER BY day DESC
        ");
        $stats['daily_califications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ===============================
        $payload = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);

        return $response->withHeader('Content-Type', 'application/json');

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



        // Crear un nuevo evento
public function create(Request $request, Response $response): Response
{
    // Establecemos la zona horaria de México
    date_default_timezone_set('America/Mexico_City');

    // Obtenemos los datos enviados desde React
    $data = json_decode($request->getBody()->getContents(), true);

    // Obtenemos IP, user agent y referer del request
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $referer = $_SERVER['HTTP_REFERER'] ?? null;

    // Fecha actual en México
    $created_at = date('Y-m-d H:i:s');

    try {
        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_events 
            (event_type, page, ip_address, user_agent, referer, duration_seconds, created_at) 
            VALUES (:event_type, :page, :ip_address, :user_agent, :referer, :duration_seconds, :created_at)
        ");

        $stmt->execute([
            ':event_type' => $data['event_type'],
            ':page' => $data['page'] ?? null,
            ':ip_address' => $ip_address,
            ':user_agent' => $user_agent,
            ':referer' => $referer,
            ':duration_seconds' => $data['duration_seconds'] ?? null,
            ':created_at' => $created_at
        ]);

        $payload = [
            'status' => 'success',
            'message' => 'Evento registrado correctamente',
            'id' => $this->pdo->lastInsertId()
        ];

        $response->getBody()->write(json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

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
