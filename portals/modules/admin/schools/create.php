<?php
// modules/admin/schools/create.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Check if editing existing school
$is_edit = isset($_GET['edit']);
$school_id = $is_edit ? (int)$_GET['edit'] : 0;
$school = null;

if ($is_edit && $school_id) {
    // Get school details
    $sql = "SELECT * FROM schools WHERE id = ?";
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
}

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Collect form data
        $form_data = [
            'name' => trim($_POST['name'] ?? ''),
            'short_name' => trim($_POST['short_name'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'country' => trim($_POST['country'] ?? 'Nigeria'),
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'contact_email' => trim($_POST['contact_email'] ?? ''),
            'contact_phone' => trim($_POST['contact_phone'] ?? ''),
            'partnership_start_date' => !empty($_POST['partnership_start_date']) ? $_POST['partnership_start_date'] : date('Y-m-d'),
            'partnership_status' => $_POST['partnership_status'] ?? 'pending',
            'notes' => trim($_POST['notes'] ?? '')
        ];

        // Validate required fields
        $required_fields = ['name'];
        
        foreach ($required_fields as $field) {
            if (empty($form_data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        // Validate email if provided
        if (!empty($form_data['contact_email']) && !isValidEmail($form_data['contact_email'])) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Check if school name already exists (for new schools or if name changed)
        if (!empty($form_data['name'])) {
            $check_sql = "SELECT id FROM schools WHERE name = ?";
            if ($is_edit) {
                $check_sql .= " AND id != ?";
            }
            
            $check_stmt = $conn->prepare($check_sql);
            if ($is_edit) {
                $check_stmt->bind_param("si", $form_data['name'], $school_id);
            } else {
                $check_stmt->bind_param("s", $form_data['name']);
            }
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors[] = 'School name already exists in the system.';
            }
        }

        // If no errors, process the form
        if (empty($errors)) {
            if ($is_edit) {
                // Update existing school
                $sql = "UPDATE schools SET 
                        name = ?, short_name = ?, address = ?, city = ?, state = ?, country = ?,
                        contact_person = ?, contact_email = ?, contact_phone = ?,
                        partnership_start_date = ?, partnership_status = ?, notes = ?
                        WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssssssi", 
                    $form_data['name'], $form_data['short_name'], $form_data['address'],
                    $form_data['city'], $form_data['state'], $form_data['country'],
                    $form_data['contact_person'], $form_data['contact_email'], $form_data['contact_phone'],
                    $form_data['partnership_start_date'], $form_data['partnership_status'],
                    $form_data['notes'], $school_id
                );
                
                if ($stmt->execute()) {
                    logActivity('school_update', "Updated school #$school_id", 'schools', $school_id);
                    $_SESSION['success'] = 'School updated successfully!';
                    $success = true;
                    
                    // Refresh school data
                    $school = array_merge($school, $form_data);
                } else {
                    $errors[] = 'Failed to update school: ' . $conn->error;
                }
            } else {
                // Create new school
                $sql = "INSERT INTO schools 
                        (name, short_name, address, city, state, country,
                         contact_person, contact_email, contact_phone,
                         partnership_start_date, partnership_status, notes) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssssss", 
                    $form_data['name'], $form_data['short_name'], $form_data['address'],
                    $form_data['city'], $form_data['state'], $form_data['country'],
                    $form_data['contact_person'], $form_data['contact_email'], $form_data['contact_phone'],
                    $form_data['partnership_start_date'], $form_data['partnership_status'],
                    $form_data['notes']
                );
                
                if ($stmt->execute()) {
                    $new_school_id = $conn->insert_id;
                    
                    logActivity('school_create', "Created new school #$new_school_id", 'schools', $new_school_id);
                    
                    // Show success message and redirect
                    $_SESSION['success'] = 'School created successfully!';
                    header("Location: view.php?id=$new_school_id");
                    exit();
                } else {
                    $errors[] = 'Failed to create school: ' . $conn->error;
                }
            }
        }
    }
}

// If editing, pre-fill form with school data
if ($is_edit && $school && empty($_POST)) {
    $_POST = array_merge($_POST, $school);
}

