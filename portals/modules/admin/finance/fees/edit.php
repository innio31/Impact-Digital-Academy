<?php
// modules/admin/finance/fees/edit.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get fee structure ID
$fee_structure_id = $_GET['id'] ?? 0;
if (!$fee_structure_id) {
    $_SESSION['error'] = "No fee structure specified";
    header('Location: index.php');
    exit();
}

// Get fee structure details
$stmt = $conn->prepare("
    SELECT fs.*, p.name as program_name, p.program_code, p.program_type 
    FROM fee_structures fs 
    JOIN programs p ON p.id = fs.program_id 
    WHERE fs.id = ?
");
$stmt->bind_param("i", $fee_structure_id);
$stmt->execute();
$fee_structure = $stmt->get_result()->fetch_assoc();

if (!$fee_structure) {
    $_SESSION['error'] = "Fee structure not found";
    header('Location: index.php');
    exit();
}

// Get all programs for dropdown
$programs_sql = "SELECT * FROM programs WHERE status = 'active' ORDER BY name";
$programs_result = $conn->query($programs_sql);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $program_id = $_POST['program_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $total_amount = $_POST['total_amount'] ?? 0;
        $registration_fee = $_POST['registration_fee'] ?? 0;
        $block1_amount = $_POST['block1_amount'] ?? 0;
        $block2_amount = $_POST['block2_amount'] ?? 0;
        $block3_amount = $_POST['block3_amount'] ?? 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate total amount matches sum of blocks + registration
        $calculated_total = $registration_fee + $block1_amount + $block2_amount + $block3_amount;

        if (abs($total_amount - $calculated_total) > 0.01) {
            $_SESSION['error'] = "Total amount (₦" . number_format($total_amount, 2) .
                ") does not match sum of registration fee and blocks (₦" .
                number_format($calculated_total, 2) . ")";
        } else {
            $sql = "UPDATE fee_structures SET 
                    program_id = ?, 
                    name = ?, 
                    description = ?, 
                    total_amount = ?, 
                    registration_fee = ?, 
                    block1_amount = ?, 
                    block2_amount = ?, 
                    block3_amount = ?, 
                    is_active = ?, 
                    updated_by = ? 
                    WHERE id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "issdddddiii",
                $program_id,
                $name,
                $description,
                $total_amount,
                $registration_fee,
                $block1_amount,
                $block2_amount,
                $block3_amount,
                $is_active,
                $_SESSION['user_id'],
                $fee_structure_id
            );

            if ($stmt->execute()) {
                $_SESSION['success'] = "Fee structure updated successfully";
                header("Location: index.php");
                exit();
            } else {
                $_SESSION['error'] = "Failed to update fee structure: " . $stmt->error;
            }
        }
    } elseif ($action === 'delete') {
        // Check if any students are using this fee structure
        $check_sql = "SELECT COUNT(*) as count FROM student_financial_status sfs
                     JOIN enrollments e ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
                     JOIN class_batches cb ON cb.id = e.class_id
                     JOIN programs p ON p.id = cb.course_id
                     WHERE p.id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $fee_structure['program_id']);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            $_SESSION['error'] = "Cannot delete fee structure. There are " . $result['count'] .
                " students using this program. Please deactivate instead.";
        } else {
            $sql = "DELETE FROM fee_structures WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $fee_structure_id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Fee structure deleted successfully";
                header("Location: index.php");
                exit();
            } else {
                $_SESSION['error'] = "Failed to delete fee structure: " . $stmt->error;
            }
        }
    } elseif ($action === 'clone') {
        $new_name = $_POST['new_name'] ?? ($fee_structure['name'] . ' (Copy)');

        $sql = "INSERT INTO fee_structures (program_id, name, description, total_amount, 
                                           registration_fee, block1_amount, block2_amount, 
                                           block3_amount, is_active, created_by) 
                SELECT program_id, ?, description, total_amount, registration_fee, 
                       block1_amount, block2_amount, block3_amount, 0, ? 
                FROM fee_structures WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $new_name, $_SESSION['user_id'], $fee_structure_id);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $_SESSION['success'] = "Fee structure cloned successfully";
            header("Location: edit.php?id=" . $new_id);
            exit();
        } else {
            $_SESSION['error'] = "Failed to clone fee structure: " . $stmt->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Fee Structure - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reuse styles from index.php */
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --dark-light: #334155;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .back-link {
            display: inline-block;
            margin-bottom: 1rem;
            color: var(--primary);
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .edit-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .edit-card h3 {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            color: var(--dark);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 0.75rem;
        }

        .col-md-4 {
            flex: 0 0 33.333%;
            max-width: 33.333%;
            padding: 0 0.75rem;
        }

        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding: 0 0.75rem;
        }

        .col-md-2 {
            flex: 0 0 16.666%;
            max-width: 16.666%;
            padding: 0 0.75rem;
        }

        .btn {
            padding: 0.5rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .amount {
            font-weight: bold;
            color: var(--primary);
        }

        .total-summary {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border: 1px solid #e2e8f0;
        }

        .total-summary h4 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .total-display {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .total-display .label {
            color: #64748b;
        }

        .total-display .value {
            font-weight: bold;
            font-size: 1.1rem;
        }

        .total-match {
            color: var(--success);
        }

        .total-mismatch {
            color: var(--danger);
        }

        .toggle-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.slider {
            background-color: var(--success);
        }

        input:checked+.slider:before {
            transform: translateX(26px);
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }
    </style>
    <script>
        function calculateTotal() {
            const regFee = parseFloat(document.getElementById('registration_fee').value) || 0;
            const block1 = parseFloat(document.getElementById('block1_amount').value) || 0;
            const block2 = parseFloat(document.getElementById('block2_amount').value) || 0;
            const block3 = parseFloat(document.getElementById('block3_amount').value) || 0;

            const total = regFee + block1 + block2 + block3;
            const inputTotal = parseFloat(document.getElementById('total_amount').value) || 0;

            document.getElementById('calculated_total').textContent = total.toFixed(2);
            document.getElementById('input_total').textContent = inputTotal.toFixed(2);

            const matchElement = document.getElementById('total_match');
            if (Math.abs(total - inputTotal) < 0.01) {
                matchElement.textContent = '✓ Totals match';
                matchElement.className = 'total-match';
            } else {
                matchElement.textContent = '✗ Totals do not match';
                matchElement.className = 'total-mismatch';
            }
        }

        function confirmDelete() {
            return confirm('Are you sure you want to delete this fee structure? This action cannot be undone.');
        }

        function cloneStructure() {
            const newName = prompt('Enter a name for the cloned fee structure:', '<?= $fee_structure['name'] ?> (Copy)');
            if (newName) {
                document.getElementById('new_name').value = newName;
                document.getElementById('clone_form').submit();
            }
        }
    </script>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar would be included from common template -->
        <div class="main-content">
            <div class="header">
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Fee Structures
                </a>
                <h1>
                    <i class="fas fa-edit"></i>
                    Edit Fee Structure: <?= htmlspecialchars($fee_structure['name']) ?>
                </h1>
                <p>Program: <?= htmlspecialchars($fee_structure['program_name']) ?> (<?= $fee_structure['program_code'] ?>)</p>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= $_SESSION['warning'];
                    unset($_SESSION['warning']); ?>
                </div>
            <?php endif; ?>

            <!-- Edit Fee Structure Form -->
            <div class="edit-card">
                <h3><i class="fas fa-edit"></i> Edit Fee Structure Details</h3>
                <form method="POST" id="edit_form">
                    <input type="hidden" name="action" value="update">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Program</label>
                                <select name="program_id" class="form-control" required>
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= $program['id'] ?>"
                                            <?= $program['id'] == $fee_structure['program_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($program['name']) ?> (<?= $program['program_code'] ?>)
                                            - <?= strtoupper($program['program_type']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Structure Name *</label>
                                <input type="text" name="name" class="form-control"
                                    value="<?= htmlspecialchars($fee_structure['name']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($fee_structure['description'] ?? '') ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Total Amount (₦) *</label>
                                <input type="number" id="total_amount" name="total_amount" class="form-control"
                                    value="<?= $fee_structure['total_amount'] ?>"
                                    step="0.01" min="0" required oninput="calculateTotal()">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Registration Fee (₦) *</label>
                                <input type="number" id="registration_fee" name="registration_fee" class="form-control"
                                    value="<?= $fee_structure['registration_fee'] ?>"
                                    step="0.01" min="0" required oninput="calculateTotal()">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Block 1 (₦) *</label>
                                <input type="number" id="block1_amount" name="block1_amount" class="form-control"
                                    value="<?= $fee_structure['block1_amount'] ?>"
                                    step="0.01" min="0" required oninput="calculateTotal()">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Block 2 (₦)</label>
                                <input type="number" id="block2_amount" name="block2_amount" class="form-control"
                                    value="<?= $fee_structure['block2_amount'] ?? 0 ?>"
                                    step="0.01" min="0" oninput="calculateTotal()">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Block 3 (₦)</label>
                                <input type="number" id="block3_amount" name="block3_amount" class="form-control"
                                    value="<?= $fee_structure['block3_amount'] ?? 0 ?>"
                                    step="0.01" min="0" oninput="calculateTotal()">
                            </div>
                        </div>
                    </div>

                    <div class="total-summary">
                        <h4>Total Verification</h4>
                        <div class="total-display">
                            <span class="label">Registration + Blocks Total:</span>
                            <span class="value" id="calculated_total"><?= number_format($fee_structure['registration_fee'] + $fee_structure['block1_amount'] + ($fee_structure['block2_amount'] ?? 0) + ($fee_structure['block3_amount'] ?? 0), 2) ?></span>
                        </div>
                        <div class="total-display">
                            <span class="label">Input Total Amount:</span>
                            <span class="value" id="input_total"><?= number_format($fee_structure['total_amount'], 2) ?></span>
                        </div>
                        <div class="total-display">
                            <span class="label">Status:</span>
                            <span class="value" id="total_match"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="toggle-group">
                            <label class="toggle-switch">
                                <input type="checkbox" name="is_active" value="1"
                                    <?= $fee_structure['is_active'] ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <span>Active</span>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>

                        <a href="configure.php?id=<?= $fee_structure_id ?>" class="btn btn-info">
                            <i class="fas fa-cogs"></i> Configure Payment Plan
                        </a>

                        <button type="button" class="btn btn-warning" onclick="cloneStructure()">
                            <i class="fas fa-copy"></i> Clone Structure
                        </button>

                        <button type="button" class="btn btn-danger" onclick="if(confirmDelete()) { document.getElementById('delete_form').submit(); }">
                            <i class="fas fa-trash"></i> Delete Structure
                        </button>

                        <a href="index.php" class="btn">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>

                <!-- Hidden forms for other actions -->
                <form method="POST" id="delete_form" style="display: none;">
                    <input type="hidden" name="action" value="delete">
                </form>

                <form method="POST" id="clone_form" style="display: none;">
                    <input type="hidden" name="action" value="clone">
                    <input type="hidden" name="new_name" id="new_name">
                </form>
            </div>

            <!-- Usage Statistics -->
            <div class="edit-card">
                <h3><i class="fas fa-chart-bar"></i> Usage Statistics</h3>
                <?php
                // Get usage statistics
                $usage_sql = "SELECT 
                    COUNT(DISTINCT sfs.student_id) as total_students,
                    COUNT(DISTINCT sfs.class_id) as total_classes,
                    SUM(CASE WHEN sfs.is_cleared = 1 THEN 1 ELSE 0 END) as cleared_students,
                    SUM(CASE WHEN sfs.is_suspended = 1 THEN 1 ELSE 0 END) as suspended_students
                    FROM student_financial_status sfs
                    JOIN enrollments e ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
                    JOIN class_batches cb ON cb.id = e.class_id
                    JOIN programs p ON p.id = cb.course_id
                    WHERE p.id = ?";

                $usage_stmt = $conn->prepare($usage_sql);
                $usage_stmt->bind_param("i", $fee_structure['program_id']);
                $usage_stmt->execute();
                $usage_stats = $usage_stmt->get_result()->fetch_assoc();
                ?>

                <div class="row">
                    <div class="col-md-3">
                        <div class="total-summary">
                            <h4>Total Students</h4>
                            <div class="total-display">
                                <span class="value"><?= $usage_stats['total_students'] ?? 0 ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="total-summary">
                            <h4>Total Classes</h4>
                            <div class="total-display">
                                <span class="value"><?= $usage_stats['total_classes'] ?? 0 ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="total-summary">
                            <h4>Cleared Students</h4>
                            <div class="total-display">
                                <span class="value total-match"><?= $usage_stats['cleared_students'] ?? 0 ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="total-summary">
                            <h4>Suspended Students</h4>
                            <div class="total-display">
                                <span class="value total-mismatch"><?= $usage_stats['suspended_students'] ?? 0 ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (($usage_stats['total_students'] ?? 0) > 0): ?>
                    <div class="alert alert-warning" style="margin-top: 1rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        This fee structure is currently being used by <?= $usage_stats['total_students'] ?> students.
                        Consider creating a new version instead of editing if changes affect existing students.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Related Actions -->
            <div class="edit-card">
                <h3><i class="fas fa-link"></i> Related Actions</h3>
                <div class="row">
                    <div class="col-md-6">
                        <div class="total-summary">
                            <h4>Program Management</h4>
                            <a href="../../academic/programs/view.php?id=<?= $fee_structure['program_id'] ?>"
                                class="btn btn-info" style="display: block; margin-bottom: 0.5rem;">
                                <i class="fas fa-external-link-alt"></i> View Program Details
                            </a>
                            <a href="../../academic/programs/edit.php?id=<?= $fee_structure['program_id'] ?>"
                                class="btn btn-warning" style="display: block; margin-bottom: 0.5rem;">
                                <i class="fas fa-edit"></i> Edit Program
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="total-summary">
                            <h4>Financial Management</h4>
                            <a href="../students/index.php?program_id=<?= $fee_structure['program_id'] ?>"
                                class="btn btn-success" style="display: block; margin-bottom: 0.5rem;">
                                <i class="fas fa-users"></i> View Student Finances
                            </a>
                            <a href="../invoices/index.php?program_id=<?= $fee_structure['program_id'] ?>"
                                class="btn btn-primary" style="display: block; margin-bottom: 0.5rem;">
                                <i class="fas fa-file-invoice"></i> View Program Invoices
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Initialize the total calculation on page load
        calculateTotal();
    </script>
</body>

</html>
<?php $conn->close(); ?>