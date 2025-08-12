<?php
namespace App\Models;

class CalificationToTeacher
{
    public int $id;
    public int $teacher_id;
    public string $opinion;
    public string $keywords;
    public int $score;
    public int $materia_id;
    public string $created_at;
}
