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

// Get role from query parameter (always student now, but keep for backward compatibility)
$role = 'student'; // Force to student only

// Get database connection
$conn = getDBConnection();

// Get programs for dropdown (with program type)
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

// Get upcoming academic periods for all program types
$academic_periods = [
    'onsite' => [],
    'online' => [],
    'school' => []
];

// Get upcoming terms (onsite)
$terms_sql = "SELECT id, period_name, academic_year, start_date, end_date 
              FROM academic_periods 
              WHERE program_type = 'onsite' 
              AND period_type = 'term' 
              AND status IN ('upcoming', 'active')
              AND (start_date > CURDATE() OR status = 'active')
              ORDER BY start_date LIMIT 3";
$terms_result = $conn->query($terms_sql);
if ($terms_result) {
    $academic_periods['onsite'] = $terms_result->fetch_all(MYSQLI_ASSOC);
}

// Get upcoming blocks (online)
$blocks_sql = "SELECT id, period_name, academic_year, start_date, end_date 
               FROM academic_periods 
               WHERE program_type = 'online' 
               AND period_type = 'block' 
               AND status IN ('upcoming', 'active')
               AND (start_date > CURDATE() OR status = 'active')
               ORDER BY start_date LIMIT 3";
$blocks_result = $conn->query($blocks_sql);
if ($blocks_result) {
    $academic_periods['online'] = $blocks_result->fetch_all(MYSQLI_ASSOC);
}

// Get upcoming school terms
$school_terms_sql = "SELECT id, period_name, academic_year, start_date, end_date 
                     FROM academic_periods 
                     WHERE program_type = 'school' 
                     AND period_type = 'term' 
                     AND status IN ('upcoming', 'active')
                     AND (start_date > CURDATE() OR status = 'active')
                     ORDER BY start_date LIMIT 3";
