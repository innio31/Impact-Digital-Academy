    <!-- backend/config/database.php -->
    <?php
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

    // Handle preflight requests
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
        public $conn;

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
                $this->conn->exec("set names utf8");
            } catch (PDOException $exception) {
                echo json_encode(["error" => "Connection error: " . $exception->getMessage()]);
                exit;
            }
            return $this->conn;
        }
    }

    // JWT Authentication Helper
    class Auth
    {
        private static $secret_key = 'your-secret-key-change-this-to-something-secure';
        private static $algorithm = 'HS256';

        public static function generateToken($user_data)
        {
            $header = json_encode(['typ' => 'JWT', 'alg' => self::$algorithm]);
            $payload = json_encode([
                'user_id' => $user_data['id'],
                'email' => $user_data['email'],
                'user_type' => $user_data['user_type'],
                'school_id' => $user_data['school_id'] ?? null,
                'exp' => time() + (7 * 24 * 60 * 60) // 7 days expiry
            ]);

            $base64_header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $base64_payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
            $signature = hash_hmac('sha256', $base64_header . "." . $base64_payload, self::$secret_key, true);
            $base64_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

            return $base64_header . "." . $base64_payload . "." . $base64_signature;
        }

        public static function validateToken($token)
        {
            try {
                $token_parts = explode('.', $token);
                if (count($token_parts) != 3) return false;

                $signature = hash_hmac('sha256', $token_parts[0] . "." . $token_parts[1], self::$secret_key, true);
                $base64_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

                if ($base64_signature !== $token_parts[2]) return false;

                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[1])), true);
                if ($payload['exp'] < time()) return false;

                return $payload;
            } catch (Exception $e) {
                return false;
            }
        }

        public static function authenticate()
        {
            $headers = getallheaders();
            $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';

            if (!$auth_header) {
                http_response_code(401);
                echo json_encode(['error' => 'No authorization token provided']);
                exit;
            }

            $token = str_replace('Bearer ', '', $auth_header);
            $user_data = self::validateToken($token);

            if (!$user_data) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid or expired token']);
                exit;
            }

            return $user_data;
        }

        public static function checkRole($user_data, $allowed_roles)
        {
            if (!in_array($user_data['user_type'], $allowed_roles)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied. Insufficient permissions.']);
                exit;
            }
            return true;
        }
    }
    ?>