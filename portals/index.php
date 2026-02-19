<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once __DIR__ . '/includes/config.php';

// Check if user is already logged in
if (isLoggedIn()) {
    // Redirect based on role
    switch ($_SESSION['user_role']) {
        case 'admin':
            redirect(BASE_URL . 'modules/admin/dashboard.php');
            break;
        case 'instructor':
            redirect(BASE_URL . 'modules/instructor/dashboard.php');
            break;
        case 'student':
            redirect(BASE_URL . 'modules/student/dashboard.php');
            break;
        default:
            // For applicants or other roles, stay on portal
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impact Digital Academy Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#008080">
    <link rel="icon" href="public/images/favicon.ico">
    
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--light);
            color: var(--dark);
            overflow-x: hidden;
        }

        .portal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 1.5rem 5%;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-weight: bold;
        }

        .portal-hero {
            min-height: 80vh;
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)),
                        url('https://images.unsplash.com/photo-1522202176988-66273c2fd55f?ixlib=rb-4.0.3&auto=format&fit=crop&w=1600&q=80');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            padding: 2rem;
        }
        
        .portal-hero-content {
            max-width: 800px;
            padding: 3rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .portal-hero h1 {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            color: white;
        }
        
        .portal-hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            line-height: 1.6;
        }
        
        .cta-button {
            background-color: var(--accent);
            color: var(--dark);
            border: none;
            padding: 0.8rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            font-size: 1rem;
            margin: 0.5rem;
        }

        .cta-button:hover {
            background-color: #fbbf24;
            transform: translateY(-3px);
        }
        
        .secondary-button {
            background-color: transparent;
            color: white;
            border: 2px solid white;
            padding: 0.8rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            font-size: 1rem;
            margin: 0.5rem;
        }

        .secondary-button:hover {
            background-color: white;
            color: var(--primary);
        }
        
        .portal-login-box {
            background: white;
            padding: 2.5rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            max-width: 400px;
            margin: -100px auto 3rem;
            position: relative;
            z-index: 10;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        /* Password field wrapper */
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 0.25rem;
            font-size: 1rem;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--primary);
        }
        
        .password-wrapper input {
            padding-right: 40px;
        }
        
        .login-btn {
            width: 100%;
            padding: 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .login-btn:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }
        
        .portal-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            padding: 5rem 5%;
            background: #f8fafc;
        }
        
        .portal-card {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .portal-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .portal-icon {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }
        
        .portal-footer {
            background: var(--dark);
            color: white;
            padding: 2rem 5%;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .portal-hero h1 {
                font-size: 2.2rem;
            }
            
            .portal-hero-content {
                padding: 2rem;
            }
            
            .portal-login-box {
                margin: -50px 5% 3rem;
            }
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            position: relative;
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
        
        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .close-alert {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }
        
        .close-alert:hover {
            opacity: 1;
        }
    </style>
</head>
<body>
    <!-- Portal Header -->
    <header class="portal-header">
        <div style="display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto;">
            <div class="logo">
                <div class="logo-icon">IDA</div>
                <span>Impact Digital Academy Portal</span>
            </div>
            <div>
                <?php if (isLoggedIn()): ?>
                    <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="secondary-button" style="font-size: 0.9rem;">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>" class="secondary-button" style="font-size: 0.9rem;">
                        <i class="fas fa-arrow-left"></i> Back to Main Site
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success" style="max-width: 800px; margin: 1rem auto;">
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error" style="max-width: 800px; margin: 1rem auto;">
            <?php echo htmlspecialchars($_SESSION['error']); ?>
            <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['info'])): ?>
        <div class="alert alert-info" style="max-width: 800px; margin: 1rem auto;">
            <?php echo htmlspecialchars($_SESSION['info']); ?>
            <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

    <!-- Hero Section -->
    <section class="portal-hero">
        <div class="portal-hero-content">
            <h1>Welcome to Impact Digital Academy Portal</h1>
            <p>Your gateway to digital transformation education. Access courses, connect with instructors, and manage your learning journey in one seamless platform.</p>
            
            <div class="hero-buttons">
                <?php if (isLoggedIn()): ?>
                    <?php
                    $dashboard_url = '';
                    switch ($_SESSION['user_role']) {
                        case 'admin':
                            $dashboard_url = BASE_URL . 'modules/admin/dashboard.php';
                            break;
                        case 'instructor':
                            $dashboard_url = BASE_URL . 'modules/instructor/dashboard.php';
                            break;
                        case 'student':
                            $dashboard_url = BASE_URL . 'modules/student/dashboard.php';
                            break;
                        default:
                            $dashboard_url = '#';
                    }
                    ?>
                    <a href="<?php echo $dashboard_url; ?>" class="cta-button">
                        <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/profile/edit.php" class="secondary-button">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                <?php else: ?>
                    <a href="#login" class="cta-button">Login to Portal</a>
                    <a href="#apply" class="secondary-button">Apply for Admission</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if (!isLoggedIn()): ?>
        <!-- Login Section -->
        <section id="login">
            <div class="portal-login-box">
                <h2 style="text-align: center; margin-bottom: 0.5rem; color: var(--dark);">Portal Login</h2>
                <p style="text-align: center; color: #6c757d; margin-bottom: 1.5rem;">Access your learning dashboard</p>
                
                <?php if (isset($_SESSION['login_error'])): ?>
                    <div class="alert alert-error" style="margin-bottom: 1.5rem;">
                        <?php echo htmlspecialchars($_SESSION['login_error']); ?>
                        <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                    <?php unset($_SESSION['login_error']); ?>
                <?php endif; ?>
                
                <form action="<?php echo BASE_URL; ?>modules/auth/login.php" method="POST" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="Enter your email" required 
                               value="<?php echo isset($_SESSION['login_email']) ? htmlspecialchars($_SESSION['login_email']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="form-control" 
                                   placeholder="Enter your password" required>
                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group" style="text-align: right; margin-bottom: 1rem;">
                        <a href="<?php echo BASE_URL; ?>modules/auth/forgot_password.php" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">
                            Forgot Password?
                        </a>
                    </div>
                    
                    <button type="submit" class="login-btn">
                        <i class="fas fa-sign-in-alt"></i> Login to Portal
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0;">
                    <p style="color: #6c757d; margin-bottom: 1rem;">Don't have an account yet?</p>
                    <a href="#apply" class="secondary-button" style="width: 100%; background: transparent; color: var(--primary); border-color: var(--primary);">
                        Apply for Admission
                    </a>
                </div>
            </div>
        </section>
    <?php else: ?>
        <!-- Quick Stats for Logged-in Users -->
        <section class="portal-cards">
            <div class="portal-card" onclick="window.location.href='<?php echo BASE_URL; ?>modules/classes/'">
                <div class="portal-icon">
                    <i class="fas fa-chalkboard"></i>
                </div>
                <h3>My Classes</h3>
                <p>Access all your enrolled courses and learning materials</p>
                <div style="margin-top: 1rem;">
                    <span style="color: var(--primary); font-weight: 600;">View Classes →</span>
                </div>
            </div>
            
            <div class="portal-card" onclick="window.location.href='<?php echo BASE_URL; ?>modules/assignments/'">
                <div class="portal-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <h3>Assignments</h3>
                <p>View pending assignments and submit your work</p>
                <div style="margin-top: 1rem;">
                    <span style="color: var(--primary); font-weight: 600;">View Assignments →</span>
                </div>
            </div>
            
            <div class="portal-card" onclick="window.location.href='<?php echo BASE_URL; ?>modules/grades/'">
                <div class="portal-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>Grades & Progress</h3>
                <p>Track your academic performance and progress</p>
                <div style="margin-top: 1rem;">
                    <span style="color: var(--primary); font-weight: 600;">View Grades →</span>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Features Section -->
    <section class="portal-cards">
        <div class="portal-card">
            <div class="portal-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h3>Interactive Learning</h3>
            <p>Access comprehensive course materials, video lectures, and interactive assignments designed for effective learning.</p>
        </div>
        
        <div class="portal-card">
            <div class="portal-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <h3>Expert Instructors</h3>
            <p>Learn from industry professionals with years of experience in digital transformation and technology education.</p>
        </div>
        
        <div class="portal-card">
            <div class="portal-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3>Track Progress</h3>
            <p>Monitor your learning journey with detailed progress tracking, grades, and performance analytics.</p>
        </div>
    </section>

    <!-- Apply Section -->
    <section style="background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; padding: 5rem 5%; text-align: center;" id="apply">
        <h2 style="font-size: 2.5rem; margin-bottom: 1rem;">Start Your Digital Journey</h2>
        <p style="font-size: 1.2rem; max-width: 700px; margin: 0 auto 3rem; opacity: 0.9;">Join our community of learners and transform your career with cutting-edge digital skills</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; max-width: 1000px; margin: 0 auto;">
            <div style="background: rgba(255, 255, 255, 0.1); padding: 2rem; border-radius: 15px; border: 1px solid rgba(255, 255, 255, 0.2); transition: all 0.3s ease; cursor: pointer;" onclick="window.location.href='<?php echo BASE_URL; ?>modules/auth/register.php?role=student'">
                <div style="font-size: 3rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <h3 style="margin-bottom: 1rem;">Apply as Student</h3>
                <p style="margin-bottom: 1.5rem;">Join our comprehensive digital skills training programs. Gain hands-on experience and industry-relevant certifications.</p>
                <span style="color: white; font-weight: 600;">Apply Now <i class="fas fa-arrow-right"></i></span>
            </div>
            
            <div style="background: rgba(255, 255, 255, 0.1); padding: 2rem; border-radius: 15px; border: 1px solid rgba(255, 255, 255, 0.2); transition: all 0.3s ease; cursor: pointer;" onclick="window.location.href='<?php echo BASE_URL; ?>modules/auth/register.php?role=instructor'">
                <div style="font-size: 3rem; margin-bottom: 1.5rem;">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <h3 style="margin-bottom: 1rem;">Apply as Instructor</h3>
                <p style="margin-bottom: 1.5rem;">Share your expertise and guide the next generation of digital professionals. Join our faculty of industry experts.</p>
                <span style="color: white; font-weight: 600;">Apply Now <i class="fas fa-arrow-right"></i></span>
            </div>
        </div>
    </section>

    <!-- Portal Footer -->
    <footer class="portal-footer">
        <p><strong>Impact Digital Academy Portal</strong></p>
        <p>Empowering the next generation of digital professionals</p>
        
        <div style="display: flex; justify-content: center; gap: 2rem; margin: 2rem 0; flex-wrap: wrap;">
            <a href="<?php echo BASE_URL; ?>help/" style="color: #adb5bd; text-decoration: none;">
                <i class="fas fa-question-circle"></i> Help Center
            </a>
            <a href="<?php echo BASE_URL; ?>contact/" style="color: #adb5bd; text-decoration: none;">
                <i class="fas fa-headset"></i> Contact Support
            </a>
            <a href="<?php echo BASE_URL; ?>about/" style="color: #adb5bd; text-decoration: none;">
                <i class="fas fa-info-circle"></i> About the Academy
            </a>
            <a href="<?php echo BASE_URL; ?>privacy/" style="color: #adb5bd; text-decoration: none;">
                <i class="fas fa-shield-alt"></i> Privacy Policy
            </a>
        </div>
        
        <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid rgba(255, 255, 255, 0.1);">
            <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. A DigSkills Initiative.</p>
            <p style="font-size: 0.9rem; opacity: 0.8; margin-top: 0.5rem;">All learning materials and content are proprietary.</p>
            <?php if (DEBUG_MODE): ?>
                <p style="font-size: 0.8rem; opacity: 0.6; margin-top: 0.5rem;">
                    <i class="fas fa-code"></i> Development Mode | 
                    DB: <?php echo DB_NAME; ?> | 
                    Time: <?php echo date('Y-m-d H:i:s'); ?>
                </p>
            <?php endif; ?>
        </div>
    </footer>

    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;

                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    e.preventDefault();
                    window.scrollTo({
                        top: targetElement.offsetTop - 100,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Add hover effects to role cards
        document.querySelectorAll('.portal-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-10px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 500);
            });
        }, 5000);

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const inputs = this.querySelectorAll('input[required], select[required], textarea[required]');
                let isValid = true;
                
                inputs.forEach(input => {
                    if (!input.value.trim()) {
                        isValid = false;
                        input.style.borderColor = '#dc2626';
                        
                        // Add error message if not exists
                        if (!input.nextElementSibling || !input.nextElementSibling.classList.contains('error-message')) {
                            const errorMsg = document.createElement('div');
                            errorMsg.className = 'error-message';
                            errorMsg.style.color = '#dc2626';
                            errorMsg.style.fontSize = '0.875rem';
                            errorMsg.style.marginTop = '0.25rem';
                            errorMsg.textContent = 'This field is required';
                            input.parentNode.appendChild(errorMsg);
                        }
                    } else {
                        input.style.borderColor = '#e2e8f0';
                        
                        // Remove error message if exists
                        if (input.nextElementSibling && input.nextElementSibling.classList.contains('error-message')) {
                            input.nextElementSibling.remove();
                        }
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                }
            });
        });

        // Clear form inputs on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Initialize tooltips for icons
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function(e) {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.getAttribute('title');
                tooltip.style.position = 'absolute';
                tooltip.style.background = 'var(--dark)';
                tooltip.style.color = 'white';
                tooltip.style.padding = '0.5rem 1rem';
                tooltip.style.borderRadius = '4px';
                tooltip.style.fontSize = '0.875rem';
                tooltip.style.zIndex = '1000';
                tooltip.style.top = (e.clientY + 10) + 'px';
                tooltip.style.left = (e.clientX + 10) + 'px';
                document.body.appendChild(tooltip);
                
                this._tooltip = tooltip;
            });
            
            element.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                }
            });
        });

        // Password visibility toggle
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            if (togglePassword && passwordInput) {
                const passwordIcon = togglePassword.querySelector('i');
                
                togglePassword.addEventListener('click', function() {
                    // Toggle the type attribute
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    
                    // Toggle the eye icon
                    if (type === 'text') {
                        passwordIcon.classList.remove('fa-eye');
                        passwordIcon.classList.add('fa-eye-slash');
                        togglePassword.setAttribute('aria-label', 'Hide password');
                    } else {
                        passwordIcon.classList.remove('fa-eye-slash');
                        passwordIcon.classList.add('fa-eye');
                        togglePassword.setAttribute('aria-label', 'Show password');
                    }
                    
                    // Focus back on password field after toggle
                    passwordInput.focus();
                });
                
                // Add keyboard support for accessibility
                togglePassword.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        togglePassword.click();
                    }
                });
            }
        });
    </script>
</body>
</html>