<?php
// config.php - Place in root directory
class Database
{
    private $host = "localhost"; // Usually localhost for HostAfrica
    private $db_name = "impactdi_portal";
    private $username = "impactdi_portal"; // CHANGE THIS
    private $password = "yCuhEpaX3rRVxRrWMWGJ"; // CHANGE THIS
    private $conn;

    public function getConnection()
    {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Connection error: " . $e->getMessage());
            return null;
        }
        return $this->conn;
    }
}

// Email configuration (using PHPMailer - install via composer or download manually)
require_once '../portals/vendor/PHPMailer/src/PHPMailer.php';
require_once '../portals/vendor/PHPMailer/src/SMTP.php';
require_once '../portals/vendor/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);

        // SMTP Configuration (use your HostAfrica email settings)
        $this->mail->isSMTP();
        $this->mail->Host       = 'mail.impactdigitalacademy.com.ng'; // Your HostAfrica mail server
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = 'admin@impactdigitalacademy.com.ng'; // Your email
        $this->mail->Password   = 'Innioluwa@1995'; // Your email password
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mail->Port       = 465;
        $this->mail->setFrom('noreply@impactdigitalacademy.com.ng', 'Impact Digital Academy');
        $this->mail->isHTML(true);
    }

    public function sendWelcomeEmail($to, $name = 'Educator')
    {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($to);
            $this->mail->Subject = 'Welcome to Impact Digital Educators Series!';

            // HTML email template
            $this->mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #008080; color: white; padding: 20px; text-align: center; }
                    .content { padding: 30px; background: #f9f7f3; }
                    .button { background: #f59e0b; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Welcome to Impact Digital Academy!</h2>
                    </div>
                    <div class='content'>
                        <p>Hello " . htmlspecialchars($name) . ",</p>
                        <p>Thank you for subscribing to our Nigerian Educators Series. You'll now receive:</p>
                        <ul>
                            <li>Weekly articles on practical teaching strategies</li>
                            <li>Exclusive resources for Nigerian classrooms</li>
                            <li>Invitations to webinars and workshops</li>
                        </ul>
                        <p>Your next article, <strong>'The Classroom Is Not a Prison'</strong>, will arrive soon.</p>
                        <p style='margin-top: 30px;'>
                            <a href='https://impactdigitalacademy.com.ng/articles' class='button'>Read Latest Articles</a>
                        </p>
                        <p style='margin-top: 30px;'>Keep impacting lives,<br><strong>The Impact Digital Team</strong></p>
                    </div>
                </div>
            </body>
            </html>";

            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $this->mail->Body));

            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendNotificationEmail($commentData)
    {
        // Notify admin of new comment
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress('admin@impactdigitalacademy.com.ng');
            $this->mail->Subject = 'New Comment on Article';
            $this->mail->Body = "
            <h3>New Comment Posted</h3>
            <p><strong>Author:</strong> " . htmlspecialchars($commentData['author']) . "</p>
            <p><strong>Article:</strong> " . htmlspecialchars($commentData['article']) . "</p>
            <p><strong>Comment:</strong> " . htmlspecialchars($commentData['text']) . "</p>
            <p><a href='https://impactdigitalacademy.com.ng/admin/comments.php'>Moderate Comments</a></p>";

            return $this->mail->send();
        } catch (Exception $e) {
            return false;
        }
    }
}

// Get client IP address
function getClientIP()
{
    $ipaddress = '';
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if (isset($_SERVER['HTTP_X_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    else if (isset($_SERVER['HTTP_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if (isset($_SERVER['HTTP_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    else if (isset($_SERVER['REMOTE_ADDR']))
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = 'UNKNOWN';
    return $ipaddress;
}
