<?php
// admin/pins.php - PIN Management System
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: login.php");
    exit();
}

require_once '../config/config.php';

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];
$success_message = '';
$error_message = '';

$db = getDB();

// Handle PIN generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate_pins') {
        $school_id = (int)$_POST['school_id'];
        $quantity = min(max(1, (int)$_POST['quantity']), 10000);
        $price_per_pin = (float)$_POST['price_per_pin'];
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $max_uses = (int)($_POST['max_uses'] ?? 3);

        if ($school_id <= 0 || $quantity <= 0) {
            $error_message = "Invalid school or quantity";
        } else {
            try {
                // Verify school exists
                $stmt = $db->prepare("SELECT school_name FROM schools WHERE id = ? AND status = 'active'");
                $stmt->execute([$school_id]);
                $school = $stmt->fetch();

                if (!$school) {
                    $error_message = "School not found or inactive";
                } else {
                    // Generate batch number
                    $batch_number = 'PIN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

                    // Generate PINs
                    $pins = [];
                    $pin_codes = [];
                    $inserted = 0;

                    for ($i = 0; $i < $quantity; $i++) {
                        $pin_code = generateUniquePIN($db, $school_id);
                        $pin_codes[] = $pin_code;

                        $stmt = $db->prepare("
                            INSERT INTO result_pins (school_id, pin_code, batch_number, max_uses, generated_by, expiry_date, price, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'unused')
                        ");
                        $stmt->execute([$school_id, $pin_code, $batch_number, $max_uses, $admin_id, $expiry_date, $price_per_pin]);
                        $inserted++;
                    }

                    // Record batch
                    $total_amount = $quantity * $price_per_pin;
                    $stmt = $db->prepare("
                        INSERT INTO pin_batches (school_id, batch_number, quantity, price_per_pin, total_amount, generated_by, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'completed')
                    ");
                    $stmt->execute([$school_id, $batch_number, $quantity, $price_per_pin, $total_amount, $admin_id]);

                    // Generate CSV file
                    $csv_content = "PIN Code,Status,Max Uses,Price (₦),Expiry Date,Generated Date\n";
                    foreach ($pins as $pin) {
                        $csv_content .= "\"{$pin['pin']}\",Unused,{$max_uses},{$price_per_pin}," . ($expiry_date ?? 'Never') . "," . date('Y-m-d H:i:s') . "\n";
                    }

                    $filename = "pins_{$batch_number}_{$school['school_name']}.csv";
                    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
                    $filepath = "../downloads/{$filename}";
                    file_put_contents($filepath, $csv_content);

                    $success_message = "Successfully generated {$inserted} PINs for {$school['school_name']}!";
                    logActivity($admin_id, 'admin', 'Generated PIN batch', "Batch: $batch_number, Quantity: $quantity, School: {$school['school_name']}");

                    // Store download info in session for the modal
                    $_SESSION['last_batch'] = [
                        'batch_number' => $batch_number,
                        'filename' => $filename,
                        'quantity' => $quantity,
                        'school_name' => $school['school_name']
                    ];
                }
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    }

    // Bulk delete/expire PINs
    elseif ($_POST['action'] === 'bulk_action' && isset($_POST['pin_ids'])) {
        $pin_ids = $_POST['pin_ids'];
        $bulk_action = $_POST['bulk_action'];

        if (empty($pin_ids)) {
            $error_message = "No PINs selected";
        } else {
            $placeholders = implode(',', array_fill(0, count($pin_ids), '?'));

            if ($bulk_action === 'delete') {
                $stmt = $db->prepare("DELETE FROM result_pins WHERE id IN ($placeholders)");
                $stmt->execute($pin_ids);
                $success_message = count($pin_ids) . " PIN(s) deleted successfully";
            } elseif ($bulk_action === 'expire') {
                $stmt = $db->prepare("UPDATE result_pins SET status = 'expired' WHERE id IN ($placeholders) AND status IN ('unused', 'active')");
                $stmt->execute($pin_ids);
                $success_message = count($pin_ids) . " PIN(s) expired successfully";
            }
        }
    }
}

// Handle single PIN action (delete/expire/reset)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $pin_id = (int)$_GET['id'];
    $action = $_GET['action'];

    try {
        if ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM result_pins WHERE id = ?");
            $stmt->execute([$pin_id]);
            $success_message = "PIN deleted successfully";
        } elseif ($action === 'expire') {
            $stmt = $db->prepare("UPDATE result_pins SET status = 'expired' WHERE id = ? AND status IN ('unused', 'active')");
            $stmt->execute([$pin_id]);
            $success_message = "PIN expired successfully";
        } elseif ($action === 'reset') {
            $stmt = $db->prepare("UPDATE result_pins SET used_count = 0, status = 'unused', student_id = NULL, first_used_at = NULL, last_used_at = NULL WHERE id = ?");
            $stmt->execute([$pin_id]);
            $success_message = "PIN reset successfully";
        }
        logActivity($admin_id, 'admin', "$action PIN", "PIN ID: $pin_id");
    } catch (PDOException $e) {
        $error_message = "Error performing action";
    }
}

