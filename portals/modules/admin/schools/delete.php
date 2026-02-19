<?php
// modules/admin/schools/delete.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get school ID
$school_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$school_id) {
    $_SESSION['error'] = 'School ID is required';
    header('Location: manage.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Get school details
$sql = "SELECT s.*, 
               COUNT(p.id) as program_count,
               COUNT(u.id) as user_count
        FROM schools s
        LEFT JOIN programs p ON s.id = p.school_id
        LEFT JOIN users u ON s.id = u.school_id
        WHERE s.id = ?
        GROUP BY s.id";
        
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
$school = $result->fetch_assoc();

if (!$school) {
    $_SESSION['error'] = 'School not found';
    header('Location: manage.php');
    exit();
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token';
    } else {
        // Check if school has associated programs or users
        if ($school['program_count'] > 0 || $school['user_count'] > 0) {
            $_SESSION['error'] = 'Cannot delete school that has associated programs or users. Please reassign or delete them first.';
        } else {
            // Delete school
            $delete_sql = "DELETE FROM schools WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $school_id);
            
            if ($delete_stmt->execute()) {
                logActivity('school_delete', "Deleted school #$school_id: " . $school['name'], 'schools', $school_id);
                $_SESSION['success'] = 'School deleted successfully!';
                header('Location: manage.php');
                exit();
            } else {
                $_SESSION['error'] = 'Failed to delete school: ' . $conn->error;
            }
        }
    }
    header('Location: delete.php?id=' . $school_id);
    exit();
}

// Log activity
logActivity('school_delete_view', "Accessed delete school page #$school_id", 'schools', $school_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete School - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --danger: #ef4444;
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .delete-container {
            max-width: 600px;
            width: 100%;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .delete-header {
            padding: 2rem;
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white;
            text-align: center;
        }

        .delete-header i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .delete-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .delete-content {
            padding: 2rem;
        }

        .school-info {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-item {
            margin-bottom: 0.75rem;
        }

        .info-label {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .info-value {
            color: var(--dark);
            font-size: 1.1rem;
            font-weight: 500;
        }

        .warning-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .warning-box h4 {
            color: #92400e;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--danger);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .delete-actions {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            flex: 1;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 1px solid #e5e7eb;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--danger);
        }

        form {
            margin: 0;
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .delete-actions {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="delete-container">
        <div class="delete-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h2>Delete School</h2>
            <p>This action cannot be undone</p>
        </div>

        <div class="delete-content">
            <div class="school-info">
                <div class="info-item">
                    <div class="info-label">School Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($school['name']); ?></div>
                </div>
                <?php if ($school['short_name']): ?>
                    <div class="info-item">
                        <div class="info-label">Short Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($school['short_name']); ?></div>
                    </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-label">Partnership Status</div>
                    <div class="info-value"><?php echo ucfirst($school['partnership_status']); ?></div>
                </div>
            </div>

            <?php if ($school['program_count'] > 0 || $school['user_count'] > 0): ?>
                <div class="warning-box">
                    <h4><i class="fas fa-exclamation-circle"></i> Cannot Delete School</h4>
                    <p>This school cannot be deleted because it has associated programs or users.</p>
                    
                    <div class="stats-grid">
                        <?php if ($school['program_count'] > 0): ?>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $school['program_count']; ?></div>
                                <div class="stat-label">Associated Programs</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($school['user_count'] > 0): ?>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $school['user_count']; ?></div>
                                <div class="stat-label">Associated Users</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <p style="margin-top: 1rem; color: #92400e;">
                        <strong>Action Required:</strong> Before deleting this school, you must:
                    </p>
                    <ul style="margin-top: 0.5rem; padding-left: 1.5rem; color: #92400e;">
                        <?php if ($school['program_count'] > 0): ?>
                            <li>Delete or reassign all associated programs</li>
                        <?php endif; ?>
                        <?php if ($school['user_count'] > 0): ?>
                            <li>Delete or reassign all associated users</li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="warning-box">
                    <h4><i class="fas fa-exclamation-circle"></i> Warning: Permanent Deletion</h4>
                    <p>You are about to permanently delete this school from the system. This action will:</p>
                    <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                        <li>Permanently remove the school record</li>
                        <li>Delete all school information and notes</li>
                        <li>Remove the school from all reports</li>
                        <li>This action cannot be undone</li>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="delete-actions">
                <a href="view.php?id=<?php echo $school_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Cancel
                </a>
                
                <?php if ($school['program_count'] == 0 && $school['user_count'] == 0): ?>
                    <form method="POST" onsubmit="return confirmDeletion()">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete School Permanently
                        </button>
                    </form>
                <?php else: ?>
                    <a href="manage.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Schools
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function confirmDeletion() {
            const schoolName = "<?php echo addslashes($school['name']); ?>";
            return confirm(`WARNING: You are about to permanently delete "${schoolName}".\n\nThis action cannot be undone. Are you absolutely sure?`);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Esc to cancel
            if (e.key === 'Escape') {
                e.preventDefault();
                window.location.href = 'view.php?id=<?php echo $school_id; ?>';
            }
            // Ctrl+Enter to submit (if form exists)
            if (e.ctrlKey && e.key === 'Enter') {
                const form = document.querySelector('form');
                if (form && <?php echo ($school['program_count'] == 0 && $school['user_count'] == 0) ? 'true' : 'false'; ?>) {
                    e.preventDefault();
                    if (confirmDeletion()) {
                        form.submit();
                    }
                }
            }
        });
    </script>
</body>
</html>