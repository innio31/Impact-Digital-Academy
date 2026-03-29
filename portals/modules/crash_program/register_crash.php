<?php
// modules/crash_program/register_crash.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is already logged in
$is_logged_in = isLoggedIn();

// Get program settings
$conn = getDBConnection();

// Get total spots and current registrations with confirmed payments
$spots_sql = "SELECT setting_value FROM crash_program_settings WHERE setting_key = 'total_spots'";
$spots_result = $conn->query($spots_sql);
$total_spots = $spots_result->fetch_assoc()['setting_value'] ?? 50;

// Count confirmed registrations (only those with payment confirmed)
$count_sql = "SELECT COUNT(*) as count FROM crash_program_registrations WHERE payment_status = 'confirmed' AND status = 'payment_confirmed'";
$count_result = $conn->query($count_sql);
$confirmed_count = $count_result->fetch_assoc()['count'] ?? 0;

$spots_left = $total_spots - $confirmed_count;
$registration_closed = $spots_left <= 0;

// Get program dates
$dates_sql = "SELECT setting_key, setting_value FROM crash_program_settings WHERE setting_key IN ('program_start_date', 'program_end_date', 'program_fee')";
$dates_result = $conn->query($dates_sql);
$program_dates = [];
while ($row = $dates_result->fetch_assoc()) {
    $program_dates[$row['setting_key']] = $row['setting_value'];
}

$start_date = date('F j, Y', strtotime($program_dates['program_start_date'] ?? '2026-04-13'));
$end_date = date('F j, Y', strtotime($program_dates['program_end_date'] ?? '2026-04-24'));
$program_fee = number_format($program_dates['program_fee'] ?? 10000, 2);

