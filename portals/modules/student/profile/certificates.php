<?php
// modules/student/profile/certificates.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// Get certificates
$certificates = [];
$sql = "SELECT e.*, cb.batch_code, c.title as course_title, c.course_code,
               p.name as program_name, p.program_code,
               CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
               e.completion_date, e.final_grade, e.certificate_issued_date,
               e.certificate_url, e.certificate_number
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN users i ON cb.instructor_id = i.id
        WHERE e.student_id = ? AND e.certificate_issued = 1
        ORDER BY e.certificate_issued_date DESC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $certificates = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get completed courses without certificates
$completed_courses = [];
$sql = "SELECT e.*, cb.batch_code, c.title as course_title, c.course_code,
               p.name as program_name, p.program_code,
               CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
               e.completion_date, e.final_grade
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN users i ON cb.instructor_id = i.id
        WHERE e.student_id = ? AND e.status = 'completed' AND e.certificate_issued = 0
        ORDER BY e.completion_date DESC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $completed_courses = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get certificate stats
$total_certificates = count($certificates);
$total_completed = count($completed_courses);
$total_courses = $total_certificates + $total_completed;
$completion_rate = $total_courses > 0 ? round(($total_certificates / $total_courses) * 100) : 0;

// Log activity
logActivity($user_id, 'certificates_view', 'Student viewed certificates', $_SERVER['REMOTE_ADDR']);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Certificates - Student Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #edf2ff;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --success-light: #e6f9ff;
            --warning: #f72585;
            --warning-light: #ffe6f0;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }

        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: var(--transition);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }

        .certificates-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        @media (max-width: 768px) {
            .certificates-container {
                padding: 1rem;
            }
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2.5rem;
            text-align: center;
        }

        .page-header h1 {
            color: var(--dark);
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1.125rem;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            padding: 1.75rem;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            text-align: center;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card.success::before {
            background: linear-gradient(90deg, var(--success), #3da8d5);
        }

        .stat-card.warning::before {
            background: linear-gradient(90deg, var(--warning), #e6176f);
        }

        .stat-card.info::before {
            background: linear-gradient(90deg, var(--info), #4895ef);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .stat-card.success .stat-icon {
            background: linear-gradient(135deg, var(--success), #3da8d5);
        }

        .stat-card.warning .stat-icon {
            background: linear-gradient(135deg, var(--warning), #e6176f);
        }

        .stat-card.info .stat-icon {
            background: linear-gradient(135deg, var(--info), #4895ef);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-card.success .stat-value {
            color: var(--success);
        }

        .stat-card.warning .stat-value {
            color: var(--warning);
        }

        .stat-card.info .stat-value {
            color: var(--info);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-subtext {
            font-size: 0.75rem;
            color: var(--gray-light);
            margin-top: 0.5rem;
        }

        /* Tabs */
        .tabs-container {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
        }

        .tabs {
            display: flex;
            gap: 0.5rem;
            border-bottom: 2px solid var(--border);
            padding-bottom: 1rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tab:hover:not(.active) {
            background: var(--primary-light);
            color: var(--primary);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }

        .tab-badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.125rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            min-width: 24px;
            text-align: center;
        }

        .tab.active .tab-badge {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Certificate Cards */
        .certificates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 2rem;
        }

        @media (max-width: 768px) {
            .certificates-grid {
                grid-template-columns: 1fr;
            }
        }

        .certificate-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: 1px solid var(--border);
            position: relative;
        }

        .certificate-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-lg);
        }

        .certificate-card.featured {
            border: 2px solid var(--primary);
            box-shadow: 0 10px 30px rgba(67, 97, 238, 0.2);
        }

        .certificate-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: linear-gradient(135deg, var(--success), #3da8d5);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 1;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .certificate-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .certificate-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            opacity: 0.2;
            animation: moveBackground 20s linear infinite;
        }

        @keyframes moveBackground {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .certificate-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .certificate-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .certificate-program {
            opacity: 0.9;
            font-size: 0.875rem;
            position: relative;
            z-index: 1;
        }

        .certificate-body {
            padding: 2rem;
        }

        .certificate-info {
            margin-bottom: 1.5rem;
        }

        .info-grid {
            display: grid;
            gap: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            padding: 0.75rem;
            background: var(--light);
            border-radius: 8px;
            transition: var(--transition);
        }

        .info-item:hover {
            background: var(--primary-light);
            transform: translateX(5px);
        }

        .info-icon {
            width: 32px;
            height: 32px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: var(--primary);
            font-size: 0.875rem;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .info-value {
            color: var(--gray);
            font-size: 0.875rem;
            line-height: 1.4;
        }

        .grade-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: linear-gradient(135deg, var(--warning), #e6176f);
            color: white;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }

        .certificate-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            transition: var(--transition);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex: 1;
            justify-content: center;
            min-width: 120px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }

        .btn-secondary {
            background: var(--light);
            color: var(--dark);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--border);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #3da8d5);
            color: white;
            box-shadow: 0 4px 12px rgba(76, 201, 240, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 201, 240, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #e6176f);
            color: white;
            box-shadow: 0 4px 12px rgba(247, 37, 133, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(247, 37, 133, 0.4);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            display: block;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .empty-state h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.5rem;
            font-weight: 700;
        }

        .empty-state p {
            color: var(--gray);
            max-width: 500px;
            margin: 0 auto 2rem;
            font-size: 1.125rem;
            line-height: 1.6;
        }

        /* Pending Courses */
        .completed-courses-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .completed-course {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--warning);
            transition: var(--transition);
        }

        .completed-course:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-lg);
        }

        .course-info h4 {
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 1.125rem;
            font-weight: 600;
        }

        .course-info p {
            color: var(--gray);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .course-meta {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--gray-light);
            font-size: 0.75rem;
        }

        /* Footer Navigation */
        .footer-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .page-info {
            color: var(--gray);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Print Styles */
        @media print {

            .tabs-container,
            .stats-cards,
            .footer-nav,
            .btn {
                display: none !important;
            }

            .certificate-card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #000 !important;
            }

            .certificates-grid {
                display: block;
            }
        }
    </style>
</head>

<body>

    <div class="main-content" style="margin-left: 260px; padding: 2rem;">
        <div class="certificates-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1>My Certificates</h1>
                <p>View and download your academic certificates from completed courses</p>
            </div>

            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_certificates; ?></div>
                    <div class="stat-label">Certificates Issued</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_courses; ?></div>
                    <div class="stat-label">Completed Courses</div>
                    <div class="stat-subtext"><?php echo $total_certificates; ?> with certificates</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_completed; ?></div>
                    <div class="stat-label">Pending Certificates</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo $completion_rate; ?>%</div>
                    <div class="stat-label">Completion Rate</div>
                </div>
            </div>

            <!-- Tabs Container -->
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab active" onclick="showTab('certificates')">
                        <i class="fas fa-certificate"></i>
                        Certificates
                        <span class="tab-badge"><?php echo count($certificates); ?></span>
                    </button>
                    <button class="tab" onclick="showTab('pending')">
                        <i class="fas fa-clock"></i>
                        Pending Certificates
                        <span class="tab-badge"><?php echo count($completed_courses); ?></span>
                    </button>
                    <button class="tab" onclick="showTab('share')">
                        <i class="fas fa-share-alt"></i>
                        Share Certificates
                    </button>
                </div>
            </div>

            <!-- Certificates Tab -->
            <div id="certificatesTab" class="tab-content">
                <?php if (!empty($certificates)): ?>
                    <div class="certificates-grid">
                        <?php foreach ($certificates as $index => $cert): ?>
                            <div class="certificate-card <?php echo $index === 0 ? 'featured' : ''; ?>">
                                <?php if ($index === 0): ?>
                                    <div class="certificate-badge">
                                        <i class="fas fa-star"></i> Latest
                                    </div>
                                <?php endif; ?>

                                <div class="certificate-header">
                                    <div class="certificate-icon">
                                        <i class="fas fa-award"></i>
                                    </div>
                                    <div class="certificate-title">
                                        <?php echo htmlspecialchars($cert['course_title']); ?>
                                    </div>
                                    <div class="certificate-program">
                                        <?php echo htmlspecialchars($cert['program_name']); ?>
                                    </div>
                                </div>

                                <div class="certificate-body">
                                    <div class="certificate-info">
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <div class="info-icon">
                                                    <i class="fas fa-id-card"></i>
                                                </div>
                                                <div class="info-content">
                                                    <div class="info-label">Certificate ID</div>
                                                    <div class="info-value">
                                                        <?php echo !empty($cert['certificate_number']) ?
                                                            htmlspecialchars($cert['certificate_number']) :
                                                            'CERT-' . str_pad($cert['id'], 6, '0', STR_PAD_LEFT);
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="info-item">
                                                <div class="info-icon">
                                                    <i class="fas fa-book"></i>
                                                </div>
                                                <div class="info-content">
                                                    <div class="info-label">Course Details</div>
                                                    <div class="info-value">
                                                        <?php echo htmlspecialchars($cert['course_code']); ?> •
                                                        <?php echo htmlspecialchars($cert['batch_code']); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="info-item">
                                                <div class="info-icon">
                                                    <i class="fas fa-user-graduate"></i>
                                                </div>
                                                <div class="info-content">
                                                    <div class="info-label">Instructor</div>
                                                    <div class="info-value">
                                                        <?php echo htmlspecialchars($cert['instructor_name'] ?? 'Not assigned'); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="info-item">
                                                <div class="info-icon">
                                                    <i class="fas fa-calendar-check"></i>
                                                </div>
                                                <div class="info-content">
                                                    <div class="info-label">Issued Date</div>
                                                    <div class="info-value">
                                                        <?php echo !empty($cert['certificate_issued_date']) ?
                                                            date('F j, Y', strtotime($cert['certificate_issued_date'])) :
                                                            date('F j, Y', strtotime($cert['completion_date']));
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="info-item">
                                                <div class="info-icon">
                                                    <i class="fas fa-chart-bar"></i>
                                                </div>
                                                <div class="info-content">
                                                    <div class="info-label">Final Grade</div>
                                                    <div class="info-value">
                                                        <strong>
                                                            <?php echo htmlspecialchars($cert['final_grade'] ?? 'N/A'); ?>
                                                        </strong>
                                                        <?php if (!empty($cert['final_grade']) && is_numeric($cert['final_grade'])): ?>
                                                            <span class="grade-badge">
                                                                <?php echo getGradeLetter($cert['final_grade']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="certificate-actions">
                                        <?php if (!empty($cert['certificate_url'])): ?>
                                            <a href="<?php echo BASE_URL . $cert['certificate_url']; ?>"
                                                target="_blank"
                                                class="btn btn-primary">
                                                <i class="fas fa-eye"></i> View Certificate
                                            </a>
                                            <a href="<?php echo BASE_URL . $cert['certificate_url']; ?>"
                                                download
                                                class="btn btn-secondary">
                                                <i class="fas fa-download"></i> Download PDF
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-warning" onclick="requestCertificate(<?php echo $cert['id']; ?>)">
                                                <i class="fas fa-file-download"></i> Generate Certificate
                                            </button>
                                        <?php endif; ?>

                                        <a href="<?php echo BASE_URL; ?>modules/student/profile/share_certificate.php?certificate_id=<?php echo $cert['id']; ?>"
                                            class="btn btn-success">
                                            <i class="fas fa-share-alt"></i> Share
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-award"></i>
                        <h3>No Certificates Yet</h3>
                        <p>You haven't earned any certificates yet. Complete courses to receive certificates. Your hard work will be rewarded!</p>
                        <div class="nav-buttons" style="justify-content: center; margin-top: 2rem;">
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn btn-primary">
                                <i class="fas fa-chalkboard"></i> Browse Courses
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-tachometer-alt"></i> View Dashboard
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pending Certificates Tab -->
            <div id="pendingTab" class="tab-content" style="display: none;">
                <?php if (!empty($completed_courses)): ?>
                    <div class="completed-courses-list">
                        <?php foreach ($completed_courses as $course): ?>
                            <div class="completed-course">
                                <div class="course-info">
                                    <h4>
                                        <?php echo htmlspecialchars($course['course_title']); ?>
                                        <?php if (!empty($course['final_grade'])): ?>
                                            <span class="grade-badge"><?php echo htmlspecialchars($course['final_grade']); ?></span>
                                        <?php endif; ?>
                                    </h4>
                                    <p>
                                        <?php echo htmlspecialchars($course['program_name']); ?> •
                                        <?php echo htmlspecialchars($course['course_code']); ?>
                                    </p>
                                    <div class="course-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-calendar-check"></i>
                                            <?php echo date('F j, Y', strtotime($course['completion_date'])); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-user-graduate"></i>
                                            <?php echo htmlspecialchars($course['instructor_name'] ?? 'Not assigned'); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-users"></i>
                                            <?php echo htmlspecialchars($course['batch_code']); ?>
                                        </div>
                                    </div>
                                </div>

                                <button class="btn btn-warning" onclick="requestCertificate(<?php echo $course['id']; ?>)">
                                    <i class="fas fa-file-certificate"></i>
                                    Request Certificate
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>No Pending Certificates</h3>
                        <p>All your completed courses have certificates. Keep up the great work! Continue learning to earn more certificates.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Share Tab -->
            <div id="shareTab" class="tab-content" style="display: none;">
                <div class="empty-state">
                    <i class="fas fa-share-alt"></i>
                    <h3>Share Your Certificates</h3>
                    <p>Share your achievements with the world! Connect your certificates to LinkedIn, download them for your portfolio, or share them with potential employers.</p>
                    <div class="nav-buttons" style="justify-content: center; margin-top: 2rem;">
                        <button class="btn btn-primary" onclick="shareAllCertificates()">
                            <i class="fab fa-linkedin"></i> Share to LinkedIn
                        </button>
                        <button class="btn btn-success" onclick="exportAllCertificates()">
                            <i class="fas fa-file-archive"></i> Export All Certificates
                        </button>
                        <a href="<?php echo BASE_URL; ?>modules/student/profile/view.php" class="btn btn-secondary">
                            <i class="fas fa-user"></i> View Profile
                        </a>
                    </div>
                </div>
            </div>

            <!-- Footer Navigation -->
            <div class="footer-nav">
                <div class="nav-buttons">
                    <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/profile/view.php" class="btn btn-secondary">
                        <i class="fas fa-user"></i> View Profile
                    </a>
                    <button class="btn btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Certificates
                    </button>
                </div>
                <div class="page-info">
                    <i class="fas fa-info-circle"></i>
                    Showing <?php echo count($certificates); ?> of <?php echo $total_courses; ?> completed courses
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.getElementById('certificatesTab').style.display = 'none';
            document.getElementById('pendingTab').style.display = 'none';
            document.getElementById('shareTab').style.display = 'none';

            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + 'Tab').style.display = 'block';

            // Add active class to clicked tab
            event.currentTarget.classList.add('active');
        }

        function requestCertificate(enrollmentId) {
            if (confirm('Request certificate for this course?\n\nOnce requested, our team will review and issue your certificate within 3-5 business days.')) {
                // Show loading
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                button.disabled = true;
                button.classList.add('disabled');

                // Simulate API call
                setTimeout(() => {
                    button.innerHTML = '<i class="fas fa-check"></i> Request Sent';
                    button.classList.remove('btn-warning');
                    button.classList.add('btn-success');
                    button.style.backgroundColor = '';

                    // Show success message
                    showNotification('Certificate request submitted successfully! You will be notified when it\'s ready.', 'success');

                    // Reset button after 3 seconds
                    setTimeout(() => {
                        button.innerHTML = originalText;
                        button.disabled = false;
                        button.classList.remove('disabled', 'btn-success');
                        button.classList.add('btn-warning');

                        // Reload the pending tab to show updated status
                        setTimeout(() => {
                            showTab('pending');
                        }, 1000);
                    }, 3000);
                }, 1500);
            }
        }

        function shareAllCertificates() {
            showNotification('Sharing functionality would be implemented here. In a real application, this would connect to LinkedIn API.', 'info');
        }

        function exportAllCertificates() {
            showNotification('Export functionality would be implemented here. This would create a ZIP file of all certificates.', 'info');
        }

        function showNotification(message, type) {
            // Remove existing notification
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            // Create notification
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: linear-gradient(135deg, 
                    ${type === 'success' ? '#4cc9f0' : 
                      type === 'warning' ? '#f72585' : 
                      type === 'info' ? '#4895ef' : '#4361ee'}, 
                    ${type === 'success' ? '#3da8d5' : 
                      type === 'warning' ? '#e6176f' : 
                      type === 'info' ? '#4895ef' : '#7209b7'});
                color: white;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.15);
                z-index: 9999;
                animation: slideIn 0.3s ease;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                min-width: 300px;
                max-width: 400px;
                backdrop-filter: blur(10px);
            `;

            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 
                                 type === 'warning' ? 'exclamation-circle' : 
                                 'info-circle'}" 
                   style="font-size: 1.25rem;"></i>
                <div style="flex: 1;">
                    <strong style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem;">
                        ${type === 'success' ? 'Success!' : 
                          type === 'warning' ? 'Warning!' : 
                          'Information'}
                    </strong>
                    <span style="font-size: 0.875rem;">${message}</span>
                </div>
                <button onclick="this.parentElement.remove()" 
                        style="background: none; border: none; color: white; cursor: pointer; padding: 0.25rem;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            // Remove notification after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            .disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
        `;
        document.head.appendChild(style);

        // Helper function for grade letters (you should implement this in PHP)
        function getGradeLetter(grade) {
            if (grade >= 90) return 'A+';
            if (grade >= 85) return 'A';
            if (grade >= 80) return 'A-';
            if (grade >= 75) return 'B+';
            if (grade >= 70) return 'B';
            if (grade >= 65) return 'B-';
            if (grade >= 60) return 'C+';
            if (grade >= 55) return 'C';
            if (grade >= 50) return 'C-';
            return 'F';
        }
    </script>
</body>

</html>