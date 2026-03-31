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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Crash Program Registration - <?php echo $inst_name; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="../../images/favicon.ico">
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

        .institution-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding: 1rem;
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
        }

        .institution-tagline {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
            margin-top: 0.25rem;
        }

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

        .spots-counter.warning {
            background: rgba(245, 158, 11, 0.4);
        }

        .spots-counter.critical {
            background: rgba(239, 68, 68, 0.4);
        }

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

        .card-content {
            padding: 2rem;
        }

        .applicant-toggle {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            background: var(--gray-100);
            padding: 0.5rem;
            border-radius: 16px;
        }

        .applicant-option {
            flex: 1;
            text-align: center;
            padding: 1rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: transparent;
            border: none;
            font-weight: 600;
            color: var(--gray-600);
        }

        .applicant-option.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .applicant-option i {
            font-size: 1.5rem;
            display: block;
            margin-bottom: 0.5rem;
        }

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

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--gray-700);
            font-weight: 500;
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

            .program-options {
                grid-template-columns: 1fr;
            }

            .card-content {
                padding: 1.5rem;
            }
        }

        .btn {
            width: 100%;
            padding: 1rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            border: none;
            background: var(--primary);
            color: white;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
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

        .fee-info {
            background: var(--gray-100);
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .fee-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

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
                <img src="<?php echo $inst_logo; ?>" alt="<?php echo $inst_name; ?>">
            </div>
            <div class="institution-name"><?php echo $inst_name; ?></div>
            <div class="institution-tagline"><?php echo $inst_tagline; ?></div>
        </div>

        <!-- Program Hero -->
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
                    <img src="../../../images/ai.jpeg" alt="Crash Program">
                </div>
            </div>
        </div>

        <!-- Spots Counter -->
        <div class="spots-counter <?php echo $spots_left <= 10 ? ($spots_left <= 5 ? 'critical' : 'warning') : ''; ?>">
            <span class="spots-number"><?php echo $spots_left; ?></span>
            <span class="spots-label">Spots Available out of <?php echo $total_spots; ?></span>
        </div>

        <!-- Registration Card -->
        <div class="card">
            <div class="card-header">
                <h2>🚀 Register for the Crash Program</h2>
                <div class="program-dates">
                    <i class="fas fa-calendar-alt"></i> <?php echo $start_date; ?> - <?php echo $end_date; ?>
                    <p><i class="fas fa-map-marker-alt"></i> Mighty School for Valours</p>
                </div>
            </div>
            <div class="card-content">
                <?php if ($registration_closed): ?>
                    <div style="text-align: center; padding: 3rem;">
                        <i class="fas fa-ban" style="font-size: 4rem; color: var(--danger);"></i>
                        <h3>Registration Closed</h3>
                        <p>All <?php echo $total_spots; ?> spots have been filled. Thank you for your interest!</p>
                    </div>
                <?php else: ?>
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Please fix the following errors:</strong>
                            <ul style="margin: 0.5rem 0 0 1.5rem;">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="fee-info">
                        <span class="fee-label">Program Fee:</span>
                        <div class="fee-amount" id="feeAmount">₦<?php echo number_format($stud_fee_val, 2); ?></div>
                        <small>Payment required to secure your spot</small>
                    </div>

                    <form method="POST" id="registrationForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="applicant_type" id="applicant_type" value="student">
                        <input type="hidden" name="program_choice" id="program_choice" value="">

                        <!-- Applicant Type Toggle -->
                        <div class="applicant-toggle">
                            <button type="button" class="applicant-option active" id="btnStudent">
                                <i class="fas fa-graduation-cap"></i>
                                Student
                            </button>
                            <button type="button" class="applicant-option" id="btnProfessional">
                                <i class="fas fa-briefcase"></i>
                                Professional
                            </button>
                        </div>

                        <!-- Program Options -->
                        <div class="program-options">
                            <div class="program-card" data-val="dtp">
                                <div class="program-icon"><i class="fas fa-print"></i></div>
                                <h3>Desktop Publishing (DTP)</h3>
                                <p>MsWord, MsExcel, MsPowerpoint, AI Tools</p>
                            </div>
                            <div class="program-card" data-val="web_design">
                                <div class="program-icon"><i class="fas fa-code"></i></div>
                                <h3>Web Design</h3>
                                <p>HTML, CSS, JavaScript, Responsive Design</p>
                            </div>
                        </div>

                        <!-- Personal Information -->
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

                        <!-- Student Fields -->
                        <div id="studentFields">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="required">School/Institution Name</label>
                                    <input type="text" name="school_name" id="school_name" class="form-control" placeholder="e.g., University of Lagos" required>
                                </div>
                                <div class="form-group">
                                    <label class="required">Class/Level</label>
                                    <input type="text" name="school_class" id="school_class" class="form-control" placeholder="e.g., 200 Level, SS3" required>
                                </div>
                            </div>
                        </div>

                        <!-- Professional Fields -->
                        <div id="professionalFields" style="display: none;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="required">Company/Organization</label>
                                    <input type="text" name="company_name" id="company_name" class="form-control" placeholder="e.g., Google, Self-employed">
                                </div>
                                <div class="form-group">
                                    <label class="required">Job Title</label>
                                    <input type="text" name="job_title" id="job_title" class="form-control" placeholder="e.g., Software Engineer">
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
                                    <textarea name="professional_skills" class="form-control" rows="2" placeholder="List your relevant skills or what you hope to learn..."></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
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

                        <button type="submit" class="btn">
                            <i class="fas fa-paper-plane"></i> Register Now
                        </button>
                    </form>

                    <div style="text-align: center; margin-top: 1.5rem; color: var(--gray-600);">
                        Already have an account? <a href="<?php echo BASE_URL; ?>modules/auth/login.php" style="color: var(--primary);">Login here</a>
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
        document.addEventListener('DOMContentLoaded', function() {
            const btnStudent = document.getElementById('btnStudent');
            const btnProfessional = document.getElementById('btnProfessional');
            const studentFields = document.getElementById('studentFields');
            const professionalFields = document.getElementById('professionalFields');
            const typeInput = document.getElementById('applicant_type');
            const progInput = document.getElementById('program_choice');
            const progCards = document.querySelectorAll('.program-card');
            const feeAmount = document.getElementById('feeAmount');
            const professionalFee = <?php echo $prof_fee_val; ?>;
            const studentFee = <?php echo $stud_fee_val; ?>;

            // Get form elements for required attributes
            const schoolName = document.getElementById('school_name');
            const schoolClass = document.getElementById('school_class');
            const companyName = document.getElementById('company_name');
            const jobTitle = document.getElementById('job_title');

            // 1. Toggle Applicant Type
            btnStudent.onclick = () => {
                typeInput.value = 'student';
                btnStudent.classList.add('active');
                btnProfessional.classList.remove('active');
                studentFields.style.display = 'block';
                professionalFields.style.display = 'none';
                feeAmount.innerHTML = '₦' + studentFee.toLocaleString();

                // Update required attributes
                if (schoolName) schoolName.required = true;
                if (schoolClass) schoolClass.required = true;
                if (companyName) companyName.required = false;
                if (jobTitle) jobTitle.required = false;
            };

            btnProfessional.onclick = () => {
                typeInput.value = 'professional';
                btnProfessional.classList.add('active');
                btnStudent.classList.remove('active');
                studentFields.style.display = 'none';
                professionalFields.style.display = 'block';
                feeAmount.innerHTML = '₦' + professionalFee.toLocaleString();

                // Update required attributes
                if (schoolName) schoolName.required = false;
                if (schoolClass) schoolClass.required = false;
                if (companyName) companyName.required = true;
                if (jobTitle) jobTitle.required = true;
            };

            // 2. Select Program
            progCards.forEach(card => {
                card.onclick = function() {
                    progCards.forEach(c => c.classList.remove('selected'));
                    this.classList.add('selected');
                    progInput.value = this.getAttribute('data-val');
                };
            });

            // 3. Final Validation
            document.getElementById('registrationForm').onsubmit = function(e) {
                if (!progInput.value) {
                    alert("Please select a program (Desktop Publishing or Web Design)");
                    e.preventDefault();
                    return false;
                }

                // Validate based on applicant type
                const applicantType = typeInput.value;
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

                // Validate email
                const email = document.querySelector('[name="email"]').value;
                if (!email || !email.includes('@')) {
                    alert("Please enter a valid email address");
                    e.preventDefault();
                    return false;
                }

                // Validate phone
                const phone = document.querySelector('[name="phone"]').value;
                if (!phone || phone.length < 10) {
                    alert("Please enter a valid phone number (minimum 10 digits)");
                    e.preventDefault();
                    return false;
                }

                return true;
            };
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>