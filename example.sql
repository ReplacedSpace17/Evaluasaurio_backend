-- Crear base de datos
CREATE DATABASE IF NOT EXISTS example CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Usar la base de datos
USE example;

-- Crear tabla alumnos
CREATE TABLE IF NOT EXISTS alumnos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    correo VARCHAR(150) UNIQUE NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar 4 registros de ejemplo
INSERT INTO alumnos (nombre, apellido, correo, fecha_nacimiento) VALUES
('Javier', 'Gutiérrez', 'javier.gutierrez@example.com', '1990-05-15'),
('María', 'López', 'maria.lopez@example.com', '1992-08-22'),
('Carlos', 'Martínez', 'carlos.martinez@example.com', '1988-11-10'),
('Ana', 'Ramírez', 'ana.ramirez@example.com', '1995-03-05');
