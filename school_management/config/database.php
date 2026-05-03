<?php
// api/config/database.php (with docblock to suppress warnings)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class Database
{
    private $host = "localhost";
    private $db_name = "impactdi_school_management";
    private $username = "impactdi_school_management";
    private $password = "innioluwa1995";
    private $conn;

    /**
     * Database constructor.
     * Public constructor to allow instantiation
     */
    public function __construct()
    {
        // Initialization if needed
    }

    /**
     * Get database connection
     * @return PDO|null
     */
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
            echo json_encode(["error" => "Connection error: " . $exception->getMessage()]);
            exit();
        }
        return $this->conn;
    }
}
