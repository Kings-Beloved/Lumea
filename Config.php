<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class Config {
    protected $connection;
    protected const APP_SECRET_KEY = 'cf42d5937c8a38b17be5ce2afdeb4a074a3ed8af4326894052930ebd44b5c0c9';

    public function __construct() {
        $host     = getenv('DB_HOST')     ?: 'localhost';
        $user     = getenv('DB_USER')     ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';
        $database = getenv('DB_NAME')     ?: 'lumea_database';
        $port     = getenv('DB_PORT')     ?: 16565;

        try {
            $this->connection = mysqli_connect($host, $user, $password, $database, (int)$port);

            if (!$this->connection) {
                die(json_encode([
                    'status' => 500,
                    'message' => 'Database Connection Failed: ' . mysqli_connect_error()
                ]));
            }
        } catch (mysqli_sql_exception $e) {
            die(json_encode([
                'status' => 500,
                'message' => 'Database Connection Exception: ' . $e->getMessage()
            ]));
        }
    }

    public function getConnection() {
        return $this->connection;
    }
}