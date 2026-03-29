<?php
// config/database.php - Database configuration for MyResultChecker Portal
// Secure database connection using PDO

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'impactdi_result-checker');
define('DB_USER', 'impactdi_result-checker');
define('DB_PASS', 'uenrqFrgYbcY5YmSLTH6');

// Set timezone
date_default_timezone_set('Africa/Lagos'); // Nigeria timezone

// Disable error display in production (log instead)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

class Database
{
    private static $instance = null;
    private $connection;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        try {
            // Set PDO options for better security and performance
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch as associative array
                PDO::ATTR_EMULATE_PREPARES => false,  // Use native prepared statements
                PDO::ATTR_PERSISTENT => false,  // Don't use persistent connections
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            // Create DSN (Data Source Name)
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";

            // Create PDO instance
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log error and show user-friendly message
            error_log("Database Connection Error: " . $e->getMessage());

            // In production, show a generic error
            if ($this->isProduction()) {
                die("Database connection failed. Please try again later.");
            } else {
                // In development, show detailed error
                die("Database Connection Failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Get singleton instance of Database
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Get database connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Check if environment is production
     */
    private function isProduction()
    {
        // You can set this in your server environment
        // For now, check domain or use a constant
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return (strpos($host, 'localhost') === false && strpos($host, '192.168') === false);
    }

    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback()
    {
        return $this->connection->rollBack();
    }

    /**
     * Prepare and execute a query with parameters
     */
    public function executeQuery($sql, $params = [])
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            throw $e;
        }
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Escape string (for manual queries, though prepared statements are preferred)
     */
    public function escape($string)
    {
        return substr($this->connection->quote($string), 1, -1);
    }

    /**
     * Test database connection
     */
    public function testConnection()
    {
        try {
            $stmt = $this->connection->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get database version
     */
    public function getVersion()
    {
        return $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
}

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

// Simple test script (remove in production)
if (basename($_SERVER['PHP_SELF']) == 'database.php' && isset($_GET['test'])) {
    header('Content-Type: text/plain');
    $db = Database::getInstance();
    if ($db->testConnection()) {
        echo "Database connection successful!\n";
        echo "MySQL Version: " . $db->getVersion() . "\n";
    } else {
        echo "Database connection failed!\n";
    }
    exit();
}
