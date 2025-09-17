<?php
namespace App\Models;

class TeacherRequest
{
    public int $id;
    public string $name;
    public string $apellido_paterno;
    public string $apellido_materno;
    public string $sexo;
    public string $departament;
    public ?string $email;
    public string $status;
    public string $created_at;
}
