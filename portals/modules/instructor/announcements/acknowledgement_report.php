<?php
// modules/admin/system/acknowledgment_report.php

session_start();
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get acknowledgment statistics
$sql = "SELECT 
        a.id,
        a.title,
        a.created_at,
        a.requires_acknowledgment,
        COUNT(aa.id) as acknowledgment_count,
        GROUP_CONCAT(DISTINCT u.first_name, ' ', u.last_name SEPARATOR ', ') as acknowledged_by
        FROM announcements a
        LEFT JOIN announcement_acknowledgments aa ON a.id = aa.announcement_id
        LEFT JOIN users u ON aa.user_id = u.id
        WHERE a.requires_acknowledgment = 1
        GROUP BY a.id
        ORDER BY a.created_at DESC";

$result = $conn->query($sql);
$acknowledgments = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acknowledgment Report - Admin Dashboard</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
        }
    </style>
</head>

<body>
    <h1>Announcement Acknowledgment Report</h1>

    <table>
        <thead>
            <tr>
                <th>Announcement ID</th>
                <th>Title</th>
                <th>Created Date</th>
                <th>Acknowledgment Count</th>
                <th>Acknowledged By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($acknowledgments as $ack): ?>
                <tr>
                    <td><?php echo $ack['id']; ?></td>
                    <td><?php echo htmlspecialchars($ack['title']); ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($ack['created_at'])); ?></td>
                    <td><?php echo $ack['acknowledgment_count']; ?></td>
                    <td><?php echo htmlspecialchars($ack['acknowledged_by'] ?: 'None'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>

</html>