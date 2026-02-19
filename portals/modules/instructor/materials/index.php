<?php
// modules/instructor/materials/index.php

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

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$instructor_id = $_SESSION['user_id'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;

// Initialize variables
$class = null;
$materials = [];
$stats = [
    'total_materials' => 0,
    'recent_uploads' => 0,
    'total_downloads' => 0,
    'total_views' => 0
];

// If class_id is provided, get class details
if ($class_id) {
    $sql = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name
            FROM class_batches cb 
            JOIN courses c ON cb.course_id = c.id 
            JOIN programs p ON c.program_id = p.id 
            WHERE cb.id = ? AND cb.instructor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $class_id, $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $class = $result->fetch_assoc();
    } else {
        // Instructor doesn't have access to this class
        $_SESSION['error_message'] = "You don't have access to this class or it doesn't exist.";
        header('Location: ' . BASE_URL . 'modules/instructor/classes/');
        exit();
    }
    $stmt->close();
}

// Get materials based on filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$sql = "SELECT m.*, cb.batch_code, c.title as course_title,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
               (SELECT COUNT(*) FROM submission_files sf WHERE sf.submission_id IN 
                (SELECT id FROM assignment_submissions WHERE assignment_id IN 
                 (SELECT id FROM assignments WHERE class_id = cb.id))) as student_submissions
        FROM materials m 
        JOIN class_batches cb ON m.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        JOIN users u ON m.instructor_id = u.id 
        WHERE m.instructor_id = ?";

$params = [$instructor_id];
$param_types = "i";

// Add class filter if specified
if ($class_id) {
    $sql .= " AND m.class_id = ?";
    $params[] = $class_id;
    $param_types .= "i";
}

// Add filter conditions
switch ($filter) {
    case 'published':
        $sql .= " AND m.is_published = 1";
        break;
    case 'draft':
        $sql .= " AND m.is_published = 0";
        break;
    case 'video':
        $sql .= " AND m.file_type = 'video'";
        break;
    case 'document':
        $sql .= " AND m.file_type IN ('pdf', 'document', 'presentation')";
        break;
    case 'link':
        $sql .= " AND m.file_type = 'link'";
        break;
}

// Add search condition
if (!empty($search)) {
    $sql .= " AND (m.title LIKE ? OR m.description LIKE ? OR m.topic LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "sss";
}

$sql .= " ORDER BY m.is_published DESC, m.created_at DESC";

// Prepare and execute
$stmt = $conn->prepare($sql);

if ($param_types === "i") {
    $stmt->bind_param($param_types, $params[0]);
} elseif (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $materials = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get stats
$sql = "SELECT 
            COUNT(*) as total_materials,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_uploads,
            SUM(downloads_count) as total_downloads,
            SUM(views_count) as total_views
        FROM materials 
        WHERE instructor_id = ?";

if ($class_id) {
    $sql .= " AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $instructor_id, $class_id);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
}

$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $stats = $row;
}
$stmt->close();

