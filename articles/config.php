<?php
// config.php - Full configuration file with all constants defined
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// ========== DATABASE CONFIGURATION ==========
// UPDATE THESE WITH YOUR HOSTAFRICA DETAILS
define('DB_HOST', 'localhost');
define('DB_NAME', 'impactdi_portal');
define('DB_USER', 'impactdi_portal');
define('DB_PASS', 'yCuhEpaX3rRVxRrWMWGJ');

// ========== EMAIL CONFIGURATION (SMTP) ==========
// UPDATE WITH YOUR HOSTAFRICA EMAIL SETTINGS
define('SMTP_HOST', 'mail.impactdigitalacademy.com.ng');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@impactdigitalacademy.com.ng');   // Your email address
define('SMTP_PASS', 'Innioluwa@1995');      // Your email password
define('SMTP_FROM', 'noreply@impactdigitalacademy.com.ng');   // From address
define('SMTP_FROM_NAME', 'Impact Digital Academy');

// ========== SITE CONFIGURATION ==========
define('SITE_URL', 'https://impactdigitalacademy.com.ng');    // CHANGE THIS to your actual domain
define('SITE_NAME', 'Impact Digital Academy');

// Load PHPMailer - adjust path based on your installation
$phpmailer_paths = [
    __DIR__ . '/phpmailer/src/PHPMailer.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/libs/phpmailer/src/PHPMailer.php'
];

$loaded = false;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        require_once dirname($path) . '/SMTP.php';
        require_once dirname($path) . '/Exception.php';
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    error_log("PHPMailer not found. Please download from https://github.com/PHPMailer/PHPMailer");
    // Don't exit - we'll handle errors gracefully
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Database
{
    private $conn = null;

    public function getConnection()
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            return $this->conn;
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
}

class MailSender
{
    private $mail;
    private $error = '';

    public function __construct()
    {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->error = 'PHPMailer not loaded';
            error_log($this->error);
            return;
        }

        try {
            $this->mail = new PHPMailer(true);

            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host       = SMTP_HOST;
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = SMTP_USER;
            $this->mail->Password   = SMTP_PASS;
            $this->mail->SMTPSecure = SMTP_PORT == 587 ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $this->mail->Port       = SMTP_PORT;

            // Default sender
            $this->mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $this->mail->isHTML(true);
            $this->mail->CharSet = 'UTF-8';
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            error_log("Mailer setup failed: " . $this->error);
        }
    }

    public function sendWelcomeEmail($to)
    {
        // Check if mailer is properly initialized
        if (!isset($this->mail) || !empty($this->error)) {
            error_log("Mailer not available: " . $this->error);
            return false;
        }

        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($to);

            $this->mail->Subject = 'Welcome to Impact Digital Educators Series!';

            // HTML email body
            $this->mail->Body = $this->getWelcomeHTML($to);
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $this->getWelcomeHTML($to)));

            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Welcome email failed to $to: " . $e->getMessage());
            return false;
        }
    }

    private function getWelcomeHTML($to)
    {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #008080; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f7f3; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #f59e0b; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                .footer { margin-top: 30px; font-size: 0.9em; color: #666; text-align: center; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Welcome to Impact Digital Academy!</h2>
                </div>
                <div class="content">
                    <p>Dear Educator,</p>
                    <p>Thank you for joining our Nigerian Educators Series. You\'re now part of a community of passionate teachers who believe that real education happens inside, not around.</p>
                    
                    <h3>What to expect:</h3>
                    <ul>
                        <li>Weekly articles with practical teaching strategies</li>
                        <li>Real stories from Nigerian classrooms</li>
                        <li>Exclusive resources and lesson ideas</li>
                        <li>Invitations to free webinars</li>
                    </ul>
                    
                    <p>Your next article, <strong>"The Classroom Is Not a Prison: Why Students Check Out and How to Invite Them Back In"</strong>, will arrive next week.</p>
                    
                    <p style="text-align: center;">
                        <a href="' . SITE_URL . '/articles.html" class="button">Explore All Articles</a>
                    </p>
                    
                    <p>Keep impacting lives,<br>
                    <strong>The Impact Digital Team</strong></p>
                    
                    <p><small>P.S. Share this journey with a colleague who needs to hear it.</small></p>
                </div>
                <div class="footer">
                    <p>' . SITE_NAME . ' | Ota, Nigeria</p>
                    <p><small>You received this because you subscribed to our Educators Series. <a href="' . SITE_URL . '/unsubscribe.php?email=' . urlencode($to) . '">Unsubscribe</a></small></p>
                </div>
            </div>
        </body>
        </html>
        ';
    }

    public function getError()
    {
        return $this->error;
    }
}

function getClientIP()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function sendJSON($success, $message, $data = [])
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

// Test function to verify configuration
function testConfig()
{
    $errors = [];

    if (!defined('DB_HOST')) $errors[] = 'DB_HOST not defined';
    if (!defined('SMTP_HOST')) $errors[] = 'SMTP_HOST not defined';
    if (!defined('SITE_URL')) $errors[] = 'SITE_URL not defined';

    if (empty($errors)) {
        return ['success' => true, 'message' => 'Configuration OK'];
    } else {
        return ['success' => false, 'message' => 'Configuration errors', 'errors' => $errors];
    }
}