// Log activity
logActivity('school_form_access', $is_edit ? "Accessed edit school form #$school_id" : "Accessed create school form");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit School' : 'Create School'; ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header */
        .header {
            margin-bottom: 2rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.75rem;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
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
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .form-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .form-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .form-content {
            padding: 2rem;
        }

        /* Form Layout */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }

        .required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.95rem;
            background: white;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-control.invalid {
            border-color: var(--danger);
        }

        .form-text {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        .error-message {
            color: var(--danger);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .error-message i {
            font-size: 0.75rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert ul {
            margin: 0.5rem 0 0 1.5rem;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 2rem;
            border-top: 2px solid var(--light-gray);
            margin-top: 2rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
                gap: 1rem;
            }

            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="manage.php">Schools</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo $is_edit ? 'Edit School' : 'Create School'; ?></span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1><?php echo $is_edit ? 'Edit School' : 'Create New School'; ?></h1>
            <div class="page-actions">
                <a href="manage.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Schools
                </a>
                <?php if ($is_edit): ?>
                    <a href="view.php?id=<?php echo $school_id; ?>" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View School
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success'] ?? ''); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Please fix the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <div class="form-header">
                <h2><?php echo $is_edit ? 'Update School Information' : 'Add New Partner School'; ?></h2>
                <p>Fill in the school details below. Fields marked with * are required.</p>
            </div>

            <div class="form-content">
                <form method="POST" id="schoolForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- Basic Information Section -->
                    <div class="section-title">
                        <h3>Basic Information</h3>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name" class="required">School Name</label>
                            <input type="text" id="name" name="name" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                   required
                                   placeholder="Enter full school name"
                                   maxlength="255">
                            <div class="form-text">
                                The official name of the school
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="short_name">Short Name / Abbreviation</label>
                            <input type="text" id="short_name" name="short_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['short_name'] ?? ''); ?>" 
                                   placeholder="e.g., SHS, GHS, ABC Academy"
                                   maxlength="100">
                            <div class="form-text">
                                Abbreviation or shorter name for display
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="section-title">
                        <h3>Contact Information</h3>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="contact_person">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>" 
                                   placeholder="Name of primary contact"
                                   maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_email">Contact Email</label>
                            <input type="email" id="contact_email" name="contact_email" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['contact_email'] ?? ''); ?>" 
                                   placeholder="contact@school.edu.ng"
                                   maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_phone">Contact Phone</label>
                            <input type="tel" id="contact_phone" name="contact_phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['contact_phone'] ?? ''); ?>" 
                                   placeholder="+234 800 000 0000"
                                   maxlength="20">
                        </div>
                    </div>

                    <!-- Location Information Section -->
                    <div class="section-title">
                        <h3>Location Information</h3>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Full Address</label>
                        <textarea id="address" name="address" class="form-control" 
                                  placeholder="Enter full school address"
                                  rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" 
                                   placeholder="City"
                                   maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="state">State/Province</label>
                            <input type="text" id="state" name="state" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>" 
                                   placeholder="State"
                                   maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="country">Country</label>
                            <input type="text" id="country" name="country" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['country'] ?? 'Nigeria'); ?>" 
                                   placeholder="Country"
                                   maxlength="100">
                        </div>
                    </div>

                    <!-- Partnership Information Section -->
                    <div class="section-title">
                        <h3>Partnership Information</h3>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="partnership_start_date">Partnership Start Date</label>
                            <input type="date" id="partnership_start_date" name="partnership_start_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['partnership_start_date'] ?? date('Y-m-d')); ?>"
                                   max="<?php echo date('Y-m-d'); ?>">
                            <div class="form-text">
                                When the partnership with this school began
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="partnership_status" class="required">Partnership Status</label>
                            <select id="partnership_status" name="partnership_status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="active" <?php echo ($_POST['partnership_status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="pending" <?php echo ($_POST['partnership_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="expired" <?php echo ($_POST['partnership_status'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="terminated" <?php echo ($_POST['partnership_status'] ?? '') === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                            </select>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="section-title">
                        <h3>Additional Information</h3>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes & Additional Information</label>
                        <textarea id="notes" name="notes" class="form-control" 
                                  placeholder="Any additional notes about the school or partnership..."
                                  rows="4"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                        <div class="form-text">
                            Internal notes about the school, partnership terms, special agreements, etc.
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <div>
                            <a href="manage.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                        
                        <div>
                            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?php echo $is_edit ? 'Update School' : 'Create School'; ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Form validation
        document.getElementById('schoolForm').addEventListener('submit', function(e) {
            let isValid = true;
            
            // Check required fields
            const requiredFields = this.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('invalid');
                    isValid = false;
                } else {
                    field.classList.remove('invalid');
                }
            });
            
            // Check email format
            const emailField = document.getElementById('contact_email');
            if (emailField.value) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(emailField.value)) {
                    emailField.classList.add('invalid');
                    isValid = false;
                } else {
                    emailField.classList.remove('invalid');
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields correctly.');
                return false;
            }
            
            // Confirm if terminating partnership
            const statusField = document.getElementById('partnership_status');
            if (statusField.value === 'terminated') {
                if (!confirm('Are you sure you want to terminate this partnership? This action may affect associated programs and users.')) {
                    e.preventDefault();
                    return false;
                }
            }
        });

        // Real-time validation
        document.getElementById('contact_email')?.addEventListener('input', function() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (this.value && !emailRegex.test(this.value)) {
                this.classList.add('invalid');
            } else {
                this.classList.remove('invalid');
            }
        });

        // Reset form
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All unsaved changes will be lost.')) {
                document.getElementById('schoolForm').reset();
                // Clear any invalid states
                document.querySelectorAll('.form-control.invalid').forEach(el => {
                    el.classList.remove('invalid');
                });
            }
        }

        // Auto-focus first field
        document.getElementById('name').focus();

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('button[type="submit"]').click();
            }
        });
    </script>
</body>
</html>