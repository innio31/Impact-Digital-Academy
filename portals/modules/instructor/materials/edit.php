<?php
// modules/instructor/materials/edit.php

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

// Get instructor's classes for dropdown
$sql = "SELECT cb.id, cb.batch_code, c.title as course_name, c.course_code
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.instructor_id = ? AND cb.status IN ('ongoing', 'scheduled')
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $errors = [];
    $success = false;

    // Validate required fields
    if (empty($_POST['title'])) {
        $errors[] = "Title is required";
    }

    if (empty($_POST['class_id'])) {
        $errors[] = "Please select a class";
    } else {
        // Verify instructor has access to this class
        $valid_class = false;
        foreach ($instructor_classes as $class) {
            if ($class['id'] == $_POST['class_id']) {
                $valid_class = true;
                break;
            }
        }
        if (!$valid_class) {
            $errors[] = "You don't have access to the selected class";
        }
    }

    // Handle file upload if provided
    $file_url = $material['file_url'];
    $file_type = $material['file_type'];
    $file_size = $material['file_size'];

    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        // New file uploaded
        $allowed_types = [
            'pdf' => 'application/pdf',
            'document' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'presentation' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'video' => ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/x-ms-wmv'],
            'other' => []
        ];

        $file_name = $_FILES['file']['name'];
        $file_tmp = $_FILES['file']['tmp_name'];
        $file_size = $_FILES['file']['size'];

        // Get file extension
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Validate file size (max 50MB)
        $max_size = 50 * 1024 * 1024; // 50MB in bytes
        if ($file_size > $max_size) {
            $errors[] = "File size must be less than 50MB";
        }

        // Determine file type based on extension
        if (in_array($file_ext, ['doc', 'docx', 'txt', 'rtf'])) {
            $file_type = 'document';
        } elseif ($file_ext == 'pdf') {
            $file_type = 'pdf';
        } elseif (in_array($file_ext, ['ppt', 'pptx'])) {
            $file_type = 'presentation';
        } elseif (in_array($file_ext, ['mp4', 'avi', 'mov', 'wmv', 'mpeg'])) {
            $file_type = 'video';
        } else {
            $file_type = 'other';
        }

        if (empty($errors)) {
            // Generate unique filename
            $unique_filename = uniqid() . '_' . time() . '.' . $file_ext;
            $upload_dir = BASE_PATH . 'public/uploads/materials/';

            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Delete old file if it exists and is not a link
            if ($material['file_type'] != 'link' && $material['file_url'] && file_exists(BASE_PATH . 'public/uploads/materials/' . basename($material['file_url']))) {
                unlink(BASE_PATH . 'public/uploads/materials/' . basename($material['file_url']));
            }

            $destination = $upload_dir . $unique_filename;

            if (move_uploaded_file($file_tmp, $destination)) {
                $file_url = $unique_filename;
            } else {
                $errors[] = "Failed to upload file. Please try again.";
            }
        }
    } elseif ($_POST['file_type'] == 'link' && !empty($_POST['file_url'])) {
        // Update link
        if (!filter_var($_POST['file_url'], FILTER_VALIDATE_URL)) {
            $errors[] = "Please enter a valid URL";
        } else {
            // Delete old file if it exists and is not a link
            if ($material['file_type'] != 'link' && $material['file_url'] && file_exists(BASE_PATH . 'public/uploads/materials/' . basename($material['file_url']))) {
                unlink(BASE_PATH . 'public/uploads/materials/' . basename($material['file_url']));
            }

            $file_url = $_POST['file_url'];
            $file_type = 'link';
            $file_size = 0;
        }
    }

    // If no errors, update database
    if (empty($errors)) {
        $sql = "UPDATE materials SET 
                title = ?,
                description = ?,
                class_id = ?,
                file_url = ?,
                file_type = ?,
                file_size = ?,
                week_number = ?,
                topic = ?,
                is_published = ?,
                publish_date = ?
                WHERE id = ? AND instructor_id = ?";

        $stmt = $conn->prepare($sql);

        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $publish_date = $is_published ? ($material['publish_date'] ? $material['publish_date'] : date('Y-m-d H:i:s')) : null;
        $week_number = !empty($_POST['week_number']) ? intval($_POST['week_number']) : null;
        $topic = !empty($_POST['topic']) ? trim($_POST['topic']) : null;

        $stmt->bind_param(
            "ssissiissiii",
            $_POST['title'],
            $_POST['description'],
            $_POST['class_id'],
            $file_url,
            $file_type,
            $file_size,
            $week_number,
            $topic,
            $is_published,
            $publish_date,
            $material_id,
            $instructor_id
        );

        if ($stmt->execute()) {
            $success = true;

            // Log activity
            logActivity('edit_material', 'Updated teaching material: ' . $_POST['title'], $instructor_id);

            // Redirect to view page
            $_SESSION['success_message'] = "Material updated successfully!";
            header('Location: view.php?id=' . $material_id);
            exit();
        } else {
            $errors[] = "Failed to update material: " . $stmt->error;
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Material - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .current-file {
            background: #f8fafc;
            border: 2px dashed #cbd5e0;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .file-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .file-icon-large {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
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

        .type-selector .card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .type-selector .card:hover {
            transform: translateY(-5px);
        }

        .type-selector .card.active {
            border-color: #3b82f6;
            background-color: rgba(59, 130, 246, 0.05);
        }
    </style>
</head>

<body>

    <div class="container mt-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php">Teaching Materials</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?php echo $material['id']; ?>"><?php echo htmlspecialchars(substr($material['title'], 0, 30)); ?>...</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2">
                    <i class="fas fa-edit text-primary me-2"></i>
                    Edit Material
                </h1>
                <p class="text-muted mb-0">Update your teaching material details</p>
            </div>
            <div>
                <a href="view.php?id=<?php echo $material['id']; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to View
                </a>
            </div>
        </div>

        <!-- Error/Success Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h5><i class="fas fa-exclamation-triangle me-2"></i> Please fix the following errors:</h5>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Current File Info -->
        <div class="current-file">
            <h5 class="mb-3">Current File</h5>
            <div class="file-info">
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
                <div>
                    <div class="fw-bold">
                        <?php if ($material['file_type'] == 'link'): ?>
                            External Link
                        <?php else: ?>
                            <?php echo basename($material['file_url']); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($material['file_type'] != 'link' && $material['file_size']): ?>
                        <div class="text-muted small">Size: <?php echo formatFileSize($material['file_size']); ?></div>
                    <?php endif; ?>
                    <div class="text-muted small">Type: <?php echo ucfirst($material['file_type']); ?></div>
                </div>
            </div>
            <?php if ($material['file_type'] == 'link'): ?>
                <div class="mb-3">
                    <strong>URL:</strong>
                    <a href="<?php echo $material['file_url']; ?>" target="_blank" class="text-decoration-none">
                        <?php echo htmlspecialchars($material['file_url']); ?>
                    </a>
                </div>
            <?php else: ?>
                <div>
                    <a href="<?php echo BASE_URL . 'public/uploads/materials/' . basename($material['file_url']); ?>"
                        class="btn btn-sm btn-outline-primary" download>
                        <i class="fas fa-download me-1"></i> Download Current File
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Edit Form -->
        <div class="card">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="edit-form">
                    <div class="row">
                        <div class="col-md-8">
                            <!-- Material Type Selection -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Change Material Type (Optional)</label>
                                <div class="row type-selector">
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo $material['file_type'] == 'document' ? 'active' : ''; ?>"
                                            data-type="document">
                                            <div class="card-body">
                                                <i class="fas fa-file-word fa-2x text-success mb-2"></i>
                                                <h6 class="card-title">Document</h6>
                                                <p class="card-text small text-muted">DOC, DOCX, TXT, RTF</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo $material['file_type'] == 'pdf' ? 'active' : ''; ?>"
                                            data-type="pdf">
                                            <div class="card-body">
                                                <i class="fas fa-file-pdf fa-2x text-danger mb-2"></i>
                                                <h6 class="card-title">PDF</h6>
                                                <p class="card-text small text-muted">PDF Files</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo $material['file_type'] == 'presentation' ? 'active' : ''; ?>"
                                            data-type="presentation">
                                            <div class="card-body">
                                                <i class="fas fa-file-powerpoint fa-2x text-warning mb-2"></i>
                                                <h6 class="card-title">Presentation</h6>
                                                <p class="card-text small text-muted">PPT, PPTX</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo $material['file_type'] == 'video' ? 'active' : ''; ?>"
                                            data-type="video">
                                            <div class="card-body">
                                                <i class="fas fa-file-video fa-2x text-primary mb-2"></i>
                                                <h6 class="card-title">Video</h6>
                                                <p class="card-text small text-muted">MP4, AVI, MOV, WMV</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="card h-100 text-center type-card <?php echo $material['file_type'] == 'link' ? 'active' : ''; ?>"
                                            data-type="link">
                                            <div class="card-body">
                                                <i class="fas fa-link fa-2x text-info mb-2"></i>
                                                <h6 class="card-title">External Link</h6>
                                                <p class="card-text small text-muted">YouTube, Website, etc.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="file_type" id="file_type" value="<?php echo $material['file_type']; ?>">
                            </div>

                            <!-- File Upload Section -->
                            <div id="file-upload-section" class="mb-4" style="<?php echo $material['file_type'] == 'link' ? 'display: none;' : ''; ?>">
                                <label class="form-label fw-bold">Replace File (Optional)</label>
                                <div class="upload-section" id="drop-area">
                                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                    <h6>Drag & Drop new file here</h6>
                                    <p class="text-muted small mb-2">or click to browse</p>
                                    <input type="file" name="file" id="file-input" class="d-none">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('file-input').click();">
                                        <i class="fas fa-folder-open me-1"></i> Browse Files
                                    </button>
                                    <div class="mt-2">
                                        <small class="text-muted">Maximum file size: 50MB</small>
                                    </div>
                                </div>
                                <div id="file-preview" class="mt-3 text-center"></div>
                            </div>

                            <!-- Link Input Section -->
                            <div id="link-input-section" class="mb-4" style="<?php echo $material['file_type'] != 'link' ? 'display: none;' : ''; ?>">
                                <label class="form-label fw-bold">Update URL</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-link"></i>
                                    </span>
                                    <input type="url" name="file_url" id="file_url" class="form-control"
                                        placeholder="https://example.com/video"
                                        value="<?php echo $material['file_type'] == 'link' ? htmlspecialchars($material['file_url']) : ''; ?>">
                                </div>
                                <small class="text-muted">Enter the full URL including https://</small>
                            </div>

                            <!-- Basic Information -->
                            <div class="row mb-4">
                                <div class="col-md-8">
                                    <label for="title" class="form-label fw-bold">Material Title *</label>
                                    <input type="text" name="title" id="title" class="form-control"
                                        placeholder="e.g., Introduction to Python Programming"
                                        value="<?php echo htmlspecialchars($material['title']); ?>"
                                        required>
                                </div>
                                <div class="col-md-4">
                                    <label for="class_id" class="form-label fw-bold">Class *</label>
                                    <select name="class_id" id="class_id" class="form-select" required>
                                        <option value="">Select a class</option>
                                        <?php foreach ($instructor_classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>"
                                                <?php echo $material['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['course_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="description" class="form-label fw-bold">Description</label>
                                <textarea name="description" id="description" class="form-control"
                                    rows="4" placeholder="Brief description of this material..."><?php echo htmlspecialchars($material['description']); ?></textarea>
                            </div>

                            <!-- Metadata -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="week_number" class="form-label fw-bold">Week Number (Optional)</label>
                                    <select name="week_number" id="week_number" class="form-select">
                                        <option value="">Not specific to a week</option>
                                        <?php for ($i = 1; $i <= 20; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $material['week_number'] == $i ? 'selected' : ''; ?>>
                                                Week <?php echo $i; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="topic" class="form-label fw-bold">Topic/Tag (Optional)</label>
                                    <input type="text" name="topic" id="topic" class="form-control"
                                        placeholder="e.g., Python, Variables, Functions"
                                        value="<?php echo htmlspecialchars($material['topic']); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar Settings -->
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-cog me-2"></i>Publishing Settings</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="is_published"
                                                id="is_published" value="1" <?php echo $material['is_published'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold" for="is_published">
                                                Publish Material
                                            </label>
                                        </div>
                                        <small class="text-muted">If unchecked, material will be saved as draft</small>
                                    </div>

                                    <hr>

                                    <div class="mb-3">
                                        <h6 class="fw-bold mb-2">Current Stats</h6>
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <div class="text-primary fw-bold"><?php echo $material['views_count']; ?></div>
                                                <div class="text-muted small">Views</div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-success fw-bold"><?php echo $material['downloads_count']; ?></div>
                                                <div class="text-muted small">Downloads</div>
                                            </div>
                                        </div>
                                    </div>

                                    <hr>

                                    <div class="mb-3">
                                        <h6 class="fw-bold mb-2">Last Updated</h6>
                                        <p class="small text-muted mb-0">
                                            <?php echo date('F j, Y, g:i a', strtotime($material['updated_at'])); ?>
                                        </p>
                                    </div>

                                    <hr>

                                    <div class="d-grid gap-2">
                                        <button type="submit" name="update" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i> Update Material
                                        </button>
                                        <a href="view.php?id=<?php echo $material['id']; ?>" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-2"></i> Cancel
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Material type selection
        document.querySelectorAll('.type-card').forEach(card => {
            card.addEventListener('click', function() {
                const type = this.dataset.type;

                // Update active card
                document.querySelectorAll('.type-card').forEach(c => c.classList.remove('active'));
                this.classList.add('active');

                // Update hidden input
                document.getElementById('file_type').value = type;

                // Show/hide appropriate sections
                if (type === 'link') {
                    document.getElementById('file-upload-section').style.display = 'none';
                    document.getElementById('link-input-section').style.display = 'block';
                } else {
                    document.getElementById('file-upload-section').style.display = 'block';
                    document.getElementById('link-input-section').style.display = 'none';
                }
            });
        });

        // File upload handling
        const dropArea = document.getElementById('drop-area');
        const fileInput = document.getElementById('file-input');
        const filePreview = document.getElementById('file-preview');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });

        function highlight() {
            dropArea.classList.add('drag-over');
        }

        function unhighlight() {
            dropArea.classList.remove('drag-over');
        }

        dropArea.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }

        fileInput.addEventListener('change', function() {
            handleFiles(this.files);
        });

        function handleFiles(files) {
            if (files.length === 0) return;

            const file = files[0];

            // Validate file size (50MB)
            if (file.size > 50 * 1024 * 1024) {
                alert('File size must be less than 50MB');
                fileInput.value = '';
                return;
            }

            // Display file preview
            displayFilePreview(file);
        }

        function displayFilePreview(file) {
            filePreview.innerHTML = '';

            const div = document.createElement('div');
            div.className = 'file-preview';

            const icon = document.createElement('i');
            icon.className = 'fas fa-file fa-2x text-primary mb-2';

            const fileName = document.createElement('div');
            fileName.className = 'fw-bold';
            fileName.textContent = file.name;

            const fileSize = document.createElement('div');
            fileSize.className = 'text-muted small';
            fileSize.textContent = formatFileSize(file.size);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-outline-danger mt-2';
            removeBtn.innerHTML = '<i class="fas fa-times me-1"></i> Remove';
            removeBtn.onclick = () => {
                fileInput.value = '';
                filePreview.innerHTML = '';
            };

            div.appendChild(icon);
            div.appendChild(fileName);
            div.appendChild(fileSize);
            div.appendChild(removeBtn);
            filePreview.appendChild(div);
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Form validation
        document.getElementById('edit-form').addEventListener('submit', function(e) {
            const fileType = document.getElementById('file_type').value;

            if (fileType === 'link') {
                const urlInput = document.getElementById('file_url');
                if (!urlInput.value.trim()) {
                    alert('Please enter a URL for the link');
                    e.preventDefault();
                    return;
                }
            }

            // Show loading state
            const submitBtn = this.querySelector('button[name="update"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Updating...';
                submitBtn.disabled = true;
            }
        });
    </script>
</body>

</html>