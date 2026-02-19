<?php
// modules/instructor/materials/delete.php

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

// Get material details
$sql = "SELECT m.*, cb.batch_code, c.title as course_title 
        FROM materials m 
        JOIN class_batches cb ON m.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
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

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['confirm_delete'])) {
        // Delete the file from server if it exists and is not a link
        if ($material['file_type'] != 'link' && $material['file_url'] && file_exists(BASE_PATH . 'public/uploads/materials/' . basename($material['file_url']))) {
            unlink(BASE_PATH . 'public/uploads/materials/' . basename($material['file_url']));
        }

        // Delete from database
        $sql = "DELETE FROM materials WHERE id = ? AND instructor_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $material_id, $instructor_id);

        if ($stmt->execute()) {
            // Log activity
            logActivity('delete_material', 'Deleted teaching material: ' . $material['title'], $instructor_id);

            $_SESSION['success_message'] = "Material deleted successfully!";
            header('Location: index.php?class_id=' . $material['class_id']);
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to delete material: " . $stmt->error;
            header('Location: view.php?id=' . $material_id);
            exit();
        }
        $stmt->close();
    } else {
        // Cancelled deletion
        header('Location: view.php?id=' . $material_id);
        exit();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Material - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .delete-container {
            max-width: 600px;
            margin: 3rem auto;
        }

        .delete-card {
            border: 2px solid #fecaca;
            border-radius: 15px;
            overflow: hidden;
        }

        .delete-header {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .material-info {
            background: #fef2f2;
            padding: 1.5rem;
            border-radius: 10px;
            margin: 1.5rem 0;
        }

        .file-icon-large {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 1rem;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            text-align: center;
            margin: 1rem 0;
        }

        .stat-item {
            padding: 0.5rem;
            background: white;
            border-radius: 8px;
        }
    </style>
</head>

<body>

    <div class="delete-container">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php">Teaching Materials</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?php echo $material['id']; ?>"><?php echo htmlspecialchars(substr($material['title'], 0, 30)); ?>...</a></li>
                <li class="breadcrumb-item active">Delete</li>
            </ol>
        </nav>

        <!-- Delete Card -->
        <div class="card delete-card">
            <div class="delete-header">
                <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                <h2 class="h4 mb-2">Delete Material</h2>
                <p class="mb-0 opacity-75">This action cannot be undone</p>
            </div>

            <div class="card-body p-4">
                <!-- Material Info -->
                <div class="material-info">
                    <div class="text-center mb-4">
                        <?php
                        $icon_class = '';
                        $icon = 'fa-file';
                        switch ($material['file_type']) {
                            case 'pdf':
                                $icon_class = 'pdf-icon';
                                $icon = 'fa-file-pdf';
                                break;
                            case 'video':
                                $icon_class = 'video-icon';
                                $icon = 'fa-file-video';
                                break;
                            case 'document':
                                $icon_class = 'doc-icon';
                                $icon = 'fa-file-word';
                                break;
                            case 'presentation':
                                $icon_class = 'presentation-icon';
                                $icon = 'fa-file-powerpoint';
                                break;
                            case 'link':
                                $icon_class = 'link-icon';
                                $icon = 'fa-link';
                                break;
                        }
                        ?>
                        <div class="file-icon-large <?php echo $icon_class; ?>">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <h5 class="fw-bold"><?php echo htmlspecialchars($material['title']); ?></h5>
                        <p class="text-muted mb-0">
                            <?php echo htmlspecialchars($material['course_title']); ?> - <?php echo $material['batch_code']; ?>
                        </p>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="text-primary fw-bold"><?php echo $material['views_count']; ?></div>
                            <div class="text-muted small">Views</div>
                        </div>
                        <div class="stat-item">
                            <div class="text-success fw-bold"><?php echo $material['downloads_count']; ?></div>
                            <div class="text-muted small">Downloads</div>
                        </div>
                        <div class="stat-item">
                            <div class="text-danger fw-bold">
                                <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                            </div>
                            <div class="text-muted small">Created</div>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> Deleting this material will remove it from the system permanently.
                        <?php if ($material['file_type'] != 'link'): ?>
                            The file will also be deleted from the server.
                        <?php endif; ?>
                    </div>
                </div>

                <!-- File Details -->
                <div class="mb-4">
                    <h6 class="fw-bold mb-3">Material Details:</h6>
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
                        <?php if ($material['week_number']): ?>
                            <tr>
                                <td><strong>Week Number:</strong></td>
                                <td>Week <?php echo $material['week_number']; ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($material['topic']): ?>
                            <tr>
                                <td><strong>Topic:</strong></td>
                                <td><?php echo htmlspecialchars($material['topic']); ?></td>
                            </tr>
                        <?php endif; ?>
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

                <!-- Description -->
                <?php if ($material['description']): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3">Description:</h6>
                        <div class="bg-light p-3 rounded">
                            <?php echo nl2br(htmlspecialchars($material['description'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Confirmation Form -->
                <form method="POST">
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="confirm_delete" id="confirm_delete" required>
                        <label class="form-check-label fw-bold" for="confirm_delete">
                            I understand that this action cannot be undone and I want to delete this material permanently.
                        </label>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-danger btn-lg">
                            <i class="fas fa-trash me-2"></i> Delete Material Permanently
                        </button>
                        <a href="view.php?id=<?php echo $material['id']; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i> Cancel and Go Back
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Disable submit button until checkbox is checked
        document.getElementById('confirm_delete').addEventListener('change', function() {
            document.querySelector('button[type="submit"]').disabled = !this.checked;
        });

        // Initialize button state
        document.querySelector('button[type="submit"]').disabled = true;

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>

</html>