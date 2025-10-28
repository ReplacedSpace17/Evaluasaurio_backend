<?php 

namespace App\Utils;

use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

/*
En caso de que esto crezca sera mejor usar un ORM
*/
class Paginator {
    private int $page;
    private int $limit;
    private Request $request;
    private PDO $pdo;

    public function __construct(Request $request, PDO $pdo){
        $paramsRequest = $request->getQueryParams();
        $this->page = isset($paramsRequest['page']) ? max(1, (int)$paramsRequest['page']) : 1;
        $this->limit = isset($paramsRequest['limit']) ? max(1, (int)$paramsRequest['limit']) : 10;
        $this->request = $request;
        $this->pdo = $pdo;
    }

    public function getData(string $query, array $params, int $fetchType){
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);

        return $statement->fetchAll($fetchType);
    }

    public function paginate(string $baseQuery, array $params = [], int $fetchType = PDO::FETCH_BOTH): array{
        $offset = ($this->page - 1) * $this->limit;
        
        $baseCountQuery = "SELECT COUNT(*) as total FROM (" . $baseQuery . ") as subquery";
        $baseQuery .= " LIMIT :limit OFFSET :offset";
        $paginateParams = [":limit" => $this->limit, ":offset" => $offset];
        
        $data = $this->getData($baseQuery, [...$paginateParams, ...$params], $fetchType);
        $meta = $this->getMeta($baseCountQuery, $params);

        return [
            "data" => $data,
            ...$meta,
        ];
    }

    private function getMeta(string $baseCountQuery, array $params): array {
        $countStmt = $this->pdo->prepare($baseCountQuery);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $lastPage = (int) ceil($total / $this->limit);

        $uri = $this->request->getUri();
        $queryParams = $this->request->getQueryParams();

        $buildUrl = function (int $page) use ($uri, $queryParams) {
            $queryParams['page'] = $page;
            $queryString = http_build_query($queryParams);
            
            $port = $uri->getPort();
            $portSegment = $port ? ':' . $port : '';
            
            return $uri->getScheme() 
                . '://' 
                . $uri->getHost() 
                . $portSegment 
                . $uri->getPath() 
                . '?' 
                . $queryString;
        };

        return [
            'meta' => [
                'current_page' => $this->page,
                'last_page' => $lastPage,
                'per_page' => $this->limit,
                'total' => $total
            ],
            'links' => [
                'prev' => $this->page > 1 ? $buildUrl($this->page - 1) : null,
                'next' => $this->page < $lastPage ? $buildUrl($this->page + 1) : null
            ]
        ];
    }

    public function getPage(){
        return $this->page;
    }

    public function getLimit(){
        return $this->limit;
    }

}