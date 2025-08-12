<?php
namespace App\Models;

use PDO;

class Alumno
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function obtenerTodos(): array
    {
        $stmt = $this->pdo->query('SELECT id, nombre, apellido, correo, fecha_nacimiento FROM alumnos');
        return $stmt->fetchAll();
    }

    // Puedes agregar más métodos para insertar, actualizar, eliminar, etc.
}
