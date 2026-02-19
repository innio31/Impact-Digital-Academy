<?php
// modules/instructor/classes/materials.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code, 
               p.name as program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
        WHERE cb.id = ? AND cb.instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Handle file upload or external link
$upload_success = false;
$upload_error = '';
$allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'mov', 'zip'];
$max_size = 50 * 1024 * 1024; // 50MB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $week_number = $_POST['week_number'] ?? null;
    $topic = trim($_POST['topic'] ?? '');
    $file_type = $_POST['file_type'] ?? 'document';
    $source_type = $_POST['source_type'] ?? 'file';

    // Validate input
    if (empty($title)) {
        $upload_error = "Title is required";
    } elseif ($source_type === 'file' && !isset($_FILES['material_file'])) {
        $upload_error = "Please select a file to upload";
    } elseif ($source_type === 'link' && empty(trim($_POST['external_url'] ?? ''))) {
        $upload_error = "Please enter a valid URL";
    } else {
        $file_url = null;
        $external_url = null;
        $is_external_link = 0;
        $file_size = 0;

        if ($source_type === 'file') {
            // Handle file upload
            if ($_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
                $upload_error = "File upload error: " . $_FILES['material_file']['error'];
            } elseif ($_FILES['material_file']['size'] > $max_size) {
                $upload_error = "File size exceeds maximum limit of 50MB";
            } else {
                // Check file extension
                $file_name = $_FILES['material_file']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed_types)) {
                    $upload_error = "File type '$file_ext' not allowed. Allowed types: " . implode(', ', $allowed_types);
                } else {
                    // Create uploads directory if it doesn't exist
                    $upload_dir = '../../../../public/uploads/materials/' . $class_id . '/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    // Generate unique filename
                    $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
                    $destination = $upload_dir . $unique_name;

                    // Move uploaded file
                    if (move_uploaded_file($_FILES['material_file']['tmp_name'], $destination)) {
                        $file_url = 'uploads/materials/' . $class_id . '/' . $unique_name;
                        $file_size = $_FILES['material_file']['size'];
                        $is_external_link = 0;
                    } else {
                        $upload_error = "Failed to move uploaded file";
                    }
                }
            }
        } else {
            // Handle external link
            $external_url = filter_var(trim($_POST['external_url']), FILTER_VALIDATE_URL);
            if (!$external_url) {
                $upload_error = "Please enter a valid URL";
            } else {
                // Set file_type based on link_type if provided
                $link_type = $_POST['link_type'] ?? 'link';
                $file_type = $link_type;
                $is_external_link = 1;

                // Set a generic file_url for external links
                $file_url = 'external/link/' . md5($external_url);
            }
        }

        if (!$upload_error) {
            // Insert into database
            $sql = "INSERT INTO materials (class_id, instructor_id, title, description, file_url, 
                    external_url, is_external_link, file_type, file_size, week_number, topic, publish_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "iissssssiis",
                $class_id,
                $instructor_id,
                $title,
                $description,
                $file_url,
                $external_url,
                $is_external_link,
                $file_type,
                $file_size,
                $week_number,
                $topic
            );

            if ($stmt->execute()) {
                $upload_success = true;
                $material_id = $stmt->insert_id;

                // Log activity
                logActivity('material_uploaded', "Uploaded material: $title", 'materials', $material_id);

                // Clear form
                $_POST = [];
            } else {
                $upload_error = "Failed to save material to database";
                // Delete uploaded file if it was a file upload
                if ($source_type === 'file' && isset($destination)) {
                    unlink($destination);
                }
            }
            $stmt->close();
        }
    }
}

// Handle material deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $material_id = (int)$_GET['delete'];

    // Verify instructor owns this material
    $sql = "SELECT file_url, is_external_link FROM materials WHERE id = ? AND instructor_id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $material_id, $instructor_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $material = $result->fetch_assoc();

        // Delete file only if it's not an external link and file exists
        if (!$material['is_external_link'] && !empty($material['file_url'])) {
            $file_path = '../../../../public/' . $material['file_url'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        // Delete from database
        $delete_sql = "DELETE FROM materials WHERE id = ? AND instructor_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $material_id, $instructor_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        // Log activity
        logActivity('material_deleted', "Deleted material ID: $material_id", 'materials', $material_id);

        // Redirect to avoid resubmission
        header("Location: materials.php?class_id=$class_id&deleted=1");
        exit();
    }
    $stmt->close();
}

