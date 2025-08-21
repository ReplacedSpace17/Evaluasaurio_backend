<?php
namespace App\Models;

class SubjectRequest
{
    public int $id;
    public string $name;
    public ?string $description;
    public string $status;
    public string $created_at;
}