// Handle form submission
$errors = [];
$success = false;
$registration_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$registration_closed) {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Collect and validate form data
        $form_data = [
            'email' => trim($_POST['email'] ?? ''),
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'program_choice' => $_POST['program_choice'] ?? '',
            'school_name' => trim($_POST['school_name'] ?? ''),
            'school_class' => trim($_POST['school_class'] ?? ''),
            'is_student' => isset($_POST['is_student']) ? 1 : 0,
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'how_heard' => trim($_POST['how_heard'] ?? '')
        ];

        // Validate required fields
        $required_fields = ['email', 'first_name', 'last_name', 'phone', 'program_choice'];
        foreach ($required_fields as $field) {
            if (empty($form_data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        // Validate email
        if (!empty($form_data['email']) && !isValidEmail($form_data['email'])) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Check if email already registered with confirmed payment
        $check_sql = "SELECT id, payment_status FROM crash_program_registrations WHERE email = ? AND payment_status = 'confirmed'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('s', $form_data['email']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $errors[] = 'This email has already been registered and payment confirmed.';
        }

        // Validate phone format
        if (!empty($form_data['phone']) && !preg_match('/^[0-9+\-\s]{10,15}$/', $form_data['phone'])) {
            $errors[] = 'Please enter a valid phone number (10-15 digits).';
        }

        // Check spots again before inserting
        $current_count_sql = "SELECT COUNT(*) as count FROM crash_program_registrations WHERE payment_status = 'confirmed'";
        $current_result = $conn->query($current_count_sql);
        $current_count = $current_result->fetch_assoc()['count'] ?? 0;

        if ($current_count >= $total_spots) {
            $errors[] = 'Sorry, all 50 spots have been filled. Registration is now closed.';
        }

        // If no errors, insert registration
        if (empty($errors)) {
            $insert_sql = "INSERT INTO crash_program_registrations 
                          (email, first_name, last_name, phone, program_choice, school_name, school_class, is_student, address, city, state, how_heard, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment')";

            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param(
                'sssssssissss',
                $form_data['email'],
                $form_data['first_name'],
                $form_data['last_name'],
                $form_data['phone'],
                $form_data['program_choice'],
                $form_data['school_name'],
                $form_data['school_class'],
                $form_data['is_student'],
                $form_data['address'],
                $form_data['city'],
                $form_data['state'],
                $form_data['how_heard']
            );

            if ($stmt->execute()) {
                $registration_id = $stmt->insert_id;
                $success = true;

                // Send welcome email to user
                require_once __DIR__ . '/../../includes/email_functions.php';
                sendCrashProgramRegistrationEmail($form_data, $registration_id);

                // Send admin notification
                sendCrashProgramAdminNotification($form_data, $registration_id);

                // Store registration ID in session for payment page
                $_SESSION['crash_registration_id'] = $registration_id;
                $_SESSION['crash_registration_email'] = $form_data['email'];
                $_SESSION['crash_program_choice'] = $form_data['program_choice'];

                // Redirect to payment page after 3 seconds
                header("Refresh: 3; url=" . BASE_URL . "modules/crash_program/confirm_payment.php?id=" . $registration_id);
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
            $stmt->close();
        }
    }
}

// Get current spots count for display
$spots_sql = "SELECT COUNT(*) as confirmed FROM crash_program_registrations WHERE payment_status = 'confirmed'";
$spots_result = $conn->query($spots_sql);
$confirmed_spots = $spots_result->fetch_assoc()['confirmed'] ?? 0;
$spots_available = $total_spots - $confirmed_spots;

// Get institution settings
$inst_name = "Impact Digital Academy";
$inst_tagline = "Empowering Digital Futures";
$inst_logo = BASE_URL . "images/logo.png"; // Update with actual logo path
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Crash Program Registration - <?php echo $inst_name; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="../../../images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --secondary: #7c3aed;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-600: #475569;
            --gray-700: #334155;
            --orange-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --blue-gradient: linear-gradient(135deg, #3b82f6, #1e40af);
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #6d28d9);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 1.5rem;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        /* Header with Institution Branding */
        .institution-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding: 1rem;
        }

        .institution-logo {
            margin-bottom: 1rem;
        }

        .institution-logo img {
            max-height: 80px;
            width: auto;
        }

        .institution-name {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
            letter-spacing: -0.5px;
        }

        .institution-tagline {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
            margin-top: 0.25rem;
        }

        /* Program Hero Image */
        .program-hero {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .hero-content {
            display: flex;
            flex-direction: column;
            position: relative;
            min-height: 200px;
        }

        @media (min-width: 768px) {
            .hero-content {
                flex-direction: row;
                min-height: 280px;
            }
        }

        .hero-text {
            flex: 1;
            padding: 2rem;
            color: white;
            z-index: 2;
        }

        .hero-text .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-bottom: 1rem;
        }

        .hero-text h2 {
            font-size: clamp(1.5rem, 4vw, 2.2rem);
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .hero-text p {
            font-size: 1rem;
            margin-bottom: 1rem;
            opacity: 0.95;
        }

        .hero-features {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }

        .hero-features span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 0.5rem 1rem;
            border-radius: 30px;
        }

        .hero-image {
            flex: 0.8;
            position: relative;
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .hero-image img {
            max-width: 100%;
            height: auto;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.2));
        }

        @media (max-width: 768px) {
            .hero-image {
                padding: 1rem;
            }

            .hero-image img {
                max-width: 150px;
            }
        }

        /* Spots Counter */
        .spots-counter {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 1.5rem;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .spots-counter .spots-number {
            font-size: 2rem;
            font-weight: 700;
            display: block;
        }

        .spots-counter .spots-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .spots-counter.warning {
            background: rgba(245, 158, 11, 0.4);
        }

        .spots-counter.critical {
            background: rgba(239, 68, 68, 0.4);
        }

        /* Registration Card */
        .card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1.5rem;
            text-align: center;
        }

        .card-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .card-header .program-dates {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .card-content {
            padding: 2rem;
        }

        /* Program Options */
        .program-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .program-card {
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
        }

        .program-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .program-card.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(30, 64, 175, 0.05));
        }

        .program-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .program-card h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .program-card p {
            font-size: 0.85rem;
            color: var(--gray-600);
        }

        .program-badge {
            position: absolute;
            top: -10px;
            right: 10px;
            background: var(--accent);
            color: white;
            font-size: 0.7rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
        }

        @media (max-width: 640px) {
            .program-options {
                grid-template-columns: 1fr;
            }
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--gray-700);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .card-content {
                padding: 1.5rem;
            }
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.5rem;
            margin-bottom: 1rem;
        }

        .checkbox-group input {
            width: 20px;
            height: 20px;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 1rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .closed-message {
            text-align: center;
            padding: 3rem;
        }

        .closed-message i {
            font-size: 4rem;
            color: var(--danger);
            margin-bottom: 1rem;
        }

        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--gray-600);
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .program-fee {
            background: var(--gray-100);
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .program-fee .fee-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: 2rem;
            padding: 1rem;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.85rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Institution Header -->
        <div class="institution-header">
            <div class="institution-logo">
                <img src="<?php echo $inst_logo; ?>" alt="<?php echo $inst_name; ?>" onerror="this.style.display='none'">
            </div>
            <div class="institution-name"><?php echo $inst_name; ?></div>
            <div class="institution-tagline"><?php echo $inst_tagline; ?></div>
        </div>

        <!-- Program Hero Image -->
        <div class="program-hero">
            <div class="hero-content">
                <div class="hero-text">
                    <div class="badge">🔥 Limited Spots Available</div>
                    <h2>2-Week Intensive Crash Program</h2>
                    <p>Master in-demand digital skills in just 2 weeks with hands-on training and expert mentorship.</p>
                    <div class="hero-features">
                        <span><i class="fas fa-certificate"></i> Certificate of Completion</span>
                        <span><i class="fas fa-laptop-code"></i> Hands-on Projects</span>
                        <span><i class="fas fa-users"></i> Expert Mentorship</span>
                        <span><i class="fas fa-clock"></i> Flexible Schedule</span>
                    </div>
                </div>
                <div class="hero-image">
                    <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 180'%3E%3Crect width='200' height='180' fill='%233b82f6'/%3E%3Ctext x='100' y='90' text-anchor='middle' fill='white' font-size='14' font-family='Arial'%3ECrash Program%3C/text%3E%3C/svg%3E" alt="Crash Program">
                </div>
            </div>
        </div>


        <div class="spots-counter <?php echo $spots_available <= 10 ? ($spots_available <= 5 ? 'critical' : 'warning') : ''; ?>">
            <span class="spots-number"><?php echo $spots_available; ?></span>
            <span class="spots-label">Spots Available out of <?php echo $total_spots; ?></span>
        </div>


        <div class="card">
            <div class="card-header">
                <h2>🚀 Register for the Crash Program</h2>
                <div class="program-dates">
                    <i class="fas fa-calendar-alt"></i> <?php echo $start_date; ?> - <?php echo $end_date; ?>
                    <p> <i class="fas-fa-venue"></i> Mighty School for Valours </p>
                </div>
            </div>
            <div class="card-content">
                <?php if ($registration_closed): ?>
                    <div class="closed-message">
                        <i class="fas fa-ban"></i>
                        <h3>Registration Closed</h3>
                        <p>All <?php echo $total_spots; ?> spots have been filled. Thank you for your interest!</p>
                    </div>
                <?php else: ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Registration Successful!</strong><br>
                                A confirmation email has been sent to your email address.<br>
                                You will be redirected to the payment page in a few seconds.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <strong>Please fix the following errors:</strong>
                                <ul style="margin: 0.5rem 0 0 1.5rem;">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="program-fee">
                        <span>Program Fee:</span>
                        <div class="fee-amount">₦<?php echo $program_fee; ?></div>
                        <small>Payment required to secure your spot</small>
                    </div>

                    <form method="POST" id="registrationForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                        <div class="program-options">
                            <div class="program-card" data-program="web_development" onclick="selectProgram('web_development')">
                                <div class="program-icon"><i class="fas fa-code"></i></div>
                                <h3>Web Development</h3>
                                <p>HTML, CSS, JavaScript, React basics</p>
                                <div class="program-badge">Popular</div>
                            </div>
                            <div class="program-card" data-program="ai_faceless_video" onclick="selectProgram('ai_faceless_video')">
                                <div class="program-icon"><i class="fas fa-video"></i></div>
                                <h3>AI Faceless Video Creation</h3>
                                <p>Create viral videos using AI tools</p>
                            </div>
                        </div>
                        <input type="hidden" name="program_choice" id="program_choice" value="">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name" class="required">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="last_name" class="required">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email" class="required">Email Address</label>
                                <input type="email" id="email" name="email" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="phone" class="required">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                    placeholder="08012345678" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="address">Address</label>
                                <input type="text" id="address" name="address" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="state">State</label>
                                <input type="text" id="state" name="state" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="how_heard">How did you hear about us?</label>
                                <select id="how_heard" name="how_heard" class="form-control">
                                    <option value="">Select an option</option>
                                    <option value="social_media" <?php echo ($_POST['how_heard'] ?? '') == 'social_media' ? 'selected' : ''; ?>>Social Media</option>
                                    <option value="friend" <?php echo ($_POST['how_heard'] ?? '') == 'friend' ? 'selected' : ''; ?>>Friend/Referral</option>
                                    <option value="school" <?php echo ($_POST['how_heard'] ?? '') == 'school' ? 'selected' : ''; ?>>School Announcement</option>
                                    <option value="email" <?php echo ($_POST['how_heard'] ?? '') == 'email' ? 'selected' : ''; ?>>Email Newsletter</option>
                                    <option value="whatsapp" <?php echo ($_POST['how_heard'] ?? '') == 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
                                    <option value="other" <?php echo ($_POST['how_heard'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="is_student" name="is_student" value="1"
                                <?php echo (isset($_POST['is_student']) && $_POST['is_student'] == 1) ? 'checked' : 'checked'; ?>>
                            <label for="is_student">I am currently a student</label>
                        </div>

                        <div id="student-fields" style="display: <?php echo (isset($_POST['is_student']) && $_POST['is_student'] == 0) ? 'none' : 'block'; ?>;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="school_name">School/Institution Name</label>
                                    <input type="text" id="school_name" name="school_name" class="form-control"
                                        value="<?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?>"
                                        placeholder="e.g., University of Lagos">
                                </div>
                                <div class="form-group">
                                    <label for="school_class">Class/Level</label>
                                    <input type="text" id="school_class" name="school_class" class="form-control"
                                        value="<?php echo htmlspecialchars($_POST['school_class'] ?? ''); ?>"
                                        placeholder="e.g., 200 Level, SS3, etc.">
                                </div>
                            </div>
                        </div>



                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Register Now
                        </button>
                    </form>

                    <div class="login-link">
                        Already have an account? <a href="<?php echo BASE_URL; ?>modules/auth/login.php">Login here</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $inst_name; ?>. All rights reserved.</p>
            <p>📍 Nigeria | 📞 +234 905 158 6024 | ✉️ info@impactdigitalacademy.com.ng</p>
        </div>
    </div>

    <script>
        function selectProgram(program) {
            document.querySelectorAll('.program-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`.program-card[data-program="${program}"]`).classList.add('selected');
            document.getElementById('program_choice').value = program;
        }

        // Handle student checkbox
        const studentCheckbox = document.getElementById('is_student');
        if (studentCheckbox) {
            studentCheckbox.addEventListener('change', function() {
                const studentFields = document.getElementById('student-fields');
                if (this.checked) {
                    studentFields.style.display = 'block';
                } else {
                    studentFields.style.display = 'none';
                }
            });
        }

        // Form validation before submit
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const programChoice = document.getElementById('program_choice').value;
            if (!programChoice) {
                e.preventDefault();
                alert('Please select a program');
                return false;
            }

            const email = document.getElementById('email').value;
            if (!email || !email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }

            const phone = document.getElementById('phone').value;
            if (!phone || phone.length < 10) {
                e.preventDefault();
                alert('Please enter a valid phone number');
                return false;
            }

            return true;
        });

        // If program was previously selected, highlight it
        <?php if (isset($_POST['program_choice']) && $_POST['program_choice']): ?>
            selectProgram('<?php echo $_POST['program_choice']; ?>');
        <?php endif; ?>
    </script>
</body>

</html>
<?php $conn->close(); ?>