// Handle filters
$filter_week = $_GET['week'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query for materials
$query = "SELECT m.*, 
                 CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
                 m.downloads_count + m.views_count as total_access
          FROM materials m 
          JOIN users u ON m.instructor_id = u.id 
          WHERE m.class_id = ? AND m.instructor_id = ?";

$params = [$class_id, $instructor_id];
$types = "ii";

// Apply week filter
if ($filter_week !== 'all' && is_numeric($filter_week)) {
    $query .= " AND m.week_number = ?";
    $params[] = $filter_week;
    $types .= "i";
}

// Apply type filter
if ($filter_type !== 'all') {
    $query .= " AND m.file_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

// Apply search filter
if (!empty($search_term)) {
    $query .= " AND (m.title LIKE ? OR m.description LIKE ? OR m.topic LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query .= " ORDER BY m.week_number ASC, m.created_at DESC";

// Get materials
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$materials = $result->fetch_all(MYSQLI_ASSOC);

// Get unique weeks for filter
$weeks_sql = "SELECT DISTINCT week_number FROM materials 
              WHERE class_id = ? AND instructor_id = ? AND week_number IS NOT NULL 
              ORDER BY week_number";
$stmt = $conn->prepare($weeks_sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$weeks_result = $stmt->get_result();
$available_weeks = $weeks_result->fetch_all(MYSQLI_ASSOC);

// Get file type counts for stats
$type_counts_sql = "SELECT file_type, COUNT(*) as count 
                    FROM materials 
                    WHERE class_id = ? AND instructor_id = ? 
                    GROUP BY file_type";
$stmt = $conn->prepare($type_counts_sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$type_counts_result = $stmt->get_result();
$type_counts = [];
while ($row = $type_counts_result->fetch_assoc()) {
    $type_counts[$row['file_type']] = $row['count'];
}

// Get total stats
$stats_sql = "SELECT 
                COUNT(*) as total_materials,
                COALESCE(SUM(file_size), 0) as total_size,
                COALESCE(SUM(downloads_count), 0) as total_downloads,
                COALESCE(SUM(views_count), 0) as total_views
              FROM materials 
              WHERE class_id = ? AND instructor_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teaching Materials - <?php echo htmlspecialchars($class['batch_code']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-left: 6px solid var(--primary);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .class-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-info p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .status-badge {
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-scheduled {
            background: #fef3c7;
            color: #92400e;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #e5e7eb;
            color: #374151;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid var(--primary);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.size {
            border-top-color: var(--info);
        }

        .stat-card.downloads {
            border-top-color: var(--success);
        }

        .stat-card.views {
            border-top-color: var(--warning);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-link {
            margin-top: 0.5rem;
        }

        .stat-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-link a:hover {
            text-decoration: underline;
        }

        /* Filters Card */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
            padding-right: 3rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }

        /* Materials List */
        .materials-list-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            background: #f8fafc;
        }

        .table-header h2 {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .materials-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .materials-table th {
            background: #f8fafc;
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .materials-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .materials-table tr:hover {
            background: #f8fafc;
        }

        .materials-table tr:last-child td {
            border-bottom: none;
        }

        /* Material Item */
        .material-info-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .material-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .material-icon.pdf {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .material-icon.document {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .material-icon.presentation {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .material-icon.spreadsheet {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .material-icon.video {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .material-icon.image {
            background: rgba(236, 72, 153, 0.1);
            color: #ec4899;
        }

        .material-icon.other {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray);
        }

        .material-details h4 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .material-details p {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .week-badge {
            background: #e0e7ff;
            color: #3730a3;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .file-size-badge {
            background: #f3f4f6;
            color: var(--dark);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .access-badge {
            background: #f3f4f6;
            color: var(--dark);
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .access-high {
            background: #d1fae5;
            color: #065f46;
        }

        .access-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .access-low {
            background: #fee2e2;
            color: #991b1b;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            padding: 0.5rem;
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Upload Form */
        .upload-form-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .form-header h3 {
            font-size: 1.25rem;
            color: var(--dark);
        }

        .toggle-form {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .form-content {
            display: none;
        }

        .form-content.show {
            display: block;
        }

        .file-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .file-upload:hover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .file-upload input {
            display: none;
        }

        .file-label {
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
        }

        .file-label i {
            font-size: 2rem;
            color: var(--primary);
        }

        .file-name {
            font-size: 0.875rem;
            margin-top: 0.5rem;
            color: var(--dark);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 2px solid #f1f5f0;
        }

        /* Messages */
        .message {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .message-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Type Filters */
        .type-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .type-filter {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--dark);
        }

        .type-filter:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .type-filter.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .type-filter .count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.125rem 0.375rem;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 0.25rem;
        }

        /* Add to your CSS */
        .source-section {
            transition: all 0.3s ease;
        }

        .file-type-icon {
            font-size: 1rem;
            margin-right: 0.5rem;
        }

        /* Link-specific styles */
        .link-preview {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border-left: 4px solid var(--primary);
        }

        .link-icon {
            background: #e0f2fe;
            color: #0369a1;
            padding: 0.75rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Teaching Materials</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Teaching Materials</h1>
                    <p><?php echo htmlspecialchars($class['name']); ?></p>
                </div>
                <span class="status-badge status-<?php echo $class['status']; ?>">
                    <?php echo ucfirst($class['status']); ?>
                </span>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-file-alt"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="students.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Gradebook
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($upload_success): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo "Material uploaded successfully!"; ?></div>
            </div>
        <?php elseif ($upload_error): ?>
            <div class="message message-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($upload_error); ?></div>
            </div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div>Material deleted successfully!</div>
            </div>
        <?php endif; ?>

        <!-- Class Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total_materials']; ?></div>
                <div class="stat-label">Total Materials</div>
            </div>
            <div class="stat-card size">
                <div class="stat-value"><?php echo formatFileSize($stats['total_size']); ?></div>
                <div class="stat-label">Total Size</div>
            </div>
            <div class="stat-card downloads">
                <div class="stat-value"><?php echo $stats['total_downloads']; ?></div>
                <div class="stat-label">Total Downloads</div>
            </div>
            <div class="stat-card views">
                <div class="stat-value"><?php echo $stats['total_views']; ?></div>
                <div class="stat-label">Total Views</div>
            </div>
        </div>

        <!-- Upload Form -->
        <div class="upload-form-card">
            <div class="form-header">
                <h3><i class="fas fa-upload"></i> Upload New Material</h3>
                <button class="toggle-form" onclick="toggleUploadForm()">
                    <i class="fas fa-plus-circle"></i> New Upload
                </button>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" id="uploadForm" class="form-content">
                <div class="form-group">
                    <label class="form-label" for="title">Material Title</label>
                    <input type="text" id="title" name="title" class="form-control"
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                        placeholder="e.g., Introduction to Web Development" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"
                        placeholder="Brief description of the material"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <small class="form-help">Optional. Helps students understand what the material is about.</small>
                </div>

                <div class="filters-form" style="grid-template-columns: 1fr 1fr 1fr; margin-top: 1rem;">
                    <div class="form-group">
                        <label class="form-label" for="week_number">Week/Topic Number</label>
                        <input type="number" id="week_number" name="week_number" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['week_number'] ?? ''); ?>"
                            placeholder="e.g., 1" min="1">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="topic">Topic/Module</label>
                        <input type="text" id="topic" name="topic" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['topic'] ?? ''); ?>"
                            placeholder="e.g., HTML Basics">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="file_type">File Type</label>
                        <select id="file_type" name="file_type" class="form-control form-select">
                            <option value="document" <?php echo ($_POST['file_type'] ?? 'document') === 'document' ? 'selected' : ''; ?>>Document</option>
                            <option value="pdf" <?php echo ($_POST['file_type'] ?? '') === 'pdf' ? 'selected' : ''; ?>>PDF</option>
                            <option value="presentation" <?php echo ($_POST['file_type'] ?? '') === 'presentation' ? 'selected' : ''; ?>>Presentation</option>
                            <option value="spreadsheet" <?php echo ($_POST['file_type'] ?? '') === 'spreadsheet' ? 'selected' : ''; ?>>Spreadsheet</option>
                            <option value="video" <?php echo ($_POST['file_type'] ?? '') === 'video' ? 'selected' : ''; ?>>Video</option>
                            <option value="image" <?php echo ($_POST['file_type'] ?? '') === 'image' ? 'selected' : ''; ?>>Image</option>
                            <option value="link" <?php echo ($_POST['file_type'] ?? '') === 'link' ? 'selected' : ''; ?>>External Link</option>
                            <option value="other" <?php echo ($_POST['file_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <!-- Material Source Selection -->
                <div class="form-group">
                    <label class="form-label">Material Source</label>
                    <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="radio" name="source_type" value="file" checked onchange="toggleSourceType()">
                            <span>Upload File</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="radio" name="source_type" value="link" onchange="toggleSourceType()">
                            <span>External Link</span>
                        </label>
                    </div>
                </div>

                <!-- File Upload Section -->
                <div id="file-upload-section" class="source-section">
                    <div class="form-group">
                        <label class="form-label">Select File</label>
                        <div class="file-upload">
                            <input type="file" id="material_file" name="material_file"
                                accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.mp4,.mov,.zip">
                            <label for="material_file" class="file-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <div>
                                    <strong>Click to upload</strong>
                                    <div style="font-size: 0.75rem; margin-top: 0.25rem;">
                                        Max file size: 50MB
                                    </div>
                                </div>
                            </label>
                            <div id="file-name" class="file-name"></div>
                        </div>
                        <small class="form-help">
                            Allowed types: PDF, Word, Excel, PowerPoint, Images, Video, ZIP
                        </small>
                    </div>
                </div>

                <!-- External Link Section -->
                <div id="link-upload-section" class="source-section" style="display: none;">
                    <div class="form-group">
                        <label class="form-label" for="external_url">External Link</label>
                        <input type="url" id="external_url" name="external_url" class="form-control"
                            placeholder="https://drive.google.com/file/d/... or https://youtube.com/watch?v=..."
                            pattern="https?://.+">
                        <small class="form-help">
                            Enter a valid URL (Google Drive, YouTube, OneDrive, Dropbox, etc.)
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="link_type">Link Type</label>
                        <select id="link_type" name="link_type" class="form-control form-select">
                            <option value="document">Document (PDF, Word, etc.)</option>
                            <option value="video">Video (YouTube, Vimeo, etc.)</option>
                            <option value="presentation">Presentation</option>
                            <option value="link">General Link</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="toggleUploadForm()">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Save Material
                    </button>
                </div>
            </form>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" class="filters-form">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <div class="form-group">
                    <label class="form-label" for="search">Search Materials</label>
                    <input type="text" id="search" name="search" class="form-control"
                        placeholder="Search by title, description or topic..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="week">Week</label>
                    <select id="week" name="week" class="form-control form-select">
                        <option value="all" <?php echo $filter_week === 'all' ? 'selected' : ''; ?>>All Weeks</option>
                        <?php foreach ($available_weeks as $week): ?>
                            <option value="<?php echo $week['week_number']; ?>"
                                <?php echo $filter_week == $week['week_number'] ? 'selected' : ''; ?>>
                                Week <?php echo $week['week_number']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="type">File Type</label>
                    <select id="type" name="type" class="form-control form-select">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="pdf" <?php echo $filter_type === 'pdf' ? 'selected' : ''; ?>>PDF Documents</option>
                        <option value="document" <?php echo $filter_type === 'document' ? 'selected' : ''; ?>>Documents</option>
                        <option value="presentation" <?php echo $filter_type === 'presentation' ? 'selected' : ''; ?>>Presentations</option>
                        <option value="spreadsheet" <?php echo $filter_type === 'spreadsheet' ? 'selected' : ''; ?>>Spreadsheets</option>
                        <option value="video" <?php echo $filter_type === 'video' ? 'selected' : ''; ?>>Videos</option>
                        <option value="image" <?php echo $filter_type === 'image' ? 'selected' : ''; ?>>Images</option>
                        <option value="link" <?php echo $filter_type === 'link' ? 'selected' : ''; ?>>External Links</option>
                        <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other Files</option>
                    </select>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="materials.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>

            <!-- Type Filters -->
            <div style="margin-top: 1rem;">
                <label style="font-size: 0.875rem; font-weight: 600; color: var(--dark); margin-bottom: 0.5rem; display: block;">
                    Quick Filter by Type:
                </label>
                <div class="type-filters">
                    <a href="?class_id=<?php echo $class_id; ?>&type=all&week=<?php echo $filter_week; ?>&search=<?php echo urlencode($search_term); ?>"
                        class="type-filter <?php echo $filter_type === 'all' ? 'active' : ''; ?>">
                        All <span class="count"><?php echo $stats['total_materials']; ?></span>
                    </a>
                    <?php foreach ($type_counts as $type => $count): ?>
                        <a href="?class_id=<?php echo $class_id; ?>&type=<?php echo $type; ?>&week=<?php echo $filter_week; ?>&search=<?php echo urlencode($search_term); ?>"
                            class="type-filter <?php echo $filter_type === $type ? 'active' : ''; ?>">
                            <i class="<?php echo getFileIcon($type); ?>"></i> <?php echo getFileTypeLabel($type); ?>
                            <span class="count"><?php echo $count; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Materials List -->
        <div class="materials-list-container">
            <div class="table-header">
                <h2><i class="fas fa-file-alt"></i> Materials List</h2>
                <?php if (count($materials) > 0): ?>
                    <span style="font-size: 0.875rem; color: var(--gray);">
                        Showing <?php echo count($materials); ?> material(s)
                    </span>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <?php if (empty($materials)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h3>No Materials Found</h3>
                        <p>
                            <?php if ($filter_type !== 'all' || $filter_week !== 'all' || !empty($search_term)): ?>
                                No materials match your current filters. <a href="?class_id=<?php echo $class_id; ?>" style="color: var(--primary);">Clear filters</a> to see all materials.
                            <?php else: ?>
                                You haven't uploaded any materials yet. Click "New Upload" to add your first material.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="materials-table">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Week</th>
                                <th>Access</th>
                                <th>Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials as $material):
                                $access_class = $material['total_access'] > 20 ? 'access-high' : ($material['total_access'] > 10 ? 'access-medium' : 'access-low');
                                $is_external = $material['is_external_link'] ?? 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="material-info-cell">
                                            <div class="material-icon <?php echo $material['file_type']; ?>">
                                                <?php if ($is_external): ?>
                                                    <i class="fas fa-external-link-alt"></i>
                                                <?php else: ?>
                                                    <i class="<?php echo getFileIcon($material['file_type']); ?>"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="material-details">
                                                <h4>
                                                    <?php echo htmlspecialchars($material['title']); ?>
                                                    <?php if ($is_external): ?>
                                                        <span style="font-size: 0.75rem; background: #e0f2fe; color: #0369a1; padding: 0.125rem 0.5rem; border-radius: 4px; margin-left: 0.5rem;">External Link</span>
                                                    <?php endif; ?>
                                                </h4>
                                                <?php if (!empty($material['description'])): ?>
                                                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars(substr($material['description'], 0, 60)); ?><?php if (strlen($material['description']) > 60): ?>...<?php endif; ?></p>
                                                <?php endif; ?>
                                                <?php if ($is_external && !empty($material['external_url'])): ?>
                                                    <p style="font-size: 0.75rem; color: var(--primary); margin-top: 0.25rem;">
                                                        <i class="fas fa-link"></i> <?php echo htmlspecialchars(parse_url($material['external_url'], PHP_URL_HOST)); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.875rem; font-weight: 500;">
                                            <?php echo getFileTypeLabel($material['file_type']); ?>
                                            <?php if ($is_external): ?>
                                                <br><small style="font-size: 0.75rem; color: var(--gray);">(External)</small>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($is_external): ?>
                                            <span class="file-size-badge">External</span>
                                        <?php else: ?>
                                            <span class="file-size-badge">
                                                <?php echo formatFileSize($material['file_size']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($material['week_number']): ?>
                                            <span class="week-badge">
                                                Week <?php echo $material['week_number']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-size: 0.875rem;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="access-badge <?php echo $access_class; ?>">
                                            <i class="fas fa-download"></i> <?php echo $material['downloads_count']; ?> •
                                            <i class="fas fa-eye"></i> <?php echo $material['views_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.875rem;">
                                            <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($is_external && !empty($material['external_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($material['external_url']); ?>"
                                                    target="_blank" class="btn btn-primary btn-icon" title="Open Link">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo BASE_URL . 'public/' . $material['file_url']; ?>"
                                                    target="_blank" class="btn btn-primary btn-icon" title="Preview">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo BASE_URL . 'public/' . $material['file_url']; ?>"
                                                    download class="btn btn-secondary btn-icon" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="?class_id=<?php echo $class_id; ?>&delete=<?php echo $material['id']; ?>"
                                                class="btn btn-danger btn-icon" title="Delete"
                                                onclick="return confirm('Are you sure you want to delete this material? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Toggle upload form
        function toggleUploadForm() {
            const form = document.getElementById('uploadForm');
            form.classList.toggle('show');
        }

        // Toggle between file upload and external link
        function toggleSourceType() {
            const sourceType = document.querySelector('input[name="source_type"]:checked').value;
            const fileSection = document.getElementById('file-upload-section');
            const linkSection = document.getElementById('link-upload-section');
            const fileInput = document.getElementById('material_file');
            const linkInput = document.getElementById('external_url');

            if (sourceType === 'file') {
                fileSection.style.display = 'block';
                linkSection.style.display = 'none';
                fileInput.required = true;
                linkInput.required = false;
            } else {
                fileSection.style.display = 'none';
                linkSection.style.display = 'block';
                fileInput.required = false;
                linkInput.required = true;
            }
        }

        // Detect link type from URL
        function detectLinkType(url) {
            const youtubePatterns = [/youtube\.com/, /youtu\.be/, /vimeo\.com/];
            const drivePatterns = [/drive\.google\.com/, /docs\.google\.com/];
            const presentationPatterns = [/slides\.google\.com/, /powerpoint/];

            for (const pattern of youtubePatterns) {
                if (pattern.test(url)) return 'video';
            }
            for (const pattern of drivePatterns) {
                if (pattern.test(url)) return 'document';
            }
            for (const pattern of presentationPatterns) {
                if (pattern.test(url)) return 'presentation';
            }

            return 'link';
        }

        // Show selected file name
        document.getElementById('material_file').addEventListener('change', function(e) {
            const fileName = this.files[0] ? this.files[0].name : 'No file selected';
            document.getElementById('file-name').textContent = fileName;
        });

        // Auto-detect link type when URL changes
        document.getElementById('external_url').addEventListener('input', function(e) {
            const url = e.target.value;
            if (url && url.startsWith('http')) {
                const detectedType = detectLinkType(url);
                document.getElementById('link_type').value = detectedType;
            }
        });

        // Form validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const sourceType = document.querySelector('input[name="source_type"]:checked').value;

            if (!title) {
                e.preventDefault();
                alert('Please enter a title for the material');
                document.getElementById('title').focus();
                return false;
            }

            if (sourceType === 'file') {
                const file = document.getElementById('material_file').files[0];
                if (!file) {
                    e.preventDefault();
                    alert('Please select a file to upload');
                    return false;
                }

                // Check file size (50MB limit)
                if (file.size > 50 * 1024 * 1024) {
                    e.preventDefault();
                    alert('File size exceeds 50MB limit. Please choose a smaller file.');
                    return false;
                }
            } else {
                const url = document.getElementById('external_url').value.trim();
                if (!url) {
                    e.preventDefault();
                    alert('Please enter a valid URL');
                    document.getElementById('external_url').focus();
                    return false;
                }

                if (!url.startsWith('http://') && !url.startsWith('https://')) {
                    e.preventDefault();
                    alert('Please enter a valid URL starting with http:// or https://');
                    document.getElementById('external_url').focus();
                    return false;
                }
            }

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
        });

        // Auto-submit search after typing stops
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.closest('form').submit();
                }, 500);
            });
        }

        // Initialize form state
        document.addEventListener('DOMContentLoaded', function() {
            // Show form if there was an upload error
            <?php if ($upload_error): ?>
                document.getElementById('uploadForm').classList.add('show');
                toggleSourceType(); // Ensure correct source type is shown
            <?php endif; ?>

            // Initialize source type toggle
            toggleSourceType();

            // Show success message temporarily
            const successAlert = document.querySelector('.message-success');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.5s ease';
                    successAlert.style.opacity = '0';
                    setTimeout(() => successAlert.remove(), 500);
                }, 5000);
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }

            // Ctrl + U to toggle upload form
            if (e.ctrlKey && e.key === 'u') {
                e.preventDefault();
                toggleUploadForm();
            }

            // Esc to clear search
            if (e.key === 'Escape' && searchInput && searchInput.value) {
                searchInput.value = '';
                searchInput.closest('form').submit();
            }

            // Escape to close upload form
            if (e.key === 'Escape') {
                const form = document.getElementById('uploadForm');
                if (form.classList.contains('show')) {
                    toggleUploadForm();
                }
            }
        });
    </script>
</body>

</html>