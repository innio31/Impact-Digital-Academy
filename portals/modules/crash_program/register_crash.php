<?php
// modules/crash_program/register_crash.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

$conn = getDBConnection();

// 1. GET SETTINGS
$spots_sql = "SELECT setting_value FROM crash_program_settings WHERE setting_key = 'total_spots'";
$total_spots = $conn->query($spots_sql)->fetch_assoc()['setting_value'] ?? 50;

$count_sql = "SELECT COUNT(*) as count FROM crash_program_registrations WHERE payment_status = 'confirmed'";
$confirmed_count = $conn->query($count_sql)->fetch_assoc()['count'] ?? 0;

$spots_left = $total_spots - $confirmed_count;
$registration_closed = $spots_left <= 0;

$settings_sql = "SELECT setting_key, setting_value FROM crash_program_settings WHERE setting_key IN ('program_start_date', 'program_end_date', 'professional_fee', 'student_fee')";
$settings_result = $conn->query($settings_sql);
$p_set = [];
while ($row = $settings_result->fetch_assoc()) {
    $p_set[$row['setting_key']] = $row['setting_value'];
}

$start_date = date('F j, Y', strtotime($p_set['program_start_date'] ?? '2026-04-13'));
$end_date = date('F j, Y', strtotime($p_set['program_end_date'] ?? '2026-04-24'));
$prof_fee_val = $p_set['professional_fee'] ?? 10000;
$stud_fee_val = $p_set['student_fee'] ?? 7000;

