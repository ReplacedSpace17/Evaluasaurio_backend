<?php
namespace App\Controllers;

use App\Utils\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class SubjectController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $textFind = $queryParams["find"] ?? null;
        $paramsSql = [];

        $query = "SELECT id, name, created_at FROM subjects";

        if($textFind){
            $searchTerm = "%".strtolower(trim($textFind))."%";
            
            $whereClause = " WHERE LOWER(name) LIKE :find_name ";

            $paramsSql = [
                ":find_name" => $searchTerm,
            ];

            $query .= $whereClause;
        }

        $paginator = new Paginator($request, $this->pdo);
        $dataPagination = $paginator->paginate($query, $paramsSql);

        $payload = json_encode($dataPagination, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
