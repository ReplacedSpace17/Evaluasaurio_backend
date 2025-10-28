<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AdminController
{
    private PDO $pdo;
    private string $jwtSecret;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        // Configura tu clave secreta para JWT (debería estar en variables de entorno)
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? 'tu_clave_secreta_muy_segura_aqui';
    }

    /**
     * Verificar si es el primer registro (para setup inicial)
     */
    public function isMyFirst(Request $request, Response $response): Response
    {
        try {
            $isEmpty = $this->isTableEmpty();
            
            $response->getBody()->write(json_encode([
                "status" => "success",
                "data" => [
                    "is_first" => $isEmpty,
                    "message" => $isEmpty ? 
                        "No hay administradores registrados. Puede crear el primer administrador." : 
                        "Ya existen administradores registrados en el sistema."
                ]
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Error al verificar el estado de la tabla: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Crear un nuevo administrador
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            // Obtener datos del cuerpo de la petición
            $data = json_decode($request->getBody()->getContents(), true);

            // Validar campos requeridos
            if (empty($data['email']) || empty($data['password'])) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "Faltan campos obligatorios: email y password."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $email = trim($data['email']);
            $password = $data['password'];

            // Validar formato de email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "El formato del email no es válido."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Validar fortaleza de la contraseña
            $passwordValidation = $this->validatePassword($password);
            if (!$passwordValidation['valid']) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => $passwordValidation['message']
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Verificar si el email ya existe
            if ($this->emailExists($email)) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "El email ya está registrado."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }

            // Hash de la contraseña
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            if ($passwordHash === false) {
                throw new \Exception("Error al generar el hash de la contraseña");
            }

            // Insertar en la base de datos
            $stmt = $this->pdo->prepare("
                INSERT INTO user_admin (correo, contraseña_hash, active) 
                VALUES (:email, :password, TRUE)
            ");

            $stmt->execute([
                ':email' => $email,
                ':password' => $passwordHash
            ]);

            // Obtener ID insertado
            $adminId = $this->pdo->lastInsertId();

            // Respuesta de éxito
            $response->getBody()->write(json_encode([
                "status" => "success",
                "message" => "Administrador creado exitosamente.",
                "data" => [
                    "id" => (int)$adminId,
                    "email" => $email,
                    "active" => true,
                    "is_first_admin" => $this->isTableEmpty() // Indica si fue el primer admin
                ]
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (\PDOException $e) {
            // Error de base de datos
            $errorCode = $e->getCode();
            
            if ($errorCode === '23000') { // Violación de unique constraint
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "El email ya está registrado."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }

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
     * Login de administrador con JWT
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);

            // Validar campos requeridos
            if (empty($data['email']) || empty($data['password'])) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "Faltan campos obligatorios: email y password."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $email = trim($data['email']);
            $password = $data['password'];

            // Buscar administrador
            $stmt = $this->pdo->prepare("
                SELECT id, correo, contraseña_hash, active 
                FROM user_admin 
                WHERE correo = :email
            ");
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificar si existe y está activo
            if (!$admin) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "Credenciales incorrectas."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            if (!$admin['active']) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "La cuenta está desactivada."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            // Verificar contraseña
            if (!password_verify($password, $admin['contraseña_hash'])) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "Credenciales incorrectas."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            // Generar token JWT válido por 1 hora
            $token = $this->generateJWT([
                'id' => (int)$admin['id'],
                'email' => $admin['correo'],
                'type' => 'admin'
            ]);

            // Respuesta de éxito con token
            $response->getBody()->write(json_encode([
                "status" => "success",
                "message" => "Login exitoso.",
                "data" => [
                    "id" => (int)$admin['id'],
                    "email" => $admin['correo'],
                    "active" => (bool)$admin['active'],
                    "token" => $token,
                    "token_type" => "Bearer",
                    "expires_in" => 3600, // 1 hora en segundos
                    "expires_at" => date('Y-m-d H:i:s', time() + 3600)
                ]
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Error en el login: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Generar token JWT
     */
    private function generateJWT(array $payload): string
    {
        $issuedAt = time();
        $expire = $issuedAt + 3600; // 1 hora

        $tokenPayload = [
            'iss' => 'tu_dominio.com', // Emisor
            'aud' => 'tu_dominio.com', // Audiencia
            'iat' => $issuedAt, // Tiempo de emisión
            'exp' => $expire, // Tiempo de expiración
            'data' => $payload // Datos del usuario
        ];

        return JWT::encode($tokenPayload, $this->jwtSecret, 'HS256');
    }

    /**
     * Verificar token JWT
     */
    public function verifyToken(Request $request, Response $response): Response
    {
        try {
            $authHeader = $request->getHeader('Authorization')[0] ?? '';
            
            if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "Token no proporcionado o formato inválido."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            $token = $matches[1];
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            
            // Token válido
            $response->getBody()->write(json_encode([
                "status" => "success",
                "message" => "Token válido.",
                "data" => [
                    "valid" => true,
                    "user" => $decoded->data,
                    "expires_at" => date('Y-m-d H:i:s', $decoded->exp)
                ]
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Token inválido: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
    }

    /**
     * Middleware para verificar token en otras rutas
     */
    public function middlewareVerifyToken(Request $request, Response $response, callable $next): Response
    {
        try {
            $authHeader = $request->getHeader('Authorization')[0] ?? '';
            
            if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $response->getBody()->write(json_encode([
                    "status" => "error",
                    "message" => "Token de autorización requerido."
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            $token = $matches[1];
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            
            // Agregar datos del usuario al request para uso posterior
            $request = $request->withAttribute('user', $decoded->data);
            
            return $next($request, $response);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Token inválido o expirado: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
    }

    // ... (los demás métodos permanecen igual: validatePassword, emailExists, isTableEmpty, getAll)

    /**
     * Validar fortaleza de la contraseña
     */
    private function validatePassword(string $password): array
    {
        if (strlen($password) < 8) {
            return [
                'valid' => false,
                'message' => 'La contraseña debe tener al menos 8 caracteres.'
            ];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'La contraseña debe contener al menos una letra mayúscula.'
            ];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'La contraseña debe contener al menos una letra minúscula.'
            ];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'La contraseña debe contener al menos un número.'
            ];
        }

        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            return [
                'valid' => false,
                'message' => 'La contraseña debe contener al menos un carácter especial.'
            ];
        }

        return ['valid' => true, 'message' => 'Contraseña válida.'];
    }

    /**
     * Verificar si el email ya existe
     */
    private function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM user_admin WHERE correo = :email");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() !== false;
    }

    /**
     * Verificar si la tabla está vacía
     */
    private function isTableEmpty(): bool
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM user_admin");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] == 0;
    }

    /**
     * Obtener todos los administradores (solo para super admin)
     */
    public function getAll(Request $request, Response $response): Response
    {
        try {
            $stmt = $this->pdo->query("
                SELECT id, correo, active, created_at, updated_at 
                FROM user_admin 
                ORDER BY created_at DESC
            ");
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                "status" => "success",
                "data" => [
                    "total" => count($admins),
                    "admins" => $admins
                ]
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Error al obtener administradores: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    /**
 * Obtener todos los emails de administradores
 *//**
 * Obtener todos los administradores con todos los datos
 */
public function getAllAdmins(Request $request, Response $response): Response
{
    try {
        $stmt = $this->pdo->query("
            SELECT id, correo, active, created_at, updated_at 
            FROM user_admin 
            ORDER BY created_at DESC
        ");
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode([
            "status" => "success",
            "data" => [
                "total" => count($admins),
                "admins" => $admins
            ]
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error al obtener administradores: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

/**
 * Eliminar administrador
 */
public function deleteAdmin(Request $request, Response $response, array $args): Response
{
    try {
        $adminId = $args['id'] ?? null;
        
        if (!$adminId) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "ID de administrador no proporcionado."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Verificar si existe
        $stmt = $this->pdo->prepare("SELECT id FROM user_admin WHERE id = :id");
        $stmt->execute([':id' => $adminId]);
        
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Administrador no encontrado."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Eliminar
        $stmt = $this->pdo->prepare("DELETE FROM user_admin WHERE id = :id");
        $stmt->execute([':id' => $adminId]);

        $response->getBody()->write(json_encode([
            "status" => "success",
            "message" => "Administrador eliminado correctamente."
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            "status" => "error",
            "message" => "Error al eliminar administrador: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
}

/**
 * Actualizar contraseña de administrador
 */
public function updatePassword(Request $request, Response $response, array $args): Response
{
    try {
        // Obtener ID del administrador desde los parámetros de la ruta
        $adminId = $args['id'] ?? null;
        
        if (!$adminId) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "ID de administrador no proporcionado."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Obtener datos del cuerpo de la petición
        $data = json_decode($request->getBody()->getContents(), true);

        // Validar campos requeridos
        if (empty($data['current_password']) || empty($data['new_password'])) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Faltan campos obligatorios: current_password y new_password."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $currentPassword = $data['current_password'];
        $newPassword = $data['new_password'];

        // Buscar administrador en la base de datos
        $stmt = $this->pdo->prepare("
            SELECT id, correo, contraseña_hash, active 
            FROM user_admin 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar si el administrador existe
        if (!$admin) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Administrador no encontrado."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Verificar si el administrador está activo
        if (!$admin['active']) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "La cuenta del administrador está desactivada."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Verificar contraseña actual
        if (!password_verify($currentPassword, $admin['contraseña_hash'])) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "La contraseña actual es incorrecta."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Validar que la nueva contraseña sea diferente a la actual
        if (password_verify($newPassword, $admin['contraseña_hash'])) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "La nueva contraseña debe ser diferente a la actual."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validar fortaleza de la nueva contraseña
        $passwordValidation = $this->validatePassword($newPassword);
        if (!$passwordValidation['valid']) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => $passwordValidation['message']
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Hash de la nueva contraseña
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($newPasswordHash === false) {
            throw new \Exception("Error al generar el hash de la nueva contraseña");
        }

        // Actualizar contraseña en la base de datos
        $stmt = $this->pdo->prepare("
            UPDATE user_admin 
            SET contraseña_hash = :password_hash, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");

        $stmt->execute([
            ':password_hash' => $newPasswordHash,
            ':id' => $adminId
        ]);

        // Verificar si se actualizó correctamente
        if ($stmt->rowCount() === 0) {
            throw new \Exception("No se pudo actualizar la contraseña");
        }

        // Respuesta de éxito
        $response->getBody()->write(json_encode([
            "status" => "success",
            "message" => "Contraseña actualizada exitosamente.",
            "data" => [
                "id" => (int)$adminId,
                "email" => $admin['correo'],
                "password_updated" => true,
                "updated_at" => date('Y-m-d H:i:s')
            ]
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


public function updatePasswordByAdmin(Request $request, Response $response, array $args): Response
{
    try {
        // Obtener ID del administrador desde los parámetros de la ruta
        $adminId = $args['id'] ?? null;
        
        if (!$adminId) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "ID de administrador no proporcionado."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Obtener datos del cuerpo de la petición
        $data = json_decode($request->getBody()->getContents(), true);

        // Validar campos requeridos
        if (empty($data['new_password'])) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Faltan campos obligatorios: new_password."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $newPassword = $data['new_password'];

        // Buscar administrador en la base de datos
        $stmt = $this->pdo->prepare("
            SELECT id, correo, active 
            FROM user_admin 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar si el administrador existe
        if (!$admin) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => "Administrador no encontrado."
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Validar fortaleza de la nueva contraseña
        $passwordValidation = $this->validatePassword($newPassword);
        if (!$passwordValidation['valid']) {
            $response->getBody()->write(json_encode([
                "status" => "error",
                "message" => $passwordValidation['message']
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Hash de la nueva contraseña
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($newPasswordHash === false) {
            throw new \Exception("Error al generar el hash de la nueva contraseña");
        }

        // Actualizar contraseña en la base de datos
        $stmt = $this->pdo->prepare("
            UPDATE user_admin 
            SET contraseña_hash = :password_hash, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");

        $stmt->execute([
            ':password_hash' => $newPasswordHash,
            ':id' => $adminId
        ]);

        // Verificar si se actualizó correctamente
        if ($stmt->rowCount() === 0) {
            throw new \Exception("No se pudo actualizar la contraseña");
        }

        // Respuesta de éxito
        $response->getBody()->write(json_encode([
            "status" => "success",
            "message" => "Contraseña actualizada exitosamente.",
            "data" => [
                "id" => (int)$adminId,
                "email" => $admin['correo'],
                "password_updated" => true,
                "updated_at" => date('Y-m-d H:i:s')
            ]
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

}