// Get all classes for this instructor for dropdown
$sql = "SELECT cb.id, cb.batch_code, c.title as course_name, 
               (SELECT COUNT(*) FROM materials WHERE class_id = cb.id) as material_count
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.instructor_id = ? 
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$instructor_classes = [];
if ($result && $result->num_rows > 0) {
    $instructor_classes = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Log activity
logActivity('view_materials', 'Viewed teaching materials', $instructor_id);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teaching Materials - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
        }

        .material-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .material-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .file-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-right: 15px;
        }

        .pdf-icon {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .video-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .doc-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .presentation-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .link-icon {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
        }

        .stats-card {
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .stats-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stats-card.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .stats-card.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .stats-card.info {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }

        .upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 10px;
            padding: 3rem;
            text-align: center;
            background: #f8fafc;
            transition: all 0.3s ease;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: #f1f5f9;
        }

        .dropdown-item:hover {
            background-color: var(--primary);
            color: white;
        }
    </style>
</head>

<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="position-sticky pt-3">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="card-title mb-3">
                                <i class="fas fa-filter me-2"></i>Filters
                            </h6>
                            <div class="list-group list-group-flush">
                                <a href="?<?php echo $class_id ? 'class_id=' . $class_id . '&' : ''; ?>filter=all"
                                    class="list-group-item list-group-item-action <?php echo $filter === 'all' ? 'active' : ''; ?>">
                                    <i class="fas fa-layer-group me-2"></i> All Materials
                                </a>
                                <a href="?<?php echo $class_id ? 'class_id=' . $class_id . '&' : ''; ?>filter=published"
                                    class="list-group-item list-group-item-action <?php echo $filter === 'published' ? 'active' : ''; ?>">
                                    <i class="fas fa-eye me-2"></i> Published
                                </a>
                                <a href="?<?php echo $class_id ? 'class_id=' . $class_id . '&' : ''; ?>filter=draft"
                                    class="list-group-item list-group-item-action <?php echo $filter === 'draft' ? 'active' : ''; ?>">
                                    <i class="fas fa-eye-slash me-2"></i> Drafts
                                </a>
                                <a href="?<?php echo $class_id ? 'class_id=' . $class_id . '&' : ''; ?>filter=video"
                                    class="list-group-item list-group-item-action <?php echo $filter === 'video' ? 'active' : ''; ?>">
                                    <i class="fas fa-video me-2"></i> Videos
                                </a>
                                <a href="?<?php echo $class_id ? 'class_id=' . $class_id . '&' : ''; ?>filter=document"
                                    class="list-group-item list-group-item-action <?php echo $filter === 'document' ? 'active' : ''; ?>">
                                    <i class="fas fa-file-alt me-2"></i> Documents
                                </a>
                                <a href="?<?php echo $class_id ? 'class_id=' . $class_id . '&' : ''; ?>filter=link"
                                    class="list-group-item list-group-item-action <?php echo $filter === 'link' ? 'active' : ''; ?>">
                                    <i class="fas fa-link me-2"></i> Links
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title mb-3">
                                <i class="fas fa-chalkboard me-2"></i> My Classes
                            </h6>
                            <div class="list-group list-group-flush">
                                <a href="index.php"
                                    class="list-group-item list-group-item-action <?php echo !$class_id ? 'active' : ''; ?>">
                                    <i class="fas fa-globe me-2"></i> All Classes
                                    <span class="badge bg-primary float-end"><?php echo $stats['total_materials']; ?></span>
                                </a>
                                <?php foreach ($instructor_classes as $class_item): ?>
                                    <a href="?class_id=<?php echo $class_item['id']; ?>"
                                        class="list-group-item list-group-item-action <?php echo $class_id == $class_item['id'] ? 'active' : ''; ?>">
                                        <i class="fas fa-chalkboard-teacher me-2"></i>
                                        <?php echo htmlspecialchars($class_item['batch_code']); ?>
                                        <span class="badge bg-secondary float-end"><?php echo $class_item['material_count']; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/instructor/classes/">My Classes</a></li>
                        <?php if ($class): ?>
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/instructor/classes/view.php?id=<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['batch_code']); ?></a></li>
                        <?php endif; ?>
                        <li class="breadcrumb-item active">Teaching Materials</li>
                    </ol>
                </nav>

                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-file-alt text-primary me-2"></i>
                            Teaching Materials
                        </h1>
                        <p class="text-muted mb-0">
                            <?php if ($class): ?>
                                <?php echo htmlspecialchars($class['batch_code']); ?> - <?php echo htmlspecialchars($class['course_title']); ?>
                            <?php else: ?>
                                All your teaching materials across all classes
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <a href="upload.php<?php echo $class_id ? '?class_id=' . $class_id : ''; ?>" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Upload Material
                        </a>
                        <a href="organize.php<?php echo $class_id ? '?class_id=' . $class_id : ''; ?>" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-folder me-2"></i> Organize
                        </a>
                    </div>
                </div>

                <!-- Search and Stats -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <form method="GET" class="d-flex">
                            <div class="input-group">
                                <input type="text" class="form-control" name="search"
                                    placeholder="Search materials by title, description, or topic..."
                                    value="<?php echo htmlspecialchars($search); ?>">
                                <?php if ($class_id): ?>
                                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                                <?php endif; ?>
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-end">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleView('grid')">
                                    <i class="fas fa-th-large"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleView('list')">
                                    <i class="fas fa-list"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card primary">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $stats['total_materials']; ?></h3>
                                    <p class="mb-0 opacity-75">Total Materials</p>
                                </div>
                                <i class="fas fa-layer-group fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card success">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $stats['recent_uploads']; ?></h3>
                                    <p class="mb-0 opacity-75">This Week</p>
                                </div>
                                <i class="fas fa-clock fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card warning">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $stats['total_downloads']; ?></h3>
                                    <p class="mb-0 opacity-75">Total Downloads</p>
                                </div>
                                <i class="fas fa-download fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card info">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $stats['total_views']; ?></h3>
                                    <p class="mb-0 opacity-75">Total Views</p>
                                </div>
                                <i class="fas fa-eye fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Materials Grid -->
                <div id="materials-grid" class="row">
                    <?php if (empty($materials)): ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-file-alt fa-4x text-muted mb-3"></i>
                                <h3 class="text-muted">No Materials Found</h3>
                                <p class="text-muted mb-4">
                                    <?php if (!empty($search)): ?>
                                        No materials match your search criteria.
                                    <?php else: ?>
                                        You haven't uploaded any materials yet.
                                    <?php endif; ?>
                                </p>
                                <a href="upload.php<?php echo $class_id ? '?class_id=' . $class_id : ''; ?>" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i> Upload Your First Material
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($materials as $material): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card material-card h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-start mb-3">
                                            <div class="file-icon <?php echo $material['file_type']; ?>-icon">
                                                <?php
                                                $icon = 'fa-file';
                                                switch ($material['file_type']) {
                                                    case 'pdf':
                                                        $icon = 'fa-file-pdf';
                                                        break;
                                                    case 'video':
                                                        $icon = 'fa-file-video';
                                                        break;
                                                    case 'document':
                                                        $icon = 'fa-file-word';
                                                        break;
                                                    case 'presentation':
                                                        $icon = 'fa-file-powerpoint';
                                                        break;
                                                    case 'link':
                                                        $icon = 'fa-link';
                                                        break;
                                                }
                                                ?>
                                                <i class="fas <?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h5 class="card-title mb-1">
                                                    <?php echo htmlspecialchars($material['title']); ?>
                                                </h5>
                                                <p class="text-muted small mb-2">
                                                    <i class="fas fa-chalkboard-teacher me-1"></i>
                                                    <?php echo htmlspecialchars($material['course_title']); ?> - <?php echo $material['batch_code']; ?>
                                                </p>
                                                <div class="d-flex align-items-center mb-2">
                                                    <span class="badge bg-<?php echo $material['is_published'] ? 'success' : 'warning'; ?> status-badge me-2">
                                                        <?php echo $material['is_published'] ? 'Published' : 'Draft'; ?>
                                                    </span>
                                                    <?php if ($material['week_number']): ?>
                                                        <span class="badge bg-info status-badge">
                                                            Week <?php echo $material['week_number']; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if ($material['description']): ?>
                                            <p class="card-text small text-muted mb-3">
                                                <?php echo htmlspecialchars(substr($material['description'], 0, 100)); ?>
                                                <?php if (strlen($material['description']) > 100): ?>...<?php endif; ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($material['topic']): ?>
                                            <div class="mb-3">
                                                <span class="badge bg-light text-dark">
                                                    <i class="fas fa-tag me-1"></i>
                                                    <?php echo htmlspecialchars($material['topic']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="small text-muted">
                                                <i class="fas fa-eye me-1"></i> <?php echo $material['views_count']; ?> views
                                                <i class="fas fa-download ms-3 me-1"></i> <?php echo $material['downloads_count']; ?> downloads
                                            </div>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                                    data-bs-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <a class="dropdown-item" href="view.php?id=<?php echo $material['id']; ?>">
                                                            <i class="fas fa-eye me-2"></i> View Details
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="edit.php?id=<?php echo $material['id']; ?>">
                                                            <i class="fas fa-edit me-2"></i> Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <?php if ($material['file_url'] && $material['file_type'] != 'link'): ?>
                                                            <a class="dropdown-item" href="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>" download>
                                                                <i class="fas fa-download me-2"></i> Download
                                                            </a>
                                                        <?php elseif ($material['file_type'] == 'link'): ?>
                                                            <a class="dropdown-item" href="<?php echo $material['file_url']; ?>" target="_blank">
                                                                <i class="fas fa-external-link-alt me-2"></i> Open Link
                                                            </a>
                                                        <?php endif; ?>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="delete.php?id=<?php echo $material['id']; ?>"
                                                            onclick="return confirm('Are you sure you want to delete this material?');">
                                                            <i class="fas fa-trash me-2"></i> Delete
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-transparent border-top-0">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                            </small>
                                            <small class="text-muted">
                                                <?php if ($material['file_size']): ?>
                                                    <?php echo formatFileSize($material['file_size']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if (!empty($materials)): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item disabled">
                                <a class="page-link" href="#" tabindex="-1">Previous</a>
                            </li>
                            <li class="page-item active"><a class="page-link" href="#">1</a></li>
                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                            <li class="page-item">
                                <a class="page-link" href="#">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleView(viewType) {
            const grid = document.getElementById('materials-grid');
            if (viewType === 'list') {
                grid.classList.remove('row-cols-md-3', 'row-cols-lg-4');
                grid.classList.add('row-cols-1');
            } else {
                grid.classList.remove('row-cols-1');
                grid.classList.add('row-cols-md-3', 'row-cols-lg-4');
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.form.submit();
                    }
                });
            }
        });
    </script>
</body>

</html>