$school_terms_result = $conn->query($school_terms_sql);
if ($school_terms_result) {
    $academic_periods['school'] = $school_terms_result->fetch_all(MYSQLI_ASSOC);
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
        // Collect form data - always 'student' for applying_as
        $form_data = [
            'email' => trim($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? '',
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'applying_as' => 'student', // Force to student only
            'program_type' => $_POST['program_type'] ?? 'online',
            'program_id' => !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null,
            'school_id' => !empty($_POST['school_id']) ? (int)$_POST['school_id'] : null,
            'school_name' => trim($_POST['school_name'] ?? ''),
            'preferred_term' => trim($_POST['preferred_term'] ?? ''),
            'preferred_block' => trim($_POST['preferred_block'] ?? ''),
            'preferred_school_term' => trim($_POST['preferred_school_term'] ?? ''),
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

        // Validate program selection for students
        if ($form_data['program_type'] === 'school' && empty($form_data['school_id'])) {
            $errors[] = 'Please select a school for school-based program.';
        } elseif ($form_data['program_type'] !== 'school' && empty($form_data['program_id'])) {
            $errors[] = 'Please select a program.';
        }

        // Validate program type selection
        if (!in_array($form_data['program_type'], ['onsite', 'online', 'school'])) {
            $errors[] = 'Please select a valid program type.';
        }

        // Validate term/block/school term selection based on program type
        if ($form_data['program_type'] === 'onsite' && empty($form_data['preferred_term'])) {
            $errors[] = 'Please select your preferred term for onsite program.';
        }

        if ($form_data['program_type'] === 'online' && empty($form_data['preferred_block'])) {
            $errors[] = 'Please select your preferred block for online program.';
        }

        if ($form_data['program_type'] === 'school' && empty($form_data['preferred_school_term'])) {
            $errors[] = 'Please select your preferred term for school-based program.';
        }

        // If no errors, process registration
        if (empty($errors)) {
            require_once __DIR__ . '/../../includes/auth.php';

            // Remove confirm_password from data before sending to registration function
            unset($form_data['confirm_password']);

            // Convert learning_mode_preference based on program_type
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

                // Store success message in session for redirect
                $_SESSION['success'] = $result['message'];

                // Store email for login page
                $_SESSION['login_email'] = $form_data['email'];

                // Store program type for login context
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

    <!-- Favicon -->
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
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .register-container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 0 0.5rem;
        }

        .register-header h1 {
            color: var(--dark);
            font-size: clamp(1.8rem, 5vw, 2.5rem);
            margin-bottom: 0.5rem;
            line-height: 1.2;
            font-weight: 700;
        }

        .register-header p {
            color: var(--gray-500);
            font-size: clamp(0.95rem, 3vw, 1.1rem);
            line-height: 1.5;
            max-width: 600px;
            margin: 0 auto;
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            position: relative;
            padding: 0 0.5rem;
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
            padding: 0.5rem;
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
            font-size: 0.95rem;
            position: relative;
            z-index: 2;
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
            line-height: 1.3;
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
                font-size: 0.85rem;
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
            font-weight: 600;
        }

        .card-header p {
            opacity: 0.9;
            font-size: clamp(0.9rem, 3vw, 1rem);
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
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .form-navigation::-webkit-scrollbar {
            display: none;
        }

        .nav-step {
            flex: 1;
            text-align: center;
            padding: 0.75rem 0.5rem;
            cursor: pointer;
            position: relative;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--gray-500);
            white-space: nowrap;
            min-width: 80px;
            transition: all 0.2s ease;
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
            border-radius: 3px 3px 0 0;
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
            position: relative;
            font-weight: 600;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: var(--primary);
            border-radius: 2px;
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
                gap: 1rem;
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
            height: 100%;
            display: flex;
            flex-direction: column;
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

        .program-type-card.onsite {
            border-left: 4px solid var(--onsite-color);
        }

        .program-type-card.online {
            border-left: 4px solid var(--online-color);
        }

        .program-type-card.school {
            border-left: 4px solid var(--school-color);
        }

        .program-type-card.onsite.active {
            border-color: var(--onsite-color);
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.05), rgba(124, 58, 237, 0.05));
        }

        .program-type-card.online.active {
            border-color: var(--online-color);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(5, 150, 105, 0.05));
        }

        .program-type-card.school.active {
            border-color: var(--school-color);
            background: linear-gradient(135deg, rgba(139, 69, 19, 0.05), rgba(101, 51, 15, 0.05));
        }

        .program-type-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: center;
        }

        .program-type-card.onsite .program-type-icon {
            color: var(--onsite-color);
        }

        .program-type-card.online .program-type-card.online .program-type-icon {
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
            flex-grow: 1;
        }

        .program-type-card p strong {
            color: var(--dark);
        }

        .program-type-badge {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 1rem;
            text-align: center;
            align-self: center;
        }

        .badge-onsite {
            background: rgba(139, 92, 246, 0.1);
            color: var(--onsite-color);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .badge-online {
            background: rgba(16, 185, 129, 0.1);
            color: var(--online-color);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-school {
            background: rgba(139, 69, 19, 0.1);
            color: var(--school-color);
            border: 1px solid rgba(139, 69, 19, 0.2);
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
            font-size: 0.95rem;
        }

        .school-info {
            margin-top: 0.25rem;
            font-size: 0.85rem;
            color: var(--gray-500);
        }

        .no-schools-found {
            text-align: center;
            padding: 2rem;
            color: var(--gray-500);
        }

        .no-schools-found i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .selected-school-display {
            background: var(--gray-50);
            padding: 1rem 1.25rem;
            border-radius: 10px;
            margin-top: 1rem;
            border: 1px solid var(--gray-200);
            display: none;
        }

        .selected-school-display.active {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .selected-school-display i {
            color: var(--school-color);
            font-size: 1.5rem;
        }

        .selected-school-text {
            flex-grow: 1;
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

        .change-school-btn:hover {
            text-decoration: underline;
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
                gap: 1rem;
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
            color: var(--gray-800);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
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
            line-height: 1.5;
            font-family: inherit;
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
            align-items: flex-start;
            gap: 0.5rem;
            line-height: 1.4;
        }

        .error-message i {
            font-size: 0.8rem;
            margin-top: 0.1rem;
            flex-shrink: 0;
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

        .alert-success i {
            color: #059669;
            margin-right: 0.5rem;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .alert-error i {
            color: #dc2626;
            margin-right: 0.5rem;
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
            white-space: nowrap;
            touch-action: manipulation;
        }

        .btn:active {
            transform: scale(0.98);
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

        .btn-submit {
            min-width: 200px;
        }

        .btn-nav {
            min-width: 140px;
        }

        @media (max-width: 640px) {
            .form-actions {
                flex-direction: column;
                width: 100%;
            }

            .btn-submit,
            .btn-nav {
                width: 100%;
                min-width: unset;
            }
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
            transition: color 0.2s ease;
        }

        .login-link a:hover {
            text-decoration: underline;
            color: var(--secondary);
        }

        .program-info {
            background: var(--gray-50);
            padding: 1.25rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .program-info {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .program-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .program-fee {
            color: var(--success);
            font-weight: 700;
            font-size: 1.2rem;
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

        .character-count.warning {
            color: var(--warning);
        }

        .character-count.error {
            color: var(--danger);
        }

        .period-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        @media (max-width: 640px) {
            .period-options {
                grid-template-columns: 1fr;
            }
        }

        .period-option {
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .period-option:hover {
            border-color: var(--primary);
            background: rgba(37, 99, 235, 0.05);
            transform: translateY(-2px);
        }

        .period-option.selected {
            border-color: var(--primary);
            background: rgba(37, 99, 235, 0.1);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
        }

        .period-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .period-dates {
            font-size: 0.9rem;
            color: var(--gray-600);
            line-height: 1.5;
        }

        .period-academic-year {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
            font-weight: 500;
        }

        /* Mobile-specific optimizations */
        @media (max-width: 480px) {
            body {
                padding: 0.75rem;
            }

            .register-card {
                border-radius: 12px;
            }

            .card-header,
            .card-content {
                padding: 1.25rem;
            }

            .form-control {
                padding: 0.75rem;
                font-size: 16px;
                /* Prevents iOS zoom on focus */
            }

            .btn {
                padding: 0.75rem 1.5rem;
            }

            .program-type-card {
                padding: 1.25rem;
            }
        }

        /* iOS specific fixes */
        @supports (-webkit-touch-callout: none) {
            .form-control {
                font-size: 16px;
            }

            select.form-control {
                font-size: 16px;
            }
        }

        /* Accessibility improvements */
        .btn:focus-visible,
        .form-control:focus-visible,
        .program-type-card:focus-visible,
        .period-option:focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 2px;
        }

        /* Print styles */
        @media print {

            .form-navigation,
            .form-actions,
            .login-link {
                display: none;
            }

            .register-card {
                box-shadow: none;
                border: 1px solid #ccc;
            }

            .form-section {
                display: block !important;
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
                <div class="step-label">Application Details</div>
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

                    <!-- Form Navigation -->
                    <div class="form-navigation">
                        <div class="nav-step active" onclick="showStep(1)" tabindex="0" role="button" aria-label="Go to Program Type section">
                            <span>Program Type</span>
                        </div>
                        <div class="nav-step" onclick="showStep(2)" tabindex="0" role="button" aria-label="Go to Account Info section">
                            <span>Account Info</span>
                        </div>
                        <div class="nav-step" onclick="showStep(3)" tabindex="0" role="button" aria-label="Go to Personal Info section">
                            <span>Personal Info</span>
                        </div>
                        <div class="nav-step" onclick="showStep(4)" tabindex="0" role="button" aria-label="Go to Application Details section">
                            <span>Application Details</span>
                        </div>
                    </div>

                    <!-- Step 1: Program Type Selection -->
                    <div id="step1" class="form-section active">
                        <h3 class="section-title">Select Program Type</h3>
                        <p style="color: var(--gray-600); margin-bottom: 1.5rem; line-height: 1.6;">
                            Choose between our onsite (term-based), online (block-based), and school-based programs.
                            Each offers comprehensive digital skills training with different delivery modes.
                        </p>

                        <div class="program-type-selector">
                            <div class="program-type-card onsite <?php echo ($_POST['program_type'] ?? '') === 'onsite' ? 'active' : ''; ?>"
                                onclick="selectProgramType('onsite')"
                                tabindex="0"
                                role="button"
                                aria-label="Select Onsite Program">
                                <div class="program-type-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <h3>Onsite Program</h3>
                                <p><strong>Term-based structure</strong> (10 weeks per term)</p>
                                <p>• 3 terms per academic year</p>
                                <p>• Physical classroom attendance</p>
                                <p>• Hands-on practical sessions</p>
                                <p>• Regular face-to-face interactions</p>
                                <div class="program-type-badge badge-onsite">Onsite Learning</div>
                            </div>

                            <div class="program-type-card online <?php echo ($_POST['program_type'] ?? '') === 'online' ? 'active' : ''; ?>"
                                onclick="selectProgramType('online')"
                                tabindex="0"
                                role="button"
                                aria-label="Select Online Program">
                                <div class="program-type-icon">
                                    <i class="fas fa-laptop-code"></i>
                                </div>
                                <h3>Online Program</h3>
                                <p><strong>Block-based structure</strong> (8 weeks per block)</p>
                                <p>• 5 blocks per academic year</p>
                                <p>• Flexible virtual learning</p>
                                <p>• Recorded and live sessions</p>
                                <p>• Global student community</p>
                                <div class="program-type-badge badge-online">Online Learning</div>
                            </div>

                            <div class="program-type-card school <?php echo ($_POST['program_type'] ?? '') === 'school' ? 'active' : ''; ?>"
                                onclick="selectProgramType('school')"
                                tabindex="0"
                                role="button"
                                aria-label="Select School-based Program">
                                <div class="program-type-icon">
                                    <i class="fas fa-school"></i>
                                </div>
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

                        <!-- School Selection Section (only shown for school-based programs) -->
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
                                    <div class="no-schools-found">
                                        <i class="fas fa-school"></i>
                                        <p>No school partnerships available at the moment.</p>
                                        <p>Please contact admissions for school-based program inquiries.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="school_id" id="school_id" value="<?php echo htmlspecialchars($_POST['school_id'] ?? ''); ?>">
                        </div>

                        <!-- Academic Period Selection -->
                        <div id="academic-period-section" style="display: <?php echo isset($_POST['program_type']) ? 'block' : 'none'; ?>; margin-top: 2rem;">
                            <h3 class="section-title">Select Preferred Start Period</h3>

                            <!-- Onsite Terms -->
                            <div id="onsite-terms" style="display: <?php echo ($_POST['program_type'] ?? '') === 'onsite' ? 'block' : 'none'; ?>;">
                                <p style="color: var(--gray-600); margin-bottom: 1rem;">Select your preferred term start date:</p>
                                <div class="period-options">
                                    <?php foreach ($academic_periods['onsite'] as $term): ?>
                                        <div class="period-option <?php echo ($_POST['preferred_term'] ?? '') === $term['period_name'] ? 'selected' : ''; ?>"
                                            onclick="selectPeriod('onsite', '<?php echo $term['period_name']; ?>')"
                                            tabindex="0"
                                            role="button">
                                            <div class="period-name"><?php echo htmlspecialchars($term['period_name']); ?></div>
                                            <div class="period-dates">
                                                <?php echo date('M j', strtotime($term['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($term['end_date'])); ?>
                                            </div>
                                            <div class="period-academic-year"><?php echo htmlspecialchars($term['academic_year']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($academic_periods['onsite'])): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                            <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                            <p>No upcoming terms available at the moment.</p>
                                            <p>Please check back later or contact admissions.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="preferred_term" id="preferred_term" value="<?php echo htmlspecialchars($_POST['preferred_term'] ?? ''); ?>">
                            </div>

                            <!-- Online Blocks -->
                            <div id="online-blocks" style="display: <?php echo ($_POST['program_type'] ?? '') === 'online' ? 'block' : 'none'; ?>;">
                                <p style="color: var(--gray-600); margin-bottom: 1rem;">Select your preferred block start date:</p>
                                <div class="period-options">
                                    <?php foreach ($academic_periods['online'] as $block): ?>
                                        <div class="period-option <?php echo ($_POST['preferred_block'] ?? '') === $block['period_name'] ? 'selected' : ''; ?>"
                                            onclick="selectPeriod('online', '<?php echo $block['period_name']; ?>')"
                                            tabindex="0"
                                            role="button">
                                            <div class="period-name"><?php echo htmlspecialchars($block['period_name']); ?></div>
                                            <div class="period-dates">
                                                <?php echo date('M j', strtotime($block['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($block['end_date'])); ?>
                                            </div>
                                            <div class="period-academic-year"><?php echo htmlspecialchars($block['academic_year']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($academic_periods['online'])): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                            <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                            <p>No upcoming blocks available at the moment.</p>
                                            <p>Please check back later or contact admissions.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="preferred_block" id="preferred_block" value="<?php echo htmlspecialchars($_POST['preferred_block'] ?? ''); ?>">
                            </div>

                            <!-- School Terms -->
                            <div id="school-terms" style="display: <?php echo ($_POST['program_type'] ?? '') === 'school' ? 'block' : 'none'; ?>;">
                                <p style="color: var(--gray-600); margin-bottom: 1rem;">Select your preferred school term start date:</p>
                                <div class="period-options">
                                    <?php foreach ($academic_periods['school'] as $term): ?>
                                        <div class="period-option <?php echo ($_POST['preferred_school_term'] ?? '') === $term['period_name'] ? 'selected' : ''; ?>"
                                            onclick="selectPeriod('school', '<?php echo $term['period_name']; ?>')"
                                            tabindex="0"
                                            role="button">
                                            <div class="period-name"><?php echo htmlspecialchars($term['period_name']); ?></div>
                                            <div class="period-dates">
                                                <?php echo date('M j', strtotime($term['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($term['end_date'])); ?>
                                            </div>
                                            <div class="period-academic-year"><?php echo htmlspecialchars($term['academic_year']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($academic_periods['school'])): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                            <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                            <p>No upcoming school terms available at the moment.</p>
                                            <p>Please check back later or contact admissions.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="preferred_school_term" id="preferred_school_term" value="<?php echo htmlspecialchars($_POST['preferred_school_term'] ?? ''); ?>">
                            </div>
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
                                    required
                                    placeholder="your.email@example.com"
                                    autocomplete="email">
                            </div>

                            <div class="form-group">
                                <label for="password" class="required">Password</label>
                                <input type="password" id="password" name="password" class="form-control"
                                    required minlength="8"
                                    placeholder="At least 8 characters"
                                    autocomplete="new-password">
                            </div>

                            <div class="form-group">
                                <label for="confirm_password" class="required">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                    required
                                    placeholder="Re-enter your password"
                                    autocomplete="new-password">
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
                                    required
                                    placeholder="Enter your first name"
                                    autocomplete="given-name">
                            </div>

                            <div class="form-group">
                                <label for="last_name" class="required">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                                    required
                                    placeholder="Enter your last name"
                                    autocomplete="family-name">
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                    placeholder="+234 800 000 0000"
                                    autocomplete="tel">
                            </div>

                            <div class="form-group">
                                <label for="date_of_birth">Date of Birth</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>"
                                    max="<?php echo date('Y-m-d'); ?>"
                                    autocomplete="bday">
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
                                    placeholder="Enter your full address"
                                    autocomplete="street-address"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>"
                                    placeholder="Enter your city"
                                    autocomplete="address-level2">
                            </div>

                            <div class="form-group">
                                <label for="state">State</label>
                                <input type="text" id="state" name="state" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>"
                                    placeholder="Enter your state"
                                    autocomplete="address-level1">
                            </div>

                            <div class="form-group">
                                <label for="country">Country</label>
                                <input type="text" id="country" name="country" class="form-control"
                                    value="<?php echo htmlspecialchars($_POST['country'] ?? 'Nigeria'); ?>"
                                    placeholder="Enter your country"
                                    autocomplete="country">
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
                            <select id="program_id" name="program_id" class="form-control"
                                <?php echo ($_POST['program_type'] ?? 'online') !== 'school' ? 'required' : ''; ?>>
                                <option value="">-- Select a Program --</option>
                                <?php
                                $programs_by_type = [
                                    'onsite' => [],
                                    'online' => []
                                ];

                                foreach ($programs as $program) {
                                    if (isset($programs_by_type[$program['program_type']])) {
                                        $programs_by_type[$program['program_type']][] = $program;
                                    }
                                }

                                $selected_program_type = $_POST['program_type'] ?? 'online';
                                ?>

                                <?php if ($selected_program_type !== 'school' && !empty($programs_by_type[$selected_program_type])): ?>
                                    <optgroup label="<?php echo $selected_program_type === 'onsite' ? 'Onsite Programs' : 'Online Programs'; ?>">
                                        <?php foreach ($programs_by_type[$selected_program_type] as $program): ?>
                                            <option value="<?php echo $program['id']; ?>"
                                                <?php echo ($_POST['program_id'] ?? '') == $program['id'] ? 'selected' : ''; ?>
                                                data-duration="<?php echo $program['duration_mode']; ?>">
                                                <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['name']); ?>
                                                - <?php echo $program['program_type'] === 'onsite' ? 'Onsite' : 'Online'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php elseif ($selected_program_type !== 'school'): ?>
                                    <option value="">No programs available for <?php echo $selected_program_type === 'onsite' ? 'onsite' : 'online'; ?> learning</option>
                                <?php endif; ?>
                            </select>
                            <small id="program-note" style="color: var(--gray-500); <?php echo ($selected_program_type ?? 'online') === 'school' ? 'display: block;' : 'display: none;'; ?>">
                                School-based programs are selected in Step 1
                            </small>
                        </div>

                        <div id="program-details" style="display: none; margin-bottom: 1.5rem;">
                            <div class="program-info">
                                <div>
                                    <span class="program-name" id="selected-program-name"></span><br>
                                    <small id="selected-program-duration"></small>
                                </div>
                                <div>
                                    <span class="program-type-indicator" id="selected-program-type"></span>
                                </div>
                            </div>
                        </div>

                        <h3 class="section-title">Application Details</h3>

                        <div class="form-group">
                            <label for="motivation">Motivation Statement</label>
                            <textarea id="motivation" name="motivation" class="form-control"
                                placeholder="Tell us why you want to join the academy, your goals, and what you hope to achieve..."
                                rows="4"><?php echo htmlspecialchars($_POST['motivation'] ?? ''); ?></textarea>
                            <div class="character-count">
                                <span id="motivation_count">0</span> / 500 characters
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="qualifications">Educational Background & Qualifications</label>
                            <textarea id="qualifications" name="qualifications" class="form-control"
                                placeholder="List your educational background, degrees, certifications, and relevant qualifications..."
                                rows="4"><?php echo htmlspecialchars($_POST['qualifications'] ?? ''); ?></textarea>
                            <div class="character-count">
                                <span id="qualifications_count">0</span> / 500 characters
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="experience">Relevant Experience</label>
                            <textarea id="experience" name="experience" class="form-control"
                                placeholder="Describe your relevant work experience, skills, and achievements..."
                                rows="4"><?php echo htmlspecialchars($_POST['experience'] ?? ''); ?></textarea>
                            <div class="character-count">
                                <span id="experience_count">0</span> / 500 characters
                            </div>
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

                <div class="login-link" style="margin-top: 0.5rem; font-size: 0.85rem;">
                    <em>Note: This application is for students only. Instructors are added by administrators.</em>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentStep = 1;
        let isProcessingStep = false;
        let formData = {};
        let allSchools = <?php echo json_encode($schools); ?>;

        // Initialize on DOM load
        document.addEventListener('DOMContentLoaded', function() {
            // Load saved form data if any
            loadSavedFormData();

            // Initialize character counters
            initializeCharacterCounters();

            // Set up password validation and visibility toggle
            setupPasswordFields();

            // Initialize program dropdown
            setupProgramSelection();

            // Set up form submission
            setupFormSubmission();

            // Initialize step navigation
            initializeStepNavigation();

            // Set up program type selection
            setupProgramTypeSelection();

            // Set up period selection
            setupPeriodSelection();

            // Set up school search functionality
            setupSchoolSearch();

            // Initialize based on POST data
            initializeFromPostData();
        });

        // School search functionality
        function setupSchoolSearch() {
            const searchInput = document.getElementById('schoolSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    filterSchools();
                    saveFormData();
                });
            }
        }

        function filterSchools() {
            const searchInput = document.getElementById('schoolSearch');
            const searchTerm = searchInput.value.toLowerCase();
            const schoolOptions = document.querySelectorAll('.school-option');
            let found = false;

            schoolOptions.forEach(option => {
                const schoolName = option.querySelector('.school-name').textContent.toLowerCase();
                if (schoolName.includes(searchTerm) || searchTerm === '') {
                    option.style.display = 'flex';
                    found = true;
                } else {
                    option.style.display = 'none';
                }
            });

            // Show "no results" message if no schools match
            const noResultsDiv = document.querySelector('.no-schools-found');
            if (schoolOptions.length > 0 && !found && searchTerm !== '') {
                if (!noResultsDiv) {
                    const schoolsList = document.getElementById('schoolsList');
                    const message = document.createElement('div');
                    message.className = 'no-schools-found';
                    message.innerHTML = `
                        <i class="fas fa-search"></i>
                        <p>No schools found matching "${searchTerm}"</p>
                        <p>Try a different search term or contact admissions</p>
                    `;
                    schoolsList.appendChild(message);
                }
            } else if (noResultsDiv && (found || searchTerm === '')) {
                noResultsDiv.remove();
            }
        }

        function selectSchool(schoolId, schoolName) {
            document.getElementById('school_id').value = schoolId;
            document.getElementById('school_name').value = schoolName;

            const selectedDisplay = document.getElementById('selectedSchoolDisplay');
            const schoolNameElement = document.querySelector('.selected-school-name');
            schoolNameElement.textContent = schoolName;

            selectedDisplay.style.display = 'flex';
            selectedDisplay.classList.add('active');
            document.getElementById('schoolsList').style.display = 'none';

            // Clear search
            document.getElementById('schoolSearch').value = '';
            filterSchools();

            saveFormData();
        }

        function showSchoolSelection() {
            document.getElementById('selectedSchoolDisplay').style.display = 'none';
            document.getElementById('selectedSchoolDisplay').classList.remove('active');
            document.getElementById('schoolsList').style.display = 'block';
        }

        // Form data persistence
        function loadSavedFormData() {
            try {
                const saved = sessionStorage.getItem('registrationFormData');
                if (saved) {
                    formData = JSON.parse(saved);

                    // Restore form values
                    Object.keys(formData).forEach(key => {
                        const element = document.querySelector(`[name="${key}"]`);
                        if (element) {
                            if (element.type === 'radio' || element.type === 'checkbox') {
                                if (element.value === formData[key]) {
                                    element.checked = true;
                                }
                            } else {
                                element.value = formData[key];
                            }

                            // Trigger change for selects
                            if (element.tagName === 'SELECT') {
                                element.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                }
            } catch (e) {
                console.warn('Failed to load saved form data:', e);
            }
        }

        function saveFormData() {
            try {
                const form = document.getElementById('applicationForm');
                const data = new FormData(form);
                const formObject = {};

                for (let [key, value] of data.entries()) {
                    formObject[key] = value;
                }

                // Save program type and school selections
                formObject['program_type'] = document.getElementById('program_type').value;
                formObject['school_name'] = document.getElementById('school_name').value;
                formObject['school_id'] = document.getElementById('school_id').value;

                sessionStorage.setItem('registrationFormData', JSON.stringify(formObject));
                formData = formObject;
            } catch (e) {
                console.warn('Failed to save form data:', e);
            }
        }

        function clearSavedFormData() {
            sessionStorage.removeItem('registrationFormData');
            formData = {};
        }

        // Step navigation
        function initializeStepNavigation() {
            // Navigation step clicks
            document.querySelectorAll('.nav-step').forEach((nav, index) => {
                nav.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetStep = index + 1;
                    if (targetStep !== currentStep) {
                        navigateToStep(targetStep);
                    }
                });
            });

            // Next button handlers
            document.addEventListener('click', function(e) {
                const nextBtn = e.target.closest('.btn-nav');
                if (!nextBtn) return;

                e.preventDefault();

                if (nextBtn.textContent.includes('Next')) {
                    if (validateCurrentStep()) {
                        navigateToStep(currentStep + 1);
                    }
                }
            });

            // Back button handlers
            document.addEventListener('click', function(e) {
                const backBtn = e.target.closest('.btn-nav');
                if (!backBtn) return;

                if (backBtn.textContent.includes('Back')) {
                    e.preventDefault();
                    navigateToStep(currentStep - 1);
                }
            });
        }

        function navigateToStep(stepNumber) {
            // Prevent multiple rapid calls
            if (isProcessingStep || stepNumber < 1 || stepNumber > 4) {
                return;
            }

            // Don't navigate to current step
            if (stepNumber === currentStep) {
                return;
            }

            // Moving forward requires validation
            if (stepNumber > currentStep && !validateCurrentStep()) {
                return;
            }

            isProcessingStep = true;

            try {
                // Hide all steps
                document.querySelectorAll('.form-section').forEach(section => {
                    section.classList.remove('active');
                });

                // Show target step
                const targetStep = document.getElementById(`step${stepNumber}`);
                if (targetStep) {
                    targetStep.classList.add('active');
                }

                // Update navigation indicators
                updateNavigationIndicators(stepNumber);

                // Save form data before moving
                saveFormData();

                // Scroll to form
                scrollToForm();

                currentStep = stepNumber;

            } catch (error) {
                console.error('Error navigating to step:', error);
            } finally {
                // Reset processing flag with delay
                setTimeout(() => {
                    isProcessingStep = false;
                }, 300);
            }
        }

        function updateNavigationIndicators(stepNumber) {
            // Update nav steps
            document.querySelectorAll('.nav-step').forEach((nav, index) => {
                nav.classList.remove('active');
                if (index === stepNumber - 1) {
                    nav.classList.add('active');
                }
            });

            // Update progress steps
            document.querySelectorAll('.step').forEach((step, index) => {
                if (index < stepNumber) {
                    step.classList.add('active');
                } else {
                    step.classList.remove('active');
                }
            });
        }

        function scrollToForm() {
            setTimeout(() => {
                const card = document.querySelector('.register-card');
                if (card) {
                    window.scrollTo({
                        top: card.offsetTop - 50,
                        behavior: 'smooth'
                    });
                }
            }, 100);
        }

        // Step validation
        function validateCurrentStep() {
            switch (currentStep) {
                case 1:
                    return validateStep1();
                case 2:
                    return validateStep2();
                case 3:
                    return validateStep3();
                case 4:
                    return validateStep4();
                default:
                    return true;
            }
        }

        function validateStep1() {
            const programType = document.getElementById('program_type').value;
            const preferredTerm = document.getElementById('preferred_term').value;
            const preferredBlock = document.getElementById('preferred_block').value;
            const preferredSchoolTerm = document.getElementById('preferred_school_term').value;
            const schoolId = document.getElementById('school_id').value;
            const schoolName = document.getElementById('school_name').value;

            let isValid = true;

            if (!programType) {
                showFieldError('program_type', 'Please select a program type');
                isValid = false;
            } else {
                clearFieldError('program_type');
            }

            // Validate period selection based on program type
            if (programType === 'onsite' && !preferredTerm) {
                showFieldError('preferred_term', 'Please select a preferred term');
                isValid = false;
            } else if (programType === 'onsite') {
                clearFieldError('preferred_term');
            }

            if (programType === 'online' && !preferredBlock) {
                showFieldError('preferred_block', 'Please select a preferred block');
                isValid = false;
            } else if (programType === 'online') {
                clearFieldError('preferred_block');
            }

            if (programType === 'school' && !preferredSchoolTerm) {
                showFieldError('preferred_school_term', 'Please select a preferred school term');
                isValid = false;
            } else if (programType === 'school') {
                clearFieldError('preferred_school_term');
            }

            // Validate school selection for school-based programs
            if (programType === 'school' && (!schoolId || !schoolName)) {
                showFieldError('school_id', 'Please select a school');
                isValid = false;
            } else if (programType === 'school') {
                clearFieldError('school_id');
            }

            if (!isValid) {
                // Ensure user sees the error
                navigateToStep(1);
            }

            return isValid;
        }

        function validateStep2() {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            let isValid = true;

            // Email validation
            if (!email) {
                showFieldError('email', 'Email is required');
                isValid = false;
            } else if (!isValidEmail(email)) {
                showFieldError('email', 'Please enter a valid email address');
                isValid = false;
            } else {
                clearFieldError('email');
            }

            // Password validation
            if (!password) {
                showFieldError('password', 'Password is required');
                isValid = false;
            } else if (password.length < 8) {
                showFieldError('password', 'Password must be at least 8 characters');
                isValid = false;
            } else {
                clearFieldError('password');
            }

            // Confirm password validation
            if (!confirmPassword) {
                showFieldError('confirm_password', 'Please confirm your password');
                isValid = false;
            } else if (password !== confirmPassword) {
                showFieldError('confirm_password', 'Passwords do not match');
                isValid = false;
            } else {
                clearFieldError('confirm_password');
            }

            if (!isValid) {
                navigateToStep(2);
            }

            return isValid;
        }

        function validateStep3() {
            const firstName = document.getElementById('first_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();

            let isValid = true;

            if (!firstName) {
                showFieldError('first_name', 'First name is required');
                isValid = false;
            } else {
                clearFieldError('first_name');
            }

            if (!lastName) {
                showFieldError('last_name', 'Last name is required');
                isValid = false;
            } else {
                clearFieldError('last_name');
            }

            if (!isValid) {
                navigateToStep(3);
            }

            return isValid;
        }

        function validateStep4() {
            const programType = document.getElementById('program_type').value;
            let isValid = true;

            // Validate program selection for students in non-school programs
            if (programType !== 'school') {
                const programId = document.getElementById('program_id').value;
                if (!programId) {
                    showFieldError('program_id', 'Please select a program');
                    isValid = false;
                } else {
                    clearFieldError('program_id');
                }
            }

            // Validate school selection for school-based student programs
            if (programType === 'school') {
                const schoolId = document.getElementById('school_id').value;
                const schoolName = document.getElementById('school_name').value;
                if (!schoolId || !schoolName) {
                    showFieldError('school_id', 'Please select a school');
                    isValid = false;
                } else {
                    clearFieldError('school_id');
                }
            }

            if (!isValid) {
                navigateToStep(4);
            }

            return isValid;
        }

        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        function showFieldError(fieldName, message) {
            const field = document.querySelector(`[name="${fieldName}"]`);
            if (!field) return;

            field.classList.add('invalid');

            // Remove existing error message
            const existingError = field.parentNode.querySelector('.error-message');
            if (existingError) {
                existingError.remove();
            }

            // Add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            field.parentNode.appendChild(errorDiv);
        }

        function clearFieldError(fieldName) {
            const field = document.querySelector(`[name="${fieldName}"]`);
            if (!field) return;

            field.classList.remove('invalid');

            const errorDiv = field.parentNode.querySelector('.error-message');
            if (errorDiv) {
                errorDiv.remove();
            }
        }

        // Password field setup with visibility toggle
        function setupPasswordFields() {
            // Create password toggle buttons
            createPasswordToggle('password');
            createPasswordToggle('confirm_password');

            // Set up password validation
            setupPasswordValidation();
        }

        function createPasswordToggle(fieldId) {
            const passwordField = document.getElementById(fieldId);
            if (!passwordField) return;

            // Create toggle button wrapper
            const wrapper = document.createElement('div');
            wrapper.className = 'password-toggle-wrapper';
            wrapper.style.position = 'relative';

            // Wrap the password field
            passwordField.parentNode.insertBefore(wrapper, passwordField);
            wrapper.appendChild(passwordField);

            // Create toggle button
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'password-toggle-btn';
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
            toggleBtn.title = 'Show password';

            // Style the button
            toggleBtn.style.cssText = `
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                color: #64748b;
                cursor: pointer;
                font-size: 1rem;
                padding: 5px;
                outline: none;
                transition: color 0.3s;
            `;

            // Add hover effect
            toggleBtn.addEventListener('mouseenter', function() {
                this.style.color = '#2563eb';
            });

            toggleBtn.addEventListener('mouseleave', function() {
                if (!this.classList.contains('active')) {
                    this.style.color = '#64748b';
                }
            });

            wrapper.appendChild(toggleBtn);

            // Add padding to password field for the button
            passwordField.style.paddingRight = '40px';

            // Toggle functionality
            toggleBtn.addEventListener('click', function() {
                const isPassword = passwordField.type === 'password';

                if (isPassword) {
                    // Show password
                    passwordField.type = 'text';
                    this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                    this.title = 'Hide password';
                    this.classList.add('active');
                    this.style.color = '#2563eb';
                } else {
                    // Hide password
                    passwordField.type = 'password';
                    this.innerHTML = '<i class="fas fa-eye"></i>';
                    this.title = 'Show password';
                    this.classList.remove('active');
                    this.style.color = '#64748b';
                }

                // Focus back on the password field
                passwordField.focus();
            });
        }

        function setupPasswordValidation() {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');

            function validatePasswordMatch() {
                const passwordValue = password.value;
                const confirmValue = confirmPassword.value;

                if (confirmValue && passwordValue !== confirmValue) {
                    showFieldError('confirm_password', 'Passwords do not match');
                    confirmPassword.classList.add('invalid');
                } else if (confirmValue) {
                    clearFieldError('confirm_password');
                    confirmPassword.classList.remove('invalid');
                }

                saveFormData();
            }

            password.addEventListener('input', validatePasswordMatch);
            confirmPassword.addEventListener('input', validatePasswordMatch);
        }

        // Program type selection
        function setupProgramTypeSelection() {
            document.querySelectorAll('.program-type-card').forEach(card => {
                card.addEventListener('click', function() {
                    let type = '';
                    if (this.classList.contains('onsite')) type = 'onsite';
                    else if (this.classList.contains('online')) type = 'online';
                    else if (this.classList.contains('school')) type = 'school';
                    selectProgramType(type);
                });
            });
        }

        function selectProgramType(type) {
            // Update hidden field
            document.getElementById('program_type').value = type;

            // Update active class
            document.querySelectorAll('.program-type-card').forEach(card => {
                card.classList.remove('active');
                if (card.classList.contains(type)) {
                    card.classList.add('active');
                }
            });

            // Show/hide school selection section
            const schoolSection = document.querySelector('.school-selection-container');
            const schoolNameInput = document.getElementById('school_name');

            if (type === 'school') {
                schoolSection.classList.add('active');
                if (schoolNameInput) schoolNameInput.required = true;
            } else {
                schoolSection.classList.remove('active');
                if (schoolNameInput) schoolNameInput.required = false;
            }

            // Show academic period section
            document.getElementById('academic-period-section').style.display = 'block';

            // Show/hide appropriate periods
            document.getElementById('onsite-terms').style.display = 'none';
            document.getElementById('online-blocks').style.display = 'none';
            document.getElementById('school-terms').style.display = 'none';

            // Clear all period values
            document.getElementById('preferred_term').value = '';
            document.getElementById('preferred_block').value = '';
            document.getElementById('preferred_school_term').value = '';

            // Clear period selections
            document.querySelectorAll('.period-option').forEach(option => {
                option.classList.remove('selected');
            });

            if (type === 'onsite') {
                document.getElementById('onsite-terms').style.display = 'block';
            } else if (type === 'online') {
                document.getElementById('online-blocks').style.display = 'block';
            } else if (type === 'school') {
                document.getElementById('school-terms').style.display = 'block';
            }

            // Update program dropdown
            updateProgramDropdown(type);

            // Show/hide program selection note
            const programNote = document.getElementById('program-note');
            const programIdSelect = document.getElementById('program_id');

            if (type === 'school') {
                programNote.style.display = 'block';
                if (programIdSelect) {
                    programIdSelect.disabled = true;
                    programIdSelect.required = false;
                }
            } else {
                programNote.style.display = 'none';
                if (programIdSelect) {
                    programIdSelect.disabled = false;
                    programIdSelect.required = true;
                }
            }

            // Save selection
            saveFormData();
        }

        // Period selection
        function setupPeriodSelection() {
            // Onsite terms
            document.querySelectorAll('#onsite-terms .period-option').forEach(option => {
                option.addEventListener('click', function() {
                    const periodName = this.querySelector('.period-name').textContent;
                    selectPeriod('onsite', periodName);
                });
            });

            // Online blocks
            document.querySelectorAll('#online-blocks .period-option').forEach(option => {
                option.addEventListener('click', function() {
                    const periodName = this.querySelector('.period-name').textContent;
                    selectPeriod('online', periodName);
                });
            });

            // School terms
            document.querySelectorAll('#school-terms .period-option').forEach(option => {
                option.addEventListener('click', function() {
                    const periodName = this.querySelector('.period-name').textContent;
                    selectPeriod('school', periodName);
                });
            });
        }

        function selectPeriod(type, periodName) {
            // Clear all period selections first
            document.querySelectorAll('.period-option').forEach(option => {
                option.classList.remove('selected');
            });

            if (type === 'onsite') {
                document.getElementById('preferred_term').value = periodName;
                document.getElementById('preferred_block').value = '';
                document.getElementById('preferred_school_term').value = '';
            } else if (type === 'online') {
                document.getElementById('preferred_block').value = periodName;
                document.getElementById('preferred_term').value = '';
                document.getElementById('preferred_school_term').value = '';
            } else if (type === 'school') {
                document.getElementById('preferred_school_term').value = periodName;
                document.getElementById('preferred_term').value = '';
                document.getElementById('preferred_block').value = '';
            }

            // Highlight selected option
            event.target.closest('.period-option').classList.add('selected');

            // Save selection
            saveFormData();
        }

        // Program dropdown
        function setupProgramSelection() {
            const programSelect = document.getElementById('program_id');

            programSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const programDetails = document.getElementById('program-details');

                if (selectedOption.value) {
                    const programName = selectedOption.textContent;
                    const programType = selectedOption.textContent.includes('Onsite') ? 'Onsite' : 'Online';
                    const durationMode = selectedOption.getAttribute('data-duration');

                    // Update display
                    document.getElementById('selected-program-name').textContent = programName;
                    document.getElementById('selected-program-type').textContent = programType;
                    document.getElementById('selected-program-type').className = `program-type-indicator ${programType === 'Onsite' ? 'badge-onsite' : 'badge-online'}`;

                    // Format duration
                    let durationText = '';
                    if (durationMode === 'termly_10_weeks') {
                        durationText = '10 weeks per term, 3 terms/year';
                    } else if (durationMode === 'block_8_weeks') {
                        durationText = '8 weeks per block, 6 blocks/year';
                    }
                    document.getElementById('selected-program-duration').textContent = durationText;

                    programDetails.style.display = 'block';
                } else {
                    programDetails.style.display = 'none';
                }

                saveFormData();
            });
        }

        function updateProgramDropdown(programType) {
            const programSelect = document.getElementById('program_id');
            const options = programSelect.querySelectorAll('option');
            const programNote = document.getElementById('program-note');

            // Reset to default
            programSelect.value = '';
            document.getElementById('program-details').style.display = 'none';

            if (programType === 'school') {
                programSelect.disabled = true;
                programNote.style.display = 'block';
                return;
            } else {
                programSelect.disabled = false;
                programNote.style.display = 'none';
            }

            // Filter options based on program type
            options.forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    const isOnsite = option.textContent.includes('Onsite');
                    const isOnline = option.textContent.includes('Online');

                    if (programType === 'onsite' && isOnsite) {
                        option.style.display = 'block';
                    } else if (programType === 'online' && isOnline) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                }
            });
        }

        // Character counters
        function initializeCharacterCounters() {
            setupCharacterCounter('motivation', 'motivation_count');
            setupCharacterCounter('qualifications', 'qualifications_count');
            setupCharacterCounter('experience', 'experience_count');
        }

        function setupCharacterCounter(textareaId, countId, maxLength = 500) {
            const textarea = document.getElementById(textareaId);
            const count = document.getElementById(countId);

            if (!textarea || !count) return;

            function updateCount() {
                const length = textarea.value.length;
                count.textContent = length;

                if (length > maxLength) {
                    count.style.color = '#ef4444';
                    textarea.classList.add('invalid');
                } else {
                    count.style.color = '';
                    textarea.classList.remove('invalid');
                }

                saveFormData();
            }

            textarea.addEventListener('input', updateCount);
            updateCount(); // Initialize
        }

        // Form submission
        function setupFormSubmission() {
            const form = document.getElementById('applicationForm');

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // Clear all previous errors
                document.querySelectorAll('.error-message').forEach(el => el.remove());
                document.querySelectorAll('.form-control.invalid').forEach(el => el.classList.remove('invalid'));

                // Validate all steps
                let isValid = true;

                // Step 1 validation
                if (!validateStep1()) {
                    isValid = false;
                    navigateToStep(1);
                }

                // Step 2 validation
                if (isValid && !validateStep2()) {
                    isValid = false;
                    navigateToStep(2);
                }

                // Step 3 validation
                if (isValid && !validateStep3()) {
                    isValid = false;
                    navigateToStep(3);
                }

                // Step 4 validation
                if (isValid && !validateStep4()) {
                    isValid = false;
                    navigateToStep(4);
                }

                // Additional validations
                if (isValid) {
                    const programType = document.getElementById('program_type').value;

                    // Validate program type period selection
                    if (programType === 'onsite' && !document.getElementById('preferred_term').value) {
                        alert('Please select your preferred term for onsite program.');
                        isValid = false;
                        navigateToStep(1);
                    } else if (programType === 'online' && !document.getElementById('preferred_block').value) {
                        alert('Please select your preferred block for online program.');
                        isValid = false;
                        navigateToStep(1);
                    } else if (programType === 'school' && !document.getElementById('preferred_school_term').value) {
                        alert('Please select your preferred term for school-based program.');
                        isValid = false;
                        navigateToStep(1);
                    }

                    // Validate program selection for students
                    if (isValid) {
                        if (programType === 'school') {
                            // Validate school selection for school-based programs
                            const schoolId = document.getElementById('school_id').value;
                            const schoolName = document.getElementById('school_name').value;
                            if (!schoolId || !schoolName) {
                                showFieldError('school_id', 'Please select a school');
                                isValid = false;
                                navigateToStep(1);
                            }
                        } else {
                            // Validate program selection for non-school programs
                            if (!document.getElementById('program_id').value) {
                                showFieldError('program_id', 'Please select a program');
                                isValid = false;
                                navigateToStep(4);
                            }
                        }
                    }
                }

                if (isValid) {
                    // Clear saved data
                    clearSavedFormData();

                    // Submit the form
                    form.submit();
                } else {
                    // Scroll to first error
                    const firstError = form.querySelector('.invalid');
                    if (firstError) {
                        firstError.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        firstError.focus();
                    }
                }
            });
        }

        // Initialize from POST data
        function initializeFromPostData() {
            // Program type
            const programType = document.getElementById('program_type').value;
            if (programType) {
                selectProgramType(programType);

                // Set selected period
                const preferredTerm = document.getElementById('preferred_term').value;
                const preferredBlock = document.getElementById('preferred_block').value;
                const preferredSchoolTerm = document.getElementById('preferred_school_term').value;

                if (programType === 'onsite' && preferredTerm) {
                    document.querySelectorAll('#onsite-terms .period-option').forEach(element => {
                        if (element.querySelector('.period-name').textContent === preferredTerm) {
                            element.classList.add('selected');
                        }
                    });
                } else if (programType === 'online' && preferredBlock) {
                    document.querySelectorAll('#online-blocks .period-option').forEach(element => {
                        if (element.querySelector('.period-name').textContent === preferredBlock) {
                            element.classList.add('selected');
                        }
                    });
                } else if (programType === 'school' && preferredSchoolTerm) {
                    document.querySelectorAll('#school-terms .period-option').forEach(element => {
                        if (element.querySelector('.period-name').textContent === preferredSchoolTerm) {
                            element.classList.add('selected');
                        }
                    });
                }

                // Set selected school
                const schoolId = document.getElementById('school_id').value;
                const schoolName = document.getElementById('school_name').value;
                if (programType === 'school' && schoolId && schoolName) {
                    const selectedDisplay = document.getElementById('selectedSchoolDisplay');
                    const schoolNameElement = document.querySelector('.selected-school-name');
                    schoolNameElement.textContent = schoolName;
                    selectedDisplay.style.display = 'flex';
                    selectedDisplay.classList.add('active');
                    document.getElementById('schoolsList').style.display = 'none';
                }
            }

            // Update program dropdown based on selected program type
            if (programType) {
                updateProgramDropdown(programType);
            }
        }
    </script>
</body>

</html>