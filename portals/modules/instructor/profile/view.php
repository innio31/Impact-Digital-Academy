<?php
// modules/instructor/profile/view.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Get instructor ID from URL
$instructor_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Fetch instructor data
$instructor_data = [];
$profile_data = [];

if ($instructor_id > 0) {
    $sql = "SELECT u.*, up.* 
            FROM users u 
            LEFT JOIN user_profiles up ON u.id = up.user_id 
            WHERE u.id = ? AND u.role = 'instructor' AND u.status = 'active'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $instructor_data = $row;
        $profile_data = $row;
    } else {
        header('Location: ' . BASE_URL . '404.php');
        exit();
    }
    $stmt->close();
} else {
    header('Location: ' . BASE_URL . '404.php');
    exit();
}

// Fetch instructor's classes
$classes = [];
$sql = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name,
               COUNT(e.id) as student_count
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
        WHERE cb.instructor_id = ? AND cb.status IN ('ongoing', 'scheduled')
        GROUP BY cb.id 
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $classes = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();
$conn->close();

$instructor_name = htmlspecialchars($instructor_data['first_name'] . ' ' . $instructor_data['last_name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $instructor_name; ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .back-link {
            margin-bottom: 2rem;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .profile-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .profile-header {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            padding: 3rem;
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .profile-avatar {
            flex-shrink: 0;
        }

        .avatar-img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .avatar-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: white;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            border: 4px solid white;
        }

        .profile-info h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .profile-tag {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .profile-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            display: block;
        }

        .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .profile-content {
            padding: 3rem;
        }

        .section {
            margin-bottom: 3rem;
        }

        .section-title {
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .bio-content {
            font-size: 1.1rem;
            line-height: 1.6;
            color: var(--gray);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: 8px;
        }

        .info-item i {
            color: var(--primary);
            font-size: 1.25rem;
            width: 24px;
        }

        .qualifications {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: 8px;
            white-space: pre-line;
            line-height: 1.6;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .social-link:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .class-card {
            background: white;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .class-code {
            font-weight: bold;
            color: var(--primary);
        }

        .class-status {
            background: var(--light-gray);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .class-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .class-meta {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .class-students {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
                padding: 2rem;
            }

            .profile-stats {
                flex-wrap: wrap;
                justify-content: center;
            }

            .profile-content {
                padding: 2rem;
            }

            .classes-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="back-link">
            <a href="<?php echo BASE_URL; ?>"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>

        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if (!empty($instructor_data['profile_image'])): ?>
                        <img src="<?php echo BASE_URL . 'public/' . htmlspecialchars($instructor_data['profile_image']); ?>"
                            alt="<?php echo $instructor_name; ?>" class="avatar-img">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($instructor_data['first_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h1><?php echo $instructor_name; ?></h1>
                    <div class="profile-tag">Instructor</div>

                    <?php if (!empty($profile_data['current_job_title'])): ?>
                        <p><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($profile_data['current_job_title']); ?>
                            <?php if (!empty($profile_data['current_company'])): ?>
                                at <?php echo htmlspecialchars($profile_data['current_company']); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($profile_data['experience_years'])): ?>
                        <p><i class="fas fa-clock"></i> <?php echo $profile_data['experience_years']; ?>+ years experience</p>
                    <?php endif; ?>

                    <div class="profile-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo count($classes); ?></span>
                            <span class="stat-label">Classes</span>
                        </div>
                        <?php if (!empty($classes)):
                            $total_students = array_sum(array_column($classes, 'student_count')); ?>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo $total_students; ?></span>
                                <span class="stat-label">Students</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="profile-content">
                <!-- Bio Section -->
                <?php if (!empty($profile_data['bio'])): ?>
                    <div class="section">
                        <h2 class="section-title">About Me</h2>
                        <div class="bio-content">
                            <?php echo nl2br(htmlspecialchars($profile_data['bio'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Contact Info -->
                <div class="section">
                    <h2 class="section-title">Contact Information</h2>
                    <div class="info-grid">
                        <?php if (!empty($instructor_data['email'])): ?>
                            <div class="info-item">
                                <i class="fas fa-envelope"></i>
                                <div>
                                    <div style="font-weight: 500;">Email</div>
                                    <div><?php echo htmlspecialchars($instructor_data['email']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($instructor_data['phone'])): ?>
                            <div class="info-item">
                                <i class="fas fa-phone"></i>
                                <div>
                                    <div style="font-weight: 500;">Phone</div>
                                    <div><?php echo htmlspecialchars($instructor_data['phone']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($profile_data['city']) || !empty($profile_data['country'])): ?>
                            <div class="info-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <div>
                                    <div style="font-weight: 500;">Location</div>
                                    <div>
                                        <?php
                                        $location = [];
                                        if (!empty($profile_data['city'])) $location[] = $profile_data['city'];
                                        if (!empty($profile_data['state'])) $location[] = $profile_data['state'];
                                        if (!empty($profile_data['country'])) $location[] = $profile_data['country'];
                                        echo htmlspecialchars(implode(', ', $location));
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Qualifications -->
                <?php if (!empty($profile_data['qualifications'])): ?>
                    <div class="section">
                        <h2 class="section-title">Qualifications</h2>
                        <div class="qualifications">
                            <?php echo nl2br(htmlspecialchars($profile_data['qualifications'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Social Links -->
                <?php if (!empty($profile_data['website']) || !empty($profile_data['linkedin_url']) || !empty($profile_data['github_url'])): ?>
                    <div class="section">
                        <h2 class="section-title">Connect</h2>
                        <div class="social-links">
                            <?php if (!empty($profile_data['website'])): ?>
                                <a href="<?php echo htmlspecialchars($profile_data['website']); ?>"
                                    class="social-link" target="_blank" title="Website">
                                    <i class="fas fa-globe"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['linkedin_url'])): ?>
                                <a href="<?php echo htmlspecialchars($profile_data['linkedin_url']); ?>"
                                    class="social-link" target="_blank" title="LinkedIn">
                                    <i class="fab fa-linkedin-in"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($profile_data['github_url'])): ?>
                                <a href="<?php echo htmlspecialchars($profile_data['github_url']); ?>"
                                    class="social-link" target="_blank" title="GitHub">
                                    <i class="fab fa-github"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Current Classes -->
                <?php if (!empty($classes)): ?>
                    <div class="section">
                        <h2 class="section-title">Current Classes</h2>
                        <div class="classes-grid">
                            <?php foreach ($classes as $class): ?>
                                <div class="class-card">
                                    <div class="class-header">
                                        <div class="class-code"><?php echo htmlspecialchars($class['batch_code']); ?></div>
                                        <div class="class-status"><?php echo ucfirst($class['status']); ?></div>
                                    </div>
                                    <div class="class-title"><?php echo htmlspecialchars($class['course_title']); ?></div>
                                    <div class="class-meta">
                                        <?php echo htmlspecialchars($class['program_name']); ?> •
                                        <?php echo date('M Y', strtotime($class['start_date'])); ?> - <?php echo date('M Y', strtotime($class['end_date'])); ?>
                                    </div>
                                    <div class="class-students">
                                        <i class="fas fa-user-graduate"></i>
                                        <?php echo $class['student_count']; ?> students enrolled
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Smooth scroll for page anchors
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Lazy load images
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img[data-src]');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.getAttribute('data-src');
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                });
            });
            images.forEach(img => imageObserver.observe(img));
        });
    </script>
</body>

</html>