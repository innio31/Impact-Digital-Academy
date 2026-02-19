<?php
// modules/instructor/materials/upload.php

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
$errors = [];
$success = false;
$uploaded_file = null;
$file_type = null;

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

// If class_id is provided, verify instructor has access
if ($class_id) {
    $valid_class = false;
    foreach ($instructor_classes as $class) {
        if ($class['id'] == $class_id) {
            $valid_class = true;
            break;
        }
    }
    if (!$valid_class) {
        $class_id = null;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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

    $file_type = $_POST['file_type'] ?? 'document';

    // Handle file upload if not a link
    if ($file_type != 'link') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
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
            $file_error = $_FILES['file']['error'];

            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            // Validate file size (max 50MB)
            $max_size = 50 * 1024 * 1024; // 50MB in bytes
            if ($file_size > $max_size) {
                $errors[] = "File size must be less than 50MB";
            }

            // Validate file type based on selected file_type
            if ($file_type == 'pdf' && $file_ext != 'pdf') {
                $errors[] = "Please upload a PDF file";
            } elseif ($file_type == 'document' && !in_array($file_ext, ['doc', 'docx', 'txt', 'rtf'])) {
                $errors[] = "Please upload a document file (DOC, DOCX, TXT, RTF)";
            } elseif ($file_type == 'presentation' && !in_array($file_ext, ['ppt', 'pptx'])) {
                $errors[] = "Please upload a presentation file (PPT, PPTX)";
            } elseif ($file_type == 'video' && !in_array($file_ext, ['mp4', 'avi', 'mov', 'wmv', 'mpeg'])) {
                $errors[] = "Please upload a video file (MP4, AVI, MOV, WMV, MPEG)";
            }

            if (empty($errors)) {
                // Generate unique filename
                $unique_filename = uniqid() . '_' . time() . '.' . $file_ext;
                $upload_dir = BASE_PATH . 'public/uploads/materials/';

                // Create directory if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $destination = $upload_dir . $unique_filename;

                if (move_uploaded_file($file_tmp, $destination)) {
                    $uploaded_file = $unique_filename;
                } else {
                    $errors[] = "Failed to upload file. Please try again.";
                }
            }
        } else {
            if ($_FILES['file']['error'] != 4) { // 4 = no file uploaded
                $errors[] = "File upload error: " . $_FILES['file']['error'];
            } else {
                $errors[] = "Please select a file to upload";
            }
        }
    } else {
        // For links, validate URL
        if (empty($_POST['file_url'])) {
            $errors[] = "Please enter a URL for the link";
        } elseif (!filter_var($_POST['file_url'], FILTER_VALIDATE_URL)) {
            $errors[] = "Please enter a valid URL";
        } else {
            $uploaded_file = $_POST['file_url'];
        }
    }

    // If no errors, save to database
    if (empty($errors)) {
        $sql = "INSERT INTO materials (class_id, instructor_id, title, description, file_url, 
                file_type, file_size, week_number, topic, is_published, publish_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $publish_date = $is_published ? date('Y-m-d H:i:s') : null;
        $file_size = $file_type != 'link' ? $_FILES['file']['size'] : 0;
        $week_number = !empty($_POST['week_number']) ? intval($_POST['week_number']) : null;
        $topic = !empty($_POST['topic']) ? trim($_POST['topic']) : null;

        $stmt->bind_param(
            "iissssiissi",
            $_POST['class_id'],
            $instructor_id,
            $_POST['title'],
            $_POST['description'],
            $uploaded_file,
            $file_type,
            $file_size,
            $week_number,
            $topic,
            $is_published,
            $publish_date
        );

        if ($stmt->execute()) {
            $material_id = $stmt->insert_id;
            $success = true;

            // Log activity
            logActivity('upload_material', 'Uploaded teaching material: ' . $_POST['title'], $instructor_id);

            // Redirect to materials page
            $_SESSION['success_message'] = "Material uploaded successfully!";
            header('Location: index.php?class_id=' . $_POST['class_id']);
            exit();
        } else {
            $errors[] = "Failed to save material to database: " . $stmt->error;
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
    <title>Upload Material - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .upload-section {
            border: 2px dashed #cbd5e0;
            border-radius: 10px;
            padding: 3rem;
            text-align: center;
            background: #f8fafc;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .upload-section:hover {
            border-color: #3b82f6;
            background: #f1f5f9;
        }

        .upload-section.drag-over {
            border-color: #10b981;
            background: #d1fae5;
        }

        .file-preview {
            max-width: 200px;
            margin: 0 auto;
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
                <li class="breadcrumb-item active">Upload Material</li>
            </ol>
        </nav>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2">
                    <i class="fas fa-upload text-primary me-2"></i>
                    Upload Teaching Material
                </h1>
                <p class="text-muted mb-0">Share learning materials with your students</p>
            </div>
            <div>
                <a href="index.php<?php echo $class_id ? '?class_id=' . $class_id : ''; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Materials
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

        <?php if ($success): ?>
            <div class="alert alert-success">
                <h5><i class="fas fa-check-circle me-2"></i> Material uploaded successfully!</h5>
                <p class="mb-0">Your material has been uploaded and saved.</p>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="card">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="upload-form">
                    <div class="row">
                        <div class="col-md-8">
                            <!-- Material Type Selection -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Material Type</label>
                                <div class="row type-selector">
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo ($file_type ?? 'document') == 'document' ? 'active' : ''; ?>"
                                            data-type="document">
                                            <div class="card-body">
                                                <i class="fas fa-file-word fa-3x text-success mb-3"></i>
                                                <h6 class="card-title">Document</h6>
                                                <p class="card-text small text-muted">DOC, DOCX, TXT, RTF</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo ($file_type ?? 'document') == 'pdf' ? 'active' : ''; ?>"
                                            data-type="pdf">
                                            <div class="card-body">
                                                <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                                <h6 class="card-title">PDF</h6>
                                                <p class="card-text small text-muted">PDF Files</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo ($file_type ?? 'document') == 'presentation' ? 'active' : ''; ?>"
                                            data-type="presentation">
                                            <div class="card-body">
                                                <i class="fas fa-file-powerpoint fa-3x text-warning mb-3"></i>
                                                <h6 class="card-title">Presentation</h6>
                                                <p class="card-text small text-muted">PPT, PPTX</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="card h-100 text-center type-card <?php echo ($file_type ?? 'document') == 'video' ? 'active' : ''; ?>"
                                            data-type="video">
                                            <div class="card-body">
                                                <i class="fas fa-file-video fa-3x text-primary mb-3"></i>
                                                <h6 class="card-title">Video</h6>
                                                <p class="card-text small text-muted">MP4, AVI, MOV, WMV</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="card h-100 text-center type-card <?php echo ($file_type ?? 'document') == 'link' ? 'active' : ''; ?>"
                                            data-type="link">
                                            <div class="card-body">
                                                <i class="fas fa-link fa-3x text-info mb-3"></i>
                                                <h6 class="card-title">External Link</h6>
                                                <p class="card-text small text-muted">YouTube, Website, etc.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="file_type" id="file_type" value="<?php echo $file_type ?? 'document'; ?>">
                            </div>

                            <!-- File Upload Section -->
                            <div id="file-upload-section" class="mb-4" style="<?php echo ($file_type ?? 'document') == 'link' ? 'display: none;' : ''; ?>">
                                <label class="form-label fw-bold">Upload File</label>
                                <div class="upload-section" id="drop-area">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <h5>Drag & Drop your file here</h5>
                                    <p class="text-muted mb-3">or click to browse</p>
                                    <input type="file" name="file" id="file-input" class="d-none" accept=".pdf,.doc,.docx,.txt,.rtf,.ppt,.pptx,.mp4,.avi,.mov,.wmv,.mpeg">
                                    <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('file-input').click();">
                                        <i class="fas fa-folder-open me-2"></i> Browse Files
                                    </button>
                                    <div class="mt-3">
                                        <small class="text-muted">Maximum file size: 50MB</small>
                                    </div>
                                </div>
                                <div id="file-preview" class="mt-3 text-center"></div>
                            </div>

                            <!-- Link Input Section -->
                            <div id="link-input-section" class="mb-4" style="<?php echo ($file_type ?? 'document') != 'link' ? 'display: none;' : ''; ?>">
                                <label class="form-label fw-bold">Enter URL</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-link"></i>
                                    </span>
                                    <input type="url" name="file_url" id="file_url" class="form-control"
                                        placeholder="https://example.com/video"
                                        value="<?php echo isset($_POST['file_url']) ? htmlspecialchars($_POST['file_url']) : ''; ?>">
                                </div>
                                <small class="text-muted">Enter the full URL including https://</small>
                            </div>

                            <!-- Basic Information -->
                            <div class="row mb-4">
                                <div class="col-md-8">
                                    <label for="title" class="form-label fw-bold">Material Title *</label>
                                    <input type="text" name="title" id="title" class="form-control"
                                        placeholder="e.g., Introduction to Python Programming"
                                        value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                                        required>
                                </div>
                                <div class="col-md-4">
                                    <label for="class_id" class="form-label fw-bold">Class *</label>
                                    <select name="class_id" id="class_id" class="form-select" required>
                                        <option value="">Select a class</option>
                                        <?php foreach ($instructor_classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>"
                                                <?php echo (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) || $class_id == $class['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['course_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="description" class="form-label fw-bold">Description</label>
                                <textarea name="description" id="description" class="form-control"
                                    rows="3" placeholder="Brief description of this material..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                            </div>

                            <!-- Metadata -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="week_number" class="form-label fw-bold">Week Number (Optional)</label>
                                    <select name="week_number" id="week_number" class="form-select">
                                        <option value="">Not specific to a week</option>
                                        <?php for ($i = 1; $i <= 20; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo (isset($_POST['week_number']) && $_POST['week_number'] == $i) ? 'selected' : ''; ?>>
                                                Week <?php echo $i; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="topic" class="form-label fw-bold">Topic/Tag (Optional)</label>
                                    <input type="text" name="topic" id="topic" class="form-control"
                                        placeholder="e.g., Python, Variables, Functions"
                                        value="<?php echo isset($_POST['topic']) ? htmlspecialchars($_POST['topic']) : ''; ?>">
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
                                                id="is_published" value="1" checked>
                                            <label class="form-check-label fw-bold" for="is_published">
                                                Publish Immediately
                                            </label>
                                        </div>
                                        <small class="text-muted">If unchecked, material will be saved as draft</small>
                                    </div>

                                    <hr>

                                    <div class="mb-3">
                                        <h6 class="fw-bold mb-2">Access Restrictions</h6>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="downloadable"
                                                id="downloadable" value="1" checked>
                                            <label class="form-check-label" for="downloadable">
                                                Allow students to download
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="shareable"
                                                id="shareable" value="1">
                                            <label class="form-check-label" for="shareable">
                                                Allow sharing with other instructors
                                            </label>
                                        </div>
                                    </div>

                                    <hr>

                                    <div class="d-grid gap-2">
                                        <button type="submit" name="save_draft" class="btn btn-outline-secondary">
                                            <i class="fas fa-save me-2"></i> Save as Draft
                                        </button>
                                        <button type="submit" name="publish" class="btn btn-primary">
                                            <i class="fas fa-paper-plane me-2"></i> Publish Material
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Upload Tips -->
                            <div class="card mt-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Tips</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled small mb-0">
                                        <li class="mb-2">
                                            <i class="fas fa-check text-success me-2"></i>
                                            Use descriptive titles
                                        </li>
                                        <li class="mb-2">
                                            <i class="fas fa-check text-success me-2"></i>
                                            Add week numbers for better organization
                                        </li>
                                        <li class="mb-2">
                                            <i class="fas fa-check text-success me-2"></i>
                                            Tag materials for easy filtering
                                        </li>
                                        <li class="mb-2">
                                            <i class="fas fa-check text-success me-2"></i>
                                            Use PDF for documents when possible
                                        </li>
                                        <li>
                                            <i class="fas fa-check text-success me-2"></i>
                                            Keep videos under 50MB or use links
                                        </li>
                                    </ul>
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

                // Update file input accept attribute
                const fileInput = document.getElementById('file-input');
                switch (type) {
                    case 'pdf':
                        fileInput.accept = '.pdf';
                        break;
                    case 'document':
                        fileInput.accept = '.doc,.docx,.txt,.rtf';
                        break;
                    case 'presentation':
                        fileInput.accept = '.ppt,.pptx';
                        break;
                    case 'video':
                        fileInput.accept = '.mp4,.avi,.mov,.wmv,.mpeg';
                        break;
                    default:
                        fileInput.accept = '.pdf,.doc,.docx,.txt,.rtf,.ppt,.pptx,.mp4,.avi,.mov,.wmv,.mpeg';
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
            const fileType = document.getElementById('file_type').value;

            // Validate file type based on selected material type
            let isValid = true;
            const fileExt = file.name.split('.').pop().toLowerCase();

            switch (fileType) {
                case 'pdf':
                    isValid = fileExt === 'pdf';
                    break;
                case 'document':
                    isValid = ['doc', 'docx', 'txt', 'rtf'].includes(fileExt);
                    break;
                case 'presentation':
                    isValid = ['ppt', 'pptx'].includes(fileExt);
                    break;
                case 'video':
                    isValid = ['mp4', 'avi', 'mov', 'wmv', 'mpeg'].includes(fileExt);
                    break;
            }

            if (!isValid) {
                alert(`Please select a valid ${fileType} file.`);
                fileInput.value = '';
                return;
            }

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
            icon.className = 'fas fa-file fa-3x text-primary mb-2';

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

        // Form submission
        document.getElementById('upload-form').addEventListener('submit', function(e) {
            const fileType = document.getElementById('file_type').value;
            const classId = document.getElementById('class_id').value;
            const title = document.getElementById('title').value.trim();

            // Basic validation
            if (!classId) {
                alert('Please select a class');
                e.preventDefault();
                return;
            }

            if (!title) {
                alert('Please enter a title');
                e.preventDefault();
                return;
            }

            if (fileType !== 'link') {
                const fileInput = document.getElementById('file-input');
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('Please select a file to upload');
                    e.preventDefault();
                    return;
                }
            } else {
                const urlInput = document.getElementById('file_url');
                if (!urlInput.value.trim()) {
                    alert('Please enter a URL');
                    e.preventDefault();
                    return;
                }
            }

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"][name="publish"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Uploading...';
                submitBtn.disabled = true;
            }
        });
    </script>
</body>

</html>