<?php
namespace App\Controllers;

use PDO;

class ReportsController {
    private $pdo;
    private $uploadDir;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->uploadDir = __DIR__ . '/../../public/uploads/reports/';
// carpeta base donde se guardarán las imágenes
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
    $mes = date("F", strtotime($fecha_hora)); // nombre del mes en inglés
    $uploadPath = $this->uploadDir . "$anio/$mes/";

    if (!file_exists($uploadPath)) {
        mkdir($uploadPath, 0777, true); // crear carpeta recursivamente
    }

    // Guardar imagen (si se envía)
    $fotoNombre = "none"; // valor por defecto en caso de no enviar imagen
    if (isset($uploadedFiles['foto'])) {
        $foto = $uploadedFiles['foto'];
        if ($foto->getError() === UPLOAD_ERR_OK) {
            $extension = pathinfo($foto->getClientFilename(), PATHINFO_EXTENSION);
            $fotoNombre = uniqid('report_') . '.' . $extension;
            $rutaDestino = $uploadPath . $fotoNombre;
            $foto->moveTo($rutaDestino);

            // Guardar ruta relativa en la BD (para servir desde frontend)
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
    // $args['path'] contendrá la ruta relativa de la imagen
    $path = $args['path'] ?? '';
    $fullPath = $this->uploadDir . $path;

    if (!file_exists($fullPath) || !is_file($fullPath)) {
        $response->getBody()->write(json_encode(["message" => "Imagen no encontrada."]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    // Determinar tipo MIME
    $mime = mime_content_type($fullPath);

    // Leer contenido de la imagen
    $imageContent = file_get_contents($fullPath);

    $response = $response->withHeader('Content-Type', $mime);
    $response->getBody()->write($imageContent);
    return $response;
}


}