// 2. HANDLE SUBMISSION
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$registration_closed) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token invalid.';
    } else {
        $applicant_type = $_POST['applicant_type'] ?? 'student';
        $program_choice = $_POST['program_choice'] ?? '';

        if (empty($program_choice)) {
            $errors[] = 'Please select a program (DTP or Web Design).';
        }

        $form_data = [
            'email' => trim($_POST['email'] ?? ''),
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'applicant_type' => $applicant_type,
            'program_choice' => $program_choice,
            'school_name' => ($applicant_type == 'student') ? trim($_POST['school_name'] ?? '') : '',
            'school_class' => ($applicant_type == 'student') ? trim($_POST['school_class'] ?? '') : '',
            'company_name' => ($applicant_type == 'professional') ? trim($_POST['company_name'] ?? '') : '',
            'job_title' => ($applicant_type == 'professional') ? trim($_POST['job_title'] ?? '') : '',
            'years_experience' => $_POST['years_experience'] ?? null,
            'professional_skills' => trim($_POST['professional_skills'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'how_heard' => trim($_POST['how_heard'] ?? '')
        ];

        // Basic Validation
        if (empty($form_data['first_name']) || empty($form_data['last_name']) || empty($form_data['email']) || empty($form_data['phone'])) {
            $errors[] = 'All fields are required.';
        }

        // Validate email
        if (!empty($form_data['email']) && !isValidEmail($form_data['email'])) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Validate applicant type specific fields
        if ($applicant_type === 'student') {
            if (empty($form_data['school_name'])) {
                $errors[] = 'School/Institution name is required for students.';
            }
            if (empty($form_data['school_class'])) {
                $errors[] = 'Class/Level is required for students.';
            }
        } elseif ($applicant_type === 'professional') {
            if (empty($form_data['company_name'])) {
                $errors[] = 'Company/Organization name is required for professionals.';
            }
            if (empty($form_data['job_title'])) {
                $errors[] = 'Job title is required for professionals.';
            }
        }

        if (empty($errors)) {
            $payment_amount = ($applicant_type === 'professional') ? $prof_fee_val : $stud_fee_val;

            $insert_sql = "INSERT INTO crash_program_registrations 
                          (email, first_name, last_name, phone, applicant_type, program_choice, 
                           school_name, school_class, company_name, job_title, years_experience, professional_skills,
                           address, city, state, how_heard, payment_amount, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment')";

            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param(
                'ssssssssssisssssd',
                $form_data['email'],
                $form_data['first_name'],
                $form_data['last_name'],
                $form_data['phone'],
                $form_data['applicant_type'],
                $form_data['program_choice'],
                $form_data['school_name'],
                $form_data['school_class'],
                $form_data['company_name'],
                $form_data['job_title'],
                $form_data['years_experience'],
                $form_data['professional_skills'],
                $form_data['address'],
                $form_data['city'],
                $form_data['state'],
                $form_data['how_heard'],
                $payment_amount
            );

            if ($stmt->execute()) {
                $_SESSION['crash_registration_id'] = $stmt->insert_id;
                header("Location: " . BASE_URL . "modules/crash_program/confirm_payment.php?id=" . $stmt->insert_id);
                exit;
            } else {
                $errors[] = 'Database error. Please try again.';
            }
        }
    }
}

// Get institution settings
$inst_name = "Impact Digital Academy";
$inst_tagline = "Empowering Digital Futures";
$inst_logo = BASE_URL . "images/logo.png";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Crash Program Registration - <?php echo $inst_name; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="../../images/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #ec489a;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --dark: #0f172a;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 16px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header Styles */
        .institution-header {
            text-align: center;
            margin-bottom: 24px;
            padding: 16px;
        }

        .institution-logo img {
            max-height: 60px;
            width: auto;
        }

        .institution-name {
            font-size: clamp(1.5rem, 5vw, 2rem);
            font-weight: 700;
            color: white;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }

        .institution-tagline {
            color: rgba(255, 255, 255, 0.9);
            font-size: clamp(0.875rem, 3vw, 1rem);
            margin-top: 4px;
        }

        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .hero-grid {
            display: flex;
            flex-direction: column;
            padding: 24px;
        }

        @media (min-width: 768px) {
            .hero-grid {
                flex-direction: row;
                align-items: center;
                padding: 40px;
            }
        }

        .hero-content {
            flex: 1;
            text-align: center;
        }

        @media (min-width: 768px) {
            .hero-content {
                text-align: left;
            }
        }

        .hero-content h1 {
            font-size: clamp(1.5rem, 6vw, 2.5rem);
            font-weight: 800;
            color: white;
            margin-bottom: 12px;
            line-height: 1.2;
        }

        .hero-content p {
            color: rgba(255, 255, 255, 0.9);
            font-size: clamp(0.875rem, 3vw, 1rem);
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        @media (min-width: 768px) {
            .hero-stats {
                justify-content: flex-start;
            }
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: clamp(1.25rem, 4vw, 1.5rem);
            font-weight: 700;
            color: white;
            display: block;
        }

        .stat-label {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .hero-image {
            text-align: center;
            margin-top: 24px;
        }

        @media (min-width: 768px) {
            .hero-image {
                margin-top: 0;
                flex: 0.6;
            }
        }

        .hero-image img {
            max-width: 100%;
            height: auto;
            max-height: 200px;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.2));
        }

        /* Spots Alert */
        .spots-alert {
            background: linear-gradient(135deg, var(--accent), #d97706);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            color: white;
            text-align: center;
        }

        @media (min-width: 640px) {
            .spots-alert {
                flex-direction: row;
                justify-content: space-between;
                text-align: left;
            }
        }

        .spots-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .spots-number {
            font-size: clamp(1.5rem, 5vw, 2rem);
            font-weight: 800;
        }

        .apply-btn-hero {
            background: white;
            color: var(--accent);
            border: none;
            padding: 12px 24px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
        }

        @media (min-width: 640px) {
            .apply-btn-hero {
                width: auto;
            }
        }

        .apply-btn-hero:active {
            transform: scale(0.98);
        }

        /* Program Cards */
        .programs-section {
            margin-bottom: 24px;
        }

        .section-title {
            text-align: center;
            color: white;
            font-size: clamp(1.5rem, 5vw, 2rem);
            margin-bottom: 24px;
            font-weight: 700;
        }

        .program-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        @media (min-width: 640px) {
            .program-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 24px;
            }
        }

        .program-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }

        .program-card:active {
            transform: scale(0.98);
        }

        .program-card.selected {
            border: 3px solid var(--primary);
            background: linear-gradient(135deg, #fff, #f0f9ff);
        }

        .program-icon {
            font-size: clamp(2.5rem, 8vw, 3rem);
            margin-bottom: 12px;
        }

        .program-card h3 {
            font-size: clamp(1.1rem, 4vw, 1.3rem);
            color: var(--dark);
            margin-bottom: 8px;
        }

        .program-card p {
            color: var(--gray-600);
            margin-bottom: 12px;
            font-size: 0.875rem;
        }

        .program-features {
            list-style: none;
            text-align: left;
            margin-top: 12px;
        }

        .program-features li {
            padding: 6px 0;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.875rem;
        }

        .program-features li i {
            color: var(--success);
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        /* Features Section */
        .features-section {
            background: white;
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        @media (min-width: 640px) {
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .features-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .feature-item {
            text-align: center;
        }

        .feature-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 12px;
        }

        .feature-item h4 {
            font-size: 1rem;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .feature-item p {
            color: var(--gray-600);
            font-size: 0.875rem;
            line-height: 1.4;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            overflow-y: auto;
            padding: 16px;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 24px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: slideUp 0.3s ease;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px;
            border-radius: 24px 24px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h2 {
            font-size: clamp(1.1rem, 4vw, 1.3rem);
        }

        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:active {
            transform: scale(0.9);
        }

        .modal-body {
            padding: 20px;
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 500;
            font-size: 0.875rem;
        }

        .required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            -webkit-appearance: none;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        @media (min-width: 640px) {
            .form-row {
                grid-template-columns: 1fr 1fr;
            }
        }

        .applicant-toggle {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            background: var(--gray-100);
            padding: 6px;
            border-radius: 16px;
        }

        .applicant-option {
            flex: 1;
            text-align: center;
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: transparent;
            border: none;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .applicant-option.active {
            background: var(--primary);
            color: white;
        }

        .applicant-option:active {
            transform: scale(0.98);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        .fee-info {
            background: var(--gray-100);
            padding: 16px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
        }

        .fee-amount {
            font-size: clamp(1.2rem, 5vw, 1.5rem);
            font-weight: 700;
            color: var(--primary);
        }

        .alert {
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.875rem;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-error ul {
            margin: 8px 0 0 20px;
        }

        .footer {
            text-align: center;
            margin-top: 32px;
            padding: 20px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.75rem;
        }

        /* Touch-friendly improvements */
        button,
        .program-card,
        .applicant-option {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        /* Scrollbar styling */
        .modal-content::-webkit-scrollbar {
            width: 6px;
        }

        .modal-content::-webkit-scrollbar-track {
            background: var(--gray-100);
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 3px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="institution-header">
            <div class="institution-logo">
                <img src="<?php echo $inst_logo; ?>" alt="<?php echo $inst_name; ?>">
            </div>
            <div class="institution-name"><?php echo $inst_name; ?></div>
            <div class="institution-tagline"><?php echo $inst_tagline; ?></div>
        </div>

        <!-- Hero Section -->
        <div class="hero-section">
            <div class="hero-grid">
                <div class="hero-content">
                    <h1>2-Week Intensive<br>Crash Program</h1>
                    <p>Master in-demand digital skills in just 2 weeks with hands-on training, expert mentorship, and real-world projects.</p>
                    <div class="hero-stats">
                        <div class="stat-item">
                            <span class="stat-number">2 Weeks</span>
                            <span class="stat-label">Duration</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">100%</span>
                            <span class="stat-label">Hands-on</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">Certified</span>
                            <span class="stat-label">Completion</span>
                        </div>
                    </div>
                </div>
                <div class="hero-image">
                    <img src="../../../images/ai.jpeg" alt="Crash Program">
                </div>
            </div>
        </div>

        <!-- Spots Alert -->
        <div class="spots-alert">
            <div class="spots-info">
                <i class="fas fa-users" style="font-size: 1.5rem;"></i>
                <div>
                    <div class="spots-number"><?php echo $spots_left; ?> Spots Left</div>
                    <div style="font-size: 0.75rem;">out of <?php echo $total_spots; ?> total spots</div>
                </div>
            </div>
            <?php if (!$registration_closed): ?>
                <button class="apply-btn-hero" onclick="openModal()">
                    <i class="fas fa-arrow-right"></i> Apply Now
                </button>
            <?php endif; ?>
        </div>

        <!-- Program Options -->
        <div class="programs-section">
            <h2 class="section-title">Choose Your Path</h2>
            <div class="program-grid">
                <div class="program-card" data-val="dtp" onclick="selectProgramCard(this, 'dtp')">
                    <div class="program-icon"><i class="fas fa-print"></i></div>
                    <h3>Desktop Publishing (DTP)</h3>
                    <p>Master professional document creation and design</p>
                    <ul class="program-features">
                        <li><i class="fas fa-check-circle"></i> Microsoft Word Mastery</li>
                        <li><i class="fas fa-check-circle"></i> Excel & PowerPoint</li>
                        <li><i class="fas fa-check-circle"></i> AI Tools Integration</li>
                        <li><i class="fas fa-check-circle"></i> Professional Layout Design</li>
                    </ul>
                </div>
                <div class="program-card" data-val="web_design" onclick="selectProgramCard(this, 'web_design')">
                    <div class="program-icon"><i class="fas fa-code"></i></div>
                    <h3>Web Design</h3>
                    <p>Build beautiful, responsive websites from scratch</p>
                    <ul class="program-features">
                        <li><i class="fas fa-check-circle"></i> HTML5 & CSS3</li>
                        <li><i class="fas fa-check-circle"></i> JavaScript Fundamentals</li>
                        <li><i class="fas fa-check-circle"></i> Responsive Design</li>
                        <li><i class="fas fa-check-circle"></i> Modern UI/UX Principles</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Features Section -->
        <div class="features-section">
            <div class="features-grid">
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    <h4>Expert Mentorship</h4>
                    <p>Learn from industry professionals with years of experience</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-project-diagram"></i></div>
                    <h4>Hands-on Projects</h4>
                    <p>Build real-world projects for your portfolio</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-certificate"></i></div>
                    <h4>Certificate of Completion</h4>
                    <p>Get certified and boost your career opportunities</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-clock"></i></div>
                    <h4>Flexible Schedule</h4>
                    <p><?php echo $start_date; ?> - <?php echo $end_date; ?></p>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $inst_name; ?>. All rights reserved.</p>
            <p style="margin-top: 8px;">📍 Nigeria | 📞 +234 905 158 6024 | ✉️ info@impactdigitalacademy.com.ng</p>
        </div>
    </div>

    <!-- Registration Modal -->
    <div id="registrationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Complete Registration</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 8px 0 0 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="fee-info">
                    <span class="fee-label">Program Fee:</span>
                    <div class="fee-amount" id="modalFeeAmount">₦<?php echo number_format($stud_fee_val, 2); ?></div>
                    <small>Payment required to secure your spot</small>
                </div>

                <form method="POST" id="registrationForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="applicant_type" id="applicant_type" value="student">
                    <input type="hidden" name="program_choice" id="program_choice" value="">

                    <div class="applicant-toggle">
                        <button type="button" class="applicant-option active" id="modalBtnStudent" onclick="setApplicantType('student')">
                            <i class="fas fa-graduation-cap"></i> Student
                        </button>
                        <button type="button" class="applicant-option" id="modalBtnProfessional" onclick="setApplicantType('professional')">
                            <i class="fas fa-briefcase"></i> Professional
                        </button>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">Email Address</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" required placeholder="08012345678">
                        </div>
                    </div>

                    <div id="modalStudentFields">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">School/Institution Name</label>
                                <input type="text" name="school_name" id="school_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Class/Level</label>
                                <input type="text" name="school_class" id="school_class" class="form-control" required>
                            </div>
                        </div>
                    </div>

                    <div id="modalProfessionalFields" style="display: none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">Company/Organization</label>
                                <input type="text" name="company_name" id="company_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="required">Job Title</label>
                                <input type="text" name="job_title" id="job_title" class="form-control">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Years of Experience</label>
                                <select name="years_experience" class="form-control">
                                    <option value="">Select experience</option>
                                    <option value="0">Less than 1 year</option>
                                    <option value="1">1 year</option>
                                    <option value="2">2 years</option>
                                    <option value="3">3 years</option>
                                    <option value="4">4 years</option>
                                    <option value="5">5 years</option>
                                    <option value="6">6-10 years</option>
                                    <option value="11">11+ years</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Key Skills/Interests</label>
                                <textarea name="professional_skills" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Address</label>
                            <input type="text" name="address" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>State</label>
                            <input type="text" name="state" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>How did you hear about us?</label>
                            <select name="how_heard" class="form-control">
                                <option value="">Select an option</option>
                                <option value="social_media">Social Media</option>
                                <option value="friend">Friend/Referral</option>
                                <option value="school">School Announcement</option>
                                <option value="email">Email Newsletter</option>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i> Submit Registration
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let selectedProgram = '';

        // Program selection
        function selectProgramCard(card, program) {
            document.querySelectorAll('.program-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            selectedProgram = program;
            document.getElementById('program_choice').value = program;
        }

        // Modal functions
        function openModal() {
            if (!selectedProgram) {
                alert('Please select a program first (Desktop Publishing or Web Design)');
                return;
            }
            document.getElementById('registrationModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('registrationModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        // Applicant type toggle
        function setApplicantType(type) {
            const studentBtn = document.getElementById('modalBtnStudent');
            const professionalBtn = document.getElementById('modalBtnProfessional');
            const studentFields = document.getElementById('modalStudentFields');
            const professionalFields = document.getElementById('modalProfessionalFields');
            const typeInput = document.getElementById('applicant_type');
            const feeAmount = document.getElementById('modalFeeAmount');
            const schoolName = document.getElementById('school_name');
            const schoolClass = document.getElementById('school_class');
            const companyName = document.getElementById('company_name');
            const jobTitle = document.getElementById('job_title');

            const professionalFee = <?php echo $prof_fee_val; ?>;
            const studentFee = <?php echo $stud_fee_val; ?>;

            typeInput.value = type;

            if (type === 'student') {
                studentBtn.classList.add('active');
                professionalBtn.classList.remove('active');
                studentFields.style.display = 'block';
                professionalFields.style.display = 'none';
                feeAmount.innerHTML = '₦' + studentFee.toLocaleString();

                schoolName.required = true;
                schoolClass.required = true;
                companyName.required = false;
                jobTitle.required = false;
            } else {
                studentBtn.classList.remove('active');
                professionalBtn.classList.add('active');
                studentFields.style.display = 'none';
                professionalFields.style.display = 'block';
                feeAmount.innerHTML = '₦' + professionalFee.toLocaleString();

                schoolName.required = false;
                schoolClass.required = false;
                companyName.required = true;
                jobTitle.required = true;
            }
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('registrationModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Form validation
        document.getElementById('registrationForm').onsubmit = function(e) {
            if (!selectedProgram) {
                alert("Please select a program first");
                e.preventDefault();
                return false;
            }

            const applicantType = document.getElementById('applicant_type').value;
            const schoolName = document.getElementById('school_name');
            const schoolClass = document.getElementById('school_class');
            const companyName = document.getElementById('company_name');
            const jobTitle = document.getElementById('job_title');

            if (applicantType === 'student') {
                if (!schoolName.value || !schoolClass.value) {
                    alert("Please fill in school name and class/level");
                    e.preventDefault();
                    return false;
                }
            } else if (applicantType === 'professional') {
                if (!companyName.value || !jobTitle.value) {
                    alert("Please fill in company name and job title");
                    e.preventDefault();
                    return false;
                }
            }

            const email = document.querySelector('[name="email"]').value;
            if (!email || !email.includes('@')) {
                alert("Please enter a valid email address");
                e.preventDefault();
                return false;
            }

            const phone = document.querySelector('[name="phone"]').value;
            if (!phone || phone.length < 10) {
                alert("Please enter a valid phone number (minimum 10 digits)");
                e.preventDefault();
                return false;
            }

            return true;
        };
    </script>
</body>

</html>
<?php $conn->close(); ?>