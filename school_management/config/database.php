<?php
header('Content-Type: application/json');

class Database
{
    private $host = "localhost";
    private $db_name = "impactdi_school_management";
    private $username = "impactdi_school_management";
    private $password = "innioluwa1995";
    private $conn;

    public function __construct()
    {
        // Public constructor - removes the warning
    }

    public function getConnection()
    {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8mb4");
        } catch (PDOException $exception) {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => "Database connection failed: " . $exception->getMessage()]);
            exit();
        }
        return $this->conn;
    }
}
