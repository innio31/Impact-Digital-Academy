<?php
// index.php - Python Essentials 1 Course Landing Page with Access Control
declare(strict_types=1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';
/**
 * Python Essentials 1 Access Controller
 */
class PythonEssentials1AccessController
{
    private $conn;
    private $user_id;
    private $user_role;
    private $user_email;
    private $first_name;
    private $last_name;
    private $allowed_roles = ['student', 'instructor', 'admin'];

    public function __construct()
    {
        $this->validateSession();
        $this->initializeProperties();
        $this->conn = $this->getDatabaseConnection();
        $this->validateAccess();
    }

    /**
     * Validate user session and authentication
     */
    private function validateSession(): void
    {
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], $this->allowed_roles)) {
            $this->redirectToLogin();
        }
    }

    /**
     * Initialize class properties from session
     */
    private function initializeProperties(): void
    {
        $this->user_id = (int)$_SESSION['user_id'];
        $this->user_role = $_SESSION['user_role'];
        $this->user_email = $_SESSION['user_email'] ?? '';
        $this->first_name = $_SESSION['first_name'] ?? '';
        $this->last_name = $_SESSION['last_name'] ?? '';
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection()
    {
        $conn = getDBConnection();

        if (!$conn) {
            $this->handleError("Database connection failed. Please check your configuration.");
        }

        return $conn;
    }

    /**
     * Validate user access to Python Essentials 1 course
     */
    private function validateAccess(): void
    {
        // Admins and instructors have automatic access
        if ($this->user_role === 'admin' || $this->user_role === 'instructor') {
            return;
        }

        // For students, check if enrolled in Python Essentials 1
        $access_count = $this->checkStudentAccess();

        if ($access_count === 0) {
            $this->showAccessDenied();
        }
    }

    /**
     * Check if student has access to Python Essentials 1 course
     */
    private function checkStudentAccess(): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM enrollments e 
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                JOIN programs p ON c.program_id = p.id
                WHERE e.student_id = ? 
                AND e.status IN ('active', 'completed')
                AND (
                    c.title LIKE '%Python Essentials 1%' 
                    OR c.title LIKE '%Python Programming%'
                    OR p.name LIKE '%Python Essentials 1%'
                )";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $this->user_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Redirect to login page
     */
    private function redirectToLogin(): void
    {
        header("Location: " . BASE_URL . "login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }

    /**
     * Show access denied page
     */
    private function showAccessDenied(): void
    {
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Denied - Python Essentials 1</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                }

                .access-denied-container {
                    background: white;
                    border-radius: 10px;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                    padding: 40px;
                    text-align: center;
                    max-width: 500px;
                }

                .icon {
                    font-size: 4rem;
                    color: #306998;
                    margin-bottom: 20px;
                }

                h1 {
                    color: #306998;
                    margin-bottom: 20px;
                }

                p {
                    color: #666;
                    margin-bottom: 30px;
                    line-height: 1.6;
                }

                .btn {
                    display: inline-block;
                    background: #306998;
                    color: white;
                    padding: 12px 24px;
                    border-radius: 50px;
                    text-decoration: none;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    margin: 5px;
                }

                .btn:hover {
                    background: #4B8BBE;
                    transform: translateY(-2px);
                    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
                }

                .btn-secondary {
                    background: #FFD43B;
                    color: #333;
                }

                .btn-secondary:hover {
                    background: #ffc107;
                }
            </style>
        </head>

        <body>
            <div class="access-denied-container">
                <div class="icon">
                    <i class="fab fa-python"></i>
                </div>
                <h1>Access Restricted</h1>
                <p>You need to be enrolled in the <strong>Python Essentials 1</strong> course to access this content.</p>
                <p>If you believe this is an error, please contact your instructor or administrator.</p>

                <div style="margin-top: 30px;">
                    <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn">
                        <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>courses.php" class="btn btn-secondary">
                        <i class="fas fa-graduation-cap"></i> Browse Courses
                    </a>
                </div>

                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #888; font-size: 0.9rem;">
                    <p>Current user: <?php echo htmlspecialchars($this->user_email); ?></p>
                    <p>Role: <?php echo ucfirst($this->user_role); ?></p>
                </div>
            </div>

            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        </body>

        </html>
    <?php
        exit();
    }

    /**
     * Handle errors
     */
    private function handleError(string $message): void
    {
        die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: $message</div>");
    }

    /**
     * Get user display name
     */
    public function getUserDisplayName(): string
    {
        return htmlspecialchars($this->first_name . ' ' . $this->last_name);
    }

    /**
     * Get user role
     */
    public function getUserRole(): string
    {
        return $this->user_role;
    }

    /**
     * Get user email
     */
    public function getUserEmail(): string
    {
        return $this->user_email;
    }

    /**
     * Check if user is enrolled in course
     */
    public function isEnrolled(): bool
    {
        return $this->user_role !== 'student' || $this->checkStudentAccess() > 0;
    }
}

// Initialize access controller
try {
    $accessController = new PythonEssentials1AccessController();

    // Now include your original HTML content
    // You can either:
    // 1. Include it directly (below)
    // 2. Or better: Load it from a separate file
    ?>

    <!-- Rest of your original index.html content goes here -->
    <!-- MODIFY the navigation section to show user info and logout -->

    <?php
    // For this example, I'll show how to modify the header section
    // You'll need to integrate this with your existing HTML
    ?>

    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Python Essentials 1 - Impact Digital Academy</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary: #306998;
                --secondary: #FFD43B;
                --accent: #4B8BBE;
                --light: #f8f9fa;
                --dark: #343a40;
                --success: #28a745;
                --warning: #ffc107;
                --danger: #dc3545;
                --shadow: rgba(0, 0, 0, 0.1);
                --gradient: linear-gradient(135deg, #306998 0%, #4B8BBE 100%);
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }

            body {
                background-color: #f5f7fa;
                color: var(--dark);
                line-height: 1.6;
                overflow-x: hidden;
            }

            /* Navigation */
            header {
                background: var(--gradient);
                color: white;
                padding: 1rem 2rem;
                position: sticky;
                top: 0;
                z-index: 1000;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }

            .nav-container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                max-width: 1200px;
                margin: 0 auto;
            }

            .logo {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .logo-icon {
                font-size: 2rem;
                color: var(--secondary);
            }

            .logo-text h1 {
                font-size: 1.5rem;
                font-weight: 700;
            }

            .logo-text p {
                font-size: 0.8rem;
                opacity: 0.9;
            }

            .nav-links {
                display: flex;
                gap: 1.5rem;
                list-style: none;
            }

            .nav-links a {
                color: white;
                text-decoration: none;
                font-weight: 500;
                padding: 0.5rem 0.8rem;
                border-radius: 4px;
                transition: all 0.3s ease;
            }

            .nav-links a:hover,
            .nav-links a.active {
                background-color: rgba(255, 255, 255, 0.2);
                transform: translateY(-2px);
            }

            .mobile-menu-btn {
                display: none;
                background: none;
                border: none;
                color: white;
                font-size: 1.5rem;
                cursor: pointer;
            }

            /* Hero Section */
            .hero {
                background: var(--gradient);
                color: white;
                padding: 4rem 2rem;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .hero::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.05)"/></svg>');
                background-size: cover;
            }

            .hero-content {
                max-width: 800px;
                margin: 0 auto;
                position: relative;
                z-index: 1;
            }

            .hero h1 {
                font-size: 3rem;
                margin-bottom: 1rem;
                animation: fadeInUp 1s ease;
            }

            .hero p {
                font-size: 1.2rem;
                margin-bottom: 2rem;
                opacity: 0.9;
                animation: fadeInUp 1s ease 0.2s forwards;
                opacity: 0;
            }

            .hero-btns {
                display: flex;
                gap: 1rem;
                justify-content: center;
                margin-top: 2rem;
                animation: fadeInUp 1s ease 0.4s forwards;
                opacity: 0;
            }

            .btn {
                padding: 0.8rem 1.8rem;
                border-radius: 50px;
                font-weight: 600;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.3s ease;
                cursor: pointer;
                border: none;
            }

            .btn-primary {
                background-color: var(--secondary);
                color: var(--dark);
            }

            .btn-primary:hover {
                background-color: #ffc107;
                transform: translateY(-3px);
                box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            }

            .btn-secondary {
                background-color: transparent;
                color: white;
                border: 2px solid white;
            }

            .btn-secondary:hover {
                background-color: white;
                color: var(--primary);
                transform: translateY(-3px);
                box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            }

            /* Python Animation */
            .python-animation {
                margin: 3rem auto;
                width: 200px;
                height: 80px;
                position: relative;
            }

            .python-circle {
                width: 80px;
                height: 80px;
                background: var(--secondary);
                border-radius: 50%;
                position: absolute;
                left: 0;
                animation: pythonMove 3s infinite alternate ease-in-out;
            }

            .python-circle::before {
                content: "";
                position: absolute;
                width: 20px;
                height: 20px;
                background: var(--dark);
                border-radius: 50%;
                top: 30px;
                left: 25px;
            }

            /* Course Modules */
            .modules-section {
                padding: 4rem 2rem;
                max-width: 1200px;
                margin: 0 auto;
            }

            .section-title {
                text-align: center;
                margin-bottom: 3rem;
                color: var(--primary);
                position: relative;
            }

            .section-title::after {
                content: "";
                display: block;
                width: 80px;
                height: 4px;
                background: var(--secondary);
                margin: 10px auto;
                border-radius: 2px;
            }

            .modules-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 2rem;
            }

            .module-card {
                background: white;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 5px 15px var(--shadow);
                transition: all 0.3s ease;
                cursor: pointer;
                border-top: 5px solid var(--primary);
            }

            .module-card:hover {
                transform: translateY(-10px);
                box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
            }

            .module-header {
                background: var(--primary);
                color: white;
                padding: 1.5rem;
                display: flex;
                align-items: center;
                gap: 1rem;
            }

            .module-icon {
                font-size: 2rem;
                background: rgba(255, 255, 255, 0.2);
                width: 60px;
                height: 60px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .module-body {
                padding: 1.5rem;
            }

            .module-body h3 {
                margin-bottom: 1rem;
                color: var(--primary);
            }

            .module-body ul {
                list-style: none;
                margin-bottom: 1.5rem;
            }

            .module-body li {
                padding: 0.3rem 0;
                border-bottom: 1px dashed #eee;
                position: relative;
                padding-left: 1.5rem;
            }

            .module-body li::before {
                content: "▸";
                color: var(--secondary);
                position: absolute;
                left: 0;
            }

            .module-progress {
                height: 8px;
                background: #e9ecef;
                border-radius: 4px;
                margin-top: 1rem;
                overflow: hidden;
            }

            .module-progress-bar {
                height: 100%;
                background: var(--success);
                width: 0%;
                transition: width 1.5s ease;
                border-radius: 4px;
            }

            /* Interactive Elements */
            .interactive-section {
                padding: 4rem 2rem;
                background: white;
                margin: 2rem auto;
                border-radius: 10px;
                max-width: 1200px;
                box-shadow: 0 5px 15px var(--shadow);
            }

            .interactive-container {
                display: flex;
                flex-wrap: wrap;
                gap: 2rem;
                align-items: flex-start;
            }

            .interactive-content {
                flex: 1;
                min-width: 300px;
            }

            .code-editor {
                flex: 1;
                min-width: 300px;
                background: #1e1e1e;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            }

            .editor-header {
                background: #2d2d30;
                padding: 0.8rem 1rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                color: #ccc;
                font-family: 'Courier New', monospace;
            }

            .editor-body {
                padding: 1.5rem;
                font-family: 'Fira Code', 'Courier New', monospace;
                color: #d4d4d4;
                line-height: 1.5;
                min-height: 300px;
                max-height: 400px;
                overflow-y: auto;
                white-space: pre;
                tab-size: 4;
            }

            .code-line {
                margin-bottom: 0.3rem;
                min-height: 1.2em;
            }

            .code-keyword {
                color: #569cd6;
            }

            .code-function {
                color: #dcdcaa;
            }

            .code-string {
                color: #ce9178;
            }

            .code-number {
                color: #b5cea8;
            }

            .code-comment {
                color: #6a9955;
                font-style: italic;
            }

            .code-operator {
                color: #d4d4d4;
            }

            .code-builtin {
                color: #4ec9b0;
            }

            .run-btn {
                margin-top: 1rem;
                background: var(--success);
                color: white;
            }

            .run-btn:hover {
                background: #218838;
            }

            .run-btn:disabled {
                opacity: 0.7;
                cursor: not-allowed;
            }

            /* Output Styles */
            .output-success {
                color: var(--success);
                background: rgba(40, 167, 69, 0.1);
                border-left: 4px solid var(--success);
            }

            .output-error {
                color: var(--danger);
                background: rgba(220, 53, 69, 0.1);
                border-left: 4px solid var(--danger);
            }

            .output-info {
                color: var(--primary);
                background: rgba(48, 105, 152, 0.1);
                border-left: 4px solid var(--primary);
            }

            /* Stats */
            .stats-section {
                padding: 3rem 2rem;
                background: var(--gradient);
                color: white;
                text-align: center;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 2rem;
                max-width: 1200px;
                margin: 0 auto;
            }

            .stat-item {
                padding: 1.5rem;
            }

            .stat-number {
                font-size: 3rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
            }

            .stat-text {
                font-size: 1.1rem;
                opacity: 0.9;
            }

            /* Certification */
            .certification-section {
                padding: 4rem 2rem;
                max-width: 1200px;
                margin: 0 auto;
            }

            .certification-card {
                background: white;
                border-radius: 10px;
                padding: 2rem;
                display: flex;
                flex-wrap: wrap;
                gap: 2rem;
                align-items: center;
                box-shadow: 0 5px 15px var(--shadow);
                border-left: 5px solid var(--secondary);
            }

            .certification-logo {
                flex: 1;
                min-width: 200px;
                text-align: center;
            }

            .certification-logo img {
                max-width: 150px;
                filter: drop-shadow(0 5px 10px rgba(0, 0, 0, 0.1));
            }

            .certification-info {
                flex: 2;
                min-width: 300px;
            }

            /* Footer */
            footer {
                background: var(--dark);
                color: white;
                padding: 3rem 2rem 1.5rem;
                margin-top: 4rem;
            }

            .footer-content {
                max-width: 1200px;
                margin: 0 auto;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 2rem;
                margin-bottom: 2rem;
            }

            .footer-section h3 {
                color: var(--secondary);
                margin-bottom: 1rem;
            }

            .footer-links {
                list-style: none;
            }

            .footer-links li {
                margin-bottom: 0.5rem;
            }

            .footer-links a {
                color: #ccc;
                text-decoration: none;
                transition: color 0.3s ease;
            }

            .footer-links a:hover {
                color: var(--secondary);
            }

            .copyright {
                text-align: center;
                padding-top: 1.5rem;
                border-top: 1px solid #444;
                color: #aaa;
                font-size: 0.9rem;
            }

            /* Animations */
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            @keyframes pythonMove {
                0% {
                    left: 0;
                    transform: scale(1);
                }

                100% {
                    left: calc(100% - 80px);
                    transform: scale(1.1);
                }
            }

            @keyframes pulse {
                0% {
                    transform: scale(1);
                }

                50% {
                    transform: scale(1.05);
                }

                100% {
                    transform: scale(1);
                }
            }

            @keyframes spin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            /* Scrollbar Styling */
            .editor-body::-webkit-scrollbar {
                width: 8px;
            }

            .editor-body::-webkit-scrollbar-track {
                background: #1e1e1e;
            }

            .editor-body::-webkit-scrollbar-thumb {
                background: #555;
                border-radius: 4px;
            }

            .editor-body::-webkit-scrollbar-thumb:hover {
                background: #777;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .nav-links {
                    display: none;
                    position: absolute;
                    top: 100%;
                    left: 0;
                    width: 100%;
                    background: var(--primary);
                    flex-direction: column;
                    padding: 1rem 0;
                    text-align: center;
                }

                .nav-links.active {
                    display: flex;
                }

                .mobile-menu-btn {
                    display: block;
                }

                .hero h1 {
                    font-size: 2.2rem;
                }

                .hero-btns {
                    flex-direction: column;
                    align-items: center;
                }

                .btn {
                    width: 100%;
                    max-width: 300px;
                    justify-content: center;
                }

                .interactive-container {
                    flex-direction: column;
                }

                .code-editor {
                    width: 100%;
                }
            }
        </style>
    </head>

    <body>
        <!-- Header & Navigation -->
        <header>
            <div class="nav-container">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fab fa-python"></i>
                    </div>
                    <div class="logo-text">
                        <h1>Python Essentials 1</h1>
                        <p>Impact Digital Academy</p>
                    </div>
                </div>

                <!-- User Info Section -->
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.9rem; font-weight: 600;">
                            <?php echo $accessController->getUserDisplayName(); ?>
                        </div>
                        <div style="font-size: 0.8rem; opacity: 0.8;">
                            <?php echo ucfirst($accessController->getUserRole()); ?>
                        </div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>

                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>

                <ul class="nav-links" id="navLinks">
                    <li><a href="#" class="active">Home</a></li>
                    <li><a href="#modules">Modules</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">Dashboard</a></li>
                    <li><a href="#certification">Certification</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/student/profile.php">Profile</a></li>
                </ul>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="hero">
            <div class="hero-content">
                <h1>Master Python Programming Fundamentals</h1>
                <p>A comprehensive beginner course designed by Impact Digital Academy in collaboration with Python
                    Institute. Prepare for PCEP certification and launch your programming career.</p>

                <div class="python-animation">
                    <div class="python-circle"></div>
                </div>

                <div class="hero-btns">
                    <a href="#modules" class="btn btn-primary">
                        <i class="fas fa-play-circle"></i> Start Learning
                    </a>
                    <a href="#certification" class="btn btn-secondary">
                        <i class="fas fa-award"></i> View Certification
                    </a>
                </div>
            </div>
        </section>

        <!-- Course Modules -->
        <section class="modules-section" id="modules">
            <h2 class="section-title">Course Modules</h2>

            <div class="modules-grid">
                <!-- Module 1 -->
                <div class="module-card" data-module="1">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-code"></i>
                        </div>
                        <h2>Module 1</h2>
                    </div>
                    <div class="module-body">
                        <h3>Introduction to Programming & Python</h3>
                        <ul>
                            <li>Programming Concepts</li>
                            <li>Python History & Features</li>
                            <li>Development Environment Setup</li>
                            <li>Your First Python Program</li>
                        </ul>
                        <div class="module-progress">
                            <div class="module-progress-bar" id="progress1"></div>
                        </div>
                        <button class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                            <i class="fas fa-book-open"></i> Start Module
                        </button>
                    </div>
                </div>

                <!-- Module 2 -->
                <div class="module-card" data-module="2">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <h2>Module 2</h2>
                    </div>
                    <div class="module-body">
                        <h3>Data Types, Variables & I/O</h3>
                        <ul>
                            <li>Python Syntax & Semantics</li>
                            <li>Variables & Data Types</li>
                            <li>Operators & Expressions</li>
                            <li>Basic Input/Output Operations</li>
                        </ul>
                        <div class="module-progress">
                            <div class="module-progress-bar" id="progress2"></div>
                        </div>
                        <button class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                            <i class="fas fa-book-open"></i> Start Module
                        </button>
                    </div>
                </div>

                <!-- Module 3 -->
                <div class="module-card" data-module="3">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-sitemap"></i>
                        </div>
                        <h2>Module 3</h2>
                    </div>
                    <div class="module-body">
                        <h3>Control Flow & Data Collections</h3>
                        <ul>
                            <li>Conditional Statements</li>
                            <li>Loops & Iterations</li>
                            <li>Lists, Tuples & Dictionaries</li>
                            <li>Functions & Scope</li>
                        </ul>
                        <div class="module-progress">
                            <div class="module-progress-bar" id="progress3"></div>
                        </div>
                        <button class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                            <i class="fas fa-book-open"></i> Start Module
                        </button>
                    </div>
                </div>

                <!-- Module 4 -->
                <div class="module-card" data-module="4">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-bug"></i>
                        </div>
                        <h2>Module 4</h2>
                    </div>
                    <div class="module-body">
                        <h3>Error Handling & Real-World Apps</h3>
                        <ul>
                            <li>Exception Handling</li>
                            <li>Debugging & Troubleshooting</li>
                            <li>Python Modules & Libraries</li>
                            <li>Real-World Projects</li>
                        </ul>
                        <div class="module-progress">
                            <div class="module-progress-bar" id="progress4"></div>
                        </div>
                        <button class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                            <i class="fas fa-book-open"></i> Start Module
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Interactive Code Editor with Piston API -->
        <section class="interactive-section">
            <h2 class="section-title">Try Python Online</h2>

            <div class="interactive-container">
                <div class="interactive-content">
                    <h3>Write and Execute Python Code</h3>
                    <p>Practice Python directly in your browser using our secure sandbox environment. Modify the code below
                        and see real execution results.</p>
                    <p><strong>Features:</strong></p>
                    <ul style="margin: 1rem 0; padding-left: 1.5rem;">
                        <li>Secure code execution via Piston API</li>
                        <li>Real Python 3.10 environment</li>
                        <li>Immediate feedback</li>
                    </ul>

                    <div id="output"
                        style="background: #f8f9fa; padding: 1.5rem; border-radius: 5px; margin-top: 1.5rem; min-height: 150px; font-family: 'Courier New', monospace; font-size: 0.95rem; white-space: pre-wrap; word-wrap: break-word;">
                        <strong>Output will appear here...</strong>
                        Try editing the code and clicking "Run Code" to see results.
                    </div>

                    <div
                        style="margin-top: 1.5rem; padding: 1rem; background: #f0f8ff; border-radius: 5px; border-left: 4px solid var(--primary);">
                        <p style="margin: 0; color: var(--primary);">
                            <i class="fas fa-info-circle"></i> <strong>Tip:</strong> Edit the code directly in the editor
                            and click "Run Code" to execute.
                        </p>
                    </div>
                </div>

                <div class="code-editor">
                    <div class="editor-header">
                        <span><i class="fab fa-python"></i> python_code.py</span>
                        <span>Python 3.10</span>
                    </div>
                    <div class="editor-body" id="codeEditor" contenteditable="true" spellcheck="false">
                        # Welcome to Python Essentials 1!
                        # Edit this code and click "Run Code"

                        print("Hello, Python Learner!")
                        print("Welcome to Impact Digital Academy")

                        # Variables and calculations
                        num1 = 15
                        num2 = 27
                        total = num1 + num2
                        print(f"{num1} + {num2} = {total}")

                        # Conditional statement
                        if total > 30:
                        print("That's a large number!")
                        else:
                        print("That's a moderate number.")

                        # Simple loop example
                        print("Counting from 1 to 3:")
                        for i in range(1, 4):
                        print(f" Number: {i}")
                    </div>
                    <div style="padding: 1rem; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button class="btn run-btn" id="runCodeBtn">
                            <i class="fas fa-play"></i> Run Code
                        </button>
                        <button class="btn btn-secondary" id="resetCodeBtn">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                        <button class="btn" id="clearOutputBtn" style="background: var(--warning); color: white;">
                            <i class="fas fa-eraser"></i> Clear Output
                        </button>
                        <button class="btn" id="exampleCodeBtn" style="background: var(--accent); color: white;">
                            <i class="fas fa-code"></i> Example Code
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Statistics -->
        <section class="stats-section">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number" id="studentsCount">0</div>
                    <div class="stat-text">Students Enrolled</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="completionRate">0%</div>
                    <div class="stat-text">Completion Rate</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="certifiedCount">0</div>
                    <div class="stat-text">PCEP Certified</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">4.8</div>
                    <div class="stat-text">Average Rating</div>
                </div>
            </div>
        </section>

        <!-- Certification -->
        <section class="certification-section" id="certification">
            <h2 class="section-title">PCEP Certification</h2>

            <div class="certification-card">
                <div class="certification-logo">
                    <div style="font-size: 5rem; color: var(--primary);">
                        <i class="fas fa-award"></i>
                    </div>
                    <h3>PCEP™</h3>
                    <p>Certified Entry-Level Python Programmer</p>
                </div>
                <div class="certification-info">
                    <h3>Prepare for Your Certification</h3>
                    <p>This course is aligned with the PCEP™ certification exam (Exam PCEP-30-0x), measuring your ability to
                        accomplish coding tasks related to Python programming essentials.</p>

                    <ul style="margin: 1rem 0; list-style-position: inside;">
                        <li>Universal programming concepts</li>
                        <li>Python syntax and semantics</li>
                        <li>Problem-solving with Python Standard Library</li>
                        <li>Industry-recognized credential</li>
                    </ul>

                    <button class="btn btn-primary" id="certificationBtn" style="margin-top: 1rem;">
                        <i class="fas fa-file-alt"></i> Learn More About PCEP
                    </button>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Python Essentials 1</h3>
                    <p>Developed in collaboration with Python Institute Open Education and Development Group.</p>
                    <p>Delivered by <strong>Impact Digital Academy</strong>.</p>
                </div>

              
                <div class="footer-section">
                    <h3>Contact</h3>
                    <ul class="footer-links">
                        <li><i class="fas fa-envelope"></i> admin@impactdigitalacademy.com.ng</li>
                        <li><i class="fas fa-phone"></i> +1 (234) 903 544 8295</li>
                        <li><i class="fas fa-map-marker-alt"></i> Digital Learning Center</li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h3>Follow Us</h3>
                    <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                        <a href="#" style="color: white; font-size: 1.5rem;"><i class="fab fa-facebook"></i></a>
                        <a href="#" style="color: white; font-size: 1.5rem;"><i class="fab fa-twitter"></i></a>
                        <a href="#" style="color: white; font-size: 1.5rem;"><i class="fab fa-linkedin"></i></a>
                        <a href="#" style="color: white; font-size: 1.5rem;"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
            </div>

            <div class="copyright">
                <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. Python Essentials 1 - Module 1 - Section 1</p>
                <p style="margin-top: 0.5rem; font-size: 0.8rem;">Code execution powered by Piston API</p>
            </div>
        </footer>

        <script>
            // Your existing JavaScript code
            // Add enrollment status check
            const enrollmentStatus = <?php echo json_encode($accessController->isEnrolled()); ?>;

            if (!enrollmentStatus && <?php echo json_encode($accessController->getUserRole() === 'student'); ?>) {
                // This shouldn't happen due to PHP check, but as backup
                alert('Access Denied: You are not enrolled in this course.');
                window.location.href = '<?php echo BASE_URL; ?>modules/student/dashboard.php';
            }

            // Mobile Menu Toggle
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const navLinks = document.getElementById('navLinks');

            mobileMenuBtn.addEventListener('click', () => {
                navLinks.classList.toggle('active');
                mobileMenuBtn.innerHTML = navLinks.classList.contains('active') ?
                    '<i class="fas fa-times"></i>' :
                    '<i class="fas fa-bars"></i>';
            });

            // Module Card Interaction
            const moduleCards = document.querySelectorAll('.module-card');

            moduleCards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.animation = 'pulse 0.5s ease';
                });

                card.addEventListener('mouseleave', () => {
                    card.style.animation = '';
                });

                card.addEventListener('click', (e) => {
                    // Prevent the button inside from triggering the card click
                    if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                        return;
                    }

                    const moduleNum = card.getAttribute('data-module');

                    // Navigate to the module page
                    switch (moduleNum) {
                        case '1':
                            window.location.href = 'module1_section1.php';
                            break;
                        case '2':
                            window.location.href = 'module2_section1.php';
                            break;
                        case '3':
                            window.location.href = 'module3.html';
                            break;
                        case '4':
                            window.location.href = 'module4.html';
                            break;
                        default:
                            alert(`Module ${moduleNum} page is not available yet.`);
                    }
                });
            });
            // Interactive Code Editor with Piston API
            const codeEditor = document.getElementById('codeEditor');
            const runCodeBtn = document.getElementById('runCodeBtn');
            const resetCodeBtn = document.getElementById('resetCodeBtn');
            const clearOutputBtn = document.getElementById('clearOutputBtn');
            const exampleCodeBtn = document.getElementById('exampleCodeBtn');
            const outputDiv = document.getElementById('output');

            // Original code template (plain text)
            const originalCode = `# Welcome to Python Essentials 1!
# Edit this code and click "Run Code"

print("Hello, Python Learner!")
print("Welcome to Impact Digital Academy")

# Variables and calculations
num1 = 15
num2 = 27
total = num1 + num2
print(f"{num1} + {num2} = {total}")

# Conditional statement
if total > 30:
    print("That's a large number!")
else:
    print("That's a moderate number.")

# Simple loop example
print("Counting from 1 to 3:")
for i in range(1, 4):
    print(f"  Number: {i}")`;

            // Example code for the "Example Code" button
            const exampleCode = `# More advanced example
# Function to calculate factorial
def factorial(n):
    if n == 0 or n == 1:
        return 1
    else:
        return n * factorial(n - 1)

# List comprehension example
numbers = [1, 2, 3, 4, 5]
squares = [x ** 2 for x in numbers]

print("Factorial of 5:", factorial(5))
print("Original numbers:", numbers)
print("Squares:", squares)

# Dictionary example
student = {
    "name": "Alex",
    "age": 22,
    "courses": ["Math", "Physics", "Programming"]
}

print(f"Student: {student['name']}")
print(f"Taking {len(student['courses'])} courses")

# Try modifying this code and running it!`;

            // Initialize editor with original code
            codeEditor.textContent = originalCode;

            // Function to get current code from editor
            function getCurrentCode() {
                return codeEditor.textContent;
            }

            // Piston API Execution Function
            async function executePythonWithPiston(code) {
                try {
                    const response = await fetch('https://emkc.org/api/v2/piston/execute', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            language: 'python',
                            version: '3.10.0',
                            files: [{
                                content: code
                            }]
                        })
                    });

                    const data = await response.json();

                    if (data.run) {
                        return {
                            success: data.run.code === 0,
                            output: data.run.output || '',
                            error: data.run.stderr || '',
                            signal: data.run.signal
                        };
                    }

                    return {
                        success: false,
                        output: '',
                        error: 'Execution failed: No response from execution engine'
                    };

                } catch (error) {
                    return {
                        success: false,
                        output: '',
                        error: 'API Error: ' + error.message
                    };
                }
            }

            // Run Code Button Event Listener
            runCodeBtn.addEventListener('click', async () => {
                // Disable button during execution
                runCodeBtn.disabled = true;
                runCodeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running...';

                // Get the Python code from the editor
                const pythonCode = getCurrentCode();

                // Show loading state
                outputDiv.innerHTML = `
        <div class="output-info" style="padding: 1rem;">
            <i class="fas fa-sync-alt fa-spin"></i> Executing Python code in secure sandbox...
        </div>
    `;

                // Execute the code using Piston API
                const result = await executePythonWithPiston(pythonCode);

                // Enable button again
                runCodeBtn.disabled = false;
                runCodeBtn.innerHTML = '<i class="fas fa-play"></i> Run Code';

                // Display the results
                if (result.success) {
                    outputDiv.innerHTML = `
            <div class="output-success" style="padding: 1rem;">
                <div style="margin-bottom: 0.5rem;">
                    <i class="fas fa-check-circle"></i> <strong>Code executed successfully!</strong>
                </div>
                <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;">${result.output}</pre>
            </div>
        `;
                } else {
                    let errorMessage = result.error;
                    // Clean up error message if it contains file path
                    errorMessage = errorMessage.replace(/\/piston\/jobs\/[^\/]+\/file0\.code/g, 'your_code.py');

                    outputDiv.innerHTML = `
            <div class="output-error" style="padding: 1rem;">
                <div style="margin-bottom: 0.5rem;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Execution error:</strong>
                </div>
                <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; color: var(--danger);">${errorMessage}</pre>
                ${result.output ? `<div style="margin-top: 0.5rem;"><strong>Output:</strong><br><pre style="margin: 0; white-space: pre-wrap;">${result.output}</pre></div>` : ''}
            </div>
        `;
                }

                // Animate the output appearance
                outputDiv.style.animation = 'fadeInUp 0.5s ease';
                setTimeout(() => {
                    outputDiv.style.animation = '';
                }, 500);
            });

            // Reset Code Button
            resetCodeBtn.addEventListener('click', () => {
                codeEditor.textContent = originalCode;
                outputDiv.innerHTML = `
        <strong>Output will appear here...</strong><br>
        Try editing the code and clicking "Run Code" to see results.
    `;
            });

            // Clear Output Button
            clearOutputBtn.addEventListener('click', () => {
                outputDiv.innerHTML = `
        <strong>Output cleared.</strong><br>
        Run some code to see output here.
    `;
            });

            // Example Code Button
            exampleCodeBtn.addEventListener('click', () => {
                codeEditor.textContent = exampleCode;
                outputDiv.innerHTML = `
        <strong>Loaded example code!</strong><br>
        Click "Run Code" to execute this more advanced example.
    `;
            });

            // Animated Statistics
            const studentsCount = document.getElementById('studentsCount');
            const completionRate = document.getElementById('completionRate');
            const certifiedCount = document.getElementById('certifiedCount');

            function animateCounter(element, target, suffix = '') {
                let current = 0;
                const increment = target / 50;
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    element.textContent = Math.floor(current) + suffix;
                }, 30);
            }

            // Start counters when stats section is in view
            const observerOptions = {
                threshold: 0.5
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        animateCounter(studentsCount, 1250);
                        animateCounter(completionRate, 92, '%');
                        animateCounter(certifiedCount, 840);
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            observer.observe(document.querySelector('.stats-section'));

            // Module Progress Bars Animation
            const progressBars = document.querySelectorAll('.module-progress-bar');
            const progressValues = [30, 15, 5, 0]; // Simulated progress percentages

            // Animate progress bars when modules are in view
            const moduleObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        progressBars.forEach((bar, index) => {
                            setTimeout(() => {
                                bar.style.width = progressValues[index] + '%';
                            }, index * 300);
                        });
                        moduleObserver.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            moduleObserver.observe(document.querySelector('.modules-section'));

            // Certification Button Animation
            const certificationBtn = document.getElementById('certificationBtn');

            certificationBtn.addEventListener('click', () => {
                certificationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                certificationBtn.disabled = true;

                setTimeout(() => {
                    alert('Redirecting to PCEP certification details page...');
                    certificationBtn.innerHTML = '<i class="fas fa-file-alt"></i> Learn More About PCEP';
                    certificationBtn.disabled = false;
                }, 1000);
            });

            // Scroll to top button
            const scrollToTopBtn = document.createElement('button');
            scrollToTopBtn.innerHTML = '<i class="fas fa-arrow-up"></i>';
            scrollToTopBtn.style.position = 'fixed';
            scrollToTopBtn.style.bottom = '20px';
            scrollToTopBtn.style.right = '20px';
            scrollToTopBtn.style.backgroundColor = 'var(--primary)';
            scrollToTopBtn.style.color = 'white';
            scrollToTopBtn.style.border = 'none';
            scrollToTopBtn.style.borderRadius = '50%';
            scrollToTopBtn.style.width = '50px';
            scrollToTopBtn.style.height = '50px';
            scrollToTopBtn.style.fontSize = '1.2rem';
            scrollToTopBtn.style.cursor = 'pointer';
            scrollToTopBtn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.2)';
            scrollToTopBtn.style.zIndex = '1000';
            scrollToTopBtn.style.display = 'none';
            scrollToTopBtn.style.transition = 'all 0.3s ease';

            scrollToTopBtn.addEventListener('mouseenter', () => {
                scrollToTopBtn.style.backgroundColor = 'var(--accent)';
                scrollToTopBtn.style.transform = 'translateY(-5px)';
            });

            scrollToTopBtn.addEventListener('mouseleave', () => {
                scrollToTopBtn.style.backgroundColor = 'var(--primary)';
                scrollToTopBtn.style.transform = 'translateY(0)';
            });

            scrollToTopBtn.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            document.body.appendChild(scrollToTopBtn);

            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    scrollToTopBtn.style.display = 'block';
                } else {
                    scrollToTopBtn.style.display = 'none';
                }
            });

            // Initialize page with animations
            window.addEventListener('load', () => {
                // Add slight delay for initial animations
                setTimeout(() => {
                    document.querySelector('.hero h1').style.opacity = '1';
                    document.querySelector('.hero p').style.opacity = '1';
                    document.querySelector('.hero-btns').style.opacity = '1';
                }, 300);

                // Add syntax highlighting for new lines
                codeEditor.addEventListener('input', () => {
                    // This could be enhanced with a proper syntax highlighter
                    // For now, we maintain the basic structure
                });
            });
        </script>
    </body>

    </html>

<?php
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #fee; border: 1px solid #f00; color: #900;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}