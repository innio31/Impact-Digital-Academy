<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/functions.php';

// Simple admin authentication
$admin_password = "admin123"; // Change this!

if (isset($_POST['login'])) {
    if ($_POST['password'] == $admin_password) {
        $_SESSION['admin'] = true;
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
}

// Handle student actions
if (isset($_SESSION['admin'])) {

    // Add Student
    if (isset($_POST['add_student'])) {
        $username = $conn->real_escape_string($_POST['username']);
        $name = $conn->real_escape_string($_POST['name']);
        $password = md5($_POST['password']); // Using MD5 for demo, use password_hash() in production

        $sql = "INSERT INTO students (username, name, password) VALUES ('$username', '$name', '$password')";
        if ($conn->query($sql)) {
            $_SESSION['message'] = "Student added successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . $conn->error;
            $_SESSION['msg_type'] = "error";
        }
        header('Location: control.php');
        exit;
    }

    // Edit Student
    if (isset($_POST['edit_student'])) {
        $id = $_POST['student_id'];
        $username = $conn->real_escape_string($_POST['username']);
        $name = $conn->real_escape_string($_POST['name']);

        $sql = "UPDATE students SET username='$username', name='$name'";

        // Update password if provided
        if (!empty($_POST['password'])) {
            $password = md5($_POST['password']);
            $sql .= ", password='$password'";
        }

        $sql .= " WHERE id=$id";

        if ($conn->query($sql)) {
            $_SESSION['message'] = "Student updated successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . $conn->error;
            $_SESSION['msg_type'] = "error";
        }
        header('Location: control.php');
        exit;
    }

    // Delete Single Student
    if (isset($_GET['delete'])) {
        $id = $_GET['delete'];

        // Disable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // Delete related answers first
        $conn->query("DELETE FROM student_answers WHERE student_id=$id");

        // Then delete student
        $sql = "DELETE FROM students WHERE id=$id";

        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        if ($conn->query($sql)) {
            $_SESSION['message'] = "Student deleted successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . $conn->error;
            $_SESSION['msg_type'] = "error";
        }
        header('Location: control.php');
        exit;
    }

    // Bulk Delete Students
    if (isset($_POST['bulk_delete_students']) && isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
        $student_ids = array_map('intval', $_POST['student_ids']);
        $ids_string = implode(',', $student_ids);

        // Disable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // Delete related answers for all selected students
        $conn->query("DELETE FROM student_answers WHERE student_id IN ($ids_string)");

        // Then delete students
        $sql = "DELETE FROM students WHERE id IN ($ids_string)";

        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        $deleted_count = count($student_ids);
        if ($conn->query($sql)) {
            $_SESSION['message'] = "$deleted_count student(s) deleted successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . $conn->error;
            $_SESSION['msg_type'] = "error";
        }
        header('Location: control.php');
        exit;
    }

    // Reset all student data
    if (isset($_POST['reset_all_data'])) {
        // Disable foreign key checks temporarily
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // Clear all answers (this will trigger points calculation but that's fine)
        $conn->query("TRUNCATE TABLE student_answers");

        // Reset last activity for all students
        $conn->query("UPDATE students SET last_activity = NULL");

        // Reset quiz session
        $conn->query("UPDATE quiz_sessions SET status = 'waiting', current_question = 0, 
                  countdown_start = NULL, question_start = NULL, question_end = NULL 
                  ORDER BY id DESC LIMIT 1");

        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        $_SESSION['message'] = "All student data has been reset successfully!";
        $_SESSION['msg_type'] = "success";
        header('Location: control.php');
        exit;
    }

    // Import CSV
    if (isset($_POST['import_csv']) && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");

        $success_count = 0;
        $error_count = 0;

        // Skip header row if exists
        $header = fgetcsv($handle);

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $username = $conn->real_escape_string($data[0]);
            $name = $conn->real_escape_string($data[1]);
            $password = md5($data[2]); // Default password or use provided

            $sql = "INSERT INTO students (username, name, password) VALUES ('$username', '$name', '$password')";
            if ($conn->query($sql)) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        fclose($handle);

        $_SESSION['message'] = "Imported: $success_count students, Errors: $error_count";
        $_SESSION['msg_type'] = $error_count > 0 ? "warning" : "success";
        header('Location: control.php');
        exit;
    }

    // ============= QUESTION MANAGEMENT =============

    // Add Question
    if (isset($_POST['add_question'])) {
        $question_text = $conn->real_escape_string($_POST['question_text']);
        $option_a = $conn->real_escape_string($_POST['option_a']);
        $option_b = $conn->real_escape_string($_POST['option_b']);
        $option_c = $conn->real_escape_string($_POST['option_c']);
        $option_d = $conn->real_escape_string($_POST['option_d']);
        $correct_option = $conn->real_escape_string($_POST['correct_option']);
        $points = intval($_POST['points']);
        $time_limit = intval($_POST['time_limit']);

        // Get the max order number
        $order_result = $conn->query("SELECT MAX(order_number) as max_order FROM questions");
        $order_row = $order_result->fetch_assoc();
        $order_number = ($order_row['max_order'] ?? 0) + 1;

        $sql = "INSERT INTO questions (question_text, option_a, option_b, option_c, option_d, correct_option, points, time_limit, order_number) 
                VALUES ('$question_text', '$option_a', '$option_b', '$option_c', '$option_d', '$correct_option', $points, $time_limit, $order_number)";

        if ($conn->query($sql)) {
            $_SESSION['message'] = "Question added successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . $conn->error;
            $_SESSION['msg_type'] = "error";
        }
        header('Location: control.php');
        exit;
    }

    // Edit Question
    if (isset($_POST['edit_question'])) {
        $id = $_POST['question_id'];
        $question_text = $conn->real_escape_string($_POST['question_text']);
        $option_a = $conn->real_escape_string($_POST['option_a']);
        $option_b = $conn->real_escape_string($_POST['option_b']);
        $option_c = $conn->real_escape_string($_POST['option_c']);
        $option_d = $conn->real_escape_string($_POST['option_d']);
        $correct_option = $conn->real_escape_string($_POST['correct_option']);
        $points = intval($_POST['points']);
        $time_limit = intval($_POST['time_limit']);

        $sql = "UPDATE questions SET 
                question_text = '$question_text',
                option_a = '$option_a',
                option_b = '$option_b',
                option_c = '$option_c',
                option_d = '$option_d',
                correct_option = '$correct_option',
                points = $points,
                time_limit = $time_limit
                WHERE id = $id";

        if ($conn->query($sql)) {
            $_SESSION['message'] = "Question updated successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . $conn->error;
            $_SESSION['msg_type'] = "error";
        }
        header('Location: control.php');
        exit;
    }

    // Delete Question
    if (isset($_GET['delete_question'])) {
        $id = $_GET['delete_question'];

        // Check if question is being used in answers
        $check_sql = "SELECT COUNT(*) as count FROM student_answers WHERE question_id = $id";
        $check_result = $conn->query($check_sql);
        $check_row = $check_result->fetch_assoc();

        if ($check_row['count'] > 0) {
            $_SESSION['message'] = "Cannot delete question that has been answered by students. Consider deactivating it instead.";
            $_SESSION['msg_type'] = "error";
        } else {
            $sql = "DELETE FROM questions WHERE id = $id";
            if ($conn->query($sql)) {
                // Reorder remaining questions
                $conn->query("SET @count = 0");
                $conn->query("UPDATE questions SET order_number = @count:= @count + 1 ORDER BY order_number");

                $_SESSION['message'] = "Question deleted successfully!";
                $_SESSION['msg_type'] = "success";
            } else {
                $_SESSION['message'] = "Error: " . $conn->error;
                $_SESSION['msg_type'] = "error";
            }
        }
        header('Location: control.php');
        exit;
    }

    // Move Question Up
    if (isset($_GET['move_up'])) {
        $id = $_GET['move_up'];

        // Get current question's order
        $current = $conn->query("SELECT order_number FROM questions WHERE id = $id")->fetch_assoc();
        $current_order = $current['order_number'];

        // Find question above
        $above = $conn->query("SELECT id, order_number FROM questions WHERE order_number < $current_order ORDER BY order_number DESC LIMIT 1")->fetch_assoc();

        if ($above) {
            $above_id = $above['id'];
            $above_order = $above['order_number'];

            // Swap orders
            $conn->query("UPDATE questions SET order_number = $above_order WHERE id = $id");
            $conn->query("UPDATE questions SET order_number = $current_order WHERE id = $above_id");

            $_SESSION['message'] = "Question moved up!";
            $_SESSION['msg_type'] = "success";
        }
        header('Location: control.php');
        exit;
    }

    // Move Question Down
    if (isset($_GET['move_down'])) {
        $id = $_GET['move_down'];

        // Get current question's order
        $current = $conn->query("SELECT order_number FROM questions WHERE id = $id")->fetch_assoc();
        $current_order = $current['order_number'];

        // Find question below
        $below = $conn->query("SELECT id, order_number FROM questions WHERE order_number > $current_order ORDER BY order_number ASC LIMIT 1")->fetch_assoc();

        if ($below) {
            $below_id = $below['id'];
            $below_order = $below['order_number'];

            // Swap orders
            $conn->query("UPDATE questions SET order_number = $below_order WHERE id = $id");
            $conn->query("UPDATE questions SET order_number = $current_order WHERE id = $below_id");

            $_SESSION['message'] = "Question moved down!";
            $_SESSION['msg_type'] = "success";
        }
        header('Location: control.php');
        exit;
    }

    // Toggle Question Active Status
    if (isset($_GET['toggle_question'])) {
        $id = $_GET['toggle_question'];
        $conn->query("UPDATE questions SET is_active = NOT is_active WHERE id = $id");

        $_SESSION['message'] = "Question status toggled!";
        $_SESSION['msg_type'] = "success";
        header('Location: control.php');
        exit;
    }
}

if (!isset($_SESSION['admin'])) {
    // Show login form (mobile friendly)
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
        <title>Admin Login - Quiz System</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 15px;
            }

            .login-container {
                background: white;
                padding: 30px 20px;
                border-radius: 20px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                width: 100%;
                max-width: 400px;
            }

            h2 {
                color: #333;
                margin-bottom: 30px;
                text-align: center;
                font-size: 28px;
            }

            .input-group {
                margin-bottom: 20px;
            }

            label {
                display: block;
                margin-bottom: 8px;
                color: #555;
                font-weight: 500;
                font-size: 16px;
            }

            input[type="password"] {
                width: 100%;
                padding: 15px;
                border: 2px solid #e0e0e0;
                border-radius: 12px;
                font-size: 16px;
                transition: border-color 0.3s;
                -webkit-appearance: none;
            }

            input[type="password"]:focus {
                outline: none;
                border-color: #667eea;
            }

            button {
                width: 100%;
                padding: 16px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 12px;
                font-size: 18px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s, opacity 0.2s;
                -webkit-tap-highlight-color: transparent;
            }

            button:active {
                transform: scale(0.98);
                opacity: 0.9;
            }
        </style>
    </head>

    <body>
        <div class="login-container">
            <h2>Admin Login</h2>
            <form method="POST">
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Enter admin password">
                </div>
                <button type="submit" name="login">Login</button>
            </form>
        </div>
    </body>

    </html>
<?php
    exit;
}

// Get quiz state and questions
$state = getQuizState();
$questions_sql = "SELECT * FROM questions ORDER BY order_number";
$questions_result = $conn->query($questions_sql);
$questions = [];
while ($row = $questions_result->fetch_assoc()) {
    $questions[] = $row;
}

// Get all students
$students_sql = "SELECT s.*, COALESCE(ss.total_points, 0) as total_points,
                 (SELECT COUNT(*) FROM student_answers WHERE student_id = s.id) as answers_count
                 FROM students s
                 LEFT JOIN student_scores ss ON s.id = ss.student_id
                 ORDER BY s.name";
$students_result = $conn->query($students_sql);
$students = [];
while ($row = $students_result->fetch_assoc()) {
    $students[] = $row;
}

// Get student for editing
$edit_student = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    foreach ($students as $s) {
        if ($s['id'] == $edit_id) {
            $edit_student = $s;
            break;
        }
    }
}

