<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


class RequestRevisionController
{
    private PDO $pdo;
    private array $settingsEmail;

    public function __construct(PDO $pdo, array $settingsEmail)
    {
        $this->pdo = $pdo;
        $this->settingsEmail = $settingsEmail;
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            // Obtener datos del cuerpo de la petición
            $data = json_decode($request->getBody()->getContents(), true);

            // Validar campos requeridos
            if (
                empty($data['id_target']) ||
                empty($data['publication_type']) ||
                empty($data['complaint_type']) ||
                empty($data['description'])
            ) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "Faltan campos obligatorios: id_target, publication_type, complaint_type o description."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Mapear tipos numéricos a ENUMs en español
            $publicationTypes = [
                1 => 'docente',
                2 => 'departamento', 
                3 => 'reporte_incidencia'
            ];

            $complaintTypes = [
                1 => 'difamación',
                2 => 'acoso',
                3 => 'incitación_violencia',
                4 => 'información_falsa',
                5 => 'spam',
                6 => 'otro'
            ];

            $publicationType = $publicationTypes[$data['publication_type']] ?? 'reporte_incidencia';
            $complaintType = $complaintTypes[$data['complaint_type']] ?? 'otro';

            // 🔍 VALIDAR QUE EL CONTENIDO EXISTA EN SU TABLA CORRESPONDIENTE
            $validationResult = $this->validateTargetContent($data['id_target'], $publicationType);
            
            if (!$validationResult['exists']) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => $validationResult['message']
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // 🔥 MANEJO DE FECHA - Si viene del cliente, usarla, sino hora actual del servidor
            if (!empty($data['created_at'])) {
                // Validar formato de fecha
                $clientDate = \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $data['created_at']);
                if (!$clientDate) {
                    $clientDate = \DateTime::createFromFormat('Y-m-d H:i:s', $data['created_at']);
                }
                
                if ($clientDate) {
                    $createdAt = $clientDate->format('Y-m-d H:i:s');
                } else {
                    // Si el formato es inválido, usar hora actual
                    $createdAt = (new \DateTime())->format('Y-m-d H:i:s');
                }
            } else {
                // Si no viene fecha, usar hora actual del servidor
                $createdAt = (new \DateTime())->format('Y-m-d H:i:s');
            }

