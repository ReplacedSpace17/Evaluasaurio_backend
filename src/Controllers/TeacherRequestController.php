<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;;
use PHPMailer\PHPMailer\Exception;
class TeacherRequestController
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

        // 🔔 Enviar correo a administradores notificando la nueva solicitud
        $stmtAdmins = $this->pdo->query("SELECT correo FROM user_admin");
        $admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

       if (!empty($admins)) {
    $subject = "📢 Nueva Solicitud de Alta de Docente";
    $mailBody = "
    <html>
    <head>
        <style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                background-color: #f4f4f9; 
                margin: 0; 
                padding: 0; 
            }
            .container {
                max-width: 600px; 
                margin: 30px auto; 
                background-color: #ffffff; 
                border-radius: 8px; 
                box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
                overflow: hidden;
            }
            .header {
                background-color: #0D3B66; /* azul oscuro */
                color: white; 
                padding: 20px; 
                text-align: center;
            }
            .header h2 {
                margin: 0;
                font-size: 22px;
            }
            .content {
                padding: 25px;
                color: #333333;
            }
            .content p {
                font-size: 16px;
            }
            .content ul {
                list-style: none;
                padding: 0;
            }
            .content li {
                background: #f0f4f8;
                margin: 5px 0;
                padding: 10px 15px;
                border-radius: 5px;
            }
            .footer {
                background: #e0e0e0; 
                padding: 15px; 
                text-align: center; 
                font-size: 12px; 
                color: #555555;
            }
            .btn {
                display: inline-block;
                padding: 10px 20px;
                margin-top: 15px;
                background-color: #0D3B66;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Solicitud de Alta de Docente</h2>
            </div>
            <div class='content'>
                <p>Se ha registrado una nueva solicitud en el sistema:</p>
                <ul>
                    <li><strong>ID:</strong> {$lastId}</li>
                    <li><strong>Nombre:</strong> {$data['name']}</li>
                    <li><strong>Apellido Paterno:</strong> {$data['apellido_paterno']}</li>
                    <li><strong>Apellido Materno:</strong> {$data['apellido_materno']}</li>
                    <li><strong>Departamento:</strong> {$data['department']}</li>
                    <li><strong>Email:</strong> {$data['email']}</li>
                </ul>
                <a href='https://evaluasaurio.singularitymx.org/admin' class='btn'>Ver Solicitud</a>
            </div>
            <div class='footer'>
                <p>Evaluasaurio - Sistema de Gestión de Evaluaciones</p>
                <p>© " . date('Y') . " Singularity MX</p>
            </div>
        </div>
    </body>
    </html>
    ";

    foreach ($admins as $adminEmail) {
        $this->sendEmail($adminEmail, $subject, $mailBody);
    }
}

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


    // Obtener todas las solicitudes
public function getAll(Request $request, Response $response): Response
{
    try {
        // Contar solicitudes pendientes
        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) as total FROM teacher_requests WHERE status = 'pendiente'");
        $stmtCount->execute();
        $countResult = $stmtCount->fetch(PDO::FETCH_ASSOC);
        $pendienteCount = (int)$countResult['total'];
        
        // Obtener todas las solicitudes
        $stmtData = $this->pdo->query("SELECT * FROM teacher_requests ORDER BY created_at DESC");
        $requests = $stmtData->fetchAll(PDO::FETCH_ASSOC);
        
        $payload = json_encode([
            "pendiente_teacher" => $pendienteCount,
            "data" => $requests
        ], JSON_UNESCAPED_UNICODE);
        
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\PDOException $e) {
        $response->getBody()->write(json_encode([
            "error" => "Error al obtener las solicitudes: " . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
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
        $mail->setFrom($this->settingsEmail['user'], 'Evaluasaurio Solicitudes');
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

public function approve(Request $request, Response $response, array $args): Response
{
    $id = $args['id'] ?? null;

    if (!$id) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Falta el parámetro ID en la solicitud.'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        // Verificar que la solicitud exista
        $stmtCheck = $this->pdo->prepare("SELECT * FROM teacher_requests WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $teacher = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$teacher) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => "No se encontró la solicitud con ID $id"
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Actualizar estado a aprobado
        $stmt = $this->pdo->prepare("
            UPDATE teacher_requests 
            SET status = 'aprobado'
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        // Si tiene correo, enviar notificación
        if (!empty($teacher['email'])) {
            $subject = "✅ Solicitud de Alta de Docente Aprobada";
            $mailBody = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 30px auto; background: #fff; padding: 20px; border-radius: 8px;
                                 box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
                    .header { background-color: #0D3B66; color: white; text-align: center; padding: 15px; border-radius: 8px 8px 0 0; }
                    .content { padding: 20px; color: #333; }
                    .footer { text-align: center; font-size: 12px; color: #777; margin-top: 15px; }
                    .btn { display: inline-block; padding: 10px 20px; background-color: #0D3B66; color: white; text-decoration: none; border-radius: 5px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Solicitud Aprobada</h2>
                    </div>
                    <div class='content'>
                        <p>Estimado(a) <strong>{$teacher['name']} {$teacher['apellido_paterno']}</strong>,</p>
                        <p>Nos complace informarle que su <strong>solicitud de alta como docente</strong> ha sido aprobada satisfactoriamente.</p>
                        <p>Ya puede acceder al sistema Evaluasaurio.</p>
                        <a href='https://evaluasaurio.singularitymx.org' class='btn'>Acceder al sistema</a>
                    </div>
                    <div class='footer'>
                        <p>Evaluasaurio - Sistema de Gestión de Evaluaciones</p>
                        <p>© " . date('Y') . " Singularity MX</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            $this->sendEmail($teacher['email'], $subject, $mailBody);
        } else {
            error_log("⚠️ No se envió correo: el docente con ID $id no tiene email registrado.");
        }

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => "Solicitud con ID $id aprobada correctamente"
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        error_log("❌ Error al aprobar solicitud: " . $e->getMessage());
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Error interno del servidor'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

public function reject(Request $request, Response $response, array $args): Response
{
    $id = $args['id'] ?? null;

    if (!$id) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Falta el parámetro ID en la solicitud.'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        // Verificar que la solicitud exista
        $stmtCheck = $this->pdo->prepare("SELECT * FROM teacher_requests WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $teacher = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$teacher) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => "No se encontró la solicitud con ID $id"
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Cambiar estado a "rechazado" (sin enviar correo)
        $stmt = $this->pdo->prepare("
            UPDATE teacher_requests 
            SET status = 'rechazado'
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => "Solicitud con ID $id ha sido marcada como rechazada."
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        error_log("❌ Error al rechazar solicitud: " . $e->getMessage());
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Error interno del servidor.'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}


}
