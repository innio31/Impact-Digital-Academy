<?php
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
    // Show login form (same as before)
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login - Quiz System</title>
        <style>
            /* Same login styles as before */
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            .login-container {
                background: white;
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
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
                margin-bottom: 5px;
                color: #555;
                font-weight: 500;
            }

            input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 2px solid #e0e0e0;
                border-radius: 5px;
                font-size: 16px;
                transition: border-color 0.3s;
            }

            input[type="password"]:focus {
                outline: none;
                border-color: #667eea;
            }

            button {
                width: 100%;
                padding: 12px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s;
            }

            button:hover {
                transform: translateY(-2px);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Control Panel</title>
    <style>
        /* Previous styles remain exactly the same until we add new ones */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 300px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            overflow-y: auto;
            height: 100vh;
            position: fixed;
        }

        .sidebar h2 {
            font-size: 24px;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        }

        .status-indicator {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .status-label {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .status-value {
            font-size: 24px;
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

        .question-list {
            margin-top: 30px;
        }

        .question-list h3 {
            margin-bottom: 15px;
            font-size: 18px;
            opacity: 0.9;
        }

        .question-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .question-item:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .question-item.active {
            background: rgba(255, 255, 255, 0.3);
            border-left: 4px solid #fff;
        }

        .question-number {
            font-size: 12px;
            opacity: 0.7;
            margin-bottom: 5px;
        }

        .question-text {
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .question-status {
            font-size: 10px;
            margin-top: 5px;
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.2);
        }

        .question-status.active {
            background: #27ae60;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 300px;
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #333;
            font-size: 28px;
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: #c0392b;
        }

        .reset-btn {
            background: #f39c12;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            transition: background 0.3s;
            border: none;
            font-size: 14px;
            cursor: pointer;
        }

        .reset-btn:hover {
            background: #e67e22;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 30px;
            background: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .tab:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Panels */
        .panel {
            display: none;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .panel.active {
            display: block;
        }

        /* Control Panel */
        .button-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Timer Display */
        .timer-display {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }

        .timer-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .timer-value {
            font-size: 48px;
            font-weight: bold;
            font-family: monospace;
        }

        /* Students Ready */
        .students-ready {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
        }

        .ready-count {
            font-size: 36px;
            font-weight: bold;
            color: #27ae60;
            margin-bottom: 10px;
        }

        .student-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }

        .student-badge {
            background: #e9ecef;
            padding: 8px;
            border-radius: 5px;
            text-align: center;
            color: #495057;
            font-size: 14px;
        }

        .student-badge.online {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Question Management */
        .question-mgmt-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .question-mgmt-header h3 {
            font-size: 24px;
            color: #333;
        }

        .questions-table-container {
            overflow-x: auto;
        }

        .questions-table {
            width: 100%;
            border-collapse: collapse;
        }

        .questions-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }

        .questions-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            color: #333;
        }

        .questions-table tr:hover {
            background: #f8f9fa;
        }

        .question-preview {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .options-preview {
            font-size: 12px;
            color: #666;
        }

        .correct-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #d4edda;
            color: #155724;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            text-decoration: none;
            color: white;
            cursor: pointer;
            border: none;
            transition: opacity 0.3s;
        }

        .action-btn:hover {
            opacity: 0.8;
        }

        .action-btn.edit {
            background: #3498db;
        }

        .action-btn.delete {
            background: #e74c3c;
        }

        .action-btn.toggle {
            background: #f39c12;
        }

        .action-btn.move {
            background: #95a5a6;
        }

        /* Student Management - New styles for bulk delete */
        .student-mgmt-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .student-mgmt-header h3 {
            font-size: 24px;
            color: #333;
        }

        .bulk-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .checkbox-col {
            width: 40px;
            text-align: center;
        }

        .select-all {
            cursor: pointer;
        }

        .selected-count {
            background: #667eea;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 40px;
            border-radius: 15px;
            width: 100%;
            max-width: 600px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content h3 {
            color: #333;
            margin-bottom: 30px;
            font-size: 24px;
        }

        .modal-content .close {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .modal-content .close:hover {
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
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
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

        .form-group small {
            display: block;
            margin-top: 5px;
            color: #999;
            font-size: 12px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Students Table */
        .students-table-container {
            overflow-x: auto;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .students-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }

        .students-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            color: #333;
        }

        .students-table tr:hover {
            background: #f8f9fa;
        }

        .students-table tr.selected {
            background: #e3f2fd;
        }

        .status-badge.online {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.offline {
            background: #e9ecef;
            color: #495057;
        }

        .action-link {
            color: #667eea;
            text-decoration: none;
            margin: 0 5px;
            font-size: 14px;
            cursor: pointer;
        }

        .action-link.delete {
            color: #e74c3c;
        }

        .action-link:hover {
            text-decoration: underline;
        }

        /* CSV Import */
        .csv-import {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .csv-import h4 {
            color: #333;
            margin-bottom: 15px;
        }

        .csv-format {
            background: #e9ecef;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .file-input {
            margin-bottom: 15px;
        }

        /* Search Box */
        .search-box {
            margin-bottom: 20px;
        }

        .search-box input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
        }

        /* Reset Modal Specific */
        .warning-text {
            color: #e74c3c;
            margin: 20px 0;
            font-size: 16px;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        /* Bulk Delete Modal Specific */
        .selected-students-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            background: #f8f9fa;
        }

        .selected-student-item {
            padding: 8px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .selected-student-item:last-child {
            border-bottom: none;
        }

        .selected-student-icon {
            width: 30px;
            height: 30px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <h2>Quiz Control Panel</h2>

            <div class="status-indicator">
                <div class="status-label">Current Status</div>
                <div class="status-value <?php echo $state['status']; ?>" id="sidebar-status">
                    <?php echo strtoupper($state['status']); ?>
                </div>
            </div>

            <div class="question-list">
                <h3>Questions</h3>
                <?php foreach ($questions as $index => $q): ?>
                    <div class="question-item <?php echo ($state['current_question'] == $q['id']) ? 'active' : ''; ?>"
                        onclick="selectQuestion(<?php echo $q['id']; ?>, this)">
                        <div class="question-number">Question <?php echo $index + 1; ?></div>
                        <div class="question-text"><?php echo htmlspecialchars($q['question_text']); ?></div>
                        <span class="question-status <?php echo $q['is_active'] ? 'active' : ''; ?>">
                            <?php echo $q['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Quiz Control</h1>
                <div class="header-actions">
                    <button class="reset-btn" onclick="showResetModal()">Reset All Data</button>
                    <a href="?logout=1" class="logout-btn">Logout</a>
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
                    <button class="close-btn" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="showTab('quiz')">Quiz Control</button>
                <button class="tab" onclick="showTab('questions')">Question Management</button>
                <button class="tab" onclick="showTab('students')">Student Management</button>
            </div>

            <!-- Quiz Control Panel -->
            <div id="quiz-panel" class="panel active">
                <!-- Timer Display -->
                <div class="timer-display" id="timerDisplay" style="display: none;">
                    <div class="timer-label" id="timerLabel">Countdown</div>
                    <div class="timer-value" id="timerValue">10</div>
                </div>

                <div class="button-group">
                    <button class="btn btn-primary" onclick="checkReadiness()" id="checkReadinessBtn">
                        Check Readiness
                    </button>

                    <button class="btn btn-success" onclick="startCountdown()" id="startCountdownBtn">
                        Start Countdown
                    </button>

                    <button class="btn btn-warning" onclick="forceShowQuestion()" id="showQuestionBtn">
                        Force Show Question
                    </button>

                    <button class="btn btn-danger" onclick="showResults()" id="showResultsBtn">
                        Show Results
                    </button>

                    <button class="btn btn-primary" onclick="nextQuestion()" id="nextQuestionBtn">
                        Next Question
                    </button>
                </div>

                <!-- Selected Question Display -->
                <div style="background: #e8f4fd; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <strong>Selected Question:</strong>
                    <span id="selectedQuestionDisplay">None selected</span>
                </div>

                <!-- Students Ready Display -->
                <div class="students-ready">
                    <h3>Students Ready</h3>
                    <div class="ready-count" id="readyCount">0</div>
                    <div class="student-list" id="studentList">
                        <div style="text-align: center; color: #999; padding: 20px;">No students online</div>
                    </div>
                </div>
            </div>

            <!-- Question Management Panel -->
            <div id="questions-panel" class="panel">
                <div class="question-mgmt-header">
                    <h3>Manage Questions</h3>
                    <div class="action-buttons">
                        <button class="btn btn-success" onclick="showAddQuestionModal()">+ Add Question</button>
                    </div>
                </div>

                <!-- Questions Table -->
                <div class="questions-table-container">
                    <table class="questions-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Question</th>
                                <th>Options</th>
                                <th>Correct</th>
                                <th>Points</th>
                                <th>Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $index => $q): ?>
                                <tr>
                                    <td>#<?php echo $index + 1; ?></td>
                                    <td>
                                        <div class="question-preview"><?php echo htmlspecialchars($q['question_text']); ?></div>
                                    </td>
                                    <td>
                                        <div class="options-preview">
                                            A: <?php echo htmlspecialchars($q['option_a']); ?><br>
                                            B: <?php echo htmlspecialchars($q['option_b']); ?><br>
                                            C: <?php echo htmlspecialchars($q['option_c']); ?><br>
                                            D: <?php echo htmlspecialchars($q['option_d']); ?>
                                        </div>
                                    </td>
                                    <td><span class="correct-badge"><?php echo $q['correct_option']; ?></span></td>
                                    <td><?php echo $q['points']; ?></td>
                                    <td><?php echo $q['time_limit']; ?>s</td>
                                    <td>
                                        <span class="status-badge <?php echo $q['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $q['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($index > 0): ?>
                                                <a href="?move_up=<?php echo $q['id']; ?>" class="action-btn move">↑</a>
                                            <?php endif; ?>
                                            <?php if ($index < count($questions) - 1): ?>
                                                <a href="?move_down=<?php echo $q['id']; ?>" class="action-btn move">↓</a>
                                            <?php endif; ?>
                                            <a href="?toggle_question=<?php echo $q['id']; ?>" class="action-btn toggle">
                                                <?php echo $q['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </a>
                                            <a href="?edit_question=<?php echo $q['id']; ?>" class="action-btn edit" onclick="showEditQuestionModal(<?php echo htmlspecialchars(json_encode($q)); ?>); return false;">Edit</a>
                                            <a href="?delete_question=<?php echo $q['id']; ?>" class="action-btn delete" onclick="return confirm('Delete this question? This action cannot be undone if no answers have been submitted.')">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($questions)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                                        No questions yet. Click "Add Question" to create your first question.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Student Management Panel -->
            <div id="students-panel" class="panel">
                <div class="student-mgmt-header">
                    <h3>Manage Students</h3>
                    <div class="bulk-actions">
                        <span class="selected-count" id="selectedCount">0 selected</span>
                        <button class="btn btn-danger" onclick="showBulkDeleteModal()" id="bulkDeleteBtn" disabled>Delete Selected</button>
                        <button class="btn btn-success" onclick="showAddModal()">+ Add Student</button>
                        <button class="btn btn-info" onclick="showImportModal()">📁 Import CSV</button>
                    </div>
                </div>

                <!-- Search -->
                <div class="search-box">
                    <input type="text" id="studentSearch" placeholder="Search students by name or username..." onkeyup="searchStudents()">
                </div>

                <!-- Students Table -->
                <div class="students-table-container">
                    <form id="bulkDeleteForm" method="POST">
                        <input type="hidden" name="bulk_delete_students" value="1">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th class="checkbox-col">
                                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll()" class="select-all">
                                    </th>
                                    <th>Status</th>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Score</th>
                                    <th>Answers</th>
                                    <th>Last Active</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $last_active = strtotime($student['last_activity'] ?? '');
                                    $is_online = $last_active > (time() - 30);
                                    ?>
                                    <tr id="student-row-<?php echo $student['id']; ?>">
                                        <td class="checkbox-col">
                                            <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>" class="student-checkbox" onchange="updateSelectedCount()">
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $is_online ? 'online' : 'offline'; ?>">
                                                <?php echo $is_online ? 'Online' : 'Offline'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['username']); ?></td>
                                        <td><?php echo $student['total_points']; ?></td>
                                        <td><?php echo $student['answers_count']; ?></td>
                                        <td>
                                            <?php
                                            if ($student['last_activity']) {
                                                echo date('h:i:s A', strtotime($student['last_activity']));
                                            } else {
                                                echo 'Never';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <a class="action-link" onclick='showEditModal(<?php echo json_encode($student); ?>)'>Edit</a>
                                            <a href="?delete=<?php echo $student['id']; ?>" class="action-link delete" onclick="return confirm('Delete this student? This will also delete all their answers and scores.')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('addModal')">&times;</span>
            <h3>Add New Student</h3>
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
                    <small>Student will use this password to login</small>
                </div>
                <button type="submit" name="add_student" class="btn btn-success">Add Student</button>
            </form>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('editModal')">&times;</span>
            <h3>Edit Student</h3>
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
                <button type="submit" name="edit_student" class="btn btn-primary">Update Student</button>
            </form>
        </div>
    </div>

    <!-- Import CSV Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('importModal')">&times;</span>
            <h3>Import Students from CSV</h3>
            <div class="csv-import">
                <h4>CSV Format:</h4>
                <div class="csv-format">
                    username,name,password<br>
                    john_doe,John Doe,pass123<br>
                    jane_smith,Jane Smith,pass123
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="file-input">
                        <input type="file" name="csv_file" accept=".csv" required>
                    </div>
                    <button type="submit" name="import_csv" class="btn btn-info">Import CSV</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Question Modal -->
    <div id="addQuestionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('addQuestionModal')">&times;</span>
            <h3>Add New Question</h3>
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
                    <small>How many seconds students have to answer</small>
                </div>

                <button type="submit" name="add_question" class="btn btn-success">Add Question</button>
            </form>
        </div>
    </div>

    <!-- Edit Question Modal -->
    <div id="editQuestionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('editQuestionModal')">&times;</span>
            <h3>Edit Question</h3>
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

                <button type="submit" name="edit_question" class="btn btn-primary">Update Question</button>
            </form>
        </div>
    </div>

    <!-- Reset Confirmation Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('resetModal')">&times;</span>
            <h3>⚠️ Reset All Data</h3>
            <div class="warning-text">
                This action will:
                <ul style="margin-top: 10px; margin-left: 20px;">
                    <li>Delete all student answers</li>
                    <li>Reset all scores to zero</li>
                    <li>Clear student online status</li>
                    <li>Reset quiz to waiting state</li>
                </ul>
                <p style="margin-top: 15px; font-weight: bold;">This cannot be undone!</p>
            </div>
            <form method="POST" id="resetForm">
                <div class="modal-actions">
                    <button type="button" class="btn btn-primary" onclick="hideModal('resetModal')">Cancel</button>
                    <button type="submit" name="reset_all_data" class="btn btn-danger">Yes, Reset Everything</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Delete Confirmation Modal -->
    <div id="bulkDeleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('bulkDeleteModal')">&times;</span>
            <h3>⚠️ Delete Selected Students</h3>
            <div class="warning-text">
                You are about to delete <span id="deleteCount"></span> student(s). This will also delete:
                <ul style="margin-top: 10px; margin-left: 20px;">
                    <li>All their answers</li>
                    <li>All their scores</li>
                </ul>
                <p style="margin-top: 15px; font-weight: bold;">This action cannot be undone!</p>
            </div>

            <div class="selected-students-list" id="selectedStudentsList">
                <!-- Will be populated by JavaScript -->
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-primary" onclick="hideModal('bulkDeleteModal')">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="submitBulkDelete()">Yes, Delete Selected</button>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentQuestionId = <?php echo $state['current_question'] ?: 0; ?>;
        let selectedQuestionElement = null;
        let timerInterval;
        let stateCheckInterval;

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Highlight selected question if any
            if (currentQuestionId > 0) {
                const activeItem = document.querySelector('.question-item.active');
                if (activeItem) {
                    updateSelectedQuestionDisplay(activeItem.querySelector('.question-text').textContent);
                }
            }

            // Start state checking
            startStateChecking();
            checkReadiness();
        });

        // Tab switching
        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));

            if (tab === 'quiz') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('quiz-panel').classList.add('active');
            } else if (tab === 'questions') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('questions-panel').classList.add('active');
            } else {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('students-panel').classList.add('active');
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

        // Question modal functions
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

        // Bulk delete functions
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.student-checkbox');

            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });

            updateSelectedCount();
            highlightSelectedRows();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const count = checkboxes.length;
            const selectedCountEl = document.getElementById('selectedCount');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

            selectedCountEl.textContent = count + ' selected';
            bulkDeleteBtn.disabled = count === 0;

            highlightSelectedRows();
        }

        function highlightSelectedRows() {
            const checkboxes = document.querySelectorAll('.student-checkbox');

            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                if (checkbox.checked) {
                    row.classList.add('selected');
                } else {
                    row.classList.remove('selected');
                }
            });
        }

        function showBulkDeleteModal() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            if (checkboxes.length === 0) return;

            // Update delete count
            document.getElementById('deleteCount').textContent = checkboxes.length;

            // Populate selected students list
            const listEl = document.getElementById('selectedStudentsList');
            let html = '';

            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const name = row.cells[2].textContent; // Name is in 3rd column (index 2)
                const username = row.cells[3].textContent; // Username is in 4th column (index 3)

                html += `
                    <div class="selected-student-item">
                        <div class="selected-student-icon">${name.charAt(0)}</div>
                        <div>
                            <strong>${name}</strong><br>
                            <small>${username}</small>
                        </div>
                    </div>
                `;
            });

            listEl.innerHTML = html;

            // Show modal
            document.getElementById('bulkDeleteModal').classList.add('active');
        }

        function submitBulkDelete() {
            const form = document.getElementById('bulkDeleteForm');
            form.submit();
        }

        // Search students
        function searchStudents() {
            let input = document.getElementById('studentSearch');
            let filter = input.value.toUpperCase();
            let table = document.getElementById('studentsTableBody');
            let rows = table.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                let name = rows[i].getElementsByTagName('td')[2]; // Name is now at index 2 (after checkbox and status)
                let username = rows[i].getElementsByTagName('td')[3]; // Username is now at index 3

                if (name && username) {
                    let nameValue = name.textContent || name.innerText;
                    let usernameValue = username.textContent || username.innerText;

                    if (nameValue.toUpperCase().indexOf(filter) > -1 || usernameValue.toUpperCase().indexOf(filter) > -1) {
                        rows[i].style.display = '';
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
            }
        }

        // Quiz control functions
        function selectQuestion(id, element) {
            currentQuestionId = id;

            // Remove active class from all questions
            document.querySelectorAll('.question-item').forEach(item => {
                item.classList.remove('active');
            });

            // Add active class to selected question
            element.classList.add('active');

            // Update display
            const questionText = element.querySelector('.question-text').textContent;
            updateSelectedQuestionDisplay(questionText);
        }

        function updateSelectedQuestionDisplay(text) {
            document.getElementById('selectedQuestionDisplay').textContent = text;
        }

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
                        studentList.innerHTML = '<div style="text-align: center; color: #999; padding: 20px;">No students online</div>';
                    }
                });
        }

        function startCountdown() {
            if (!currentQuestionId) {
                alert('Please select a question first');
                return;
            }

            const selectedQuestion = document.querySelector('.question-item.active .question-text').textContent;

            if (!confirm('Start 10-second countdown for:\n\n"' + selectedQuestion + '"')) {
                return;
            }

            // Show immediate feedback
            document.getElementById('timerDisplay').style.display = 'block';
            document.getElementById('timerLabel').textContent = 'Countdown';
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
                        // Disable buttons during countdown
                        updateButtonStates('countdown');

                        // Start local countdown timer
                        let timeLeft = 10;
                        const timerInterval = setInterval(() => {
                            timeLeft--;
                            document.getElementById('timerValue').textContent = timeLeft;

                            if (timeLeft <= 0) {
                                clearInterval(timerInterval);
                            }
                        }, 1000);

                        // Show success message
                        showNotification('Countdown started!', 'success');
                    } else {
                        alert('Error starting countdown: ' + (data.error || 'Unknown error'));
                        document.getElementById('timerDisplay').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Network error starting countdown');
                    document.getElementById('timerDisplay').style.display = 'none';
                });
        }

        // Add notification function
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = 'alert ' + type;
            notification.innerHTML = message + '<button class="close-btn" onclick="this.parentElement.remove()">&times;</button>';

            const header = document.querySelector('.header');
            header.parentNode.insertBefore(notification, header.nextSibling);

            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
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
                        document.querySelectorAll('.question-item').forEach(item => {
                            item.classList.remove('active');
                        });
                        document.getElementById('selectedQuestionDisplay').textContent = 'None selected';
                        updateButtonStates('waiting');
                    }
                });
        }

        // Start checking quiz state
        function startStateChecking() {
            // Check immediately
            checkQuizState();

            // Then check every second
            stateCheckInterval = setInterval(checkQuizState, 1000);
        }

        function checkQuizState() {
            fetch('../api/get_quiz_data.php?action=state')
                .then(response => response.json())
                .then(data => {
                    // Update sidebar status
                    document.getElementById('sidebar-status').textContent = data.status.toUpperCase();
                    document.getElementById('sidebar-status').className = 'status-value ' + data.status;

                    // Update timer display
                    const timerDisplay = document.getElementById('timerDisplay');
                    const timerLabel = document.getElementById('timerLabel');
                    const timerValue = document.getElementById('timerValue');

                    if (data.status === 'countdown' || data.status === 'question') {
                        timerDisplay.style.display = 'block';
                        timerLabel.textContent = data.status === 'countdown' ? 'Countdown' : 'Question Time';
                        timerValue.textContent = Math.ceil(data.time_left);
                    } else {
                        timerDisplay.style.display = 'none';
                    }

                    // Update button states
                    updateButtonStates(data.status);

                    // Auto-refresh page when state changes to results
                    if (data.status === 'results' && timerDisplay.style.display === 'block') {
                        setTimeout(() => location.reload(), 1000);
                    }
                });
        }

        function updateButtonStates(status) {
            const checkBtn = document.getElementById('checkReadinessBtn');
            const startBtn = document.getElementById('startCountdownBtn');
            const showBtn = document.getElementById('showQuestionBtn');
            const resultsBtn = document.getElementById('showResultsBtn');
            const nextBtn = document.getElementById('nextQuestionBtn');

            // Reset all buttons to enabled first
            [checkBtn, startBtn, showBtn, resultsBtn, nextBtn].forEach(btn => {
                btn.disabled = false;
            });

            // Disable based on status
            switch (status) {
                case 'waiting':
                    // All buttons enabled except show results and next
                    resultsBtn.disabled = true;
                    nextBtn.disabled = true;
                    break;

                case 'countdown':
                    // Disable all except maybe force show
                    checkBtn.disabled = true;
                    startBtn.disabled = true;
                    resultsBtn.disabled = true;
                    nextBtn.disabled = true;
                    break;

                case 'question':
                    // Only show results enabled
                    checkBtn.disabled = true;
                    startBtn.disabled = true;
                    showBtn.disabled = true;
                    nextBtn.disabled = true;
                    break;

                case 'results':
                    // Only next question enabled
                    checkBtn.disabled = true;
                    startBtn.disabled = true;
                    showBtn.disabled = true;
                    resultsBtn.disabled = true;
                    break;
            }

            // Also disable start if no question selected
            if (!currentQuestionId) {
                startBtn.disabled = true;
                showBtn.disabled = true;
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Clean up on page unload
        window.onbeforeunload = function() {
            if (stateCheckInterval) {
                clearInterval(stateCheckInterval);
            }
            if (timerInterval) {
                clearInterval(timerInterval);
            }
        }

        // Check readiness every 2 seconds
        setInterval(checkReadiness, 2000);
    </script>
</body>

</html>