// Get question for editing
$edit_question = null;
if (isset($_GET['edit_question'])) {
    $edit_id = $_GET['edit_question'];
    foreach ($questions as $q) {
        if ($q['id'] == $edit_id) {
            $edit_question = $q;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#667eea">
    <title>Quiz Control Panel</title>
    <style>
        /* Mobile-First Reset */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding-bottom: 70px;
            /* Space for bottom nav */
        }

        /* Mobile Header */
        .mobile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 15px 15px 15px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .header-top h1 {
            font-size: 20px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        .header-btn:active {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(0.95);
        }

        /* Status Bar */
        .status-bar {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(5px);
            margin-top: 5px;
        }

        .status-info {
            display: flex;
            flex-direction: column;
        }

        .status-label {
            font-size: 12px;
            opacity: 0.8;
        }

        .status-value {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-value.waiting {
            color: #f1c40f;
        }

        .status-value.countdown {
            color: #e67e22;
        }

        .status-value.question {
            color: #27ae60;
        }

        .status-value.results {
            color: #3498db;
        }

        .timer-mini {
            background: rgba(0, 0, 0, 0.3);
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 20px;
            font-weight: bold;
            font-family: monospace;
            display: none;
        }

        .timer-mini.active {
            display: block;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            margin: 15px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: inherit;
            padding: 0 5px;
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 8px 5px;
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }

        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 5px;
            background: none;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            color: #666;
            cursor: pointer;
            transition: all 0.2s;
            -webkit-tap-highlight-color: transparent;
        }

        .nav-item.active {
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            color: #667eea;
            font-weight: 600;
        }

        .nav-item:active {
            background: #f0f0f0;
        }

        .nav-icon {
            font-size: 22px;
            margin-bottom: 2px;
        }

        /* Content Sections */
        .content-section {
            display: none;
            padding: 15px;
        }

        .content-section.active {
            display: block;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Questions List for Mobile */
        .questions-mobile-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .question-mobile-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            border-left: 4px solid #667eea;
            cursor: pointer;
            transition: all 0.2s;
            -webkit-tap-highlight-color: transparent;
        }

        .question-mobile-item.active {
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            border-left-width: 8px;
        }

        .question-mobile-item:active {
            transform: scale(0.98);
        }

        .question-mobile-number {
            font-size: 12px;
            color: #999;
            margin-bottom: 5px;
        }

        .question-mobile-text {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }

        .question-mobile-status {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 20px;
            background: #e9ecef;
            display: inline-block;
        }

        .question-mobile-status.active {
            background: #d4edda;
            color: #155724;
        }

        /* Button Grid for Controls */
        .button-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 15px 0;
        }

        .control-btn {
            padding: 16px 10px;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            color: white;
            cursor: pointer;
            transition: all 0.2s;
            -webkit-tap-highlight-color: transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .control-btn:active {
            transform: scale(0.95);
        }

        .control-btn:disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        .control-btn .btn-icon {
            font-size: 24px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .btn-success {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
        }

        .btn-danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }

        .btn-info {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        }

        /* Students Ready Section */
        .ready-count-large {
            font-size: 48px;
            font-weight: bold;
            color: #27ae60;
            text-align: center;
            margin: 10px 0;
        }

        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .student-badge {
            background: #e9ecef;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            font-size: 13px;
            color: #495057;
        }

        .student-badge.online {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Management Tabs */
        .mgmt-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            overflow-x: auto;
            padding: 5px 0;
            -webkit-overflow-scrolling: touch;
        }

        .mgmt-tab {
            padding: 12px 20px;
            background: white;
            border: none;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            -webkit-tap-highlight-color: transparent;
        }

        .mgmt-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .mgmt-tab:active {
            transform: scale(0.95);
        }

        /* Student List Items */
        .student-list-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-list-item.selected {
            background: #e3f2fd;
            border: 2px solid #667eea;
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
            flex-shrink: 0;
        }

        .student-details {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 3px;
        }

        .student-meta {
            font-size: 13px;
            color: #666;
        }

        .student-score {
            font-weight: bold;
            color: #f39c12;
            font-size: 18px;
        }

        .student-actions {
            display: flex;
            gap: 8px;
        }

        .student-action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        .student-action-btn.edit {
            background: #3498db;
            color: white;
        }

        .student-action-btn.delete {
            background: #e74c3c;
            color: white;
        }

        /* Question Items */
        .question-list-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 10px;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .question-number {
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .question-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .question-status.active {
            background: #d4edda;
            color: #155724;
        }

        .question-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .question-text-preview {
            font-weight: 500;
            margin-bottom: 10px;
            font-size: 15px;
        }

        .options-preview {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 12px;
            font-size: 13px;
            color: #666;
        }

        .option-preview {
            background: white;
            padding: 5px 10px;
            border-radius: 8px;
        }

        .correct-badge {
            background: #27ae60;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-left: 5px;
        }

        .question-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .question-action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            color: white;
            -webkit-tap-highlight-color: transparent;
        }

        .question-action-btn:active {
            transform: scale(0.95);
        }

        .question-action-btn.edit {
            background: #3498db;
        }

        .question-action-btn.delete {
            background: #e74c3c;
        }

        .question-action-btn.toggle {
            background: #f39c12;
        }

        .question-action-btn.move {
            background: #95a5a6;
        }

        /* Search Box */
        .search-box {
            margin: 15px 0;
        }

        .search-box input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 30px;
            font-size: 16px;
            -webkit-appearance: none;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            align-items: flex-end;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 500px;
            border-radius: 25px 25px 0 0;
            padding: 25px 20px;
            animation: slideUp 0.3s ease;
            max-height: 85vh;
            overflow-y: auto;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }

            to {
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 20px;
            color: #333;
        }

        .modal-close {
            font-size: 28px;
            cursor: pointer;
            color: #999;
            padding: 5px;
        }

        .modal-close:active {
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            -webkit-appearance: none;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }

        .modal-actions button {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        /* Warning Box */
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
        }

        .warning-box ul {
            margin-top: 10px;
            margin-left: 20px;
        }

        /* Selected Students List */
        .selected-students-list {
            max-height: 200px;
            overflow-y: auto;
            margin: 15px 0;
        }

        .selected-student-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 8px;
        }

        .selected-student-icon {
            width: 40px;
            height: 40px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }

        /* Bulk Actions */
        .bulk-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 10px 0;
            flex-wrap: wrap;
        }

        .selected-count {
            background: #667eea;
            color: white;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 14px;
        }

        .select-all-btn {
            background: #f8f9fa;
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 30px;
            font-size: 14px;
            cursor: pointer;
        }

        /* Loading States */
        .loading {
            text-align: center;
            padding: 30px;
            color: #999;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
            background: #f8f9fa;
            border-radius: 16px;
        }

        /* Responsive adjustments */
        @media (min-width: 768px) {
            body {
                padding-bottom: 0;
            }

            .bottom-nav {
                display: none;
            }

            .mobile-header {
                padding: 15px 20px;
            }

            .content-section {
                max-width: 1200px;
                margin: 0 auto;
            }

            .student-list-item {
                max-width: 600px;
                margin-left: auto;
                margin-right: auto;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="header-top">
            <h1>🎮 Quiz Control</h1>
            <div class="header-actions">
                <button class="header-btn" onclick="showResetModal()">Reset</button>
                <a href="?logout=1" class="header-btn">Exit</a>
            </div>
        </div>

        <!-- Status Bar -->
        <div class="status-bar">
            <div class="status-info">
                <span class="status-label">Status</span>
                <span class="status-value <?php echo $state['status']; ?>" id="sidebar-status">
                    <?php echo strtoupper($state['status']); ?>
                </span>
            </div>
            <div class="timer-mini" id="timerMini">
                <span id="timerValue">0</span>s
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert <?php echo $_SESSION['msg_type']; ?>">
            <?php
            echo $_SESSION['message'];
            unset($_SESSION['message']);
            unset($_SESSION['msg_type']);
            ?>
            <button class="close-btn" onclick="this.parentElement.remove()">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Content Sections -->
    <div id="quizSection" class="content-section active">
        <!-- Quiz Control Card -->
        <div class="card">
            <div class="card-title">
                <span>🎯 Quiz Controls</span>
            </div>

            <!-- Selected Question Display -->
            <div style="background: #e8f4fd; padding: 15px; border-radius: 12px; margin-bottom: 15px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Selected Question:</div>
                <div id="selectedQuestionDisplay" style="font-weight: 500; word-break: break-word;">
                    <?php echo $state['current_question'] ? 'Question ' . array_search($state['current_question'], array_column($questions, 'id')) + 1 : 'None selected'; ?>
                </div>
            </div>

            <!-- Button Grid -->
            <div class="button-grid">
                <button class="control-btn btn-primary" onclick="checkReadiness()" id="checkReadinessBtn">
                    <span class="btn-icon">👥</span>
                    <span>Check Ready</span>
                </button>
                <button class="control-btn btn-success" onclick="startCountdown()" id="startCountdownBtn">
                    <span class="btn-icon">⏱️</span>
                    <span>Start Countdown</span>
                </button>
                <button class="control-btn btn-warning" onclick="forceShowQuestion()" id="showQuestionBtn">
                    <span class="btn-icon">📢</span>
                    <span>Show Question</span>
                </button>
                <button class="control-btn btn-danger" onclick="showResults()" id="showResultsBtn">
                    <span class="btn-icon">📊</span>
                    <span>Show Results</span>
                </button>
                <button class="control-btn btn-info" onclick="nextQuestion()" id="nextQuestionBtn" style="grid-column: span 2;">
                    <span class="btn-icon">⏭️</span>
                    <span>Next Question</span>
                </button>
            </div>
        </div>

        <!-- Questions List Card -->
        <div class="card">
            <div class="card-title">
                <span>📋 Questions</span>
                <span style="font-size: 14px; color: #666;"><?php echo count($questions); ?> total</span>
            </div>

            <div class="questions-mobile-list">
                <?php foreach ($questions as $index => $q): ?>
                    <div class="question-mobile-item <?php echo ($state['current_question'] == $q['id']) ? 'active' : ''; ?>"
                        onclick="selectQuestion(<?php echo $q['id']; ?>, this)">
                        <div class="question-mobile-number">Question <?php echo $index + 1; ?></div>
                        <div class="question-mobile-text"><?php echo htmlspecialchars($q['question_text']); ?></div>
                        <div>
                            <span class="question-mobile-status <?php echo $q['is_active'] ? 'active' : ''; ?>">
                                <?php echo $q['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($questions)): ?>
                    <div class="empty-state">No questions yet. Add some in Management tab.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Students Ready Card -->
        <div class="card">
            <div class="card-title">
                <span>👥 Students Ready</span>
                <span class="ready-count" id="readyCount">0</span>
            </div>

            <div class="students-grid" id="studentList">
                <div class="loading">Loading...</div>
            </div>
        </div>
    </div>

    <div id="managementSection" class="content-section">
        <!-- Management Tabs -->
        <div class="mgmt-tabs">
            <button class="mgmt-tab active" onclick="showMgmtTab('students')">👥 Students</button>
            <button class="mgmt-tab" onclick="showMgmtTab('questions')">❓ Questions</button>
        </div>

        <!-- Students Management -->
        <div id="studentsMgmt" class="mgmt-panel active">
            <!-- Search and Bulk Actions -->
            <div class="card">
                <div class="search-box">
                    <input type="text" id="studentSearch" placeholder="🔍 Search students..." onkeyup="searchStudents()">
                </div>

                <div class="bulk-actions">
                    <span class="selected-count" id="selectedCount">0 selected</span>
                    <button class="select-all-btn" onclick="toggleSelectAll()">Select All</button>
                    <button class="control-btn btn-danger" style="flex: 1;" onclick="showBulkDeleteModal()" id="bulkDeleteBtn" disabled>Delete Selected</button>
                </div>

                <button class="control-btn btn-success" style="width: 100%; margin-top: 10px;" onclick="showAddModal()">
                    <span class="btn-icon">➕</span> Add Student
                </button>

                <button class="control-btn btn-info" style="width: 100%; margin-top: 10px;" onclick="showImportModal()">
                    <span class="btn-icon">📁</span> Import CSV
                </button>
            </div>

            <!-- Students List -->
            <div id="studentsList">
                <?php foreach ($students as $student):
                    $last_active = strtotime($student['last_activity'] ?? '');
                    $is_online = $last_active > (time() - 30);
                ?>
                    <div class="student-list-item" id="student-row-<?php echo $student['id']; ?>">
                        <input type="checkbox" class="student-checkbox" value="<?php echo $student['id']; ?>" style="width: 20px; height: 20px;" onchange="updateSelectedCount()">
                        <div class="student-avatar"><?php echo strtoupper(substr($student['name'], 0, 1)); ?></div>
                        <div class="student-details">
                            <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                            <div class="student-meta">
                                @<?php echo htmlspecialchars($student['username']); ?> •
                                <span class="<?php echo $is_online ? '' : ''; ?>">
                                    <?php echo $is_online ? '🟢 Online' : '⚪ Offline'; ?>
                                </span>
                            </div>
                            <div class="student-meta">Answers: <?php echo $student['answers_count']; ?></div>
                        </div>
                        <div class="student-score"><?php echo $student['total_points']; ?> pts</div>
                        <div class="student-actions">
                            <button class="student-action-btn edit" onclick='showEditModal(<?php echo json_encode($student); ?>)'>Edit</button>
                            <button class="student-action-btn delete" onclick="deleteStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>')">Del</button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($students)): ?>
                    <div class="empty-state">No students yet. Add some using the button above.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Questions Management -->
        <div id="questionsMgmt" class="mgmt-panel">
            <div class="card">
                <button class="control-btn btn-success" style="width: 100%;" onclick="showAddQuestionModal()">
                    <span class="btn-icon">➕</span> Add Question
                </button>
            </div>

            <!-- Questions List -->
            <div id="questionsList">
                <?php foreach ($questions as $index => $q): ?>
                    <div class="question-list-item">
                        <div class="question-header">
                            <span class="question-number">Q<?php echo $index + 1; ?></span>
                            <span class="question-status <?php echo $q['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $q['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="question-text-preview">
                            <?php echo htmlspecialchars($q['question_text']); ?>
                        </div>
                        <div class="options-preview">
                            <div class="option-preview">A: <?php echo htmlspecialchars($q['option_a']); ?></div>
                            <div class="option-preview">B: <?php echo htmlspecialchars($q['option_b']); ?></div>
                            <div class="option-preview">C: <?php echo htmlspecialchars($q['option_c']); ?></div>
                            <div class="option-preview">D: <?php echo htmlspecialchars($q['option_d']); ?></div>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <span class="correct-badge">Correct: <?php echo $q['correct_option']; ?></span>
                            <span style="margin-left: 10px; color: #666;">⚡ <?php echo $q['time_limit']; ?>s • 🏆 <?php echo $q['points']; ?> pts</span>
                        </div>
                        <div class="question-actions">
                            <?php if ($index > 0): ?>
                                <a href="?move_up=<?php echo $q['id']; ?>" class="question-action-btn move">⬆️ Up</a>
                            <?php endif; ?>
                            <?php if ($index < count($questions) - 1): ?>
                                <a href="?move_down=<?php echo $q['id']; ?>" class="question-action-btn move">⬇️ Down</a>
                            <?php endif; ?>
                            <button class="question-action-btn toggle" onclick="toggleQuestion(<?php echo $q['id']; ?>)">
                                <?php echo $q['is_active'] ? '🔴 Deactivate' : '🟢 Activate'; ?>
                            </button>
                            <button class="question-action-btn edit" onclick='showEditQuestionModal(<?php echo htmlspecialchars(json_encode($q)); ?>)'>✏️ Edit</button>
                            <button class="question-action-btn delete" onclick="deleteQuestion(<?php echo $q['id']; ?>, '<?php echo htmlspecialchars($q['question_text']); ?>')">🗑️ Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($questions)): ?>
                    <div class="empty-state">No questions yet. Click "Add Question" to create your first question.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <button class="nav-item active" onclick="showSection('quiz')">
            <span class="nav-icon">🎯</span>
            <span>Quiz</span>
        </button>
        <button class="nav-item" onclick="showSection('management')">
            <span class="nav-icon">⚙️</span>
            <span>Manage</span>
        </button>
    </div>

    <!-- Add Student Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Student</h3>
                <span class="modal-close" onclick="hideModal('addModal')">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" required placeholder="Enter student's full name">
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required placeholder="Enter username for login">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Enter password">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-primary" onclick="hideModal('addModal')">Cancel</button>
                    <button type="submit" name="add_student" class="btn-success">Add Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Student</h3>
                <span class="modal-close" onclick="hideModal('editModal')">&times;</span>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="student_id" id="edit_id">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" id="edit_username" required>
                </div>
                <div class="form-group">
                    <label>New Password (leave blank to keep current)</label>
                    <input type="password" name="password" placeholder="Enter new password">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-primary" onclick="hideModal('editModal')">Cancel</button>
                    <button type="submit" name="edit_student" class="btn-success">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import CSV Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Import Students from CSV</h3>
                <span class="modal-close" onclick="hideModal('importModal')">&times;</span>
            </div>
            <div class="warning-box" style="background: #e3f2fd; color: #0c5460;">
                <strong>CSV Format:</strong><br>
                username,name,password<br>
                john_doe,John Doe,pass123<br>
                jane_smith,Jane Smith,pass123
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <input type="file" name="csv_file" accept=".csv" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-primary" onclick="hideModal('importModal')">Cancel</button>
                    <button type="submit" name="import_csv" class="btn-success">Import</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Question Modal -->
    <div id="addQuestionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Question</h3>
                <span class="modal-close" onclick="hideModal('addQuestionModal')">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Question Text</label>
                    <textarea name="question_text" required placeholder="Enter the question"></textarea>
                </div>

                <div class="form-group">
                    <label>Option A</label>
                    <input type="text" name="option_a" required placeholder="Enter option A">
                </div>

                <div class="form-group">
                    <label>Option B</label>
                    <input type="text" name="option_b" required placeholder="Enter option B">
                </div>

                <div class="form-group">
                    <label>Option C</label>
                    <input type="text" name="option_c" required placeholder="Enter option C">
                </div>

                <div class="form-group">
                    <label>Option D</label>
                    <input type="text" name="option_d" required placeholder="Enter option D">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Correct Option</label>
                        <select name="correct_option" required>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Points</label>
                        <input type="number" name="points" value="100" min="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Time Limit (seconds)</label>
                    <input type="number" name="time_limit" value="10" min="5" max="60" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-primary" onclick="hideModal('addQuestionModal')">Cancel</button>
                    <button type="submit" name="add_question" class="btn-success">Add Question</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Question Modal -->
    <div id="editQuestionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Question</h3>
                <span class="modal-close" onclick="hideModal('editQuestionModal')">&times;</span>
            </div>
            <form method="POST" id="editQuestionForm">
                <input type="hidden" name="question_id" id="edit_question_id">

                <div class="form-group">
                    <label>Question Text</label>
                    <textarea name="question_text" id="edit_question_text" required></textarea>
                </div>

                <div class="form-group">
                    <label>Option A</label>
                    <input type="text" name="option_a" id="edit_option_a" required>
                </div>

                <div class="form-group">
                    <label>Option B</label>
                    <input type="text" name="option_b" id="edit_option_b" required>
                </div>

                <div class="form-group">
                    <label>Option C</label>
                    <input type="text" name="option_c" id="edit_option_c" required>
                </div>

                <div class="form-group">
                    <label>Option D</label>
                    <input type="text" name="option_d" id="edit_option_d" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Correct Option</label>
                        <select name="correct_option" id="edit_correct_option" required>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Points</label>
                        <input type="number" name="points" id="edit_points" min="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Time Limit (seconds)</label>
                    <input type="number" name="time_limit" id="edit_time_limit" min="5" max="60" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-primary" onclick="hideModal('editQuestionModal')">Cancel</button>
                    <button type="submit" name="edit_question" class="btn-success">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Confirmation Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>⚠️ Reset All Data</h3>
                <span class="modal-close" onclick="hideModal('resetModal')">&times;</span>
            </div>
            <div class="warning-box">
                <strong>This action will:</strong>
                <ul style="margin-top: 10px;">
                    <li>Delete all student answers</li>
                    <li>Reset all scores to zero</li>
                    <li>Clear student online status</li>
                    <li>Reset quiz to waiting state</li>
                </ul>
                <p style="margin-top: 15px; font-weight: bold;">This cannot be undone!</p>
            </div>
            <form method="POST" id="resetForm">
                <div class="modal-actions">
                    <button type="button" class="btn-primary" onclick="hideModal('resetModal')">Cancel</button>
                    <button type="submit" name="reset_all_data" class="btn-danger">Yes, Reset Everything</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Delete Confirmation Modal -->
    <div id="bulkDeleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>⚠️ Delete Selected Students</h3>
                <span class="modal-close" onclick="hideModal('bulkDeleteModal')">&times;</span>
            </div>
            <div class="warning-box">
                You are about to delete <strong id="deleteCount">0</strong> student(s). This will also delete all their answers and scores.
            </div>

            <div class="selected-students-list" id="selectedStudentsList">
                <!-- Will be populated by JavaScript -->
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-primary" onclick="hideModal('bulkDeleteModal')">Cancel</button>
                <button type="button" class="btn-danger" onclick="submitBulkDelete()">Yes, Delete</button>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentQuestionId = <?php echo $state['current_question'] ?: 0; ?>;
        let selectedQuestionElement = null;
        let timerInterval;
        let stateCheckInterval;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            startStateChecking();
            checkReadiness();
            highlightSelectedQuestion();
        });

        // Section switching
        function showSection(section) {
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

            if (section === 'quiz') {
                document.getElementById('quizSection').classList.add('active');
                document.querySelectorAll('.nav-item')[0].classList.add('active');
            } else {
                document.getElementById('managementSection').classList.add('active');
                document.querySelectorAll('.nav-item')[1].classList.add('active');
            }
        }

        // Management tab switching
        function showMgmtTab(tab) {
            document.querySelectorAll('.mgmt-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.mgmt-panel').forEach(p => p.classList.remove('active'));

            if (tab === 'students') {
                document.querySelectorAll('.mgmt-tab')[0].classList.add('active');
                document.getElementById('studentsMgmt').classList.add('active');
            } else {
                document.querySelectorAll('.mgmt-tab')[1].classList.add('active');
                document.getElementById('questionsMgmt').classList.add('active');
            }
        }

        // Modal functions
        function showAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function showEditModal(student) {
            document.getElementById('edit_id').value = student.id;
            document.getElementById('edit_name').value = student.name;
            document.getElementById('edit_username').value = student.username;
            document.getElementById('editModal').classList.add('active');
        }

        function showImportModal() {
            document.getElementById('importModal').classList.add('active');
        }

        function showResetModal() {
            document.getElementById('resetModal').classList.add('active');
        }

        function showAddQuestionModal() {
            document.getElementById('addQuestionModal').classList.add('active');
        }

        function showEditQuestionModal(question) {
            document.getElementById('edit_question_id').value = question.id;
            document.getElementById('edit_question_text').value = question.question_text;
            document.getElementById('edit_option_a').value = question.option_a;
            document.getElementById('edit_option_b').value = question.option_b;
            document.getElementById('edit_option_c').value = question.option_c;
            document.getElementById('edit_option_d').value = question.option_d;
            document.getElementById('edit_correct_option').value = question.correct_option;
            document.getElementById('edit_points').value = question.points;
            document.getElementById('edit_time_limit').value = question.time_limit;
            document.getElementById('editQuestionModal').classList.add('active');
        }

        function hideModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Delete functions
        function deleteStudent(id, name) {
            if (confirm(`Delete student "${name}"? This will also delete all their answers.`)) {
                window.location.href = `?delete=${id}`;
            }
        }

        function deleteQuestion(id, text) {
            if (confirm(`Delete question "${text.substring(0, 50)}..."?`)) {
                window.location.href = `?delete_question=${id}`;
            }
        }

        function toggleQuestion(id) {
            window.location.href = `?toggle_question=${id}`;
        }

        // Question selection
        function selectQuestion(id, element) {
            currentQuestionId = id;

            document.querySelectorAll('.question-mobile-item').forEach(item => {
                item.classList.remove('active');
            });
            element.classList.add('active');

            const questionText = element.querySelector('.question-mobile-text').textContent;
            document.getElementById('selectedQuestionDisplay').textContent = questionText;
        }

        function highlightSelectedQuestion() {
            if (currentQuestionId > 0) {
                const activeItem = document.querySelector('.question-mobile-item.active');
                if (activeItem) {
                    const questionText = activeItem.querySelector('.question-mobile-text').textContent;
                    document.getElementById('selectedQuestionDisplay').textContent = questionText;
                }
            }
        }

        // Quiz control functions
        function checkReadiness() {
            fetch('../api/get_quiz_data.php?action=ready_students')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('readyCount').textContent = data.count;

                    const studentList = document.getElementById('studentList');

                    if (data.students.length > 0) {
                        studentList.innerHTML = '';
                        data.students.forEach(student => {
                            const div = document.createElement('div');
                            div.className = 'student-badge online';
                            div.textContent = student.name;
                            studentList.appendChild(div);
                        });
                    } else {
                        studentList.innerHTML = '<div class="empty-state">No students online</div>';
                    }
                });
        }

        function startCountdown() {
            if (!currentQuestionId) {
                alert('Please select a question first');
                return;
            }

            const selectedQuestion = document.querySelector('.question-mobile-item.active .question-mobile-text').textContent;

            if (!confirm('Start 10-second countdown for:\n\n"' + selectedQuestion + '"')) {
                return;
            }

            document.getElementById('timerMini').classList.add('active');
            document.getElementById('timerValue').textContent = '10';

            fetch('ajax/quiz_controls.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=start_countdown&question_id=' + currentQuestionId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateButtonStates('countdown');
                        showNotification('Countdown started!', 'success');
                    } else {
                        alert('Error starting countdown: ' + (data.error || 'Unknown error'));
                        document.getElementById('timerMini').classList.remove('active');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Network error starting countdown');
                    document.getElementById('timerMini').classList.remove('active');
                });
        }

        function forceShowQuestion() {
            if (!currentQuestionId) {
                alert('Please select a question first');
                return;
            }
            if (!confirm('Force show question now?')) return;

            fetch('ajax/quiz_controls.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=show_question&question_id=' + currentQuestionId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateButtonStates('question');
                    }
                });
        }

        function showResults() {
            if (!confirm('Show results now?')) return;

            fetch('ajax/quiz_controls.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=show_results'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateButtonStates('results');
                    }
                });
        }

        function nextQuestion() {
            if (!confirm('Move to next question?')) return;

            fetch('ajax/quiz_controls.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=next_question'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentQuestionId = 0;
                        document.querySelectorAll('.question-mobile-item').forEach(item => {
                            item.classList.remove('active');
                        });
                        document.getElementById('selectedQuestionDisplay').textContent = 'None selected';
                        updateButtonStates('waiting');
                    }
                });
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = 'alert ' + type;
            notification.innerHTML = message + '<button class="close-btn" onclick="this.remove()">&times;</button>';

            const header = document.querySelector('.mobile-header');
            header.parentNode.insertBefore(notification, header.nextSibling);

            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }

        // State checking
        function startStateChecking() {
            checkQuizState();
            stateCheckInterval = setInterval(checkQuizState, 1000);
            setInterval(checkReadiness, 2000);
        }

        function checkQuizState() {
            fetch('../api/get_quiz_data.php?action=state')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('sidebar-status').textContent = data.status.toUpperCase();
                    document.getElementById('sidebar-status').className = 'status-value ' + data.status;

                    const timerMini = document.getElementById('timerMini');
                    const timerValue = document.getElementById('timerValue');

                    if (data.status === 'countdown' || data.status === 'question') {
                        timerMini.classList.add('active');
                        timerValue.textContent = Math.ceil(data.time_left);
                    } else {
                        timerMini.classList.remove('active');
                    }

                    updateButtonStates(data.status);
                });
        }

        function updateButtonStates(status) {
            const checkBtn = document.getElementById('checkReadinessBtn');
            const startBtn = document.getElementById('startCountdownBtn');
            const showBtn = document.getElementById('showQuestionBtn');
            const resultsBtn = document.getElementById('showResultsBtn');
            const nextBtn = document.getElementById('nextQuestionBtn');

            [checkBtn, startBtn, showBtn, resultsBtn, nextBtn].forEach(btn => {
                btn.disabled = false;
            });

            switch (status) {
                case 'waiting':
                    resultsBtn.disabled = true;
                    nextBtn.disabled = true;
                    break;
                case 'countdown':
                    checkBtn.disabled = true;
                    startBtn.disabled = true;
                    resultsBtn.disabled = true;
                    nextBtn.disabled = true;
                    break;
                case 'question':
                    checkBtn.disabled = true;
                    startBtn.disabled = true;
                    showBtn.disabled = true;
                    nextBtn.disabled = true;
                    break;
                case 'results':
                    checkBtn.disabled = true;
                    startBtn.disabled = true;
                    showBtn.disabled = true;
                    resultsBtn.disabled = true;
                    break;
            }

            if (!currentQuestionId) {
                startBtn.disabled = true;
                showBtn.disabled = true;
            }
        }

        // Bulk delete functions
        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);

            checkboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
            });

            updateSelectedCount();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count + ' selected';
            document.getElementById('bulkDeleteBtn').disabled = count === 0;
        }

        function showBulkDeleteModal() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            if (checkboxes.length === 0) return;

            document.getElementById('deleteCount').textContent = checkboxes.length;

            const listEl = document.getElementById('selectedStudentsList');
            let html = '';

            checkboxes.forEach(checkbox => {
                const studentItem = checkbox.closest('.student-list-item');
                const name = studentItem.querySelector('.student-name').textContent;
                const initial = name.charAt(0);

                html += `
                    <div class="selected-student-item">
                        <div class="selected-student-icon">${initial}</div>
                        <div>${name}</div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
            document.getElementById('bulkDeleteModal').classList.add('active');
        }

        function submitBulkDelete() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'bulk_delete_students';
            actionInput.value = '1';
            form.appendChild(actionInput);

            checkboxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'student_ids[]';
                input.value = checkbox.value;
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        }

        // Search students
        function searchStudents() {
            let input = document.getElementById('studentSearch');
            let filter = input.value.toUpperCase();
            let items = document.querySelectorAll('.student-list-item');

            items.forEach(item => {
                let name = item.querySelector('.student-name').textContent;
                let username = item.querySelector('.student-meta').textContent;

                if (name.toUpperCase().indexOf(filter) > -1 || username.toUpperCase().indexOf(filter) > -1) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Clean up intervals
        window.onbeforeunload = function() {
            if (stateCheckInterval) clearInterval(stateCheckInterval);
            if (timerInterval) clearInterval(timerInterval);
        }
    </script>
</body>

</html>