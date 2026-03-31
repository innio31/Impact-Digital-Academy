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
        if (empty($form_data['first_name']) || empty($form_data['email'])) {
            $errors[] = 'Name and Email are required.';
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
        <div class="card-content">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Errors:</strong>
                    <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="registrationForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="applicant_type" id="applicant_type" value="student">
                <input type="hidden" name="program_choice" id="program_choice" value="">

                <label>I am a:</label>
                <div class="applicant-toggle">
                    <button type="button" class="applicant-option active" id="btnStudent">Student</button>
                    <button type="button" class="applicant-option" id="btnProfessional">Professional</button>
                </div>

                <label>Select Program:</label>
                <div class="program-options">
                    <div class="program-card" data-val="dtp">
                        <i class="fas fa-print"></i>
                        <h3>DTP</h3>
                    </div>
                    <div class="program-card" data-val="web_design">
                        <i class="fas fa-code"></i>
                        <h3>Web Design</h3>
                    </div>
                </div>

                <div id="commonFields">
                    <div class="form-group">
                        <input type="text" name="first_name" placeholder="First Name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <input type="email" name="email" placeholder="Email Address" class="form-control" required>
                    </div>
                </div>

                <div id="studentFields">
                    <div class="form-group">
                        <input type="text" name="school_name" id="school_name" placeholder="School Name" class="form-control" required>
                    </div>
                </div>

                <div id="professionalFields" style="display:none;">
                    <div class="form-group">
                        <input type="text" name="company_name" id="company_name" placeholder="Company Name" class="form-control">
                    </div>
                </div>

                <button type="submit" class="btn">Complete Registration</button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btnStudent = document.getElementById('btnStudent');
            const btnProf = document.getElementById('btnProfessional');
            const studentFields = document.getElementById('studentFields');
            const profFields = document.getElementById('professionalFields');
            const typeInput = document.getElementById('applicant_type');
            const progInput = document.getElementById('program_choice');
            const progCards = document.querySelectorAll('.program-card');

            // 1. Toggle Applicant Type
            btnStudent.onclick = () => {
                typeInput.value = 'student';
                btnStudent.classList.add('active');
                btnProf.classList.remove('active');
                studentFields.style.display = 'block';
                profFields.style.display = 'none';
                document.getElementById('school_name').required = true;
                document.getElementById('company_name').required = false;
            };

            btnProf.onclick = () => {
                typeInput.value = 'professional';
                btnProf.classList.add('active');
                btnStudent.classList.remove('active');
                studentFields.style.display = 'none';
                profFields.style.display = 'block';
                document.getElementById('school_name').required = false;
                document.getElementById('company_name').required = true;
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
                    alert("Please select a program (DTP or Web Design)");
                    e.preventDefault();
                    return false;
                }
            };
        });
    </script>
</body>

</html>