<?php
namespace App\Controllers;

use PDO;

class ReportsController {
    private $pdo;
    private $uploadDir;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->uploadDir = __DIR__ . '/../../public/uploads/reports/';
    }

    public function create($request, $response) {
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        // Campos recibidos
        $tipo = $data['tipo_incidente'] ?? null;
        $descripcion = $data['descripcion'] ?? null;
        $ubicacion = $data['ubicacion'] ?? null;
        $fecha_hora = $data['fecha_hora'] ?? date("Y-m-d H:i:s");

        // Validar campos obligatorios
        if (!$tipo || !$descripcion) {
            $response->getBody()->write(json_encode(["message" => "Faltan campos requeridos."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Crear subcarpetas según año y mes
        $anio = date("Y", strtotime($fecha_hora));
        $mes = date("F", strtotime($fecha_hora));
        $uploadPath = $this->uploadDir . "$anio/$mes/";

        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        // Valor por defecto si no se envía imagen
        $fotoNombre = "none";

        // Validar y guardar imagen
        if (isset($uploadedFiles['foto'])) {
            $foto = $uploadedFiles['foto'];

            if ($foto->getError() === UPLOAD_ERR_OK) {
                // Validar tamaño máximo (por ejemplo, 5MB)
                $maxSize = 5 * 1024 * 1024; // 5 MB
                if ($foto->getSize() > $maxSize) {
                    $response->getBody()->write(json_encode(["message" => "La imagen es demasiado grande. Máximo 5 MB."]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // Validar extensión
                $extension = strtolower(pathinfo($foto->getClientFilename(), PATHINFO_EXTENSION));
                $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (!in_array($extension, $extensionesPermitidas)) {
                    $response->getBody()->write(json_encode(["message" => "Formato de imagen no permitido."]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // Validar tipo MIME real
                $tmpPath = $foto->getStream()->getMetadata('uri');
                $mimeType = mime_content_type($tmpPath);
                $mimesPermitidos = [
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/webp'
                ];
                if (!in_array($mimeType, $mimesPermitidos)) {
                    $response->getBody()->write(json_encode(["message" => "El archivo no es una imagen válida."]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // Validar que realmente sea una imagen (previene shells renombrados)
                if (getimagesize($tmpPath) === false) {
                    $response->getBody()->write(json_encode(["message" => "El archivo no contiene datos de imagen válidos."]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // Generar nombre único y seguro
                $fotoNombre = uniqid('report_', true) . '.' . $extension;
                $rutaDestino = $uploadPath . $fotoNombre;

                // Mover archivo
                $foto->moveTo($rutaDestino);

                // Permisos seguros
                chmod($rutaDestino, 0644);

                // Guardar ruta relativa para servirla después
                $fotoNombre = "$anio/$mes/$fotoNombre";
            }
        }

        // Insertar en la base de datos
        $sql = "INSERT INTO reports (tipo_incidente, descripcion, ubicacion, foto, fecha_hora)
                VALUES (:tipo, :descripcion, :ubicacion, :foto, :fecha_hora)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':descripcion', $descripcion);
        $stmt->bindParam(':ubicacion', $ubicacion);
        $stmt->bindParam(':foto', $fotoNombre);
        $stmt->bindParam(':fecha_hora', $fecha_hora);

        if ($stmt->execute()) {
            $response->getBody()->write(json_encode(["message" => "Reporte creado exitosamente."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } else {
            $response->getBody()->write(json_encode(["message" => "Error al guardar el reporte."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function getAllReports($request, $response) {
        try {
            $sql = "SELECT * FROM reports ORDER BY fecha_hora DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                "success" => true,
                "data" => $reports
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                "success" => false,
                "message" => "Error al obtener los reportes",
                "error" => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function serveImage($request, $response, $args) {
        $path = $args['path'] ?? '';
        $fullPath = realpath($this->uploadDir . $path);

        // Evitar directory traversal
        if (!$fullPath || !str_starts_with($fullPath, realpath($this->uploadDir))) {
            $response->getBody()->write(json_encode(["message" => "Acceso no autorizado."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        if (!file_exists($fullPath) || !is_file($fullPath)) {
            $response->getBody()->write(json_encode(["message" => "Imagen no encontrada."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $mime = mime_content_type($fullPath);
        if (!str_starts_with($mime, 'image/')) {
            $response->getBody()->write(json_encode(["message" => "Archivo inválido."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $imageContent = file_get_contents($fullPath);
        $response = $response->withHeader('Content-Type', $mime);
        $response->getBody()->write($imageContent);
        return $response;
    }
}