// Pagination and filters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$school_filter = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$batch_filter = isset($_GET['batch']) ? trim($_GET['batch']) : '';

// Build query
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(rp.pin_code LIKE ? OR s.school_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($school_filter > 0) {
    $where_conditions[] = "rp.school_id = ?";
    $params[] = $school_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "rp.status = ?";
    $params[] = $status_filter;
}

if (!empty($batch_filter)) {
    $where_conditions[] = "rp.batch_number = ?";
    $params[] = $batch_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM result_pins rp
    JOIN schools s ON rp.school_id = s.id
    WHERE $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_pins = $stmt->fetch()['total'];
$total_pages = ceil($total_pins / $limit);

// Get PINs
$sql = "
    SELECT rp.*, s.school_name, s.school_code,
           stu.full_name as student_name, stu.admission_number
    FROM result_pins rp
    JOIN schools s ON rp.school_id = s.id
    LEFT JOIN students stu ON rp.student_id = stu.id
    WHERE $where_clause
    ORDER BY rp.generated_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$pins = $stmt->fetchAll();

// Get schools for dropdown
$stmt = $db->query("SELECT id, school_name FROM schools WHERE status = 'active' ORDER BY school_name");
$schools = $stmt->fetchAll();

// Get unique batches for filter
$stmt = $db->query("SELECT DISTINCT batch_number FROM result_pins ORDER BY batch_number DESC LIMIT 100");
$batches = $stmt->fetchAll();

// Get statistics
$stmt = $db->query("SELECT COUNT(*) as total FROM result_pins");
$total_all = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM result_pins WHERE status = 'unused'");
$total_unused = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM result_pins WHERE status = 'active'");
$total_active_pins = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM result_pins WHERE status = 'used_up'");
$total_used = $stmt->fetch()['total'];

$stmt = $db->query("SELECT SUM(used_count) as total_uses FROM result_pins");
$total_uses = $stmt->fetch()['total'] ?? 0;

// Function to generate unique PIN
function generateUniquePIN($db, $school_id)
{
    do {
        $characters = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $pin = '';
        for ($i = 0; $i < 12; $i++) {
            $pin .= $characters[random_int(0, strlen($characters) - 1)];
        }
        $pin = substr($pin, 0, 4) . '-' . substr($pin, 4, 4) . '-' . substr($pin, 8, 4);

        $stmt = $db->prepare("SELECT COUNT(*) FROM result_pins WHERE pin_code = ?");
        $stmt->execute([$pin]);
        $exists = $stmt->fetchColumn();
    } while ($exists > 0);

    return $pin;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>PIN Management - MyResultChecker Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            overflow-x: hidden;
        }

        .mobile-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 48px;
            height: 48px;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 12px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #2c3e50, #1a252f);
            color: white;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: #3498db;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.7;
        }

        .admin-info {
            padding: 20px;
            margin: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            text-align: center;
        }

        .admin-info h4 {
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .admin-info p {
            font-size: 0.7rem;
            opacity: 0.7;
            text-transform: capitalize;
        }

        .nav-links {
            list-style: none;
            padding: 10px 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-links i {
            width: 22px;
            font-size: 18px;
        }

        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }

        .top-bar {
            background: white;
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .page-title h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .page-title p {
            color: #7f8c8d;
            font-size: 0.85rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 18px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #7f8c8d;
            margin-top: 5px;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.75rem;
        }

        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3498db;
        }

        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .data-table th,
        .data-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
            font-size: 0.85rem;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .status-unused {
            background: #e8f4fd;
            color: #3498db;
        }

        .status-active {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-used_up {
            background: #fef2f2;
            color: #e74c3c;
        }

        .status-expired {
            background: #f5f5f5;
            color: #95a5a6;
        }

        .checkbox-col {
            width: 40px;
            text-align: center;
        }

        .checkbox-col input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .pagination a {
            background: #ecf0f1;
            color: #2c3e50;
        }

        .pagination a:hover {
            background: #3498db;
            color: white;
        }

        .pagination .active {
            background: #3498db;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 550px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.2rem;
            color: #2c3e50;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            color: #2c3e50;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        .alert-error {
            background: #fef2f2;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .bulk-actions {
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #ecf0f1;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        @media (max-width: 768px) {
            .mobile-toggle {
                display: flex;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar-overlay.active {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 75px 15px 20px;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .top-bar {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .bulk-actions {
                flex-direction: column;
            }

            .bulk-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        .pin-code {
            font-family: monospace;
            font-weight: 600;
            letter-spacing: 1px;
        }

        .batch-link {
            color: #3498db;
            text-decoration: none;
            font-size: 0.75rem;
        }

        .batch-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <button class="mobile-toggle" id="mobileToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3>MyResultChecker</h3>
                    <p>Admin Portal</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator'); ?></h4>
            <p><?php echo ucfirst($_SESSION['admin_role'] ?? 'admin'); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="schools.php"><i class="fas fa-school"></i> Schools</a></li>
            <li><a href="students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="pins.php" class="active"><i class="fas fa-key"></i> PIN Management</a></li>
            <li><a href="batches.php"><i class="fas fa-layer-group"></i> PIN Batches</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-key"></i> PIN Management</h1>
                <p>Generate, manage, and track result checker PINs</p>
            </div>
            <button class="btn btn-primary" onclick="openGenerateModal()">
                <i class="fas fa-plus-circle"></i> Generate PINs
            </button>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_all); ?></div>
                <div class="stat-label">Total PINs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_unused); ?></div>
                <div class="stat-label">Unused</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_active_pins); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_used); ?></div>
                <div class="stat-label">Used Up</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($total_uses); ?></div>
                <div class="stat-label">Total Views</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" placeholder="PIN or School..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-school"></i> School</label>
                    <select name="school_id">
                        <option value="0">All Schools</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?php echo $school['id']; ?>" <?php echo $school_filter == $school['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($school['school_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-flag"></i> Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <option value="unused" <?php echo $status_filter === 'unused' ? 'selected' : ''; ?>>Unused</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="used_up" <?php echo $status_filter === 'used_up' ? 'selected' : ''; ?>>Used Up</option>
                        <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-layer-group"></i> Batch</label>
                    <select name="batch">
                        <option value="">All Batches</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?php echo htmlspecialchars($batch['batch_number']); ?>" <?php echo $batch_filter === $batch['batch_number'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($batch['batch_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="pins.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- PINs Table -->
        <div class="table-container">
            <form method="POST" action="" id="bulkForm">
                <input type="hidden" name="action" value="bulk_action">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="checkbox-col"><input type="checkbox" id="selectAll"></th>
                                <th>PIN Code</th>
                                <th>School</th>
                                <th>Batch</th>
                                <th>Student</th>
                                <th>Uses</th>
                                <th>Status</th>
                                <th>Generated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pins)): ?>
                                <?php foreach ($pins as $pin): ?>
                                    <tr>
                                        <td class="checkbox-col">
                                            <input type="checkbox" name="pin_ids[]" value="<?php echo $pin['id']; ?>" class="pin-checkbox">
                                        </td>
                                        <td class="pin-code"><?php echo htmlspecialchars($pin['pin_code']); ?></td>
                                        <td><?php echo htmlspecialchars($pin['school_name']); ?></td>
                                        <td>
                                            <a href="?batch=<?php echo urlencode($pin['batch_number']); ?>" class="batch-link">
                                                <?php echo htmlspecialchars($pin['batch_number']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($pin['student_name']): ?>
                                                <small><?php echo htmlspecialchars($pin['student_name']); ?><br>
                                                    <span style="font-size: 0.7rem; color: #7f8c8d;"><?php echo htmlspecialchars($pin['admission_number']); ?></span></small>
                                            <?php else: ?>
                                                <span style="color: #95a5a6;">Not used</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $pin['used_count']; ?> / <?php echo $pin['max_uses']; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $pin['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $pin['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($pin['generated_at'])); ?></td>
                                        <td>
                                            <?php if ($pin['status'] === 'unused' || $pin['status'] === 'active'): ?>
                                                <a href="?action=expire&id=<?php echo $pin['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Expire this PIN?')">
                                                    <i class="fas fa-clock"></i>
                                                </a>
                                                <a href="?action=delete&id=<?php echo $pin['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this PIN permanently?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php elseif ($pin['status'] === 'used_up'): ?>
                                                <a href="?action=reset&id=<?php echo $pin['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Reset this PIN? It will become unused again.')">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 40px; color: #95a5a6;">
                                        <i class="fas fa-key" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                                        No PINs found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bulk-actions">
                    <span id="selectedCount">0</span> PIN(s) selected
                    <select name="bulk_action" id="bulkAction" required>
                        <option value="">-- Bulk Action --</option>
                        <option value="delete">Delete Selected</option>
                        <option value="expire">Expire Selected</option>
                    </select>
                    <button type="submit" class="btn btn-primary" onclick="return confirmBulkAction()">Apply</button>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <a href="?page=1&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'status' => $status_filter, 'batch' => $batch_filter])); ?>">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'status' => $status_filter, 'batch' => $batch_filter])); ?>">
                        <i class="fas fa-angle-left"></i>
                    </a>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'status' => $status_filter, 'batch' => $batch_filter])); ?>"
                            class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <a href="?page=<?php echo min($total_pages, $page + 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'status' => $status_filter, 'batch' => $batch_filter])); ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?page=<?php echo $total_pages; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'status' => $status_filter, 'batch' => $batch_filter])); ?>">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Generate PIN Modal -->
    <div id="generateModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="generate_pins">
                <div class="modal-header">
                    <h3><i class="fas fa-key"></i> Generate Result Checker PINs</h3>
                    <button type="button" class="close-modal" onclick="closeGenerateModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Select School *</label>
                        <select name="school_id" required>
                            <option value="">-- Select School --</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo $school['id']; ?>">
                                    <?php echo htmlspecialchars($school['school_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Quantity *</label>
                            <input type="number" name="quantity" min="1" max="10000" value="100" required>
                            <small>Max 10,000 per batch</small>
                        </div>
                        <div class="form-group">
                            <label>Price per PIN (₦) *</label>
                            <input type="number" name="price_per_pin" step="0.01" min="0" value="500" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Max Uses per PIN</label>
                            <select name="max_uses">
                                <option value="1">1 time</option>
                                <option value="2">2 times</option>
                                <option value="3" selected>3 times</option>
                                <option value="5">5 times</option>
                                <option value="10">10 times</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Expiry Date (Optional)</label>
                            <input type="date" name="expiry_date">
                        </div>
                    </div>

                    <div class="info-box" style="background: #e8f4fd; padding: 15px; border-radius: 10px; margin-top: 10px;">
                        <i class="fas fa-info-circle" style="color: #3498db;"></i>
                        <small style="color: #2c3e50;">
                            PINs will be generated in format: XXXX-XXXX-XXXX<br>
                            A CSV file will be available for download after generation.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeGenerateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate PINs</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success Batch Modal (shows when batch is generated) -->
    <?php if (isset($_SESSION['last_batch'])): ?>
        <div id="batchSuccessModal" class="modal active">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-check-circle" style="color: #27ae60;"></i> PINs Generated Successfully!</h3>
                    <button type="button" class="close-modal" onclick="closeBatchSuccessModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="text-align: center; padding: 10px;">
                        <i class="fas fa-key" style="font-size: 48px; color: #27ae60; margin-bottom: 15px;"></i>
                        <p><strong><?php echo number_format($_SESSION['last_batch']['quantity']); ?></strong> PINs generated for</p>
                        <p><strong><?php echo htmlspecialchars($_SESSION['last_batch']['school_name']); ?></strong></p>
                        <p style="margin-top: 15px;">Batch Number: <strong><?php echo $_SESSION['last_batch']['batch_number']; ?></strong></p>
                        <div style="margin-top: 20px;">
                            <a href="../downloads/<?php echo $_SESSION['last_batch']['filename']; ?>" class="btn btn-success" download>
                                <i class="fas fa-download"></i> Download CSV File
                            </a>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="closeBatchSuccessModal()">Close</button>
                </div>
            </div>
        </div>
        <script>
            function closeBatchSuccessModal() {
                document.getElementById('batchSuccessModal').classList.remove('active');
            }
        </script>
        <?php unset($_SESSION['last_batch']); ?>
    <?php endif; ?>

    <script>
        // Mobile menu
        const mobileToggle = document.getElementById('mobileToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        mobileToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);

        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSidebar();
        });

        // Modal functions
        function openGenerateModal() {
            document.getElementById('generateModal').classList.add('active');
        }

        function closeGenerateModal() {
            document.getElementById('generateModal').classList.remove('active');
        }

        // Select all functionality
        const selectAll = document.getElementById('selectAll');
        const pinCheckboxes = document.querySelectorAll('.pin-checkbox');
        const selectedCountSpan = document.getElementById('selectedCount');

        function updateSelectedCount() {
            const checked = document.querySelectorAll('.pin-checkbox:checked').length;
            selectedCountSpan.textContent = checked;
        }

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                pinCheckboxes.forEach(cb => {
                    cb.checked = selectAll.checked;
                });
                updateSelectedCount();
            });
        }

        pinCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        updateSelectedCount();

        function confirmBulkAction() {
            const action = document.getElementById('bulkAction').value;
            const checked = document.querySelectorAll('.pin-checkbox:checked').length;

            if (checked === 0) {
                alert('Please select at least one PIN');
                return false;
            }

            if (action === 'delete') {
                return confirm(`Are you sure you want to delete ${checked} PIN(s)? This action cannot be undone.`);
            } else if (action === 'expire') {
                return confirm(`Are you sure you want to expire ${checked} PIN(s)?`);
            }

            alert('Please select an action');
            return false;
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);

        // Close modals on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>

</html>