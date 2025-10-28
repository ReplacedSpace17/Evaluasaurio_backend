<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;;
use PHPMailer\PHPMailer\Exception;

class SubjectRequestController
{
    private PDO $pdo;
   private array $settingsEmail;

    public function __construct(PDO $pdo, array $settingsEmail)
    {
        $this->pdo = $pdo;
        $this->settingsEmail = $settingsEmail;
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

    // Insertar la solicitud
    $stmt = $this->pdo->prepare("
        INSERT INTO subject_requests (name, description)
        VALUES (:name, :description)
    ");
    $stmt->execute([
        ':name' => $data['name'],
        ':description' => $data['description'] ?? null
    ]);

    $lastId = $this->pdo->lastInsertId();

    // ----------------------------
    // Enviar correo a los administradores
    // ----------------------------
    $adminsStmt = $this->pdo->query("SELECT correo FROM user_admin");
    $admins = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($admins)) {
        $subject = "📢 Nueva Solicitud de Alta de Materia";
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
                    background-color: #0D3B66; 
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
                    <h2>Solicitud de Alta de Materia</h2>
                </div>
                <div class='content'>
                    <p>Se ha registrado una nueva solicitud en el sistema:</p>
                    <ul>
                        <li><strong>ID:</strong> {$lastId}</li>
                        <li><strong>Nombre:</strong> {$data['name']}</li>
                        <li><strong>Descripción:</strong> {$data['description']}</li>
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

    // Respuesta JSON igual que antes
    $response->getBody()->write(json_encode(['status' => 'success', 'id' => $lastId]));
    return $response->withHeader('Content-Type', 'application/json');
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
// Aprobar solicitud (sin enviar correo)
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
        // Verificar que exista la solicitud
        $stmtCheck = $this->pdo->prepare("SELECT * FROM subject_requests WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $requestData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$requestData) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => "No se encontró la solicitud con ID $id."
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Actualizar estado a aprobado
        $stmt = $this->pdo->prepare("
            UPDATE subject_requests 
            SET status = 'aprobado'
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => "Solicitud con ID $id aprobada correctamente."
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\PDOException $e) {
        error_log("❌ Error al aprobar solicitud: " . $e->getMessage());
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Error interno del servidor.'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

// Rechazar solicitud (sin enviar correo)
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
        // Verificar que exista la solicitud
        $stmtCheck = $this->pdo->prepare("SELECT * FROM subject_requests WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $requestData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$requestData) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => "No se encontró la solicitud con ID $id."
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Actualizar estado a rechazado
        $stmt = $this->pdo->prepare("
            UPDATE subject_requests 
            SET status = 'rechazado'
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => "Solicitud con ID $id marcada como rechazada."
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
