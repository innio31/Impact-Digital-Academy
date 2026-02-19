<?php
// modules/admin/finance/reports/export.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get available report types
$report_types = [
    'revenue' => [
        'name' => 'Revenue Report',
        'description' => 'Detailed revenue analysis by program, payment method, and time period',
        'icon' => 'chart-bar',
        'fields' => ['date_from', 'date_to', 'program_type', 'payment_method', 'status']
    ],
    'outstanding' => [
        'name' => 'Outstanding Payments',
        'description' => 'List of overdue and pending payments with aging analysis',
        'icon' => 'exclamation-triangle',
        'fields' => ['date_from', 'date_to', 'program_type', 'status']
    ],
    'collection' => [
        'name' => 'Collection Analysis',
        'description' => 'Payment collection efficiency and trends analysis',
        'icon' => 'chart-pie',
        'fields' => ['date_from', 'date_to', 'program_type']
    ],
    'transactions' => [
        'name' => 'Transaction Log',
        'description' => 'Complete transaction history with all details',
        'icon' => 'exchange-alt',
        'fields' => ['date_from', 'date_to', 'program_type', 'payment_method', 'status', 'student_id']
    ],
    'invoices' => [
        'name' => 'Invoice Register',
        'description' => 'All invoices generated with payment status',
        'icon' => 'file-invoice-dollar',
        'fields' => ['date_from', 'date_to', 'program_type', 'status', 'invoice_type']
    ],
    'students_financial' => [
        'name' => 'Student Financial Status',
        'description' => 'Complete financial status of all students',
        'icon' => 'users',
        'fields' => ['program_type', 'status', 'payment_status']
    ],
    'fee_structures' => [
        'name' => 'Fee Structures',
        'description' => 'All fee structures and payment plans',
        'icon' => 'calculator',
        'fields' => ['program_type', 'is_active']
    ]
];

// Get available formats
$export_formats = [
    'csv' => ['name' => 'CSV (Excel)', 'icon' => 'file-csv'],
    'excel' => ['name' => 'Excel (XLSX)', 'icon' => 'file-excel'],
    'pdf' => ['name' => 'PDF Document', 'icon' => 'file-pdf'],
    'json' => ['name' => 'JSON Data', 'icon' => 'file-code']
];

// Get filter options for dropdowns
$program_types = [];
$result = $conn->query("SELECT DISTINCT program_type FROM programs WHERE program_type IS NOT NULL");
while ($row = $result->fetch_assoc()) {
    $program_types[] = $row['program_type'];
}

$payment_methods = [];
$result = $conn->query("SELECT DISTINCT payment_method FROM financial_transactions WHERE payment_method IS NOT NULL");
while ($row = $result->fetch_assoc()) {
    $payment_methods[] = $row['payment_method'];
}

$status_options = ['completed', 'pending', 'failed', 'refunded', 'cancelled'];
$invoice_types = ['registration', 'tuition_block1', 'tuition_block2', 'tuition_block3', 'late_fee', 'other'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_type = $_POST['report_type'] ?? '';
    $format = $_POST['format'] ?? 'csv';
    $date_from = $_POST['date_from'] ?? date('Y-m-01');
    $date_to = $_POST['date_to'] ?? date('Y-m-d');
    $program_type = $_POST['program_type'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    $status = $_POST['status'] ?? '';
    $student_id = $_POST['student_id'] ?? '';
    $invoice_type = $_POST['invoice_type'] ?? '';

    // Validate inputs
    if (!array_key_exists($report_type, $report_types)) {
        $error = "Invalid report type selected.";
    } elseif (!array_key_exists($format, $export_formats)) {
        $error = "Invalid export format selected.";
    } else {
        // Generate the export
        $export_data = generateExport($report_type, [
            'date_from' => $date_from,
            'date_to' => $date_to,
            'program_type' => $program_type,
            'payment_method' => $payment_method,
            'status' => $status,
            'student_id' => $student_id,
            'invoice_type' => $invoice_type
        ], $format);

        if ($export_data) {
            // Log the export activity
            logActivity(
                $_SESSION['user_id'],
                'report_export',
                "Exported $report_type report in $format format"
            );

            // Set appropriate headers and output
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $report_type . '_report_' . date('Y-m-d') . '.csv"');
                echo $export_data;
            } elseif ($format === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . $report_type . '_report_' . date('Y-m-d') . '.json"');
                echo json_encode($export_data, JSON_PRETTY_PRINT);
            }
            exit();
        } else {
            $error = "Failed to generate export. Please try again.";
        }
    }
}

