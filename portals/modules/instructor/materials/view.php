<?php
// modules/instructor/materials/view.php

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

// Check if material ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "Material ID is required";
    header('Location: index.php');
    exit();
}

$material_id = intval($_GET['id']);
$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get material details with class information
$sql = "SELECT m.*, cb.id as class_id, cb.batch_code, cb.name as class_name, 
               c.title as course_title, c.course_code,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
               (SELECT COUNT(DISTINCT e.student_id) 
                FROM enrollments e 
                WHERE e.class_id = cb.id AND e.status = 'active') as total_students,
               (SELECT COUNT(*) FROM assignment_submissions s 
                WHERE s.assignment_id IN 
                    (SELECT id FROM assignments WHERE class_id = cb.id)) as total_submissions
        FROM materials m 
        JOIN class_batches cb ON m.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        JOIN users u ON m.instructor_id = u.id 
        WHERE m.id = ? AND m.instructor_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $material_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    $_SESSION['error_message'] = "Material not found or you don't have access to it";
    header('Location: index.php');
    exit();
}

$material = $result->fetch_assoc();
$stmt->close();

// Update view count
$sql = "UPDATE materials SET views_count = views_count + 1 WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $material_id);
$stmt->execute();
$stmt->close();

// Get related materials from the same class
$sql = "SELECT m.id, m.title, m.file_type, m.created_at 
        FROM materials m 
        WHERE m.class_id = ? AND m.id != ? AND m.is_published = 1 
        ORDER BY m.created_at DESC LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $material['class_id'], $material_id);
