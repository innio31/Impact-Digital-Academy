<?php
// admin/index.php - Admin Dashboard
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: login.php");
    exit();
}

require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';

// Get statistics
try {
    $db = getDB();

    // Total active schools
    $stmt = $db->query("SELECT COUNT(*) FROM schools WHERE status = 'active'");
    $total_schools = $stmt->fetchColumn();

    // Total students across all schools
    $stmt = $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'");
    $total_students = $stmt->fetchColumn();

    // Total PINs generated
    $stmt = $db->query("SELECT COUNT(*) FROM result_pins");
    $total_pins = $stmt->fetchColumn();

    // Total PINs used
    $stmt = $db->query("SELECT SUM(used_count) FROM result_pins");
    $pins_used = $stmt->fetchColumn() ?: 0;

    // Total revenue
    $stmt = $db->query("SELECT SUM(total_amount) FROM pin_batches WHERE status = 'completed'");
    $total_revenue = $stmt->fetchColumn() ?: 0;

    // Recent schools
    $stmt = $db->query("
        SELECT id, school_code, school_name, status, created_at 
        FROM schools 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_schools = $stmt->fetchAll();

    // Recent PIN batches
    $stmt = $db->query("
        SELECT pb.*, s.school_name 
        FROM pin_batches pb
        JOIN schools s ON pb.school_id = s.id
        ORDER BY pb.created_at DESC 
        LIMIT 5
    ");
    $recent_batches = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $error_message = "Error loading dashboard data";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#2c3e50">
    <title>Admin Dashboard - MyResultChecker</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
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

        /* Mobile Menu Toggle */
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
            transition: all 0.3s ease;
        }

        .mobile-toggle:hover {
            background: #1a252f;
            transform: scale(1.05);
        }

        /* Sidebar */
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
            backdrop-filter: blur(10px);
        }

        .admin-info h4 {
            font-size: 0.9rem;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }

        /* Top Bar */
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

        .logout-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card.schools {
            border-left-color: #3498db;
        }

        .stat-card.students {
            border-left-color: #27ae60;
        }

        .stat-card.pins {
            border-left-color: #f39c12;
        }

        .stat-card.revenue {
            border-left-color: #9b59b6;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-card.schools .stat-icon {
            background: #3498db;
        }

        .stat-card.students .stat-icon {
            background: #27ae60;
        }

        .stat-card.pins .stat-icon {
            background: #f39c12;
        }

        .stat-card.revenue .stat-icon {
            background: #9b59b6;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.85rem;
            margin-top: 5px;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }

        .content-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-header h3 {
            font-size: 1.1rem;
            color: #2c3e50;
        }

        .card-header a {
            color: #3498db;
            text-decoration: none;
            font-size: 0.85rem;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 400px;
        }

        .data-table th,
        .data-table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
            font-size: 0.85rem;
        }

        .data-table th {
            color: #2c3e50;
            font-weight: 600;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .status-active {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-inactive {
            background: #fef2f2;
            color: #e74c3c;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
        }

        .action-btn {
            background: #f8f9fa;
            border: 2px solid #ecf0f1;
            border-radius: 12px;
            padding: 15px 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #2c3e50;
        }

        .action-btn:hover {
            border-color: #3498db;
            transform: translateY(-3px);
            background: white;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.15);
        }

        .action-icon {
            font-size: 24px;
            color: #3498db;
            margin-bottom: 8px;
        }

        .action-text {
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
            backdrop-filter: blur(2px);
        }

        /* Responsive */
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .content-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .top-bar {
                flex-direction: column;
                text-align: center;
            }

            .page-title h1 {
                font-size: 1.3rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-card {
                padding: 15px;
            }

            .content-card {
                padding: 15px;
            }

            .logout-btn span {
                display: none;
            }

            .logout-btn {
                width: 48px;
                height: 48px;
                padding: 0;
                justify-content: center;
                border-radius: 12px;
            }
        }

        /* Touch optimizations */
        @media (hover: none) and (pointer: coarse) {
            .nav-links a {
                min-height: 48px;
            }

            .action-btn {
                min-height: 90px;
            }

            .logout-btn {
                min-height: 48px;
            }
        }

        /* Print styles */
        @media print {

            .sidebar,
            .mobile-toggle,
            .top-bar,
            .logout-btn,
            .quick-actions {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }

            .stat-card,
            .content-card {
                box-shadow: none !important;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-toggle" id="mobileToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3>MyResultChecker</h3>
                    <p>Admin Portal</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst($admin_role); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="schools.php"><i class="fas fa-school"></i> Schools</a></li>
            <li><a href="students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="pins.php"><i class="fas fa-key"></i> PIN Management</a></li>
            <li><a href="batches.php"><i class="fas fa-layer-group"></i> PIN Batches</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="results.php"><i class="fas fa-file-alt"></i> Results</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($admin_name); ?>!</p>
            </div>
            <button class="logout-btn" onclick="window.location.href='logout.php'">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card schools">
                <div class="stat-header">
                    <div class="stat-value"><?php echo number_format($total_schools ?? 0); ?></div>
                    <div class="stat-icon"><i class="fas fa-school"></i></div>
                </div>
                <div class="stat-label">Active Schools</div>
            </div>

            <div class="stat-card students">
                <div class="stat-header">
                    <div class="stat-value"><?php echo number_format($total_students ?? 0); ?></div>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-label">Total Students</div>
            </div>

            <div class="stat-card pins">
                <div class="stat-header">
                    <div class="stat-value"><?php echo number_format($total_pins ?? 0); ?></div>
                    <div class="stat-icon"><i class="fas fa-key"></i></div>
                </div>
                <div class="stat-label">PINs Generated</div>
                <div class="stat-label" style="font-size: 0.7rem;"><?php echo number_format($pins_used); ?> used</div>
            </div>

            <div class="stat-card revenue">
                <div class="stat-header">
                    <div class="stat-value">₦<?php echo number_format($total_revenue ?? 0, 2); ?></div>
                    <div class="stat-icon"><i class="fas fa-naira-sign"></i></div>
                </div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="content-card" style="margin-bottom: 25px;">
            <div class="card-header">
                <h3>Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="schools.php?action=add" class="action-btn">
                    <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="action-text">Add School</div>
                </a>
                <a href="pins.php?action=generate" class="action-btn">
                    <div class="action-icon"><i class="fas fa-key"></i></div>
                    <div class="action-text">Generate PINs</div>
                </a>
                <a href="schools.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-school"></i></div>
                    <div class="action-text">Manage Schools</div>
                </a>
                <a href="reports.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-chart-bar"></i></div>
                    <div class="action-text">View Reports</div>
                </a>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Recent Schools -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recently Added Schools</h3>
                    <a href="schools.php">View All →</a>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>School Name</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_schools)): ?>
                                <?php foreach ($recent_schools as $school): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($school['school_name']); ?></td>
                                        <td><?php echo htmlspecialchars($school['school_code']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $school['status']; ?>">
                                                <?php echo ucfirst($school['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($school['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #999; padding: 20px;">
                                        No schools found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent PIN Batches -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recent PIN Batches</h3>
                    <a href="batches.php">View All →</a>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Batch Number</th>
                                <th>School</th>
                                <th>Quantity</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_batches)): ?>
                                <?php foreach ($recent_batches as $batch): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                                        <td><?php echo htmlspecialchars($batch['school_name']); ?></td>
                                        <td><?php echo number_format($batch['quantity']); ?></td>
                                        <td>₦<?php echo number_format($batch['total_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #999; padding: 20px;">
                                        No PIN batches found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu functionality
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

        // Close sidebar on link click (mobile)
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    closeSidebar();
                }
            });
        });

        // Close sidebar with escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (window.innerWidth > 768) {
                    closeSidebar();
                }
            }, 250);
        });

        // Add animation to stat cards
        document.querySelectorAll('.stat-card').forEach((card, i) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, i * 100);
        });

        // Touch feedback for mobile
        document.querySelectorAll('.action-btn, .logout-btn, .nav-links a').forEach(el => {
            el.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            });
            el.addEventListener('touchend', function() {
                this.style.opacity = '1';
            });
        });
    </script>
</body>

</html>