            // Insertar en la tabla request_revisions CON fecha específica
            $stmt = $this->pdo->prepare("
                INSERT INTO request_revisions 
                (target_content_id, publication_type, complaint_type, description, status, created_at)
                VALUES 
                (:target_content_id, :publication_type, :complaint_type, :description, 'recibido', :created_at)
            ");

            $stmt->execute([
                ':target_content_id' => $data['id_target'],
                ':publication_type' => $publicationType,
                ':complaint_type' => $complaintType,
                ':description' => $data['description'],
                ':created_at' => $createdAt
            ]);

            // Obtener ID insertado
            $requestId = $this->pdo->lastInsertId();

            // Respuesta de éxito
            $response->getBody()->write(json_encode([
                "status" => "success",
                "message" => "Solicitud de revisión registrada correctamente.",
                "request_id" => $requestId,
                "created_at" => $createdAt,
                "content_info" => $validationResult['content_info'] ?? null
            ], JSON_UNESCAPED_UNICODE));

            // 🔔 Obtener correos de administradores
$stmtAdmins = $this->pdo->query("SELECT correo FROM user_admin");
$admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

// Si hay admins, enviar correo de notificación
if (!empty($admins)) {
    $subject = "📢 Nuevo reporte registrado";
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .header { background: #1890ff; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>Reporte de Solicitud de Revisión</h2>
        </div>
        <div class='content'>
            <p>Se ha registrado un nuevo reporte en el sistema:</p>
            <ul>
                <li><strong>ID del Reporte:</strong> {$requestId}</li>
                <li><strong>Tipo de Publicación:</strong> {$publicationType}</li>
                <li><strong>Tipo de Queja:</strong> {$complaintType}</li>
                <li><strong>Descripción:</strong> {$data['description']}</li>
                <li><strong>Fecha:</strong> {$createdAt}</li>
            </ul>
        </div>
        <div class='footer'>
            <p>Evaluasaurio - La mejor forma de evaluar</p>
            <p>© " . date('Y') . " Singularity MX</p>
        </div>
    </body>
    </html>
    ";

    foreach ($admins as $adminEmail) {
        $this->sendEmail($adminEmail, $subject, $body);
    }
}


            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

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

    /**
     * Valida que el contenido target exista en su tabla correspondiente
     */
    private function validateTargetContent(int $targetId, string $publicationType): array
    {
        switch ($publicationType) {
            case 'docente':
                $stmt = $this->pdo->prepare("
                    SELECT id, teacher_id, opinion, score 
                    FROM califications_to_teacher 
                    WHERE id = :id
                ");
                $tableName = "califications_to_teacher";
                $errorMessage = "La evaluación de docente con ID {$targetId} no existe.";
                break;

            case 'departamento':
                $stmt = $this->pdo->prepare("
                    SELECT id, id_department, opinion, score 
                    FROM department_evaluations 
                    WHERE id = :id
                ");
                $tableName = "department_evaluations";
                $errorMessage = "La evaluación de departamento con ID {$targetId} no existe.";
                break;

            case 'reporte_incidencia':
                $stmt = $this->pdo->prepare("
                    SELECT id, tipo_incidente, descripcion, ubicacion 
                    FROM reports 
                    WHERE id = :id
                ");
                $tableName = "reports";
                $errorMessage = "El reporte de incidencia con ID {$targetId} no existe.";
                break;

            default:
                return [
                    'exists' => false,
                    'message' => "Tipo de publicación '{$publicationType}' no válido."
                ];
        }

        $stmt->execute([':id' => $targetId]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$content) {
            return [
                'exists' => false,
                'message' => $errorMessage
            ];
        }

        return [
            'exists' => true,
            'content_info' => $content
        ];
    }

    // ... (Los otros métodos getAll y updateStatus se mantienen igual)
public function getAll(Request $request, Response $response): Response
{
    try {
        $stmt = $this->pdo->query("
            SELECT 
                id,
                target_content_id,
                publication_type,
                complaint_type,
                description,
                status,
                response_time_hours,
                resolution_action,
                admin_notes,
                created_at,
                resolved_at
            FROM request_revisions 
            ORDER BY created_at DESC
        ");

        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enriquecer cada solicitud con datos de la tabla correspondiente
        $enrichedRequests = [];
        foreach ($requests as $request) {
            $contentData = $this->getContentData(
                $request['target_content_id'], 
                $request['publication_type']
            );
            
            $enrichedRequests[] = array_merge($request, [
                'content_data' => $contentData
            ]);
        }

        $response->getBody()->write(json_encode($enrichedRequests, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error en la base de datos: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

/**
 * Obtiene los datos del contenido según el tipo de publicación con nombres completos
 */
private function getContentData(int $targetId, string $publicationType): ?array
{
    try {
        switch ($publicationType) {
            case 'docente':
                $stmt = $this->pdo->prepare("
                    SELECT 
                        ct.id,
                        ct.teacher_id,
                        t.name as teacher_name,
                        t.apellido_paterno,
                        t.apellido_materno,
                        t.sexo,
                        d.name as department_name,
                        ct.opinion,
                        ct.keywords,
                        ct.score,
                        ct.materia_id,
                        s.name as subject_name,
                        ct.user_fingerprint,
                        ct.created_at
                    FROM califications_to_teacher ct
                    LEFT JOIN teachers t ON ct.teacher_id = t.id
                    LEFT JOIN departments d ON t.department_id = d.id
                    LEFT JOIN subjects s ON ct.materia_id = s.id
                    WHERE ct.id = :id
                ");
                break;

            case 'departamento':
                $stmt = $this->pdo->prepare("
                    SELECT 
                        de.id,
                        de.id_department,
                        d.name as department_name,
                        de.score,
                        de.opinion,
                        de.keyword,
                        de.evaluation_date
                    FROM department_evaluations de
                    LEFT JOIN departments d ON de.id_department = d.id
                    WHERE de.id = :id
                ");
                break;

            case 'reporte_incidencia':
                $stmt = $this->pdo->prepare("
                    SELECT 
                        id,
                        tipo_incidente,
                        descripcion,
                        ubicacion,
                        foto,
                        fecha_hora,
                        creado_en,
                        actualizado_en
                    FROM reports 
                    WHERE id = :id
                ");
                break;

            default:
                return null;
        }

        $stmt->execute([':id' => $targetId]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC);

        return $content ?: null;

    } catch (\Exception $e) {
        // En caso de error, retornar null para no afectar la respuesta principal
        return null;
    }
}
/**
 * Actualizar estado de la solicitud
 */
public function updateStatus(Request $request, Response $response, array $args): Response
{
    try {
        $id = $args['id'] ?? null;
        $data = json_decode($request->getBody()->getContents(), true);

        if (empty($id) || empty($data['status']) || empty($data['resolution_action'])) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Faltan campos obligatorios: id, status o resolution_action."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Usar la fecha de resolución enviada desde React (hora CDMX)
        $resolvedAt = $data['resolved_at'] ?? null;
        
        if (!$resolvedAt) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Fecha de resolución requerida."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Calcular tiempo de respuesta usando la fecha enviada desde React
        $stmt = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(HOUR, created_at, :resolved_at) as horas_transcurridas 
            FROM request_revisions 
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':resolved_at' => $resolvedAt
        ]);
        $timeData = $stmt->fetch(PDO::FETCH_ASSOC);
        $responseTime = $timeData['horas_transcurridas'] ?? 0;

        // Actualizar solicitud
        $stmt = $this->pdo->prepare("
            UPDATE request_revisions 
            SET status = :status,
                resolution_action = :resolution_action,
                admin_notes = :admin_notes,
                response_time_hours = :response_time_hours,
                resolved_at = :resolved_at
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id,
            ':status' => $data['status'],
            ':resolution_action' => $data['resolution_action'],
            ':admin_notes' => $data['admin_notes'] ?? null,
            ':response_time_hours' => $responseTime,
            ':resolved_at' => $resolvedAt
        ]);

        $response->getBody()->write(json_encode([
            "status" => "success",
            "message" => "Solicitud actualizada correctamente.",
            "response_time_hours" => $responseTime,
            "resolved_at" => $resolvedAt
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error en la base de datos: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

    /**
 * Contar solo solicitudes nuevas (estado 'recibido')
 */
public function countNewRequests(Request $request, Response $response): Response
{
    try {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as new_requests 
            FROM request_revisions 
            WHERE status = 'recibido'
        ");

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode([
            "status" => "success",
            "data" => [
                "new_requests" => (int)$result['new_requests']
            ]
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error al contar solicitudes nuevas: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

/**
 * Eliminar solicitud y el contenido reportado
 */
/**
 * Eliminar solo el contenido reportado (sin eliminar la solicitud)
 */
public function deleteContentOnly(Request $request, Response $response, array $args): Response
{
    try {
        $id = $args['id'] ?? null;
        
        if (empty($id)) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "ID de solicitud requerido."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Obtener información de la solicitud
        $stmt = $this->pdo->prepare("
            SELECT target_content_id, publication_type 
            FROM request_revisions 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$solicitud) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Solicitud no encontrada."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $targetId = $solicitud['target_content_id'];
        $publicationType = $solicitud['publication_type'];

        // Iniciar transacción para asegurar consistencia
        $this->pdo->beginTransaction();

        try {
            // 1. Eliminar el contenido reportado según el tipo
            $contentDeleted = $this->deleteReportedContent($targetId, $publicationType);
            
            if (!$contentDeleted) {
                throw new \Exception("No se pudo eliminar el contenido reportado");
            }

            // 2. Actualizar la solicitud para marcar que el contenido fue eliminado
            $stmt = $this->pdo->prepare("
                UPDATE request_revisions 
                SET status = 'resuelto',
                    resolution_action = 'contenido_eliminado',
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), ' Contenido eliminado manualmente.'),
                    response_time_hours = TIMESTAMPDIFF(HOUR, created_at, NOW()),
                    resolved_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);

            // Confirmar transacción
            $this->pdo->commit();

            $response->getBody()->write(json_encode([
                "status" => "success",
                "message" => "Contenido reportado eliminado correctamente. La solicitud ha sido marcada como resuelta.",
                "deleted_content_type" => $publicationType,
                "deleted_content_id" => $targetId,
                "request_id" => $id,
                "request_updated" => true
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            // Revertir transacción en caso de error
            $this->pdo->rollBack();
            throw $e;
        }

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

/**
 * Elimina el contenido reportado según el tipo de publicación
 */
private function deleteReportedContent(int $targetId, string $publicationType): bool
{
    switch ($publicationType) {
        case 'docente':
            $stmt = $this->pdo->prepare("DELETE FROM califications_to_teacher WHERE id = :id");
            break;

        case 'departamento':
            $stmt = $this->pdo->prepare("DELETE FROM department_evaluations WHERE id = :id");
            break;

        case 'reporte_incidencia':
            $stmt = $this->pdo->prepare("DELETE FROM reports WHERE id = :id");
            break;

        default:
            throw new \Exception("Tipo de publicación no válido: " . $publicationType);
    }

    $stmt->execute([':id' => $targetId]);
    return $stmt->rowCount() > 0;
}
//function para eliminar la solicitud solaente con el id
/**
 * Eliminar solo la solicitud (sin el contenido reportado)
 */
public function deleteRequestOnly(Request $request, Response $response, array $args): Response
{
    try {
        $id = $args['id'] ?? null;
        
        if (empty($id)) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "ID de solicitud requerido."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Verificar que la solicitud existe
        $stmt = $this->pdo->prepare("SELECT id FROM request_revisions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$solicitud) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Solicitud no encontrada."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Eliminar solo la solicitud
        $stmt = $this->pdo->prepare("DELETE FROM request_revisions WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() > 0) {
            $response->getBody()->write(json_encode([
                "status" => "success",
                "message" => "Solicitud eliminada correctamente.",
                "deleted_request_id" => $id
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } else {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "No se pudo eliminar la solicitud."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

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



private function sendEmail($to, $subject, $body)
{
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuración EXACTA que funcionaba en Node.js
        $mail->isSMTP();
        $mail->Host = $this->settingsEmail['host']; // Usar configuración desde .env
        $mail->SMTPAuth = true;
        $mail->Username = $this->settingsEmail['user'];
        $mail->Password = $this->settingsEmail['pass'];
        $mail->SMTPSecure = 'ssl';  // ← secure: true en Node.js = ssl en PHP
        $mail->Port = 465;          // ← puerto para SSL
        
        // Configuración adicional importante
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Timeout más largo
        $mail->Timeout = 30;
        
        // Remitente
        $mail->setFrom($this->settingsEmail['user'], 'Evaluasaurio Reportes');
        $mail->addAddress($to);
        
        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        $mail->CharSet = 'UTF-8';
        
        // Debug
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log("SMTP Level $level: $str");
        };
        
        return $mail->send();
        
    } catch (\Exception $e) {
        error_log("Error PHPMailer: " . $e->getMessage());
        return false;
    }
}

     // Función para retornar settingsEmail como JSON
    public function getSettingsEmail(Request $request, Response $response): Response
    {
        $payload = [
            'settingsEmail' => $this->settingsEmail
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Función para debug: imprimir en consola del servidor (error_log)
    public function debugSettingsEmail()
    {
        error_log(print_r($this->settingsEmail, true));
    }



}