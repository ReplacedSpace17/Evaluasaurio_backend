<?php
namespace App;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;

    public static function getConnection(array $settings): PDO
    {
        if (self::$instance === null) {
            $host = $settings['host'];
            $dbname = $settings['dbname'];
            $user = $settings['user'];
            $pass = $settings['pass'];
            $charset = $settings['charset'];

            $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new PDOException($e->getMessage(), (int)$e->getCode());
            }
        }

        return self::$instance;
    }
}
