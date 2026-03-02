<?php
// notify-subscribers.php - Complete version with all methods
require_once 'config.php';

class ArticleNotifier
{
    private $db;
    private $mailer;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->mailer = new MailSender();
    }

    /**
     * Generate unique email ID for tracking
     */
    private function generateEmailId()
    {
        return uniqid() . '-' . bin2hex(random_bytes(8));
    }

    /**
     * Create tracking pixel URL
     */
    private function getTrackingPixelUrl($email_id, $subscriber_email, $article_title)
    {
        $encoded_email = base64_encode($subscriber_email);
        $encoded_title = base64_encode($article_title);
        return SITE_URL . "/articles/track.php?e=" . urlencode($email_id) . "&s=" . urlencode($encoded_email) . "&t=" . urlencode($encoded_title);
    }

    /**
     * Create tracked link URL
     */
    private function getTrackedLinkUrl($email_id, $subscriber_email, $article_title, $destination)
    {
        $encoded_email = base64_encode($subscriber_email);
        $encoded_title = base64_encode($article_title);
        $encoded_url = base64_encode($destination);
        return SITE_URL . "/articles/click.php?e=" . urlencode($email_id) . "&s=" . urlencode($encoded_email) . "&t=" . urlencode($encoded_title) . "&url=" . urlencode($encoded_url);
    }

    /**
     * Send notifications for a new article
     */
    public function notifyNewArticle($article_title, $article_url, $article_excerpt = '', $test_mode = false, $test_email = null)
    {
        $results = [
            'total_subscribers' => 0,
            'emails_sent' => 0,
            'emails_failed' => 0,
            'errors' => [],
            'campaign_id' => null
        ];

        try {
            if (!$this->db) {
                throw new Exception("Database connection failed");
            }

            // Generate unique campaign ID
            $campaign_id = $this->generateEmailId();

            // Create campaign record
            $this->createCampaign($campaign_id, $article_title, $article_url);

            // Get active subscribers
            if ($test_mode && $test_email) {
                $stmt = $this->db->prepare("SELECT email FROM subscribers WHERE email = ? AND status = 'active'");
                $stmt->execute([$test_email]);
            } else {
                $stmt = $this->db->prepare("SELECT email FROM subscribers WHERE status = 'active'");
                $stmt->execute();
            }

            $subscribers = $stmt->fetchAll();
            $results['total_subscribers'] = count($subscribers);

            // Update campaign with recipient count
            $this->updateCampaignRecipients($campaign_id, count($subscribers));
            $results['campaign_id'] = $campaign_id;

            if (empty($subscribers)) {
                $results['message'] = $test_mode ? "Test email not found or inactive" : "No active subscribers found";
                return $results;
            }

            // Send emails
            $batch_size = 50;
            $batches = array_chunk($subscribers, $batch_size);

            foreach ($batches as $batch) {
                foreach ($batch as $subscriber) {
                    $email = $subscriber['email'];

                    try {
                        $sent = $this->sendNotificationEmail(
                            $email,
                            $article_title,
                            $article_url,
                            $article_excerpt,
                            $campaign_id
                        );

                        if ($sent) {
                            $results['emails_sent']++;
                            $this->logNotification($email, $article_title, 'sent', $campaign_id);
                        } else {
                            $results['emails_failed']++;
                            $results['errors'][] = "Failed to send to $email";
                            $this->logNotification($email, $article_title, 'failed', $campaign_id);
                        }

                        usleep(100000);
                    } catch (Exception $e) {
                        $results['emails_failed']++;
                        $results['errors'][] = "Error for $email: " . $e->getMessage();
                        $this->logNotification($email, $article_title, 'error', $campaign_id, $e->getMessage());
                    }
                }
            }

            // Mark campaign as completed
            $this->completeCampaign($campaign_id);

            $results['message'] = "Notification sent to {$results['emails_sent']} of {$results['total_subscribers']} subscribers";
        } catch (Exception $e) {
            $results['errors'][] = "Fatal error: " . $e->getMessage();
            error_log("ArticleNotifier fatal error: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Create campaign record
     */
    private function createCampaign($campaign_id, $title, $url)
    {
        $stmt = $this->db->prepare("
            INSERT INTO email_campaigns (email_id, article_title, article_url, status)
            VALUES (?, ?, ?, 'sending')
        ");
        $stmt->execute([$campaign_id, $title, $url]);
    }

    /**
     * Update campaign recipient count
     */
    private function updateCampaignRecipients($campaign_id, $count)
    {
        $stmt = $this->db->prepare("
            UPDATE email_campaigns SET total_recipients = ? WHERE email_id = ?
        ");
        $stmt->execute([$count, $campaign_id]);
    }

    /**
     * Mark campaign as completed
     */
    private function completeCampaign($campaign_id)
    {
        $stmt = $this->db->prepare("
            UPDATE email_campaigns 
            SET status = 'completed', completed_at = NOW() 
            WHERE email_id = ?
        ");
        $stmt->execute([$campaign_id]);
    }

    /**
     * Send individual notification email with tracking
     */
    private function sendNotificationEmail($to, $title, $url, $excerpt, $campaign_id)
    {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_PORT == 587 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = SMTP_PORT;

            // Recipients
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "New Article: " . $title;

            // HTML body with tracking
            $mail->Body = $this->getTrackingHTML($title, $url, $excerpt, $to, $campaign_id);
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $mail->Body));

            return $mail->send();
        } catch (Exception $e) {
            error_log("Failed to send notification to $to: " . $e->getMessage());
            return false;
        }
    }

    /**
     * HTML template with tracking pixel and tracked links
     */
    private function getTrackingHTML($title, $url, $excerpt, $subscriber_email, $campaign_id)
    {
        $tracking_pixel = $this->getTrackingPixelUrl($campaign_id, $subscriber_email, $title);
        $tracked_article_url = $this->getTrackedLinkUrl($campaign_id, $subscriber_email, $title, $url);
        $tracked_unsubscribe_url = $this->getTrackedLinkUrl($campaign_id, $subscriber_email, $title, SITE_URL . '/articles/unsubscribe.php?email=' . urlencode($subscriber_email));

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #008080, #006666); color: white; padding: 40px 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .header h1 { margin: 0; font-size: 28px; }
                .content { background: #f9f7f3; padding: 40px 30px; }
                .excerpt { background: white; padding: 25px; border-left: 4px solid #008080; margin: 25px 0; border-radius: 0 10px 10px 0; font-size: 16px; }
                .button { display: inline-block; background: #f59e0b; color: white; padding: 15px 35px; text-decoration: none; border-radius: 50px; font-weight: bold; margin: 20px 0; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.3); }
                .button:hover { background: #e68a00; }
                .footer { margin-top: 30px; padding: 20px; background: #f0f0f0; border-radius: 10px; font-size: 13px; color: #666; text-align: center; }
                .stats { color: #999; font-size: 12px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <!-- Tracking Pixel -->
            <img src="' . $tracking_pixel . '" width="1" height="1" style="display:none;" alt="">
            
            <div class="container">
                <div class="header">
                    <h1>📚 Impact Digital Academy</h1>
                    <p style="margin-top: 10px; opacity: 0.9;">Educators Series</p>
                </div>
                
                <div class="content">
                    <h2 style="color: #008080; margin-top: 0;">' . htmlspecialchars($title) . '</h2>
                    
                    <div class="excerpt">
                        ' . nl2br(htmlspecialchars($excerpt)) . '
                    </div>
                    
                    <div style="text-align: center;">
                        <a href="' . $tracked_article_url . '" class="button">📖 Read Full Article</a>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 10px; margin-top: 30px;">
                        <h3 style="color: #008080; margin-top: 0;">Why this matters:</h3>
                        <p>This article is part of our ongoing series for Nigerian educators who know the struggle is real. Each edition brings practical insights you can use in your classroom tomorrow.</p>
                    </div>
                    
                    <div class="footer">
                        <p>You received this because you subscribed to the Impact Digital Educators Series.</p>
                        <p>
                            <a href="' . $tracked_unsubscribe_url . '" style="color: #999;">Unsubscribe</a> | 
                            <a href="' . SITE_URL . '/articles/archive.php" style="color: #999;">Article Archive</a>
                        </p>
                        <p style="margin-top: 15px; font-size: 11px;">
                            <strong>Impact Digital (Solutions & Academy)</strong><br>
                            Ota, Nigeria
                        </p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ';
    }

    /**
     * Log notification attempt
     */
    private function logNotification($email, $article_title, $status, $campaign_id = null, $error = null)
    {
        try {
            $this->ensureLogTable();

            $stmt = $this->db->prepare("
                INSERT INTO notification_log (email, article_title, status, error_message, campaign_id, sent_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$email, $article_title, $status, $error, $campaign_id]);
        } catch (Exception $e) {
            error_log("Failed to log notification: " . $e->getMessage());
        }
    }

    /**
     * Create log table if not exists
     */
    private function ensureLogTable()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS notification_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                article_title VARCHAR(255) NOT NULL,
                campaign_id VARCHAR(64),
                status ENUM('sent', 'failed', 'error') DEFAULT 'sent',
                error_message TEXT,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (email),
                INDEX (campaign_id),
                INDEX (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        try {
            $this->db->exec($sql);
        } catch (Exception $e) {
            // Table might already exist
        }
    }

    /**
     * Get campaign statistics
     */
    public function getCampaignStats($campaign_id)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    c.*,
                    COUNT(DISTINCT t.subscriber_email) as unique_opens_actual,
                    COUNT(DISTINCT cl.subscriber_email) as unique_clicks_actual,
                    (SELECT COUNT(*) FROM email_tracking WHERE email_id = c.email_id) as total_opens,
                    (SELECT COUNT(*) FROM email_clicks WHERE email_id = c.email_id) as total_clicks
                FROM email_campaigns c
                LEFT JOIN email_tracking t ON c.email_id = t.email_id
                LEFT JOIN email_clicks cl ON c.email_id = cl.email_id
                WHERE c.email_id = ?
                GROUP BY c.id
            ");
            $stmt->execute([$campaign_id]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Failed to get campaign stats: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all campaigns
     */
    public function getAllCampaigns($limit = 20)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM email_campaigns 
                ORDER BY sent_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Failed to get all campaigns: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get statistics for dashboard
     */
    public function getStats($days = 30)
    {
        try {
            $this->ensureLogTable();

            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(DISTINCT email) as total_recipients,
                    COUNT(*) as total_notifications,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM notification_log
                WHERE sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $result = $stmt->fetch();

            // Get subscriber count
            $subStmt = $this->db->prepare("SELECT COUNT(*) as total FROM subscribers WHERE status = 'active'");
            $subStmt->execute();
            $subscribers = $subStmt->fetch();

            if ($result) {
                $result['total_subscribers'] = $subscribers['total'] ?? 0;
            }

            return $result;
        } catch (Exception $e) {
            error_log("Failed to get stats: " . $e->getMessage());
            return [
                'total_recipients' => 0,
                'total_notifications' => 0,
                'successful' => 0,
                'failed' => 0,
                'total_subscribers' => 0
            ];
        }
    }
}
