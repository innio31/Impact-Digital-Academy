<?php
// modules/auth/register.php

// Add error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirectToDashboard();
}

// Get database connection
$conn = getDBConnection();

// Get programs for dropdown
$programs = [];
$programs_sql = "SELECT id, program_code, name, program_type, duration_mode 
                 FROM programs WHERE status = 'active' 
                 ORDER BY program_type, name";
$programs_result = $conn->query($programs_sql);
if ($programs_result) {
    $programs = $programs_result->fetch_all(MYSQLI_ASSOC);
}

// Get schools for dropdown
$schools = [];
$schools_sql = "SELECT id, name, city, state FROM schools 
                WHERE partnership_status = 'active'
                ORDER BY name";
$schools_result = $conn->query($schools_sql);
if ($schools_result) {
    $schools = $schools_result->fetch_all(MYSQLI_ASSOC);
}

// Get academic periods with registration availability
$academic_periods = [
    'onsite' => [],    // Monthly cohorts
    'online' => [],    // Block-based
    'school' => []     // School terms
];

// Get upcoming monthly cohorts (onsite)
$onsite_sql = "SELECT 
                id, 
                period_name, 
                academic_year, 
                start_date, 
                end_date,
                registration_start_date, 
                registration_deadline,
                period_number,
                duration_weeks,
                CASE 
                    WHEN registration_start_date <= CURDATE() AND registration_deadline >= CURDATE() THEN 'open'
                    WHEN registration_start_date > CURDATE() THEN 'upcoming'
                    WHEN registration_deadline < CURDATE() THEN 'closed'
                    ELSE 'unavailable'
                END as registration_status,
                DATEDIFF(registration_deadline, CURDATE()) as days_remaining,
                DATEDIFF(registration_start_date, CURDATE()) as days_until_open
              FROM academic_periods 
              WHERE program_type = 'onsite' 
              AND period_type = '' 
              AND status IN ('upcoming', 'active')
              AND (registration_deadline >= CURDATE() OR registration_start_date > CURDATE())
              ORDER BY start_date 
              LIMIT 12";
$onsite_result = $conn->query($onsite_sql);
if ($onsite_result) {
    while ($row = $onsite_result->fetch_assoc()) {
        $academic_periods['onsite'][] = $row;
    }
}

// Get upcoming blocks (online)
$online_sql = "SELECT 
                id, 
                period_name, 
                academic_year, 
                start_date, 
                end_date,
                registration_start_date, 
                registration_deadline,
                period_number,
                duration_weeks,
                CASE 
                    WHEN registration_start_date <= CURDATE() AND registration_deadline >= CURDATE() THEN 'open'
                    WHEN registration_start_date > CURDATE() THEN 'upcoming'
                    WHEN registration_deadline < CURDATE() THEN 'closed'
                    ELSE 'unavailable'
                END as registration_status,
                DATEDIFF(registration_deadline, CURDATE()) as days_remaining,
                DATEDIFF(registration_start_date, CURDATE()) as days_until_open
               FROM academic_periods 
               WHERE program_type = 'online' 
               AND period_type = 'block' 
               AND status IN ('upcoming', 'active')
               AND (registration_deadline >= CURDATE() OR registration_start_date > CURDATE())
               ORDER BY start_date 
               LIMIT 6";
$online_result = $conn->query($online_sql);
if ($online_result) {
    while ($row = $online_result->fetch_assoc()) {
        $academic_periods['online'][] = $row;
    }
}

// Get upcoming school terms
$school_sql = "SELECT 
                id, 
                period_name, 
                academic_year, 
                start_date, 
                end_date,
                registration_start_date, 
                registration_deadline,
                period_number,
                duration_weeks,
                CASE 
                    WHEN registration_start_date <= CURDATE() AND registration_deadline >= CURDATE() THEN 'open'
                    WHEN registration_start_date > CURDATE() THEN 'upcoming'
                    WHEN registration_deadline < CURDATE() THEN 'closed'
                    ELSE 'unavailable'
                END as registration_status,
                DATEDIFF(registration_deadline, CURDATE()) as days_remaining,
                DATEDIFF(registration_start_date, CURDATE()) as days_until_open
             FROM academic_periods 
             WHERE program_type = 'school' 
             AND period_type = 'term' 
             AND status IN ('upcoming', 'active')
             AND (registration_deadline >= CURDATE() OR registration_start_date > CURDATE())
             ORDER BY start_date 
             LIMIT 6";
$school_result = $conn->query($school_sql);
if ($school_result) {
    while ($row = $school_result->fetch_assoc()) {
        $academic_periods['school'][] = $row;
    }
}