// Log activity
logActivity($_SESSION['user_id'], 'export_hub_access', "Accessed report export hub");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Export Hub - Finance Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --dark-light: #334155;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--dark);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        /* Header styles */
        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .header p {
            color: #64748b;
            margin-top: 0.5rem;
            line-height: 1.5;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* Export Hub */
        .export-hub {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .export-hub {
                grid-template-columns: 1fr;
            }
        }

        /* Report Selection */
        .report-selection {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }

        .report-selection h2 {
            color: var(--dark);
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .report-card {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .report-card:hover {
            border-color: var(--primary);
            background: #f8fafc;
            transform: translateY(-2px);
        }

        .report-card.selected {
            border-color: var(--primary);
            background: #eff6ff;
        }

        .report-icon {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .report-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
        }

        .report-desc {
            color: #64748b;
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .report-badge {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Export Form */
        .export-form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            position: sticky;
            top: 2rem;
        }

        .export-form-container h2 {
            color: var(--dark);
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .format-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .format-option {
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.75rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .format-option:hover {
            border-color: var(--primary);
            background: #f8fafc;
        }

        .format-option.selected {
            border-color: var(--primary);
            background: #eff6ff;
        }

        .format-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .format-name {
            font-weight: 500;
            font-size: 0.9rem;
        }

        .btn {
            width: 100%;
            padding: 0.75rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0da271;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Instructions */
        .instructions {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .instructions h2 {
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .instruction-list {
            list-style: none;
        }

        .instruction-list li {
            padding: 0.5rem 0;
            color: #64748b;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .instruction-list i {
            color: var(--primary);
            margin-top: 0.25rem;
        }

        /* Recent Exports */
        .recent-exports {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .recent-exports h2 {
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .exports-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .export-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .export-item:last-child {
            border-bottom: none;
        }

        .export-info h4 {
            color: var(--dark);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .export-info p {
            color: #64748b;
            font-size: 0.8rem;
        }

        .export-format {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background: #e2e8f0;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* Sidebar navigation styles */
        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li {
            margin-bottom: 0.25rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        .nav-section {
            padding: 0.5rem 1.5rem;
            color: #94a3b8;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 1rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }

            .report-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }

            .report-grid {
                grid-template-columns: 1fr;
            }

            .format-options {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 480px) {
            .format-options {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header" style="padding: 1.5rem; border-bottom: 1px solid var(--dark-light);">
                <h2>Impact Academy</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Report Export Hub</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>

                    <div class="nav-section">Financial Reports</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/revenue.php">
                            <i class="fas fa-chart-bar"></i> Revenue Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php">
                            <i class="fas fa-exclamation-triangle"></i> Outstanding Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/collection.php">
                            <i class="fas fa-chart-pie"></i> Collection Analysis</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/export.php" class="active">
                            <i class="fas fa-file-export"></i> Export Reports</a></li>

                    <div class="nav-section">Back to Finance</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/">
                            <i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/">
                            <i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-file-export"></i>
                    Report Export Hub
                </h1>
                <p>
                    Generate and download financial reports in various formats. Select a report type,
                    customize filters, choose your preferred format, and download.
                </p>
            </div>

            <!-- Error/Success Messages -->
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Report exported successfully! Your download should start automatically.
                </div>
            <?php endif; ?>

            <div class="export-hub">
                <!-- Report Selection -->
                <div class="report-selection">
                    <h2><i class="fas fa-chart-line"></i> Select Report Type</h2>
                    <div class="report-grid" id="reportGrid">
                        <?php foreach ($report_types as $key => $report): ?>
                            <div class="report-card" data-report="<?php echo $key; ?>">
                                <div class="report-icon">
                                    <i class="fas fa-<?php echo $report['icon']; ?>"></i>
                                </div>
                                <div class="report-name"><?php echo $report['name']; ?></div>
                                <div class="report-desc"><?php echo $report['description']; ?></div>
                                <div class="report-badge"><?php echo strtoupper($key); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Export Form -->
                <div class="export-form-container">
                    <h2><i class="fas fa-download"></i> Export Settings</h2>
                    <form method="POST" id="exportForm">
                        <input type="hidden" name="report_type" id="reportType" value="">

                        <!-- Format Selection -->
                        <div class="form-group">
                            <label>Export Format</label>
                            <div class="format-options" id="formatOptions">
                                <?php foreach ($export_formats as $key => $format): ?>
                                    <div class="format-option" data-format="<?php echo $key; ?>">
                                        <div class="format-icon">
                                            <i class="fas fa-<?php echo $format['icon']; ?>"></i>
                                        </div>
                                        <div class="format-name"><?php echo $format['name']; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="format" id="exportFormat" value="csv">
                        </div>

                        <!-- Date Range -->
                        <div class="form-group">
                            <label>Date Range</label>
                            <div class="form-row">
                                <input type="date" name="date_from" class="form-control"
                                    value="<?php echo date('Y-m-01'); ?>" id="dateFrom">
                                <input type="date" name="date_to" class="form-control"
                                    value="<?php echo date('Y-m-d'); ?>" id="dateTo">
                            </div>
                        </div>

                        <!-- Dynamic Filters -->
                        <div id="dynamicFilters">
                            <!-- Filters will be loaded here based on report selection -->
                        </div>

                        <!-- Export Button -->
                        <div class="form-group">
                            <button type="submit" class="btn btn-success" id="exportButton" disabled>
                                <i class="fas fa-download"></i> Generate & Download Report
                            </button>
                        </div>

                        <div style="text-align: center; color: #64748b; font-size: 0.85rem; margin-top: 1rem;">
                            <i class="fas fa-info-circle"></i> Large reports may take a moment to generate
                        </div>
                    </form>
                </div>
            </div>

            <!-- Instructions -->
            <div class="instructions">
                <h2><i class="fas fa-info-circle"></i> How to Export Reports</h2>
                <ul class="instruction-list">
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Select a Report Type</strong> - Click on any report card to select it
                        </div>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Choose Export Format</strong> - Select CSV, Excel, PDF, or JSON format
                        </div>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Set Date Range</strong> - Specify the period for the report data
                        </div>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Apply Filters</strong> - Use additional filters to customize the report
                        </div>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Generate & Download</strong> - Click the download button to export
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Recent Exports (Placeholder) -->
            <div class="recent-exports">
                <h2><i class="fas fa-history"></i> Recent Exports</h2>
                <div class="exports-list">
                    <div class="export-item">
                        <div class="export-info">
                            <h4>Revenue Report</h4>
                            <p>Generated on <?php echo date('M j, Y H:i'); ?></p>
                        </div>
                        <span class="export-format">CSV</span>
                    </div>
                    <div class="export-item">
                        <div class="export-info">
                            <h4>Outstanding Payments</h4>
                            <p>Generated on <?php echo date('M j, Y H:i', strtotime('-1 hour')); ?></p>
                        </div>
                        <span class="export-format">PDF</span>
                    </div>
                    <div class="export-item">
                        <div class="export-info">
                            <h4>Collection Analysis</h4>
                            <p>Generated on <?php echo date('M j, Y H:i', strtotime('-1 day')); ?></p>
                        </div>
                        <span class="export-format">Excel</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Report selection
        let selectedReport = '';
        let selectedFormat = 'csv';

        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d"
        });

        // Report card selection
        document.querySelectorAll('.report-card').forEach(card => {
            card.addEventListener('click', function() {
                // Remove selection from all cards
                document.querySelectorAll('.report-card').forEach(c => {
                    c.classList.remove('selected');
                });

                // Add selection to clicked card
                this.classList.add('selected');

                // Set report type
                selectedReport = this.dataset.report;
                document.getElementById('reportType').value = selectedReport;

                // Enable export button
                document.getElementById('exportButton').disabled = false;

                // Load dynamic filters
                loadDynamicFilters(selectedReport);
            });
        });

        // Format selection
        document.querySelectorAll('.format-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selection from all options
                document.querySelectorAll('.format-option').forEach(o => {
                    o.classList.remove('selected');
                });

                // Add selection to clicked option
                this.classList.add('selected');

                // Set format
                selectedFormat = this.dataset.format;
                document.getElementById('exportFormat').value = selectedFormat;
            });
        });

        // Set initial format selection
        document.querySelector('.format-option[data-format="csv"]').classList.add('selected');

        // Load dynamic filters based on report type
        function loadDynamicFilters(reportType) {
            const filtersContainer = document.getElementById('dynamicFilters');
            filtersContainer.innerHTML = '';

            // Define filter configurations for each report type
            const filterConfigs = {
                'revenue': [{
                        type: 'select',
                        name: 'program_type',
                        label: 'Program Type',
                        options: ['', 'online', 'onsite']
                    },
                    {
                        type: 'select',
                        name: 'payment_method',
                        label: 'Payment Method',
                        options: ['', 'online', 'bank_transfer', 'cash', 'cheque', 'pos']
                    },
                    {
                        type: 'select',
                        name: 'status',
                        label: 'Status',
                        options: ['', 'completed', 'pending', 'failed', 'refunded']
                    }
                ],
                'outstanding': [{
                        type: 'select',
                        name: 'program_type',
                        label: 'Program Type',
                        options: ['', 'online', 'onsite']
                    },
                    {
                        type: 'select',
                        name: 'status',
                        label: 'Status',
                        options: ['', 'pending', 'overdue', 'partial']
                    }
                ],
                'collection': [{
                    type: 'select',
                    name: 'program_type',
                    label: 'Program Type',
                    options: ['', 'online', 'onsite']
                }],
                'transactions': [{
                        type: 'select',
                        name: 'program_type',
                        label: 'Program Type',
                        options: ['', 'online', 'onsite']
                    },
                    {
                        type: 'select',
                        name: 'payment_method',
                        label: 'Payment Method',
                        options: ['', 'online', 'bank_transfer', 'cash', 'cheque', 'pos']
                    },
                    {
                        type: 'select',
                        name: 'status',
                        label: 'Status',
                        options: ['', 'completed', 'pending', 'failed', 'refunded']
                    },
                    {
                        type: 'text',
                        name: 'student_id',
                        label: 'Student ID (Optional)',
                        placeholder: 'Enter student ID'
                    }
                ],
                'invoices': [{
                        type: 'select',
                        name: 'program_type',
                        label: 'Program Type',
                        options: ['', 'online', 'onsite']
                    },
                    {
                        type: 'select',
                        name: 'status',
                        label: 'Status',
                        options: ['', 'pending', 'paid', 'overdue', 'cancelled']
                    },
                    {
                        type: 'select',
                        name: 'invoice_type',
                        label: 'Invoice Type',
                        options: ['', 'registration', 'tuition_block1', 'tuition_block2', 'tuition_block3', 'late_fee', 'other']
                    }
                ],
                'students_financial': [{
                        type: 'select',
                        name: 'program_type',
                        label: 'Program Type',
                        options: ['', 'online', 'onsite']
                    },
                    {
                        type: 'select',
                        name: 'status',
                        label: 'Student Status',
                        options: ['', 'active', 'suspended', 'completed']
                    },
                    {
                        type: 'select',
                        name: 'payment_status',
                        label: 'Payment Status',
                        options: ['', 'cleared', 'overdue', 'partial', 'pending']
                    }
                ],
                'fee_structures': [{
                        type: 'select',
                        name: 'program_type',
                        label: 'Program Type',
                        options: ['', 'online', 'onsite']
                    },
                    {
                        type: 'select',
                        name: 'is_active',
                        label: 'Active Status',
                        options: ['', '1:Active', '0:Inactive']
                    }
                ]
            };

            const config = filterConfigs[reportType] || [];

            config.forEach(filter => {
                const div = document.createElement('div');
                div.className = 'form-group';

                const label = document.createElement('label');
                label.textContent = filter.label;
                div.appendChild(label);

                if (filter.type === 'select') {
                    const select = document.createElement('select');
                    select.name = filter.name;
                    select.className = 'form-control';

                    filter.options.forEach(option => {
                        const opt = document.createElement('option');
                        if (option.includes(':')) {
                            const [value, text] = option.split(':');
                            opt.value = value;
                            opt.textContent = text === '' ? 'All' : text;
                        } else {
                            opt.value = option;
                            opt.textContent = option === '' ? 'All' : option.charAt(0).toUpperCase() + option.slice(1);
                        }
                        select.appendChild(opt);
                    });

                    div.appendChild(select);
                } else if (filter.type === 'text') {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.name = filter.name;
                    input.className = 'form-control';
                    input.placeholder = filter.placeholder || '';
                    div.appendChild(input);
                }

                filtersContainer.appendChild(div);
            });
        }

        // Form validation
        document.getElementById('exportForm').addEventListener('submit', function(e) {
            if (!selectedReport) {
                e.preventDefault();
                alert('Please select a report type first.');
                return;
            }

            if (!selectedFormat) {
                e.preventDefault();
                alert('Please select an export format.');
                return;
            }

            // Show loading state
            const button = document.getElementById('exportButton');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating Report...';
            button.disabled = true;

            // Reset button after 5 seconds (in case of error)
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 5000);
        });

        // Auto-select first report on page load
        document.addEventListener('DOMContentLoaded', function() {
            const firstReport = document.querySelector('.report-card');
            if (firstReport) {
                firstReport.click();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+E to focus on export button
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                document.getElementById('exportButton').focus();
            }

            // Esc to clear selection
            if (e.key === 'Escape') {
                document.querySelectorAll('.report-card').forEach(c => {
                    c.classList.remove('selected');
                });
                selectedReport = '';
                document.getElementById('exportButton').disabled = true;
            }
        });
    </script>
</body>

</html>

<?php $conn->close(); ?>