$stmt->execute();
$result = $stmt->get_result();
$related_materials = [];
if ($result && $result->num_rows > 0) {
    $related_materials = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Log activity
logActivity('view_material_detail', 'Viewed material: ' . $material['title'], $instructor_id);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($material['title']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .material-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }

        .file-icon-large {
            width: 80px;
            height: 80px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-right: 20px;
        }

        .material-content {
            min-height: 300px;
        }

        .stats-card {
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 1rem;
        }

        .embed-container {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            max-width: 100%;
            background: #f8fafc;
            border-radius: 10px;
        }

        .embed-container iframe,
        .embed-container object,
        .embed-container embed {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
        }

        .preview-image {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body>
    <!-- Material Header -->
    <div class="material-header">
        <div class="container">
            <div class="d-flex align-items-center">
                <?php
                $icon_class = '';
                $icon = 'fa-file';
                switch ($material['file_type']) {
                    case 'pdf':
                        $icon_class = 'bg-danger bg-opacity-20';
                        $icon = 'fa-file-pdf';
                        break;
                    case 'video':
                        $icon_class = 'bg-primary bg-opacity-20';
                        $icon = 'fa-file-video';
                        break;
                    case 'document':
                        $icon_class = 'bg-success bg-opacity-20';
                        $icon = 'fa-file-word';
                        break;
                    case 'presentation':
                        $icon_class = 'bg-warning bg-opacity-20';
                        $icon = 'fa-file-powerpoint';
                        break;
                    case 'link':
                        $icon_class = 'bg-info bg-opacity-20';
                        $icon = 'fa-link';
                        break;
                }
                ?>
                <div class="file-icon-large <?php echo $icon_class; ?>">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <div class="flex-grow-1">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb bg-transparent p-0 mb-2">
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="text-white opacity-75">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="index.php" class="text-white opacity-75">Materials</a></li>
                            <li class="breadcrumb-item"><a href="index.php?class_id=<?php echo $material['class_id']; ?>" class="text-white opacity-75"><?php echo htmlspecialchars($material['batch_code']); ?></a></li>
                            <li class="breadcrumb-item active text-white" aria-current="page">View</li>
                        </ol>
                    </nav>
                    <h1 class="display-6 mb-2"><?php echo htmlspecialchars($material['title']); ?></h1>
                    <div class="d-flex align-items-center flex-wrap">
                        <span class="badge bg-white text-dark me-3 mb-2">
                            <i class="fas fa-chalkboard-teacher me-1"></i>
                            <?php echo htmlspecialchars($material['course_title']); ?> - <?php echo $material['batch_code']; ?>
                        </span>
                        <?php if ($material['week_number']): ?>
                            <span class="badge bg-light text-dark me-3 mb-2">
                                <i class="fas fa-calendar me-1"></i>
                                Week <?php echo $material['week_number']; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($material['topic']): ?>
                            <span class="badge bg-light text-dark me-3 mb-2">
                                <i class="fas fa-tag me-1"></i>
                                <?php echo htmlspecialchars($material['topic']); ?>
                            </span>
                        <?php endif; ?>
                        <span class="badge bg-<?php echo $material['is_published'] ? 'success' : 'warning'; ?> mb-2">
                            <?php echo $material['is_published'] ? 'Published' : 'Draft'; ?>
                        </span>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="edit.php?id=<?php echo $material['id']; ?>">
                                <i class="fas fa-edit me-2"></i> Edit Material
                            </a>
                        </li>
                        <li>
                            <?php if ($material['file_type'] != 'link'): ?>
                                <a class="dropdown-item" href="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>" download>
                                    <i class="fas fa-download me-2"></i> Download File
                                </a>
                            <?php else: ?>
                                <a class="dropdown-item" href="<?php echo $material['file_url']; ?>" target="_blank">
                                    <i class="fas fa-external-link-alt me-2"></i> Open Link
                                </a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <a class="dropdown-item" href="share.php?id=<?php echo $material['id']; ?>">
                                <i class="fas fa-share-alt me-2"></i> Share Material
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <a class="dropdown-item text-danger" href="delete.php?id=<?php echo $material['id']; ?>"
                                onclick="return confirm('Are you sure you want to delete this material?');">
                                <i class="fas fa-trash me-2"></i> Delete Material
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-body material-content">
                        <!-- File Preview/Embed -->
                        <?php if ($material['file_type'] == 'video'): ?>
                            <?php if (strpos($material['file_url'], 'youtube.com') !== false || strpos($material['file_url'], 'youtu.be') !== false): ?>
                                <div class="embed-container mb-4">
                                    <?php
                                    // Extract YouTube video ID
                                    $video_id = '';
                                    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $material['file_url'], $matches)) {
                                        $video_id = $matches[1];
                                    }
                                    if ($video_id): ?>
                                        <iframe src="https://www.youtube.com/embed/<?php echo $video_id; ?>"
                                            frameborder="0"
                                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                            allowfullscreen>
                                        </iframe>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center h-100">
                                            <div class="text-center">
                                                <i class="fas fa-file-video fa-3x text-muted mb-3"></i>
                                                <p>Video Link</p>
                                                <a href="<?php echo $material['file_url']; ?>" target="_blank" class="btn btn-primary">
                                                    <i class="fas fa-external-link-alt me-2"></i> Open Video
                                                </a>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($material['file_type'] == 'video' && !strpos($material['file_url'], 'http')): ?>
                                <!-- Local video file -->
                                <div class="embed-container mb-4">
                                    <video controls class="w-100 h-100">
                                        <source src="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>" type="video/mp4">
                                        Your browser does not support the video tag.
                                    </video>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 mb-4 bg-light rounded">
                                    <i class="fas fa-file-video fa-3x text-muted mb-3"></i>
                                    <h5>Video Content</h5>
                                    <p class="text-muted">Open the link to view this video</p>
                                    <a href="<?php echo $material['file_url']; ?>" target="_blank" class="btn btn-primary">
                                        <i class="fas fa-external-link-alt me-2"></i> Open Video Link
                                    </a>
                                </div>
                            <?php endif; ?>

                        <?php elseif ($material['file_type'] == 'pdf'): ?>
                            <div class="text-center py-5 mb-4 bg-light rounded">
                                <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                <h5>PDF Document</h5>
                                <p class="text-muted mb-3">Click below to view or download the PDF</p>
                                <div class="d-flex justify-content-center gap-3">
                                    <a href="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>"
                                        target="_blank" class="btn btn-primary">
                                        <i class="fas fa-eye me-2"></i> View PDF
                                    </a>
                                    <a href="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>"
                                        download class="btn btn-outline-primary">
                                        <i class="fas fa-download me-2"></i> Download PDF
                                    </a>
                                </div>
                                <?php if ($material['file_size']): ?>
                                    <p class="text-muted mt-3 small">
                                        File size: <?php echo formatFileSize($material['file_size']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                        <?php elseif (in_array($material['file_type'], ['document', 'presentation'])): ?>
                            <div class="text-center py-5 mb-4 bg-light rounded">
                                <i class="fas <?php echo $material['file_type'] == 'presentation' ? 'fa-file-powerpoint text-warning' : 'fa-file-word text-success'; ?> fa-3x mb-3"></i>
                                <h5><?php echo ucfirst($material['file_type']); ?> Document</h5>
                                <p class="text-muted mb-3">Click below to download this document</p>
                                <a href="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>"
                                    download class="btn btn-primary">
                                    <i class="fas fa-download me-2"></i> Download File
                                </a>
                                <?php if ($material['file_size']): ?>
                                    <p class="text-muted mt-3 small">
                                        File size: <?php echo formatFileSize($material['file_size']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                        <?php elseif ($material['file_type'] == 'link'): ?>
                            <div class="text-center py-5 mb-4 bg-light rounded">
                                <i class="fas fa-link fa-3x text-info mb-3"></i>
                                <h5>External Resource</h5>
                                <p class="text-muted mb-3">Click below to open this link</p>
                                <a href="<?php echo $material['file_url']; ?>" target="_blank" class="btn btn-primary">
                                    <i class="fas fa-external-link-alt me-2"></i> Open Link
                                </a>
                                <p class="text-muted mt-3 small">
                                    URL: <?php echo htmlspecialchars($material['file_url']); ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <!-- Description -->
                        <?php if ($material['description']): ?>
                            <div class="mb-4">
                                <h5 class="mb-3">Description</h5>
                                <div class="bg-light p-4 rounded">
                                    <?php echo nl2br(htmlspecialchars($material['description'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Additional Information -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Material Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td><strong>Type:</strong></td>
                                                <td><?php echo ucfirst($material['file_type']); ?></td>
                                            </tr>
                                            <?php if ($material['file_type'] != 'link' && $material['file_size']): ?>
                                                <tr>
                                                    <td><strong>File Size:</strong></td>
                                                    <td><?php echo formatFileSize($material['file_size']); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td><strong>Uploaded:</strong></td>
                                                <td><?php echo date('F j, Y, g:i a', strtotime($material['created_at'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Last Updated:</strong></td>
                                                <td><?php echo date('F j, Y, g:i a', strtotime($material['updated_at'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Views:</strong></td>
                                                <td><?php echo $material['views_count']; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Downloads:</strong></td>
                                                <td><?php echo $material['downloads_count']; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Class Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td><strong>Class:</strong></td>
                                                <td><?php echo htmlspecialchars($material['batch_code']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Course:</strong></td>
                                                <td><?php echo htmlspecialchars($material['course_title']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Students:</strong></td>
                                                <td><?php echo $material['total_students']; ?> enrolled</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Submissions:</strong></td>
                                                <td><?php echo $material['total_submissions']; ?> total</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Instructor:</strong></td>
                                                <td><?php echo htmlspecialchars($material['instructor_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Status:</strong></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $material['is_published'] ? 'success' : 'warning'; ?>">
                                                        <?php echo $material['is_published'] ? 'Published' : 'Draft'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between mb-4">
                    <a href="index.php?class_id=<?php echo $material['class_id']; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Materials
                    </a>
                    <div class="btn-group">
                        <a href="edit.php?id=<?php echo $material['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i> Edit Material
                        </a>
                        <?php if ($material['file_type'] != 'link'): ?>
                            <a href="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>"
                                download class="btn btn-success">
                                <i class="fas fa-download me-2"></i> Download
                            </a>
                        <?php else: ?>
                            <a href="<?php echo $material['file_url']; ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-external-link-alt me-2"></i> Open Link
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Quick Stats -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Material Stats</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="stats-card bg-primary bg-opacity-10">
                                    <div class="text-primary fw-bold fs-4"><?php echo $material['views_count']; ?></div>
                                    <div class="text-muted small">Views</div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="stats-card bg-success bg-opacity-10">
                                    <div class="text-success fw-bold fs-4"><?php echo $material['downloads_count']; ?></div>
                                    <div class="text-muted small">Downloads</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stats-card bg-info bg-opacity-10">
                                    <div class="text-info fw-bold fs-4"><?php echo $material['total_students']; ?></div>
                                    <div class="text-muted small">Students</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stats-card bg-warning bg-opacity-10">
                                    <div class="text-warning fw-bold fs-4"><?php echo $material['total_submissions']; ?></div>
                                    <div class="text-muted small">Submissions</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Related Materials -->
                <?php if (!empty($related_materials)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-link me-2"></i>Related Materials</h6>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <?php foreach ($related_materials as $related): ?>
                                    <a href="view.php?id=<?php echo $related['id']; ?>" class="list-group-item list-group-item-action">
                                        <div class="d-flex align-items-center">
                                            <?php
                                            $icon = 'fa-file';
                                            switch ($related['file_type']) {
                                                case 'pdf':
                                                    $icon = 'fa-file-pdf text-danger';
                                                    break;
                                                case 'video':
                                                    $icon = 'fa-file-video text-primary';
                                                    break;
                                                case 'document':
                                                    $icon = 'fa-file-word text-success';
                                                    break;
                                                case 'presentation':
                                                    $icon = 'fa-file-powerpoint text-warning';
                                                    break;
                                                case 'link':
                                                    $icon = 'fa-link text-info';
                                                    break;
                                            }
                                            ?>
                                            <i class="fas <?php echo $icon; ?> me-3"></i>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold"><?php echo htmlspecialchars($related['title']); ?></div>
                                                <small class="text-muted">
                                                    <?php echo date('M d, Y', strtotime($related['created_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Share Options -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-share-alt me-2"></i>Share Material</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Share with students via:</label>
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-primary btn-sm" onclick="copyToClipboard()">
                                    <i class="fas fa-copy me-2"></i> Copy Link
                                </button>
                                <a href="mailto:?subject=Teaching Material: <?php echo urlencode($material['title']); ?>&body=Check out this material: <?php echo urlencode(BASE_URL . 'modules/instructor/materials/view.php?id=' . $material['id']); ?>"
                                    class="btn btn-outline-danger btn-sm">
                                    <i class="fas fa-envelope me-2"></i> Email
                                </a>
                            </div>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" id="share-url" class="form-control"
                                value="<?php echo BASE_URL . 'modules/instructor/materials/view.php?id=' . $material['id']; ?>"
                                readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard()">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <small class="text-muted mt-2 d-block">
                            Note: Students can only access published materials.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyToClipboard() {
            const urlInput = document.getElementById('share-url');
            urlInput.select();
            urlInput.setSelectionRange(0, 99999); // For mobile devices

            try {
                document.execCommand('copy');
                alert('Link copied to clipboard!');
            } catch (err) {
                console.error('Failed to copy: ', err);
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Auto-close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.matches('.dropdown-toggle')) {
                const dropdowns = document.querySelectorAll('.dropdown-menu');
                dropdowns.forEach(dropdown => {
                    if (dropdown.classList.contains('show')) {
                        dropdown.classList.remove('show');
                    }
                });
            }
        });
    </script>
</body>

</html>