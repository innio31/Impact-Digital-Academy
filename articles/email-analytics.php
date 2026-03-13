<?php
// email-analytics.php - View email campaign statistics
session_start();
require_once 'config.php';
require_once 'notify-subscribers.php';

// Simple authentication (same as publish page)
$is_authenticated = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (!$is_authenticated) {
    header('Location: publish-article.php');
    exit;
}

$notifier = new ArticleNotifier();
$campaigns = $notifier->getAllCampaigns(50);
$selected_campaign = isset($_GET['campaign']) ? $_GET['campaign'] : null;
$stats = $selected_campaign ? $notifier->getCampaignStats($selected_campaign) : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Analytics - Impact Digital</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0ebe2;
            font-family: 'Segoe UI', Arial, sans-serif;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #008080;
        }

        .nav-links a {
            margin-left: 20px;
            color: #666;
            text-decoration: none;
        }

        .nav-links a:hover {
            color: #008080;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }

        /* Campaign List */
        .campaign-list {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .campaign-list h3 {
            color: #008080;
            margin-bottom: 20px;
        }

        .campaign-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s;
        }

        .campaign-item:hover {
            background: #f5f5f5;
        }

        .campaign-item.active {
            background: #e8f4f4;
            border-left: 4px solid #008080;
        }

        .campaign-item small {
            color: #999;
            display: block;
            margin-top: 5px;
        }

        .campaign-stats {
            font-size: 13px;
            margin-top: 8px;
        }

        .campaign-stats span {
            margin-right: 10px;
            color: #555;
        }

        /* Analytics Panel */
        .analytics-panel {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f9f7f3, #fff);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid #e0d9cc;
        }

        .stat-card .value {
            font-size: 36px;
            font-weight: bold;
            color: #008080;
        }

        .stat-card .label {
            color: #666;
            margin-top: 5px;
        }

        .stat-card .percentage {
            font-size: 14px;
            color: #f59e0b;
            margin-top: 5px;
        }

        .chart-container {
            height: 300px;
            margin-bottom: 30px;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .details-table th {
            background: #f0ebe2;
            padding: 12px;
            text-align: left;
            color: #555;
        }

        .details-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .details-table tr:hover {
            background: #f9f7f3;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #eee;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #008080;
            transition: width 0.3s;
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-chart-line"></i> Email Campaign Analytics</h1>
            <div class="nav-links">
                <a href="publish_article.php">← Back to Publisher</a>
                <a href="?export=csv" id="exportBtn">Export Data</a>
                <a href="?logout">Logout</a>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Campaign List -->
            <div class="campaign-list">
                <h3>📧 Recent Campaigns</h3>
                <?php foreach ($campaigns as $campaign): ?>
                    <div class="campaign-item <?= $selected_campaign == $campaign['email_id'] ? 'active' : '' ?>"
                        onclick="location.href='?campaign=<?= urlencode($campaign['email_id']) ?>'">
                        <strong><?= htmlspecialchars(substr($campaign['article_title'], 0, 40)) ?>...</strong>
                        <small><?= date('M j, Y g:i a', strtotime($campaign['sent_at'])) ?></small>
                        <div class="campaign-stats">
                            <span>📨 <?= $campaign['total_recipients'] ?></span>
                            <span>👁️ <?= $campaign['unique_opens'] ?></span>
                            <span>🖱️ <?= $campaign['unique_clicks'] ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Analytics Panel -->
            <div class="analytics-panel">
                <?php if ($stats): ?>
                    <h2 style="color: #008080; margin-bottom: 20px;">
                        <?= htmlspecialchars($stats['article_title']) ?>
                    </h2>

                    <!-- Stats Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="value"><?= $stats['total_recipients'] ?></div>
                            <div class="label">Recipients</div>
                        </div>

                        <div class="stat-card">
                            <div class="value"><?= $stats['unique_opens'] ?></div>
                            <div class="label">Unique Opens</div>
                            <div class="percentage">
                                <?= $stats['total_recipients'] > 0 ? round(($stats['unique_opens'] / $stats['total_recipients']) * 100, 1) : 0 ?>%
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="value"><?= $stats['total_opens'] ?></div>
                            <div class="label">Total Opens</div>
                            <div class="percentage">
                                Avg <?= $stats['unique_opens'] > 0 ? round($stats['total_opens'] / $stats['unique_opens'], 1) : 0 ?> opens/reader
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="value"><?= $stats['unique_clicks'] ?></div>
                            <div class="label">Unique Clicks</div>
                            <div class="percentage">
                                CTR: <?= $stats['unique_opens'] > 0 ? round(($stats['unique_clicks'] / $stats['unique_opens']) * 100, 1) : 0 ?>%
                            </div>
                        </div>
                    </div>

                    <!-- Progress Bar for Open Rate -->
                    <div style="margin-bottom: 30px;">
                        <h3 style="color: #008080; margin-bottom: 10px;">Open Rate</h3>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $stats['total_recipients'] > 0 ? round(($stats['unique_opens'] / $stats['total_recipients']) * 100) : 0 ?>%;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px; color: #666;">
                            <span><?= $stats['unique_opens'] ?> opened</span>
                            <span><?= $stats['total_recipients'] - $stats['unique_opens'] ?> didn't open</span>
                        </div>
                    </div>

                    <!-- Timeline Chart -->
                    <h3 style="color: #008080; margin-bottom: 10px;">Activity Timeline</h3>
                    <div class="chart-container">
                        <canvas id="timelineChart"></canvas>
                    </div>

                    <!-- Detailed Opens Table -->
                    <h3 style="color: #008080; margin: 30px 0 15px;">Recent Opens</h3>
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>First Open</th>
                                <th>Last Open</th>
                                <th>Opens</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Fetch recent opens for this campaign
                            $db = (new Database())->getConnection();
                            $openStmt = $db->prepare("
                                SELECT * FROM email_tracking 
                                WHERE email_id = ? 
                                ORDER BY last_open DESC 
                                LIMIT 10
                            ");
                            $openStmt->execute([$selected_campaign]);
                            $opens = $openStmt->fetchAll();
                            ?>
                            <?php foreach ($opens as $open): ?>
                                <tr>
                                    <td><?= htmlspecialchars($open['subscriber_email']) ?></td>
                                    <td><?= date('M j, g:i a', strtotime($open['first_open'])) ?></td>
                                    <td><?= date('M j, g:i a', strtotime($open['last_open'])) ?></td>
                                    <td><?= $open['opens_count'] ?></td>
                                    <td><?= htmlspecialchars($open['ip_address']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Recent Clicks -->
                    <h3 style="color: #008080; margin: 30px 0 15px;">Recent Clicks</h3>
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Clicked At</th>
                                <th>Destination</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $clickStmt = $db->prepare("
                                SELECT * FROM email_clicks 
                                WHERE email_id = ? 
                                ORDER BY clicked_at DESC 
                                LIMIT 10
                            ");
                            $clickStmt->execute([$selected_campaign]);
                            $clicks = $clickStmt->fetchAll();
                            ?>
                            <?php foreach ($clicks as $click): ?>
                                <tr>
                                    <td><?= htmlspecialchars($click['subscriber_email']) ?></td>
                                    <td><?= date('M j, g:i a', strtotime($click['clicked_at'])) ?></td>
                                    <td><?= htmlspecialchars(substr($click['clicked_link'], 0, 50)) ?>...</td>
                                    <td><?= htmlspecialchars($click['ip_address']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <script>
                        // Timeline Chart
                        const ctx = document.getElementById('timelineChart').getContext('2d');

                        // Generate last 7 days data
                        const labels = [];
                        const opensData = [];
                        const clicksData = [];

                        <?php
                        // Get hourly data for last 24 hours
                        $hourlyStmt = $db->prepare("
                            SELECT 
                                DATE_FORMAT(opened_at, '%Y-%m-%d %H:00') as hour,
                                COUNT(*) as opens
                            FROM email_tracking 
                            WHERE email_id = ? AND opened_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            GROUP BY hour
                            ORDER BY hour
                        ");
                        $hourlyStmt->execute([$selected_campaign]);
                        $hourlyOpens = $hourlyStmt->fetchAll();

                        $clickHourlyStmt = $db->prepare("
                            SELECT 
                                DATE_FORMAT(clicked_at, '%Y-%m-%d %H:00') as hour,
                                COUNT(*) as clicks
                            FROM email_clicks 
                            WHERE email_id = ? AND clicked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            GROUP BY hour
                            ORDER BY hour
                        ");
                        $clickHourlyStmt->execute([$selected_campaign]);
                        $hourlyClicks = $clickHourlyStmt->fetchAll();

                        // Create arrays for chart
                        $hours = [];
                        $opens_by_hour = [];
                        $clicks_by_hour = [];

                        for ($i = 23; $i >= 0; $i--) {
                            $hour = date('Y-m-d H:00', strtotime("-$i hours"));
                            $hours[] = date('H:00', strtotime("-$i hours"));

                            $opens = 0;
                            foreach ($hourlyOpens as $h) {
                                if ($h['hour'] == $hour) {
                                    $opens = $h['opens'];
                                    break;
                                }
                            }
                            $opens_by_hour[] = $opens;

                            $clicks = 0;
                            foreach ($hourlyClicks as $h) {
                                if ($h['hour'] == $hour) {
                                    $clicks = $h['clicks'];
                                    break;
                                }
                            }
                            $clicks_by_hour[] = $clicks;
                        }
                        ?>

                        const chart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: <?= json_encode($hours) ?>,
                                datasets: [{
                                        label: 'Opens',
                                        data: <?= json_encode($opens_by_hour) ?>,
                                        borderColor: '#008080',
                                        backgroundColor: 'rgba(0, 128, 128, 0.1)',
                                        tension: 0.4,
                                        fill: true
                                    },
                                    {
                                        label: 'Clicks',
                                        data: <?= json_encode($clicks_by_hour) ?>,
                                        borderColor: '#f59e0b',
                                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                        tension: 0.4,
                                        fill: true
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: true,
                                        position: 'top'
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            stepSize: 1
                                        }
                                    }
                                }
                            }
                        });
                    </script>

                <?php else: ?>
                    <div style="text-align: center; padding: 50px; color: #999;">
                        <i class="fas fa-chart-line" style="font-size: 48px; margin-bottom: 20px;"></i>
                        <h3>Select a campaign to view analytics</h3>
                        <p>Click on any campaign from the left sidebar to see detailed statistics including opens, clicks, and engagement metrics.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Export functionality
        document.getElementById('exportBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            if (<?= json_encode($selected_campaign !== null) ?>) {
                window.location.href = 'export-campaign.php?campaign=<?= urlencode($selected_campaign) ?>';
            } else {
                alert('Please select a campaign first');
            }
        });
    </script>
</body>

</html>