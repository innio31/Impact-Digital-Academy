<?php
// modules/admin/academic/calendar_manager.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

$conn = getDBConnection();
$current_year = date('Y');

// Get filter parameters
$program_type = $_GET['program_type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$academic_year = $_GET['academic_year'] ?? '';
$search = $_GET['search'] ?? '';

// Check if we're editing a period
$edit_id = $_GET['edit'] ?? $_POST['edit_id'] ?? 0;
$period_to_edit = null;

if ($edit_id) {
    $stmt = $conn->prepare("SELECT * FROM academic_periods WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $period_to_edit = $result->fetch_assoc();

    // Open edit modal if period exists
    if ($period_to_edit) {
        echo '<script>document.addEventListener("DOMContentLoaded", function() { openModal("editPeriodModal"); });</script>';
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_period'])) {
        // Add new academic period
        $stmt = $conn->prepare("INSERT INTO academic_periods 
            (program_type, period_type, period_number, period_name, academic_year, 
             start_date, end_date, duration_weeks, registration_start_date, registration_deadline, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param(
            "ssissssiiss",
            $_POST['program_type'],
            $_POST['period_type'],
            $_POST['period_number'],
            $_POST['period_name'],
            $_POST['academic_year'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['duration_weeks'],
            $_POST['registration_start_date'],
            $_POST['registration_deadline'],
            $_POST['status']
        );

        if ($stmt->execute()) {
            $_SESSION['success'] = "Academic period added successfully!";
        } else {
            $_SESSION['error'] = "Error adding academic period: " . $stmt->error;
        }
    }

    if (isset($_POST['update_period'])) {
        // Update existing academic period
        $stmt = $conn->prepare("UPDATE academic_periods SET 
            program_type = ?,
            period_type = ?,
            period_number = ?,
            period_name = ?,
            academic_year = ?,
            start_date = ?,
            end_date = ?,
            duration_weeks = ?,
            registration_start_date = ?,
            registration_deadline = ?,
            status = ?
            WHERE id = ?");

        $stmt->bind_param(
            "ssissssiisii",
            $_POST['program_type'],
            $_POST['period_type'],
            $_POST['period_number'],
            $_POST['period_name'],
            $_POST['academic_year'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['duration_weeks'],
            $_POST['registration_start_date'],
            $_POST['registration_deadline'],
            $_POST['status'],
            $_POST['edit_id']
        );

        if ($stmt->execute()) {
            $_SESSION['success'] = "Academic period updated successfully!";
        } else {
            $_SESSION['error'] = "Error updating academic period: " . $stmt->error;
        }

        header("Location: calendar_manager.php");
        exit();
    }

    if (isset($_POST['generate_year'])) {
        // Generate entire academic year automatically
        $academic_year = $_POST['academic_year'];
        $program_type = $_POST['program_type_gen'];

        if ($program_type === 'onsite') {
            // Generate 12 monthly periods
            $months = [
                ['number' => 1, 'name' => 'January', 'month_num' => 1],
                ['number' => 2, 'name' => 'February', 'month_num' => 2],
                ['number' => 3, 'name' => 'March', 'month_num' => 3],
                ['number' => 4, 'name' => 'April', 'month_num' => 4],
                ['number' => 5, 'name' => 'May', 'month_num' => 5],
                ['number' => 6, 'name' => 'June', 'month_num' => 6],
                ['number' => 7, 'name' => 'July', 'month_num' => 7],
                ['number' => 8, 'name' => 'August', 'month_num' => 8],
                ['number' => 9, 'name' => 'September', 'month_num' => 9],
                ['number' => 10, 'name' => 'October', 'month_num' => 10],
                ['number' => 11, 'name' => 'November', 'month_num' => 11],
                ['number' => 12, 'name' => 'December', 'month_num' => 12]
            ];

            foreach ($months as $month) {
                // Calculate dates for the month
                $start_date = $academic_year . '-' . str_pad($month['month_num'], 2, '0', STR_PAD_LEFT) . '-01';
                $end_date = date('Y-m-t', strtotime($start_date));
                
                // Set registration to open 2 weeks before start, close 1 week before
                $reg_start = date('Y-m-d', strtotime($start_date . " -2 weeks"));
                $reg_deadline = date('Y-m-d', strtotime($start_date . " -1 week"));
                
                $stmt = $conn->prepare("INSERT INTO academic_periods 
                    (program_type, period_type, period_number, period_name, academic_year, 
                     start_date, end_date, duration_weeks, registration_start_date, registration_deadline, status) 
                    VALUES ('onsite', 'month', ?, ?, ?, ?, ?, 4, ?, ?, 'upcoming')");
                
                $period_name = $month['name'] . " " . $academic_year . " Monthly Cohort";

                $stmt->bind_param(
                    "issssss",
                    $month['number'],
                    $period_name,
                    $academic_year,
                    $start_date,
                    $end_date,
                    $reg_start,
                    $reg_deadline
                );
                $stmt->execute();
            }

            $_SESSION['success'] = "Generated 12 monthly periods for onsite programs!";
        } elseif ($program_type === 'online') {
            // Generate 6 blocks
            $blocks = [
                ['number' => 1, 'season' => 'Fall', 'month' => '09'],
                ['number' => 2, 'season' => 'Fall', 'month' => '11'],
                ['number' => 3, 'season' => 'Winter', 'month' => '01'],
                ['number' => 4, 'season' => 'Spring', 'month' => '03'],
                ['number' => 5, 'season' => 'Spring', 'month' => '05'],
                ['number' => 6, 'season' => 'Summer', 'month' => '07']
            ];

            $year = intval($academic_year);

            foreach ($blocks as $block) {
                $block_year = $year;
                $next_year = $year + 1;

                // Adjust year for blocks after August
                if (in_array($block['number'], [3, 4, 5, 6])) {
                    $block_year = $next_year;
                }

                // Calculate dates (8-week blocks starting on Monday)
                $start_date = $block_year . '-' . $block['month'] . '-02';
                $start_date = date('Y-m-d', strtotime("next monday", strtotime($start_date)));
                $end_date = date('Y-m-d', strtotime($start_date . " +7 weeks +6 days"));
                $reg_start = date('Y-m-d', strtotime($start_date . " -2 weeks"));
                $reg_deadline = date('Y-m-d', strtotime($start_date . " -1 week"));

                $stmt = $conn->prepare("INSERT INTO academic_periods 
                    (program_type, period_type, period_number, period_name, academic_year, 
                     start_date, end_date, duration_weeks, registration_start_date, registration_deadline, status) 
                    VALUES ('online', 'block', ?, ?, ?, ?, ?, 8, ?, ?, 'upcoming')");

                $period_name = "Block " . chr(64 + $block['number']) . " - " . $block['season'] . " " . $block_year;
                $academic_year_str = $year . '/' . $next_year;

                $stmt->bind_param(
                    "isssssss",
                    $block['number'],
                    $period_name,
                    $academic_year_str,
                    $start_date,
                    $end_date,
                    $reg_start,
                    $reg_deadline
                );
                $stmt->execute();
            }

            $_SESSION['success'] = "Generated 6 blocks for online programs!";
        } elseif ($program_type === 'school') {
            // Generate 3 terms for school-based programs
            $terms = [
                ['number' => 1, 'name' => 'First Term', 'month' => '09'], // September start
                ['number' => 2, 'name' => 'Second Term', 'month' => '01'], // January start
                ['number' => 3, 'name' => 'Third Term', 'month' => '04']  // April start
            ];

            $year = intval($academic_year);

            foreach ($terms as $term) {
                $term_year = $year;
                
                // Adjust for terms that start in next calendar year
                if ($term['number'] === 1) {
                    // First term starts in September of the same year
                    $term_year = $year;
                } elseif ($term['number'] === 2 || $term['number'] === 3) {
                    // Second and third terms start in the next calendar year
                    $term_year = $year + 1;
                }

                // Calculate dates for each term (13 weeks)
                $start_date = $term_year . '-' . $term['month'] . '-02';
                
                // Find the first Monday of the month for start
                $start_date = date('Y-m-d', strtotime("first monday of " . date('F Y', strtotime($start_date))));
                
                // End date is 13 weeks (91 days) after start
                $end_date = date('Y-m-d', strtotime($start_date . " +12 weeks +6 days"));
                
                // Registration opens 4 weeks before start, closes 1 week before
                $reg_start = date('Y-m-d', strtotime($start_date . " -4 weeks"));
                $reg_deadline = date('Y-m-d', strtotime($start_date . " -1 week"));
                
                $stmt = $conn->prepare("INSERT INTO academic_periods 
                    (program_type, period_type, period_number, period_name, academic_year, 
                     start_date, end_date, duration_weeks, registration_start_date, registration_deadline, status) 
                    VALUES ('school', 'term', ?, ?, ?, ?, ?, 13, ?, ?, 'upcoming')");

                $period_name = $term['name'] . " " . ($term_year) . " School Term";
                $academic_year_str = $year . '/' . ($year + 1);

                $stmt->bind_param(
                    "issssss",
                    $term['number'],
                    $period_name,
                    $academic_year_str,
                    $start_date,
                    $end_date,
                    $reg_start,
                    $reg_deadline
                );
                $stmt->execute();
            }

            $_SESSION['success'] = "Generated 3 terms for school-based programs!";
        }

        header("Location: calendar_manager.php");
        exit();
    }

    if (isset($_POST['delete_period'])) {
        $period_id = (int)$_POST['delete_period'];
        $stmt = $conn->prepare("DELETE FROM academic_periods WHERE id = ?");
        $stmt->bind_param("i", $period_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Academic period deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting academic period: " . $stmt->error;
        }

        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

// Build query with filters
$query = "SELECT * FROM academic_periods WHERE 1=1";
$params = [];
$types = '';

if ($program_type !== 'all') {
    $query .= " AND program_type = ?";
    $params[] = $program_type;
    $types .= 's';
}

if ($status !== 'all') {
    $query .= " AND status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($academic_year) {
    $query .= " AND academic_year LIKE ?";
    $params[] = "%$academic_year%";
    $types .= 's';
}

if ($search) {
    $query .= " AND (period_name LIKE ? OR period_type LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$query .= " ORDER BY start_date DESC";

// Fetch all academic periods
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$periods_result = $stmt->get_result();
$academic_periods = $periods_result->fetch_all(MYSQLI_ASSOC);

// Get unique academic years for filter
$years_query = "SELECT DISTINCT academic_year FROM academic_periods ORDER BY academic_year DESC";
$years_result = $conn->query($years_query);
$academic_years = $years_result->fetch_all(MYSQLI_ASSOC);

// Get counts for statistics
$counts_query = "SELECT 
    program_type,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM academic_periods 
    GROUP BY program_type";
$counts_result = $conn->query($counts_query);
$period_counts = [];
while ($row = $counts_result->fetch_assoc()) {
    $period_counts[$row['program_type']] = $row;
}

// Log activity
logActivity('view_academic_calendar', "Viewed academic calendar with filters");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Academic Calendar Manager - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --onsite: #8b5cf6;
            --online: #10b981;
            --school: #8b4513;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --mobile-breakpoint: 768px;
            --tablet-breakpoint: 1024px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            font-size: 16px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Breadcrumb - Mobile Friendly */
        .breadcrumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.375rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            white-space: nowrap;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.75rem;
        }

        /* Page Title - Mobile Optimized */
        .page-title {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .page-title {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .page-title h1 {
            font-size: 1.75rem;
            color: var(--dark);
            font-weight: 700;
        }

        @media (max-width: 767px) {
            .page-title h1 {
                font-size: 1.5rem;
            }
        }

        .page-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        /* Buttons - Touch Friendly */
        .btn {
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            min-height: 44px;
            min-width: 44px;
            touch-action: manipulation;
        }

        @media (max-width: 767px) {
            .btn {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
                flex: 1;
            }
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover, .btn-primary:active {
            background: var(--secondary);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--light-gray);
        }

        .btn-secondary:hover, .btn-secondary:active {
            background: var(--light);
            border-color: var(--primary);
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert i {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Stats Cards - Mobile Grid */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .stats-cards {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: all 0.2s ease;
            border-left: 5px solid var(--primary);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        @media (max-width: 767px) {
            .stat-card {
                padding: 1rem;
            }
        }

        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }

        @media (max-width: 767px) {
            .stat-value {
                font-size: 1.5rem;
            }
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            line-height: 1.3;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
        }

        .badge-onsite {
            background-color: var(--onsite);
            color: white;
        }

        .badge-online {
            background-color: var(--online);
            color: white;
        }

        .badge-school {
            background-color: var(--school);
            color: white;
        }

        .badge-upcoming {
            background-color: #fbbf24;
            color: #78350f;
        }

        .badge-active {
            background-color: #10b981;
            color: white;
        }

        .badge-completed {
            background-color: #6b7280;
            color: white;
        }

        .badge-cancelled {
            background-color: #ef4444;
            color: white;
        }

        /* Filters - Mobile Optimized */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        @media (max-width: 767px) {
            .filters-card {
                padding: 1rem;
            }
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .filters-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .filter-reset {
            color: var(--primary);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.5rem;
            border-radius: 4px;
        }

        .filter-reset:active {
            background: rgba(37, 99, 235, 0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .filters-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .filters-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.375rem;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.875rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            font-size: 1rem;
            background: white;
            -webkit-appearance: none;
            appearance: none;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 2px solid var(--light-gray);
        }

        @media (max-width: 767px) {
            .filter-actions {
                flex-direction: column;
            }
        }

        /* Table Container - Mobile Responsive */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        @media (max-width: 767px) {
            table {
                min-width: 100%;
            }
        }

        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.875rem;
            white-space: nowrap;
        }

        @media (max-width: 767px) {
            th {
                padding: 0.75rem;
                font-size: 0.8rem;
            }
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        @media (max-width: 767px) {
            td {
                padding: 0.75rem;
            }
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 767px) {
            .action-buttons {
                flex-direction: column;
                gap: 0.375rem;
            }
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 1rem;
            min-width: 36px;
            min-height: 36px;
        }

        .btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-edit:hover, .btn-edit:active {
            background: #bfdbfe;
        }

        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }

        .btn-delete:hover, .btn-delete:active {
            background: #fecaca;
        }

        .btn-icon:active {
            transform: scale(0.95);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }

        /* Modal - Mobile Full Screen */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 1rem;
        }

        @media (max-width: 767px) {
            .modal {
                padding: 0;
                align-items: flex-end;
            }
        }

        .modal-content {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: modalSlideUp 0.3s ease;
        }

        @media (max-width: 767px) {
            .modal-content {
                max-width: 100%;
                border-radius: 16px 16px 0 0;
                max-height: 85vh;
                padding: 1.25rem;
            }
        }

        @keyframes modalSlideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 480px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Mobile Menu (Optional for action buttons) */
        .mobile-menu {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 0.75rem;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 999;
        }

        @media (min-width: 768px) {
            .mobile-menu {
                display: none;
            }
        }

        .mobile-menu-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            background: none;
            border: none;
            color: var(--gray);
            font-size: 0.75rem;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .mobile-menu-btn.active {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.1);
        }

        .mobile-menu-btn i {
            font-size: 1.25rem;
        }

        /* Touch Scroll Improvements */
        .touch-scroll {
            -webkit-overflow-scrolling: touch;
        }

        /* Improved Card Layout for Mobile */
        .card-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .card-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Loading State */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            color: var(--gray);
        }

        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Modal Header with Close Button */
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .modal-header h2 {
            font-size: 1.5rem;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .modal-close:active {
            background: rgba(0, 0, 0, 0.1);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--light-gray);
        }

        @media (max-width: 767px) {
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="index.php">Academics</a>
            <i class="fas fa-chevron-right"></i>
            <span>Academic Calendar</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Academic Calendar Manager</h1>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('addPeriodModal')">
                    <i class="fas fa-plus-circle"></i> <span class="hide-on-mobile">Add Period</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="openModal('generateYearModal')">
                    <i class="fas fa-calendar-plus"></i> <span class="hide-on-mobile">Generate Year</span>
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card total" onclick="window.location.href='?'">
                <div class="stat-value"><?php 
                    $total = 0;
                    foreach ($period_counts as $type => $count) {
                        $total += $count['total'] ?? 0;
                    }
                    echo $total;
                ?></div>
                <div class="stat-label">Total Periods</div>
            </div>
            <div class="stat-card onsite-total" onclick="window.location.href='?program_type=onsite'">
                <div class="stat-value"><?php echo $period_counts['onsite']['total'] ?? 0; ?></div>
                <div class="stat-label">Onsite Cohorts</div>
            </div>
            <div class="stat-card online-total" onclick="window.location.href='?program_type=online'">
                <div class="stat-value"><?php echo $period_counts['online']['total'] ?? 0; ?></div>
                <div class="stat-label">Online Blocks</div>
            </div>
            <div class="stat-card school-total" onclick="window.location.href='?program_type=school'">
                <div class="stat-value"><?php echo $period_counts['school']['total'] ?? 0; ?></div>
                <div class="stat-label">School Terms</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header">
                <h3>Filter Academic Periods</h3>
                <button type="button" class="filter-reset" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>

            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="program_type">Program Type</label>
                        <select id="program_type" name="program_type" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $program_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="onsite" <?php echo $program_type === 'onsite' ? 'selected' : ''; ?>>Onsite Cohorts</option>
                            <option value="online" <?php echo $program_type === 'online' ? 'selected' : ''; ?>>Online Blocks</option>
                            <option value="school" <?php echo $program_type === 'school' ? 'selected' : ''; ?>>School-based Terms</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="upcoming" <?php echo $status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="academic_year">Academic Year</label>
                        <select id="academic_year" name="academic_year" class="form-control" onchange="this.form.submit()">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year['academic_year']); ?>"
                                    <?php echo $academic_year === $year['academic_year'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['academic_year']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" class="form-control"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search periods...">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Academic Periods Table -->
        <div class="table-container touch-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Type</th>
                        <th>Year</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($academic_periods)): ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="fas fa-calendar-alt"></i>
                                <h3>No academic periods found</h3>
                                <p>No periods match your current filters.</p>
                                <button type="button" class="btn btn-secondary" onclick="resetFilters()" style="margin-top: 1rem;">
                                    Reset Filters
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($academic_periods as $period): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($period['period_name']); ?></strong><br>
                                    <small>#<?php echo $period['period_number']; ?></small>
                                </td>
                                <td>
                                    <?php
                                    $badge_class = '';
                                    if ($period['program_type'] === 'onsite') {
                                        $badge_class = 'badge-onsite';
                                        $type_text = $period['period_type'] === 'month' ? 'Monthly Cohort' : 'Onsite Term';
                                    } elseif ($period['program_type'] === 'online') {
                                        $badge_class = 'badge-online';
                                        $type_text = 'Online Block';
                                    } else {
                                        $badge_class = 'badge-school';
                                        $type_text = 'School Term';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo $type_text; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($period['academic_year']); ?></td>
                                <td>
                                    <?php echo date('M j', strtotime($period['start_date'])); ?> -
                                    <?php echo date('M j, Y', strtotime($period['end_date'])); ?>
                                </td>
                                <td>
                                    <?php
                                    $status_badge = 'badge-upcoming';
                                    if ($period['status'] === 'active') $status_badge = 'badge-active';
                                    if ($period['status'] === 'completed') $status_badge = 'badge-completed';
                                    if ($period['status'] === 'cancelled') $status_badge = 'badge-cancelled';
                                    ?>
                                    <span class="badge <?php echo $status_badge; ?>">
                                        <?php echo ucfirst($period['status']); ?>
                                    </span>
                                </td>
                                <td class="action-buttons">
                                    <button class="btn-icon btn-edit" onclick="editPeriod(<?php echo $period['id']; ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-icon btn-delete" onclick="deletePeriod(<?php echo $period['id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mobile Action Menu -->
    <div class="mobile-menu">
        <button class="mobile-menu-btn" onclick="openModal('addPeriodModal')">
            <i class="fas fa-plus"></i>
            <span>Add</span>
        </button>
        <button class="mobile-menu-btn" onclick="openModal('generateYearModal')">
            <i class="fas fa-calendar-plus"></i>
            <span>Generate</span>
        </button>
        <button class="mobile-menu-btn" onclick="resetFilters()">
            <i class="fas fa-filter"></i>
            <span>Filters</span>
        </button>
        <button class="mobile-menu-btn" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
            <i class="fas fa-arrow-up"></i>
            <span>Top</span>
        </button>
    </div>

    <!-- Modal: Add Academic Period -->
    <div id="addPeriodModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Add Academic Period</h2>
                <button class="modal-close" onclick="closeModal('addPeriodModal')">&times;</button>
            </div>
            <form method="POST" id="addPeriodForm">
                <div class="form-grid">
                    <div class="filter-group">
                        <label>Program Type</label>
                        <select name="program_type" class="form-control" required onchange="togglePeriodType(this.value)">
                            <option value="">Select Type</option>
                            <option value="onsite">Onsite (Monthly Cohorts)</option>
                            <option value="online">Online (Blocks)</option>
                            <option value="school">School-based (Termly)</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Period Type</label>
                        <select name="period_type" class="form-control" required id="periodTypeSelect">
                            <option value="">Select Period Type</option>
                            <option value="month" style="display:none;">Monthly Cohort</option>
                            <option value="term" style="display:none;">Term</option>
                            <option value="block" style="display:none;">Block</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Period Number</label>
                        <input type="number" name="period_number" class="form-control" required min="1" max="12" id="periodNumberInput">
                    </div>
                    <div class="filter-group">
                        <label>Period Name</label>
                        <input type="text" name="period_name" class="form-control" required placeholder="e.g., January 2024 Monthly Cohort">
                    </div>
                    <div class="filter-group">
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" class="form-control" required placeholder="e.g., 2024">
                    </div>
                    <div class="filter-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="filter-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    <div class="filter-group">
                        <label>Duration (weeks)</label>
                        <input type="number" name="duration_weeks" class="form-control" required min="1" max="52" id="durationWeeksInput">
                    </div>
                    <div class="filter-group">
                        <label>Registration Start</label>
                        <input type="date" name="registration_start_date" class="form-control" required>
                        <small style="color: #666; font-size: 0.85rem;">Registration opens</small>
                    </div>
                    <div class="filter-group">
                        <label>Registration Deadline</label>
                        <input type="date" name="registration_deadline" class="form-control" required>
                        <small style="color: #666; font-size: 0.85rem;">Registration closes</small>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="form-control" required>
                            <option value="upcoming">Upcoming</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addPeriodModal')">Cancel</button>
                    <button type="submit" name="add_period" class="btn btn-primary">Add Period</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Academic Period -->
    <div id="editPeriodModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Academic Period</h2>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <?php if ($period_to_edit): ?>
                <form method="POST" id="editPeriodForm">
                    <input type="hidden" name="edit_id" value="<?php echo $period_to_edit['id']; ?>">
                    <div class="form-grid">
                        <div class="filter-group">
                            <label>Program Type</label>
                            <select name="program_type" class="form-control" required onchange="toggleEditPeriodType(this.value)">
                                <option value="onsite" <?php echo $period_to_edit['program_type'] === 'onsite' ? 'selected' : ''; ?>>Onsite (Monthly Cohorts)</option>
                                <option value="online" <?php echo $period_to_edit['program_type'] === 'online' ? 'selected' : ''; ?>>Online (Blocks)</option>
                                <option value="school" <?php echo $period_to_edit['program_type'] === 'school' ? 'selected' : ''; ?>>School-based (Termly)</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Period Type</label>
                            <select name="period_type" class="form-control" required id="editPeriodTypeSelect">
                                <option value="month" <?php echo $period_to_edit['period_type'] === 'month' ? 'selected' : ''; ?>>Monthly Cohort</option>
                                <option value="term" <?php echo $period_to_edit['period_type'] === 'term' ? 'selected' : ''; ?>>Term</option>
                                <option value="block" <?php echo $period_to_edit['period_type'] === 'block' ? 'selected' : ''; ?>>Block</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Period Number</label>
                            <input type="number" name="period_number" class="form-control" required min="1" max="12" value="<?php echo htmlspecialchars($period_to_edit['period_number']); ?>" id="editPeriodNumberInput">
                        </div>
                        <div class="filter-group">
                            <label>Period Name</label>
                            <input type="text" name="period_name" class="form-control" required placeholder="e.g., January 2024 Monthly Cohort" value="<?php echo htmlspecialchars($period_to_edit['period_name']); ?>">
                        </div>
                        <div class="filter-group">
                            <label>Academic Year</label>
                            <input type="text" name="academic_year" class="form-control" required placeholder="e.g., 2024" value="<?php echo htmlspecialchars($period_to_edit['academic_year']); ?>">
                        </div>
                        <div class="filter-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" required value="<?php echo $period_to_edit['start_date']; ?>">
                        </div>
                        <div class="filter-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" required value="<?php echo $period_to_edit['end_date']; ?>">
                        </div>
                        <div class="filter-group">
                            <label>Duration (weeks)</label>
                            <input type="number" name="duration_weeks" class="form-control" required min="1" max="52" value="<?php echo $period_to_edit['duration_weeks']; ?>" id="editDurationWeeksInput">
                        </div>
                        <div class="filter-group">
                            <label>Registration Start</label>
                            <input type="date" name="registration_start_date" class="form-control"
                                value="<?php echo $period_to_edit['registration_start_date'] ?? ''; ?>">
                            <small style="color: #666; font-size: 0.85rem;">Registration opens</small>
                        </div>
                        <div class="filter-group">
                            <label>Registration Deadline</label>
                            <input type="date" name="registration_deadline" class="form-control"
                                value="<?php echo $period_to_edit['registration_deadline'] ?? ''; ?>">
                            <small style="color: #666; font-size: 0.85rem;">Registration closes</small>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status" class="form-control" required>
                                <option value="upcoming" <?php echo $period_to_edit['status'] === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                <option value="active" <?php echo $period_to_edit['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo $period_to_edit['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $period_to_edit['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="update_period" class="btn btn-primary">Update Period</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    Academic period not found or has been deleted.
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Close</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal: Generate Academic Year -->
    <div id="generateYearModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-calendar-plus"></i> Generate Academic Year</h2>
                <button class="modal-close" onclick="closeModal('generateYearModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="form-grid">
                    <div class="filter-group">
                        <label>Program Type</label>
                        <select name="program_type_gen" class="form-control" required id="genProgramType" onchange="togglePreview(this.value)">
                            <option value="">Select Program Type</option>
                            <option value="onsite">Onsite (12 Monthly Cohorts)</option>
                            <option value="online">Online (6 Blocks)</option>
                            <option value="school">School-based (3 Terms)</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" class="form-control" required
                            placeholder="e.g., 2024" pattern="\d{4}" title="Enter 4-digit year" value="<?php echo $current_year; ?>">
                        <small>Format: YYYY (e.g., 2024)</small>
                    </div>
                </div>
                <div id="onsitePreview" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <h4><i class="fas fa-building"></i> Onsite Monthly Cohorts</h4>
                    <p>This will generate 12 monthly cohorts:</p>
                    <ul style="margin-left: 1.5rem; font-size: 0.9rem;">
                        <li>January - December (12 monthly cohorts)</li>
                        <li>Each cohort: 4-week program</li>
                        <li>Registration opens 2 weeks before start</li>
                        <li>Registration closes 1 week before start</li>
                    </ul>
                </div>
                <div id="onlinePreview" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <h4><i class="fas fa-laptop-code"></i> Online Blocks</h4>
                    <p>This will generate 6 blocks of 8 weeks each:</p>
                    <ul style="margin-left: 1.5rem; font-size: 0.9rem;">
                        <li>Block A: September - October</li>
                        <li>Block B: November - December</li>
                        <li>Block C: January - February</li>
                        <li>Block D: March - April</li>
                        <li>Block E: May - June</li>
                        <li>Block F: July - August</li>
                    </ul>
                </div>
                <div id="schoolPreview" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <h4><i class="fas fa-school"></i> School-based Terms</h4>
                    <p>This will generate 3 terms per academic year:</p>
                    <ul style="margin-left: 1.5rem; font-size: 0.9rem;">
                        <li><strong>First Term:</strong> September - December (13 weeks)</li>
                        <li><strong>Second Term:</strong> January - March (13 weeks)</li>
                        <li><strong>Third Term:</strong> April - July (13 weeks)</li>
                        <li>Each term: 13-week program</li>
                        <li>Registration opens 4 weeks before term start</li>
                        <li>Registration closes 1 week before term start</li>
                    </ul>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('generateYearModal')">Cancel</button>
                    <button type="submit" name="generate_year" class="btn btn-primary">Generate Academic Year</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Improved Mobile Functions
        let isMobile = window.innerWidth <= 768;
        
        // Update mobile detection on resize
        window.addEventListener('resize', function() {
            isMobile = window.innerWidth <= 768;
        });

        // Modal functions with mobile optimizations
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'flex';
            
            // Prevent background scrolling on mobile
            if (isMobile) {
                document.body.style.overflow = 'hidden';
                modal.style.alignItems = 'flex-end';
            } else {
                modal.style.alignItems = 'center';
            }
            
            // Focus first input
            const firstInput = modal.querySelector('input, select, textarea');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Remove edit parameter from URL for edit modal
            if (modalId === 'editPeriodModal') {
                const url = new URL(window.location.href);
                url.searchParams.delete('edit');
                window.history.replaceState({}, document.title, url.toString());
            }
        }

        function closeEditModal() {
            closeModal('editPeriodModal');
        }

        // Toggle period type based on program type for add form
        function togglePeriodType(programType) {
            const termOption = document.querySelector('#addPeriodModal option[value="term"]');
            const blockOption = document.querySelector('#addPeriodModal option[value="block"]');
            const monthOption = document.querySelector('#addPeriodModal option[value="month"]');
            const durationInput = document.getElementById('durationWeeksInput');
            const periodNumberInput = document.getElementById('periodNumberInput');
            
            // Hide all options first
            termOption.style.display = 'none';
            blockOption.style.display = 'none';
            monthOption.style.display = 'none';
            
            if (programType === 'onsite') {
                monthOption.style.display = 'block';
                document.querySelector('#addPeriodModal select[name="period_type"]').value = 'month';
                if (durationInput && !durationInput.value) {
                    durationInput.value = 4;
                }
                if (periodNumberInput) {
                    periodNumberInput.max = 12;
                }
            } else if (programType === 'online') {
                blockOption.style.display = 'block';
                document.querySelector('#addPeriodModal select[name="period_type"]').value = 'block';
                if (durationInput && !durationInput.value) {
                    durationInput.value = 8;
                }
                if (periodNumberInput) {
                    periodNumberInput.max = 6;
                }
            } else if (programType === 'school') {
                termOption.style.display = 'block';
                document.querySelector('#addPeriodModal select[name="period_type"]').value = 'term';
                if (durationInput && !durationInput.value) {
                    durationInput.value = 13;
                }
                if (periodNumberInput) {
                    periodNumberInput.max = 3;
                }
            }
        }

        // Toggle period type for edit form
        function toggleEditPeriodType(programType) {
            const termOption = document.querySelector('#editPeriodModal option[value="term"]');
            const blockOption = document.querySelector('#editPeriodModal option[value="block"]');
            const monthOption = document.querySelector('#editPeriodModal option[value="month"]');
            const durationInput = document.getElementById('editDurationWeeksInput');
            const periodNumberInput = document.getElementById('editPeriodNumberInput');
            
            // Hide all options first
            termOption.style.display = 'none';
            blockOption.style.display = 'none';
            monthOption.style.display = 'none';
            
            if (programType === 'onsite') {
                monthOption.style.display = 'block';
                if (durationInput) {
                    durationInput.value = 4;
                }
                if (periodNumberInput) {
                    periodNumberInput.max = 12;
                }
            } else if (programType === 'online') {
                blockOption.style.display = 'block';
                if (durationInput) {
                    durationInput.value = 8;
                }
                if (periodNumberInput) {
                    periodNumberInput.max = 6;
                }
            } else if (programType === 'school') {
                termOption.style.display = 'block';
                if (durationInput) {
                    durationInput.value = 13;
                }
                if (periodNumberInput) {
                    periodNumberInput.max = 3;
                }
            }
        }

        // Show preview for generate year
        function togglePreview(programType) {
            const onsitePreview = document.getElementById('onsitePreview');
            const onlinePreview = document.getElementById('onlinePreview');
            const schoolPreview = document.getElementById('schoolPreview');

            onsitePreview.style.display = 'none';
            onlinePreview.style.display = 'none';
            schoolPreview.style.display = 'none';

            if (programType === 'onsite') {
                onsitePreview.style.display = 'block';
            } else if (programType === 'online') {
                onlinePreview.style.display = 'block';
            } else if (programType === 'school') {
                schoolPreview.style.display = 'block';
            }
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'calendar_manager.php';
        }

        // Edit period function
        function editPeriod(id) {
            const url = new URL(window.location.href);
            url.searchParams.set('edit', id);
            window.location.href = url.toString();
        }

        // Delete period function with mobile confirmation
        function deletePeriod(id) {
            if (confirm('Are you sure you want to delete this academic period?\nThis action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_period';
                input.value = id;

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Validate registration dates
        function validateRegistrationDates() {
            const regStart = document.querySelector('input[name="registration_start_date"]');
            const regDeadline = document.querySelector('input[name="registration_deadline"]');

            if (regStart && regDeadline && regStart.value && regDeadline.value) {
                const startDate = new Date(regStart.value);
                const deadlineDate = new Date(regDeadline.value);

                if (deadlineDate <= startDate) {
                    alert('Registration deadline must be after registration start date.');
                    return false;
                }

                // Optionally, validate that registration period is before start date
                const startPeriodDate = document.querySelector('input[name="start_date"]');
                if (startPeriodDate && startPeriodDate.value) {
                    const periodStartDate = new Date(startPeriodDate.value);
                    if (deadlineDate >= periodStartDate) {
                        if (!confirm('Registration deadline is on or after the period start date. Are you sure?')) {
                            return false;
                        }
                    }
                }
            }
            return true;
        }

        // Close modal when clicking outside or pressing escape
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        window.onkeydown = function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }
        };

        // Set default dates for add form
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const startDateInput = document.querySelector('#addPeriodModal input[name="start_date"]');
            const endDateInput = document.querySelector('#addPeriodModal input[name="end_date"]');
            const regStartInput = document.querySelector('#addPeriodModal input[name="registration_start_date"]');
            const regDeadlineInput = document.querySelector('#addPeriodModal input[name="registration_deadline"]');

            if (startDateInput) startDateInput.value = today;

            // Set end date to 4 weeks from today (for monthly cohorts)
            const endDate = new Date();
            endDate.setDate(endDate.getDate() + 28);
            const endDateStr = endDate.toISOString().split('T')[0];
            if (endDateInput) endDateInput.value = endDateStr;

            // Set registration start to today
            if (regStartInput) regStartInput.value = today;

            // Set registration deadline to 14 days from today
            const deadlineDate = new Date();
            deadlineDate.setDate(deadlineDate.getDate() + 14);
            const deadlineDateStr = deadlineDate.toISOString().split('T')[0];
            if (regDeadlineInput) regDeadlineInput.value = deadlineDateStr;

            // Add form validation
            const addForm = document.getElementById('addPeriodForm');
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    if (!validateRegistrationDates()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            const editForm = document.getElementById('editPeriodForm');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    if (!validateRegistrationDates()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            // Auto-submit filters on search after delay
            let searchTimeout;
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        document.getElementById('filterForm').submit();
                    }, 800);
                });
            }

            // Auto-open edit modal if we have edit parameter
            const urlParams = new URLSearchParams(window.location.search);
            const editId = urlParams.get('edit');
            if (editId) {
                openModal('editPeriodModal');
            }

            // Improve touch experience on mobile
            if (isMobile) {
                // Add touch-friendly styles to buttons
                const buttons = document.querySelectorAll('.btn, .btn-icon, .filter-reset');
                buttons.forEach(btn => {
                    btn.style.cursor = 'pointer';
                });

                // Make table rows more touch-friendly
                const tableRows = document.querySelectorAll('tbody tr');
                tableRows.forEach(row => {
                    row.style.cursor = 'pointer';
                });
            }
        });

        // Smooth scrolling for mobile
        function smoothScrollTo(element) {
            element.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    </script>
</body>

</html>