// Handle form submission
$errors = [];
$success = false;
$result = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Collect form data
        $form_data = [
            'email' => trim($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? '',
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'applying_as' => 'student',
            'program_type' => $_POST['program_type'] ?? 'online',
            'program_id' => !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null,
            'school_id' => !empty($_POST['school_id']) ? (int)$_POST['school_id'] : null,
            'school_name' => trim($_POST['school_name'] ?? ''),
            'academic_period_id' => !empty($_POST['academic_period_id']) ? (int)$_POST['academic_period_id'] : null,
            'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
            'gender' => $_POST['gender'] ?? null,
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'country' => trim($_POST['country'] ?? 'Nigeria'),
            'motivation' => trim($_POST['motivation'] ?? ''),
            'qualifications' => trim($_POST['qualifications'] ?? ''),
            'experience' => trim($_POST['experience'] ?? ''),
            'learning_mode_preference' => $_POST['learning_mode_preference'] ?? 'online_only'
        ];

        // Validate required fields
        $required_fields = ['email', 'password', 'confirm_password', 'first_name', 'last_name', 'program_type'];
        foreach ($required_fields as $field) {
            if (empty($form_data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        // Validate email
        if (!empty($form_data['email']) && !isValidEmail($form_data['email'])) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Validate password
        if (!empty($form_data['password']) && strlen($form_data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }

        // Check password confirmation
        if (!empty($form_data['password']) && $form_data['password'] !== $form_data['confirm_password']) {
            $errors[] = 'Passwords do not match.';
        }

        // Validate phone number (if provided)
        if (!empty($form_data['phone']) && !preg_match('/^[0-9+\-\s]+$/', $form_data['phone'])) {
            $errors[] = 'Please enter a valid phone number.';
        }

        // Validate program type selection
        if (!in_array($form_data['program_type'], ['onsite', 'online', 'school'])) {
            $errors[] = 'Please select a valid program type.';
        }

        // Validate school selection for school-based programs
        if ($form_data['program_type'] === 'school') {
            if (empty($form_data['school_id'])) {
                $errors[] = 'Please select a school for school-based program.';
            }
        } else {
            // Validate program selection for non-school programs
            if (empty($form_data['program_id'])) {
                $errors[] = 'Please select a program.';
            }
        }

        // Validate academic period selection
        if (empty($form_data['academic_period_id'])) {
            $errors[] = 'Please select your preferred start period.';
        } else {
            // Verify that the selected academic period is still available for registration
            $period_check_sql = "SELECT id, registration_start_date, registration_deadline, 
                                program_type, period_name, start_date
                                FROM academic_periods 
                                WHERE id = ?";
            $stmt = $conn->prepare($period_check_sql);
            $stmt->bind_param('i', $form_data['academic_period_id']);
            $stmt->execute();
            $period_result = $stmt->get_result();

            if ($period_result->num_rows === 0) {
                $errors[] = 'The selected period is invalid.';
            } else {
                $period = $period_result->fetch_assoc();

                // Check registration availability
                $today = date('Y-m-d');
                $reg_start = $period['registration_start_date'];
                $reg_deadline = $period['registration_deadline'];

                if ($reg_start && $reg_start > $today) {
                    $errors[] = 'Registration for ' . $period['period_name'] . ' opens on ' . date('F j, Y', strtotime($reg_start));
                } elseif ($reg_deadline && $reg_deadline < $today) {
                    $errors[] = 'Registration deadline for ' . $period['period_name'] . ' has passed (was ' . date('F j, Y', strtotime($reg_deadline)) . ')';
                } elseif (!$reg_start || !$reg_deadline) {
                    $errors[] = 'Registration dates are not set for this period. Please contact support.';
                }
            }
            $stmt->close();
        }

        // If no errors, process registration
        if (empty($errors)) {
            require_once __DIR__ . '/../../includes/auth.php';

            // Remove confirm_password from data
            unset($form_data['confirm_password']);

            // Set learning mode preference based on program type
            if ($form_data['program_type'] === 'onsite') {
                $form_data['learning_mode_preference'] = 'onsite_only';
            } elseif ($form_data['program_type'] === 'online') {
                $form_data['learning_mode_preference'] = 'online_only';
            } elseif ($form_data['program_type'] === 'school') {
                $form_data['learning_mode_preference'] = 'school_based';
            }

            $result = registerUser($form_data);

            if ($result['success']) {
                $success = true;
                $_SESSION['success'] = $result['message'];
                $_SESSION['login_email'] = $form_data['email'];
                $_SESSION['registered_program_type'] = $form_data['program_type'];

                // Redirect to login page after a brief delay
                header("Refresh: 3; url=" . BASE_URL . "modules/auth/login.php");
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply to Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="../../../images/favicon.ico">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --onsite-color: #8b5cf6;
            --online-color: #10b981;
            --school-color: #8b4513;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            padding: 1rem;
        }

        .register-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .register-header h1 {
            color: var(--dark);
            font-size: clamp(1.8rem, 5vw, 2.5rem);
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .register-header p {
            color: var(--gray-500);
            font-size: clamp(0.95rem, 3vw, 1.1rem);
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2.5rem;
            position: relative;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 10%;
            right: 10%;
            height: 3px;
            background: var(--gray-200);
            z-index: 1;
        }

        @media (max-width: 768px) {
            .progress-steps::before {
                display: none;
            }
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 2;
            flex: 1;
            min-width: 80px;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 3px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--gray-500);
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .step.active .step-number {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: scale(1.1);
        }

        .step-label {
            font-size: 0.85rem;
            color: var(--gray-500);
            font-weight: 600;
            text-align: center;
        }

        .step.active .step-label {
            color: var(--primary);
        }

        @media (max-width: 480px) {
            .step-label {
                font-size: 0.75rem;
            }

            .step-number {
                width: 36px;
                height: 36px;
            }
        }

        .register-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid var(--gray-200);
        }

        .card-header {
            padding: clamp(1.5rem, 4vw, 2rem);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            text-align: center;
        }

        .card-header h2 {
            font-size: clamp(1.4rem, 4vw, 1.8rem);
            margin-bottom: 0.5rem;
        }

        .card-content {
            padding: clamp(1.5rem, 4vw, 2rem);
        }

        .form-navigation {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-200);
            overflow-x: auto;
        }

        .nav-step {
            flex: 1;
            text-align: center;
            padding: 0.75rem 0.5rem;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray-500);
            white-space: nowrap;
            min-width: 80px;
            transition: all 0.2s ease;
            position: relative;
        }

        .nav-step:hover {
            color: var(--primary);
        }

        .nav-step.active {
            color: var(--primary);
            font-weight: 600;
        }

        .nav-step::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        .nav-step.active::after {
            width: 100%;
        }

        .form-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .form-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-title {
            font-size: 1.25rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
        }

        .program-type-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 640px) {
            .program-type-selector {
                grid-template-columns: 1fr;
            }
        }

        .program-type-card {
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            position: relative;
            overflow: hidden;
        }

        .program-type-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .program-type-card.active {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(30, 64, 175, 0.05));
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.15);
        }

        .program-type-card.onsite.active {
            border-color: var(--onsite-color);
        }

        .program-type-card.online.active {
            border-color: var(--online-color);
        }

        .program-type-card.school.active {
            border-color: var(--school-color);
        }

        .program-type-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        .program-type-card.onsite .program-type-icon {
            color: var(--onsite-color);
        }

        .program-type-card.online .program-type-icon {
            color: var(--online-color);
        }

        .program-type-card.school .program-type-icon {
            color: var(--school-color);
        }

        .program-type-card h3 {
            margin-bottom: 0.75rem;
            color: var(--dark);
            font-size: 1.3rem;
            font-weight: 600;
            text-align: center;
        }

        .program-type-card p {
            color: var(--gray-600);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }

        .program-type-badge {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 1rem;
            text-align: center;
            width: 100%;
        }

        .badge-onsite {
            background: rgba(139, 92, 246, 0.1);
            color: var(--onsite-color);
        }

        .badge-online {
            background: rgba(16, 185, 129, 0.1);
            color: var(--online-color);
        }

        .badge-school {
            background: rgba(139, 69, 19, 0.1);
            color: var(--school-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

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
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
            padding-right: 3rem;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-control.invalid {
            border-color: var(--danger);
            background-color: rgba(239, 68, 68, 0.05);
        }

        .error-message {
            color: var(--danger);
            font-size: 0.85rem;
            margin-top: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert {
            padding: 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-color: #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .school-selection-container {
            margin-top: 2rem;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .school-selection-container.active {
            display: block;
        }

        .school-search-container {
            margin-bottom: 1.5rem;
        }

        .school-search-box {
            position: relative;
        }

        .school-search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            z-index: 2;
        }

        .school-search-input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 3rem;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .school-search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .schools-list-container {
            max-height: 300px;
            overflow-y: auto;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            background: white;
        }

        .school-option {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .school-option:last-child {
            border-bottom: none;
        }

        .school-option:hover {
            background: var(--gray-50);
        }

        .school-option.selected {
            background: rgba(37, 99, 235, 0.1);
            border-left: 3px solid var(--primary);
        }

        .school-option i {
            color: var(--school-color);
            font-size: 1.25rem;
        }

        .school-name {
            font-weight: 500;
            color: var(--dark);
        }

        .school-info {
            font-size: 0.85rem;
            color: var(--gray-500);
        }

        .selected-school-display {
            background: var(--gray-50);
            padding: 1rem 1.25rem;
            border-radius: 10px;
            margin-top: 1rem;
            border: 1px solid var(--gray-200);
            display: none;
            align-items: center;
            gap: 1rem;
        }

        .selected-school-display.active {
            display: flex;
        }

        .selected-school-display i {
            color: var(--school-color);
            font-size: 1.5rem;
        }

        .selected-school-name {
            font-weight: 600;
            color: var(--dark);
        }

        .change-school-btn {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .period-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin: 1.5rem 0;
        }

        @media (max-width: 640px) {
            .period-options {
                grid-template-columns: 1fr;
            }
        }

        .period-option {
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            position: relative;
            overflow: hidden;
        }

        .period-option:hover:not(.disabled) {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }

        .period-option.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(30, 64, 175, 0.05));
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.15);
        }

        .period-option.open {
            border-left: 4px solid var(--success);
        }

        .period-option.upcoming {
            border-left: 4px solid var(--warning);
        }

        .period-option.closed {
            border-left: 4px solid var(--danger);
            opacity: 0.7;
            cursor: not-allowed;
            background: var(--gray-50);
        }

        .period-option.disabled {
            opacity: 0.7;
            cursor: not-allowed;
            background: var(--gray-50);
        }

        .period-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .period-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-weight: 600;
        }

        .badge-open {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-upcoming {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-closed {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .period-dates {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
        }

        .period-dates i {
            width: 16px;
            margin-right: 0.25rem;
            color: var(--gray-400);
        }

        .period-deadline {
            font-size: 0.85rem;
            padding-top: 0.5rem;
            margin-top: 0.5rem;
            border-top: 1px dashed var(--gray-200);
            color: var(--gray-600);
        }

        .period-deadline i {
            margin-right: 0.25rem;
            color: var(--danger);
        }

        .period-countdown {
            font-size: 0.8rem;
            margin-top: 0.25rem;
            font-weight: 500;
        }

        .countdown-urgent {
            color: var(--danger);
        }

        .countdown-warning {
            color: var(--warning);
        }

        .program-info {
            background: var(--gray-50);
            padding: 1.25rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border: 1px solid var(--gray-200);
        }

        .program-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .program-type-indicator {
            font-size: 0.85rem;
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
        }

        .character-count {
            text-align: right;
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 2rem;
            margin-top: 2rem;
            border-top: 2px solid var(--gray-200);
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.85rem 1.75rem;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .login-link {
            text-align: center;
            margin-top: 2rem;
            color: var(--gray-500);
            font-size: 0.95rem;
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .password-toggle-wrapper {
            position: relative;
        }

        .password-toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-500);
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
        }

        .password-toggle-btn:hover {
            color: var(--primary);
        }

        @media (max-width: 480px) {
            body {
                padding: 0.75rem;
            }

            .card-content {
                padding: 1.25rem;
            }

            .form-control {
                font-size: 16px;
            }

            .btn {
                width: 100%;
            }

            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="register-container">
        <div class="register-header">
            <h1>Apply to Impact Digital Academy</h1>
            <p>Start your digital transformation journey as a student. Fill out the application form below.</p>
        </div>

        <div class="progress-steps">
            <div class="step active">
                <div class="step-number">1</div>
                <div class="step-label">Program Type</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-label">Account Info</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-label">Personal Info</div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div class="step-label">Application</div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Application Submitted Successfully!</strong><br>
                    <?php echo htmlspecialchars($result['message'] ?? ''); ?><br>
                    <small>You will be redirected to the login page in a few seconds.</small>
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

        <div class="register-card">
            <div class="card-header">
                <h2>Student Application Form</h2>
                <p>Complete all sections below to apply as a student</p>
            </div>

            <div class="card-content">
                <form method="POST" id="applicationForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="school_name" id="school_name" value="<?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?>">
                    <input type="hidden" name="applying_as" value="student">
                    <input type="hidden" name="academic_period_id" id="academic_period_id" value="<?php echo htmlspecialchars($_POST['academic_period_id'] ?? ''); ?>">

                    <!-- Form Navigation -->
                    <div class="form-navigation">
                        <div class="nav-step active" onclick="showStep(1)">Program Type</div>
                        <div class="nav-step" onclick="showStep(2)">Account Info</div>
                        <div class="nav-step" onclick="showStep(3)">Personal Info</div>
                        <div class="nav-step" onclick="showStep(4)">Application</div>
                    </div>

                    <!-- Step 1: Program Type & Period Selection -->
                    <div id="step1" class="form-section active">
                        <h3 class="section-title">Select Program Type</h3>

                        <div class="program-type-selector">
                            <div class="program-type-card onsite <?php echo ($_POST['program_type'] ?? '') === 'onsite' ? 'active' : ''; ?>"
                                onclick="selectProgramType('onsite')">
                                <div class="program-type-icon"><i class="fas fa-building"></i></div>
                                <h3>Onsite Program</h3>
                                <p><strong>Monthly Cohorts</strong> (4 weeks per month)</p>
                                <p>• Monthly intake throughout the year</p>
                                <p>• Physical classroom attendance</p>
                                <p>• Hands-on practical sessions</p>
                                <p>• Face-to-face instruction</p>
                                <div class="program-type-badge badge-onsite">Onsite Learning</div>
                            </div>

                            <div class="program-type-card online <?php echo ($_POST['program_type'] ?? '') === 'online' ? 'active' : ''; ?>"
                                onclick="selectProgramType('online')">
                                <div class="program-type-icon"><i class="fas fa-laptop-code"></i></div>
                                <h3>Online Program</h3>
                                <p><strong>Block-based structure</strong> (8 weeks per block)</p>
                                <p>• 6 blocks per year</p>
                                <p>• Flexible virtual learning</p>
                                <p>• Recorded and live sessions</p>
                                <p>• Global student community</p>
                                <div class="program-type-badge badge-online">Online Learning</div>
                            </div>

                            <div class="program-type-card school <?php echo ($_POST['program_type'] ?? '') === 'school' ? 'active' : ''; ?>"
                                onclick="selectProgramType('school')">
                                <div class="program-type-icon"><i class="fas fa-school"></i></div>
                                <h3>School-based Program</h3>
                                <p><strong>School partnership programs</strong></p>
                                <p>• Customized digital curriculum</p>
                                <p>• Integrated school schedules</p>
                                <p>• In-school delivery</p>
                                <p>• School-specific support</p>
                                <div class="program-type-badge badge-school">School Learning</div>
                            </div>
                        </div>

                        <input type="hidden" name="program_type" id="program_type" value="<?php echo htmlspecialchars($_POST['program_type'] ?? 'online'); ?>">

                        <!-- School Selection Section -->
                        <div class="school-selection-container <?php echo ($_POST['program_type'] ?? '') === 'school' ? 'active' : ''; ?>">
                            <h3 class="section-title">Select Your School</h3>

                            <div class="school-search-container">
                                <div class="school-search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="text" id="schoolSearch" class="school-search-input"
                                        placeholder="Search for your school by name..."
                                        oninput="filterSchools()">
                                </div>
                            </div>

                            <div id="selectedSchoolDisplay" class="selected-school-display <?php echo !empty($_POST['school_name']) ? 'active' : ''; ?>">
                                <i class="fas fa-school"></i>
                                <div class="selected-school-text">
                                    <div class="selected-school-name"><?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?></div>
                                    <div class="school-info">School-based program</div>
                                </div>
                                <button type="button" class="change-school-btn" onclick="showSchoolSelection()">
                                    <i class="fas fa-edit"></i> Change
                                </button>
                            </div>

                            <div id="schoolsList" class="schools-list-container" style="<?php echo empty($_POST['school_name']) ? 'display: block;' : 'display: none;'; ?>">
                                <?php if (!empty($schools)): ?>
                                    <?php foreach ($schools as $school): ?>
                                        <div class="school-option <?php echo ($_POST['school_id'] ?? '') == $school['id'] ? 'selected' : ''; ?>"
                                            onclick="selectSchool(<?php echo $school['id']; ?>, '<?php echo htmlspecialchars(addslashes($school['name'])); ?>')">
                                            <i class="fas fa-university"></i>
                                            <div>
                                                <div class="school-name"><?php echo htmlspecialchars($school['name']); ?></div>
                                                <div class="school-info"><?php echo htmlspecialchars($school['city']); ?>, <?php echo htmlspecialchars($school['state']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-schools-found" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                        <i class="fas fa-school" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                        <p>No school partnerships available at the moment.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="school_id" id="school_id" value="<?php echo htmlspecialchars($_POST['school_id'] ?? ''); ?>">
                        </div>

                        <!-- Period Selection Section -->
                        <div id="period-selection-section" style="margin-top: 2rem; <?php echo !isset($_POST['program_type']) ? 'display: none;' : ''; ?>">
                            <h3 class="section-title">Select Your Preferred Start Period</h3>

                            <!-- Onsite Monthly Cohorts -->
                            <div id="onsite-periods" style="display: <?php echo ($_POST['program_type'] ?? '') === 'onsite' ? 'block' : 'none'; ?>;">
                                <p style="color: var(--gray-600); margin-bottom: 1rem;">Choose your preferred monthly cohort:</p>
                                <div class="period-options">
                                    <?php foreach ($academic_periods['onsite'] as $period):
                                        $is_selected = ($_POST['academic_period_id'] ?? '') == $period['id'];
                                        $status_class = $period['registration_status'];
                                        $can_select = $period['registration_status'] === 'open';
                                    ?>
                                        <div class="period-option <?php echo $status_class; ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo !$can_select ? 'disabled' : ''; ?>"
                                            onclick="<?php echo $can_select ? "selectPeriod(" . $period['id'] . ", '" . addslashes($period['period_name']) . "')" : ''; ?>"
                                            <?php echo !$can_select ? 'style="cursor: not-allowed;"' : ''; ?>>
                                            <div class="period-name">
                                                <?php echo htmlspecialchars($period['period_name']); ?>
                                                <span class="period-badge badge-<?php echo $status_class; ?>">
                                                    <?php if ($status_class === 'open'): ?>
                                                        <i class="fas fa-check-circle"></i> Open
                                                    <?php elseif ($status_class === 'upcoming'): ?>
                                                        <i class="fas fa-clock"></i> Upcoming
                                                    <?php else: ?>
                                                        <i class="fas fa-times-circle"></i> Closed
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="period-dates">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo date('M j', strtotime($period['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($period['end_date'])); ?>
                                                (<?php echo $period['duration_weeks']; ?> weeks)
                                            </div>
                                            <?php if ($period['registration_start_date']): ?>
                                                <div class="period-dates">
                                                    <i class="fas fa-door-open"></i>
                                                    Registration opens: <?php echo date('M j, Y', strtotime($period['registration_start_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($period['registration_deadline']): ?>
                                                <div class="period-deadline">
                                                    <i class="fas fa-hourglass-end"></i>
                                                    Deadline: <?php echo date('M j, Y', strtotime($period['registration_deadline'])); ?>
                                                    <?php if ($status_class === 'open' && $period['days_remaining'] <= 7): ?>
                                                        <div class="period-countdown countdown-<?php echo $period['days_remaining'] <= 3 ? 'urgent' : 'warning'; ?>">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                            Only <?php echo $period['days_remaining']; ?> day<?php echo $period['days_remaining'] > 1 ? 's' : ''; ?> left!
                                                        </div>
                                                    <?php elseif ($status_class === 'upcoming' && $period['days_until_open'] <= 7): ?>
                                                        <div class="period-countdown countdown-warning">
                                                            <i class="fas fa-clock"></i>
                                                            Opens in <?php echo $period['days_until_open']; ?> day<?php echo $period['days_until_open'] > 1 ? 's' : ''; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($academic_periods['onsite'])): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                            <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                            <p>No monthly cohorts available at the moment.</p>
                                            <p>Please check back later or contact admissions.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Online Blocks -->
                            <div id="online-periods" style="display: <?php echo ($_POST['program_type'] ?? '') === 'online' ? 'block' : 'none'; ?>;">
                                <p style="color: var(--gray-600); margin-bottom: 1rem;">Choose your preferred block:</p>
                                <div class="period-options">
                                    <?php foreach ($academic_periods['online'] as $period):
                                        $is_selected = ($_POST['academic_period_id'] ?? '') == $period['id'];
                                        $status_class = $period['registration_status'];
                                        $can_select = $period['registration_status'] === 'open';
                                    ?>
                                        <div class="period-option <?php echo $status_class; ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo !$can_select ? 'disabled' : ''; ?>"
                                            onclick="<?php echo $can_select ? "selectPeriod(" . $period['id'] . ", '" . addslashes($period['period_name']) . "')" : ''; ?>"
                                            <?php echo !$can_select ? 'style="cursor: not-allowed;"' : ''; ?>>
                                            <div class="period-name">
                                                <?php echo htmlspecialchars($period['period_name']); ?>
                                                <span class="period-badge badge-<?php echo $status_class; ?>">
                                                    <?php if ($status_class === 'open'): ?>
                                                        <i class="fas fa-check-circle"></i> Open
                                                    <?php elseif ($status_class === 'upcoming'): ?>
                                                        <i class="fas fa-clock"></i> Upcoming
                                                    <?php else: ?>
                                                        <i class="fas fa-times-circle"></i> Closed
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="period-dates">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo date('M j', strtotime($period['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($period['end_date'])); ?>
                                                (<?php echo $period['duration_weeks']; ?> weeks)
                                            </div>
                                            <?php if ($period['registration_start_date']): ?>
                                                <div class="period-dates">
                                                    <i class="fas fa-door-open"></i>
                                                    Registration opens: <?php echo date('M j, Y', strtotime($period['registration_start_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($period['registration_deadline']): ?>
                                                <div class="period-deadline">
                                                    <i class="fas fa-hourglass-end"></i>
                                                    Deadline: <?php echo date('M j, Y', strtotime($period['registration_deadline'])); ?>
                                                    <?php if ($status_class === 'open' && $period['days_remaining'] <= 7): ?>
                                                        <div class="period-countdown countdown-<?php echo $period['days_remaining'] <= 3 ? 'urgent' : 'warning'; ?>">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                            Only <?php echo $period['days_remaining']; ?> day<?php echo $period['days_remaining'] > 1 ? 's' : ''; ?> left!
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($academic_periods['online'])): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                            <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                            <p>No upcoming blocks available at the moment.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- School Terms -->
                            <div id="school-periods" style="display: <?php echo ($_POST['program_type'] ?? '') === 'school' ? 'block' : 'none'; ?>;">
                                <p style="color: var(--gray-600); margin-bottom: 1rem;">Choose your preferred school term:</p>
                                <div class="period-options">
                                    <?php foreach ($academic_periods['school'] as $period):
                                        $is_selected = ($_POST['academic_period_id'] ?? '') == $period['id'];
                                        $status_class = $period['registration_status'];
                                        $can_select = $period['registration_status'] === 'open';
                                    ?>
                                        <div class="period-option <?php echo $status_class; ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo !$can_select ? 'disabled' : ''; ?>"
                                            onclick="<?php echo $can_select ? "selectPeriod(" . $period['id'] . ", '" . addslashes($period['period_name']) . "')" : ''; ?>"
                                            <?php echo !$can_select ? 'style="cursor: not-allowed;"' : ''; ?>>
                                            <div class="period-name">
                                                <?php echo htmlspecialchars($period['period_name']); ?>
                                                <span class="period-badge badge-<?php echo $status_class; ?>">
                                                    <?php if ($status_class === 'open'): ?>
                                                        <i class="fas fa-check-circle"></i> Open
                                                    <?php elseif ($status_class === 'upcoming'): ?>
                                                        <i class="fas fa-clock"></i> Upcoming
                                                    <?php else: ?>
                                                        <i class="fas fa-times-circle"></i> Closed
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="period-dates">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo date('M j', strtotime($period['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($period['end_date'])); ?>
                                                (<?php echo $period['duration_weeks']; ?> weeks)
                                            </div>
                                            <?php if ($period['registration_start_date']): ?>
                                                <div class="period-dates">
                                                    <i class="fas fa-door-open"></i>
                                                    Registration opens: <?php echo date('M j, Y', strtotime($period['registration_start_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($period['registration_deadline']): ?>
                                                <div class="period-deadline">
                                                    <i class="fas fa-hourglass-end"></i>
                                                    Deadline: <?php echo date('M j, Y', strtotime($period['registration_deadline'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($academic_periods['school'])): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                            <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                            <p>No upcoming school terms available at the moment.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div id="selected-period-display" style="display: none; margin: 1rem 0; padding: 1rem; background: var(--gray-50); border-radius: 10px; border: 1px solid var(--gray-200);">
                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                            <span id="selected-period-text"></span>
                        </div>

                        <div class="form-actions">
                            <div style="flex: 1;"></div>
                            <button type="button" class="btn btn-primary btn-nav" onclick="showStep(2)">
                                Next: Account Info <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Account Information -->
                    <div id="step2" class="form-section">
                        <h3 class="section-title">Account Information</h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="email" class="required">Email Address</label>
                                <input type="email" id="email" name="email" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                    required placeholder="your.email@example.com">
                            </div>

                            <div class="form-group">
                                <label for="password" class="required">Password</label>
                                <div class="password-toggle-wrapper">
                                    <input type="password" id="password" name="password" class="form-control"
                                        required minlength="8" placeholder="At least 8 characters">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password" class="required">Confirm Password</label>
                                <div class="password-toggle-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                        required placeholder="Re-enter your password">
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary btn-nav" onclick="showStep(1)">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn btn-primary btn-nav" onclick="showStep(3)">
                                Next: Personal Info <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Personal Information -->
                    <div id="step3" class="form-section">
                        <h3 class="section-title">Personal Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="first_name" class="required">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                                    required placeholder="Enter your first name">
                            </div>

                            <div class="form-group">
                                <label for="last_name" class="required">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                                    required placeholder="Enter your last name">
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                    placeholder="+234 800 000 0000">
                            </div>

                            <div class="form-group">
                                <label for="date_of_birth">Date of Birth</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>"
                                    max="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" class="form-control">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo ($_POST['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($_POST['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo ($_POST['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="address">Address</label>
                                <textarea id="address" name="address" class="form-control"
                                    placeholder="Enter your full address"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>"
                                    placeholder="Enter your city">
                            </div>

                            <div class="form-group">
                                <label for="state">State</label>
                                <input type="text" id="state" name="state" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>"
                                    placeholder="Enter your state">
                            </div>

                            <div class="form-group">
                                <label for="country">Country</label>
                                <input type="text" id="country" name="country" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['country'] ?? 'Nigeria'); ?>"
                                    placeholder="Enter your country">
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary btn-nav" onclick="showStep(2)">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn btn-primary btn-nav" onclick="showStep(4)">
                                Next: Application Details <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 4: Application Details -->
                    <div id="step4" class="form-section">
                        <h3 class="section-title">Program Selection</h3>

                        <div class="form-group">
                            <label for="program_id" class="<?php echo ($_POST['program_type'] ?? 'online') !== 'school' ? 'required' : ''; ?>">Select Program</label>
                            <select id="program_id" name="program_id" class="form-control">
                                <option value="">-- Select a Program --</option>
                                <?php
                                $selected_program_type = $_POST['program_type'] ?? 'online';
                                foreach ($programs as $program):
                                    if ($program['program_type'] !== $selected_program_type && $selected_program_type !== 'school') {
                                        continue;
                                    }
                                ?>
                                    <option value="<?php echo $program['id']; ?>"
                                        <?php echo ($_POST['program_id'] ?? '') == $program['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="program-note" style="color: var(--gray-500); <?php echo $selected_program_type === 'school' ? 'display: block;' : 'display: none;'; ?>">
                                School-based programs are selected in Step 1
                            </small>
                        </div>

                        <h3 class="section-title">Application Details</h3>

                        <div class="form-group">
                            <label for="motivation">Motivation Statement</label>
                            <textarea id="motivation" name="motivation" class="form-control"
                                placeholder="Tell us why you want to join the academy..."
                                rows="4"><?php echo htmlspecialchars($_POST['motivation'] ?? ''); ?></textarea>
                            <div class="character-count"><span id="motivation_count">0</span> / 500 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="qualifications">Educational Background & Qualifications</label>
                            <textarea id="qualifications" name="qualifications" class="form-control"
                                placeholder="List your educational background..."
                                rows="4"><?php echo htmlspecialchars($_POST['qualifications'] ?? ''); ?></textarea>
                            <div class="character-count"><span id="qualifications_count">0</span> / 500 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="experience">Relevant Experience</label>
                            <textarea id="experience" name="experience" class="form-control"
                                placeholder="Describe your relevant experience..."
                                rows="4"><?php echo htmlspecialchars($_POST['experience'] ?? ''); ?></textarea>
                            <div class="character-count"><span id="experience_count">0</span> / 500 characters</div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary btn-nav" onclick="showStep(3)">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button type="submit" class="btn btn-primary btn-submit">
                                <i class="fas fa-paper-plane"></i> Submit Application
                            </button>
                        </div>
                    </div>
                </form>

                <div class="login-link">
                    Already have an account? <a href="<?php echo BASE_URL; ?>modules/auth/login.php">Login here</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentStep = 1;
        let allSchools = <?php echo json_encode($schools); ?>;
        let selectedPeriodId = '<?php echo $_POST['academic_period_id'] ?? ''; ?>';
        let selectedPeriodName = '';

        document.addEventListener('DOMContentLoaded', function() {
            initializePasswordToggles();
            initializeCharacterCounters();
            initializeStepNavigation();

            <?php if (!empty($_POST['academic_period_id'])): ?>
                // If a period was already selected, show it
                document.getElementById('selected-period-display').style.display = 'block';
                document.getElementById('selected-period-text').innerHTML = 'Selected: <?php echo addslashes($_POST['period_name'] ?? ''); ?>';
            <?php endif; ?>
        });

        function initializePasswordToggles() {
            ['password', 'confirm_password'].forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field) return;

                const wrapper = field.closest('.password-toggle-wrapper');
                if (!wrapper) return;

                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'password-toggle-btn';
                toggle.innerHTML = '<i class="fas fa-eye"></i>';

                toggle.onclick = function() {
                    const type = field.type === 'password' ? 'text' : 'password';
                    field.type = type;
                    this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
                };

                wrapper.appendChild(toggle);
                field.style.paddingRight = '40px';
            });
        }

        function initializeCharacterCounters() {
            ['motivation', 'qualifications', 'experience'].forEach(id => {
                const textarea = document.getElementById(id);
                const counter = document.getElementById(id + '_count');
                if (textarea && counter) {
                    textarea.addEventListener('input', function() {
                        counter.textContent = this.value.length;
                    });
                    counter.textContent = textarea.value.length;
                }
            });
        }

        function initializeStepNavigation() {
            document.querySelectorAll('.nav-step').forEach((nav, index) => {
                nav.addEventListener('click', () => showStep(index + 1));
            });
        }

        function showStep(step) {
            if (step === currentStep) return;

            // Validate current step before moving forward
            if (step > currentStep && !validateStep(currentStep)) {
                return;
            }

            // Hide all steps
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
            });

            // Show target step
            document.getElementById(`step${step}`).classList.add('active');

            // Update navigation
            document.querySelectorAll('.nav-step').forEach((nav, index) => {
                nav.classList.toggle('active', index === step - 1);
            });

            // Update progress steps
            document.querySelectorAll('.step').forEach((stepEl, index) => {
                stepEl.classList.toggle('active', index < step);
            });

            currentStep = step;
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        function validateStep(step) {
            switch (step) {
                case 1:
                    return validateStep1();
                case 2:
                    return validateStep2();
                case 3:
                    return validateStep3();
                default:
                    return true;
            }
        }

        function validateStep1() {
            const programType = document.getElementById('program_type').value;
            const periodId = document.getElementById('academic_period_id').value;

            if (!programType) {
                alert('Please select a program type');
                return false;
            }

            if (!periodId) {
                alert('Please select your preferred start period');
                return false;
            }

            if (programType === 'school') {
                const schoolId = document.getElementById('school_id').value;
                if (!schoolId) {
                    alert('Please select a school');
                    return false;
                }
            }

            return true;
        }

        function validateStep2() {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;

            if (!email || !email.includes('@')) {
                alert('Please enter a valid email address');
                return false;
            }

            if (!password || password.length < 8) {
                alert('Password must be at least 8 characters');
                return false;
            }

            if (password !== confirm) {
                alert('Passwords do not match');
                return false;
            }

            return true;
        }

        function validateStep3() {
            const firstName = document.getElementById('first_name').value;
            const lastName = document.getElementById('last_name').value;

            if (!firstName || !lastName) {
                alert('Please enter your full name');
                return false;
            }

            return true;
        }

        function selectProgramType(type) {
            document.getElementById('program_type').value = type;

            // Update card styles
            document.querySelectorAll('.program-type-card').forEach(card => {
                card.classList.remove('active');
            });
            document.querySelector(`.program-type-card.${type}`).classList.add('active');

            // Show/hide school selection
            const schoolSection = document.querySelector('.school-selection-container');
            if (type === 'school') {
                schoolSection.classList.add('active');
            } else {
                schoolSection.classList.remove('active');
            }

            // Show appropriate periods
            document.getElementById('onsite-periods').style.display = type === 'onsite' ? 'block' : 'none';
            document.getElementById('online-periods').style.display = type === 'online' ? 'block' : 'none';
            document.getElementById('school-periods').style.display = type === 'school' ? 'block' : 'none';

            // Show period selection section
            document.getElementById('period-selection-section').style.display = 'block';

            // Clear previously selected period
            document.getElementById('academic_period_id').value = '';
            document.getElementById('selected-period-display').style.display = 'none';

            // Update program dropdown
            updateProgramDropdown(type);
        }

        function selectPeriod(periodId, periodName) {
            document.getElementById('academic_period_id').value = periodId;
            selectedPeriodName = periodName;

            // Update display
            document.getElementById('selected-period-display').style.display = 'block';
            document.getElementById('selected-period-text').innerHTML = `Selected: ${periodName}`;

            // Update selected class on period options
            document.querySelectorAll('.period-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
        }

        function updateProgramDropdown(programType) {
            const programSelect = document.getElementById('program_id');
            const programNote = document.getElementById('program-note');

            if (programType === 'school') {
                programSelect.disabled = true;
                programNote.style.display = 'block';
            } else {
                programSelect.disabled = false;
                programNote.style.display = 'none';
            }
        }

        function selectSchool(schoolId, schoolName) {
            document.getElementById('school_id').value = schoolId;
            document.getElementById('school_name').value = schoolName;

            // Update display
            const display = document.getElementById('selectedSchoolDisplay');
            document.querySelector('.selected-school-name').textContent = schoolName;
            display.classList.add('active');
            display.style.display = 'flex';

            // Hide schools list
            document.getElementById('schoolsList').style.display = 'none';
            document.getElementById('schoolSearch').value = '';
        }

        function showSchoolSelection() {
            document.getElementById('selectedSchoolDisplay').classList.remove('active');
            document.getElementById('schoolsList').style.display = 'block';
        }

        function filterSchools() {
            const search = document.getElementById('schoolSearch').value.toLowerCase();
            document.querySelectorAll('.school-option').forEach(option => {
                const name = option.querySelector('.school-name').textContent.toLowerCase();
                option.style.display = name.includes(search) ? 'flex' : 'none';
            });
        }
    </script>
</body>

</html>