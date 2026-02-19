<?php
// modules/admin/finance/payments/confirmed.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get confirmed payments (completed and verified)
$sql = "SELECT ft.*, u.first_name, u.last_name, u.email,
               cb.batch_code, c.title as course_title,
               p.name as program_name
        FROM financial_transactions ft
        JOIN users u ON u.id = ft.student_id
        JOIN class_batches cb ON cb.id = ft.class_id
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        WHERE ft.status = 'completed' AND ft.is_verified = 1
        ORDER BY ft.created_at DESC
        LIMIT 100";

$result = $conn->query($sql);
$confirmed_payments = $result->fetch_all(MYSQLI_ASSOC);

// Get stats
$stats_sql = "SELECT COUNT(*) as count, SUM(amount) as total 
              FROM financial_transactions 
              WHERE status = 'completed' AND is_verified = 1";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Log activity
logActivity($_SESSION['user_id'], 'view_confirmed_payments', "Viewed confirmed payments list");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmed Payments - Admin Finance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            padding: 2rem;
            background: #f1f5f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #10b981;
            margin-bottom: 0.5rem;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #1e293b;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .verified-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-check-circle"></i> Confirmed Payments</h1>
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to All Payments
            </a>
        </div>
        
        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['count']; ?></div>
                <div>Confirmed Payments</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo formatCurrency($stats['total']); ?></div>
                <div>Total Amount</div>
            </div>
        </div>
        
        <!-- Payments Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Transaction</th>
                        <th>Student</th>
                        <th>Program</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Verified</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($confirmed_payments)): ?>
                        <?php foreach ($confirmed_payments as $payment): ?>
                            <tr>
                                <td><?php echo $payment['gateway_reference']; ?></td>
                                <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($payment['program_name']); ?></td>
                                <td><?php echo formatCurrency($payment['amount']); ?></td>
                                <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                <td>
                                    <span class="verified-badge">
                                        <i class="fas fa-check"></i> Confirmed
                                    </span>
                                </td>
                                <td>
                                    <?php if ($payment['is_verified']): ?>
                                        <span style="color: #10b981;">
                                            <i class="fas fa-check-circle"></i> Verified
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="view.php?id=<?php echo $payment['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="receipt.php?id=<?php echo $payment['id']; ?>" 
                                       target="_blank" class="btn btn-success">
                                        <i class="fas fa-receipt"></i> Receipt
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 3rem;">
                                <i class="fas fa-check-circle" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                                <h3>No Confirmed Payments</h3>
                                <p>No confirmed payments found.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>