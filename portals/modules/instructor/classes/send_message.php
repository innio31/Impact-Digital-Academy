<?php
// modules/instructor/classes/send_message.php

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

// Check required parameters
if (!isset($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Check if this is a specific student message
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : null;

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify the instructor has access to this class
$sql = "SELECT cb.*, c.course_code, c.title as course_title 
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
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

// Get instructor info
$sql = "SELECT first_name, last_name, email FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$instructor = $result->fetch_assoc();
$stmt->close();

// Get students for dropdown (if not specific student)
$students = [];
if (!$student_id) {
    $sql = "SELECT u.id, u.first_name, u.last_name, u.email 
            FROM enrollments e
            JOIN users u ON e.student_id = u.id
            WHERE e.class_id = ? AND e.status = 'active'
            ORDER BY u.last_name, u.first_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // Get specific student info
    $sql = "SELECT u.id, u.first_name, u.last_name, u.email 
            FROM enrollments e
            JOIN users u ON e.student_id = u.id
            WHERE e.class_id = ? AND u.id = ? AND e.status = 'active'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $class_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $specific_student = $result->fetch_assoc();
    $stmt->close();

    if (!$specific_student) {
        $conn->close();
        header('Location: students.php?class_id=' . $class_id);
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipients = $_POST['recipients'] ?? [];
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $send_email = isset($_POST['send_email']) ? 1 : 0;

    // Validate
    $errors = [];

    if (empty($recipients)) {
        $errors[] = "Please select at least one recipient";
    }

    if (empty($subject)) {
        $errors[] = "Subject is required";
    }

    if (empty($message)) {
        $errors[] = "Message is required";
    }

    if (empty($errors)) {
        $success_count = 0;

        foreach ($recipients as $recipient_id) {
            // Insert into internal_messages
            $sql = "INSERT INTO internal_messages (
                        sender_id, receiver_id, subject, message, priority,
                        related_class_id, message_type
                    ) VALUES (?, ?, ?, ?, ?, ?, 'user_message')";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisssi", $instructor_id, $recipient_id, $subject, $message, $priority, $class_id);

            if ($stmt->execute()) {
                $message_id = $stmt->insert_id;
                $success_count++;

                // If send email option is checked, send email notification
                if ($send_email) {
                    // Get student email
                    $sql_email = "SELECT email FROM users WHERE id = ?";
                    $stmt_email = $conn->prepare($sql_email);
                    $stmt_email->bind_param("i", $recipient_id);
                    $stmt_email->execute();
                    $result_email = $stmt_email->get_result();
                    $student_email = $result_email->fetch_assoc()['email'];
                    $stmt_email->close();

                    // Send email (you'll need to implement your email sending function)
                    // sendEmailNotification($student_email, $subject, $message, $instructor);
                }
            }
            $stmt->close();
        }

        // Log activity
        logActivity('send_message', "Sent message to {$success_count} student(s) in class: {$class['batch_code']}", 'class_batches', $class_id);

        // Redirect with success message
        $_SESSION['success_message'] = "Message sent successfully to {$success_count} student(s)";
        header('Location: students.php?class_id=' . $class_id);
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
    <title>Send Message - <?php echo htmlspecialchars($class['batch_code']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            max-width: 800px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
            flex-wrap: wrap;
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

        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .card-header {
            margin-bottom: 2rem;
        }

        .card-header h1 {
            font-size: 1.75rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .card-header p {
            color: var(--gray);
        }

        /* Form */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            min-height: 200px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--multiple {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            min-height: 48px;
            padding: 0.25rem;
        }

        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: var(--primary);
            border: none;
            color: white;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            margin-right: 0.25rem;
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        /* Radio buttons */
        .radio-group {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
        }

        /* Preview */
        .message-preview {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
            font-size: 0.875rem;
            line-height: 1.6;
        }

        /* Buttons */
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid #e2e8f0;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
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

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }

        .alert-danger {
            background: #fef2f2;
            border-color: var(--danger);
            color: #991b1b;
        }

        .alert-success {
            background: #f0fdf4;
            border-color: var(--success);
            color: #065f46;
        }

        /* Character counter */
        .char-counter {
            text-align: right;
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .char-counter.warning {
            color: var(--warning);
        }

        .char-counter.danger {
            color: var(--danger);
        }

        /* Student info */
        .student-info {
            background: #f0f9ff;
            border: 2px solid #bae6fd;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .student-info h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .student-info p {
            color: var(--gray);
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
            <a href="students.php?class_id=<?php echo $class_id; ?>">
                <i class="fas fa-user-graduate"></i> Students
            </a>
            <span class="separator">/</span>
            <span>Send Message</span>
        </div>

        <!-- Display errors -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong>Please fix the following errors:</strong>
                <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h1>Send Message to Students</h1>
                <p><?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['batch_code']); ?></p>
            </div>

            <?php if ($student_id && isset($specific_student)): ?>
                <div class="student-info">
                    <h3><i class="fas fa-user-graduate"></i> Sending to specific student:</h3>
                    <p><strong><?php echo htmlspecialchars($specific_student['first_name'] . ' ' . $specific_student['last_name']); ?></strong></p>
                    <p><?php echo htmlspecialchars($specific_student['email']); ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="messageForm">
                <!-- Recipients -->
                <div class="form-group">
                    <label class="form-label" for="recipients">
                        <i class="fas fa-users"></i> Recipients
                        <?php if (!$student_id): ?>
                            <span class="form-text">(Select one or more students)</span>
                        <?php endif; ?>
                    </label>

                    <?php if ($student_id): ?>
                        <!-- Hidden input for specific student -->
                        <input type="hidden" name="recipients[]" value="<?php echo $student_id; ?>">
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($specific_student['first_name'] . ' ' . $specific_student['last_name']); ?>" disabled>
                    <?php else: ?>
                        <select name="recipients[]" id="recipients" class="form-control" multiple="multiple" required>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>" <?php echo (isset($_POST['recipients']) && in_array($student['id'], $_POST['recipients'])) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['email'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <a href="#" id="selectAll">Select all</a> |
                            <a href="#" id="deselectAll">Deselect all</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Subject -->
                <div class="form-group">
                    <label class="form-label" for="subject">
                        <i class="fas fa-heading"></i> Subject
                    </label>
                    <input type="text"
                        class="form-control"
                        id="subject"
                        name="subject"
                        value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>"
                        required
                        placeholder="e.g., Assignment Update, Class Announcement, etc.">
                </div>

                <!-- Message -->
                <div class="form-group">
                    <label class="form-label" for="message">
                        <i class="fas fa-comment-alt"></i> Message
                    </label>
                    <textarea class="form-control"
                        id="message"
                        name="message"
                        rows="8"
                        required
                        placeholder="Type your message here..."><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    <div class="char-counter" id="charCounter">
                        <span id="charCount">0</span> characters
                    </div>
                </div>

                <!-- Priority -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-exclamation-circle"></i> Priority
                    </label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="priority" value="low" <?php echo ($_POST['priority'] ?? 'normal') == 'low' ? 'checked' : ''; ?>>
                            <span>Low</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="priority" value="normal" <?php echo ($_POST['priority'] ?? 'normal') == 'normal' ? 'checked' : ''; ?> checked>
                            <span>Normal</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="priority" value="high" <?php echo ($_POST['priority'] ?? 'normal') == 'high' ? 'checked' : ''; ?>>
                            <span>High</span>
                        </label>
                    </div>
                </div>

                <!-- Send email notification -->
                <div class="form-group">
                    <label class="checkbox-group">
                        <input type="checkbox" name="send_email" id="send_email" value="1" <?php echo isset($_POST['send_email']) ? 'checked' : ''; ?>>
                        <span>Also send email notification to selected students</span>
                    </label>
                    <div class="form-text">
                        Students will receive this message in their portal inbox. Check this option to also send it to their email.
                    </div>
                </div>

                <!-- Preview -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-eye"></i> Preview
                    </label>
                    <div class="message-preview" id="messagePreview">
                        Your message will appear here...
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                    <a href="students.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2 for recipients
            $('#recipients').select2({
                placeholder: "Select students...",
                allowClear: true
            });

            // Select all / Deselect all
            $('#selectAll').click(function(e) {
                e.preventDefault();
                $('#recipients option').prop('selected', true);
                $('#recipients').trigger('change');
            });

            $('#deselectAll').click(function(e) {
                e.preventDefault();
                $('#recipients option').prop('selected', false);
                $('#recipients').trigger('change');
            });

            // Character counter
            const messageTextarea = $('#message');
            const charCounter = $('#charCounter');
            const charCount = $('#charCount');
            const messagePreview = $('#messagePreview');

            function updateCounter() {
                const length = messageTextarea.val().length;
                charCount.text(length);

                charCounter.removeClass('warning danger');
                if (length > 1000) {
                    charCounter.addClass('warning');
                }
                if (length > 2000) {
                    charCounter.addClass('danger');
                }

                // Update preview
                messagePreview.text(messageTextarea.val() || 'Your message will appear here...');
            }

            messageTextarea.on('input', updateCounter);
            updateCounter();

            // Preview subject and message
            $('#subject').on('input', function() {
                const subject = $(this).val();
                const message = messageTextarea.val();
                const preview = subject ? `Subject: ${subject}\n\n${message}` : message;
                messagePreview.text(preview || 'Your message will appear here...');
            });

            // Form submission confirmation
            $('#messageForm').submit(function(e) {
                const recipients = $('#recipients').val();
                if (!recipients || recipients.length === 0) {
                    alert('Please select at least one recipient.');
                    e.preventDefault();
                    return;
                }

                if (recipients.length > 10) {
                    const confirmed = confirm(`You are about to send this message to ${recipients.length} students. Are you sure?`);
                    if (!confirmed) {
                        e.preventDefault();
                    }
                }
            });

            // Keyboard shortcuts
            $(document).keydown(function(e) {
                // Ctrl+Enter to submit
                if (e.ctrlKey && e.key === 'Enter') {
                    $('#messageForm').submit();
                }

                // Esc to cancel
                if (e.key === 'Escape') {
                    window.location.href = 'students.php?class_id=<?php echo $class_id; ?>';
                }
            });
        });
    </script>
</body>

</html>