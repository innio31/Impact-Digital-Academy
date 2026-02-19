<?php
// modules/admin/academic/index.php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$conn = getDBConnection();

// Get counts for dashboard
$counts = [];

// Programs count
$programs_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN program_type = 'onsite' THEN 1 ELSE 0 END) as onsite,
    SUM(CASE WHEN program_type = 'online' THEN 1 ELSE 0 END) as online
    FROM programs";
$programs_result = $conn->query($programs_query);
$counts['programs'] = $programs_result->fetch_assoc();

// Courses count
$courses_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN level = 'beginner' THEN 1 ELSE 0 END) as beginner,
    SUM(CASE WHEN level = 'intermediate' THEN 1 ELSE 0 END) as intermediate,
    SUM(CASE WHEN level = 'advanced' THEN 1 ELSE 0 END) as advanced
    FROM courses";
$courses_result = $conn->query($courses_query);
$counts['courses'] = $courses_result->fetch_assoc();

// Academic periods count
$periods_query = "SELECT 
    program_type,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming
    FROM academic_periods 
    GROUP BY program_type";
$periods_result = $conn->query($periods_query);
$period_counts = ['onsite' => [], 'online' => []];
while ($row = $periods_result->fetch_assoc()) {
    $period_counts[$row['program_type']] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Management - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
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
            --onsite: #8b5cf6;
            --online: #10b981;
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
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.75rem;
        }

        /* Page Title */
        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border-left: 4px solid;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card.programs { border-left-color: #8b5cf6; }
        .stat-card.courses { border-left-color: #10b981; }
        .stat-card.onsite { border-left-color: #f59e0b; }
        .stat-card.online { border-left-color: #3b82f6; }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .stat-card.programs .stat-icon { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
        .stat-card.courses .stat-icon { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .stat-card.onsite .stat-icon { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .stat-card.online .stat-icon { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        
        .stat-main {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .stat-details {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .stat-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .detail-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        
        .action-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
            border: 2px solid transparent;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
            border-color: var(--primary);
        }
        
        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }
        
        .action-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .action-description {
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 1rem;
        }
        
        .action-link {
            color: var(--primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Recent Activity */
        .recent-activity {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .section-title i {
            color: var(--primary);
        }
        
        .activity-list {
            list-style: none;
        }
        
        .activity-item {
            padding: 1rem 0;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-text {
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-size: 0.875rem;
            color: #94a3b8;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-active { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .badge-inactive { background: rgba(107, 114, 128, 0.1); color: #6b7280; }
        .badge-upcoming { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .badge-onsite { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
        .badge-online { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .stats-grid,
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <span>Academic Management</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Academic Management Dashboard</h1>
            <div class="page-actions">
                <a href="calendar_manager.php" class="btn btn-primary">
                    <i class="fas fa-calendar-alt"></i> Manage Calendar
                </a>
                <a href="programs/create.php" class="btn btn-secondary">
                    <i class="fas fa-plus-circle"></i> New Program
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card programs" onclick="window.location.href='programs/'">
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-main"><?php echo $counts['programs']['total'] ?? 0; ?></div>
                <div class="stat-label">Total Programs</div>
                <div class="stat-details">
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #10b981;"></span>
                        <?php echo $counts['programs']['active'] ?? 0; ?> Active
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #8b5cf6;"></span>
                        <?php echo $counts['programs']['onsite'] ?? 0; ?> Onsite
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #3b82f6;"></span>
                        <?php echo $counts['programs']['online'] ?? 0; ?> Online
                    </div>
                </div>
            </div>
            
            <div class="stat-card courses" onclick="window.location.href='courses/'">
                <div class="stat-icon">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="stat-main"><?php echo $counts['courses']['total'] ?? 0; ?></div>
                <div class="stat-label">Total Courses</div>
                <div class="stat-details">
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #10b981;"></span>
                        <?php echo $counts['courses']['active'] ?? 0; ?> Active
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #f59e0b;"></span>
                        <?php echo $counts['courses']['beginner'] ?? 0; ?> Beginner
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #3b82f6;"></span>
                        <?php echo $counts['courses']['intermediate'] ?? 0; ?> Intermediate
                    </div>
                </div>
            </div>
            
            <div class="stat-card onsite" onclick="window.location.href='calendar_manager.php?program_type=onsite'">
                <div class="stat-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-main"><?php echo $period_counts['onsite']['total'] ?? 0; ?></div>
                <div class="stat-label">Onsite Terms</div>
                <div class="stat-details">
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #f59e0b;"></span>
                        <?php echo $period_counts['onsite']['upcoming'] ?? 0; ?> Upcoming
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #10b981;"></span>
                        <?php echo $period_counts['onsite']['active'] ?? 0; ?> Active
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #8b5cf6;"></span>
                        Onsite
                    </div>
                </div>
            </div>
            
            <div class="stat-card online" onclick="window.location.href='calendar_manager.php?program_type=online'">
                <div class="stat-icon">
                    <i class="fas fa-laptop-code"></i>
                </div>
                <div class="stat-main"><?php echo $period_counts['online']['total'] ?? 0; ?></div>
                <div class="stat-label">Online Blocks</div>
                <div class="stat-details">
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #f59e0b;"></span>
                        <?php echo $period_counts['online']['upcoming'] ?? 0; ?> Upcoming
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #10b981;"></span>
                        <?php echo $period_counts['online']['active'] ?? 0; ?> Active
                    </div>
                    <div class="stat-detail">
                        <span class="detail-dot" style="background: #3b82f6;"></span>
                        Online
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="calendar_manager.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="action-title">Academic Calendar Manager</div>
                <div class="action-description">
                    Manage terms for onsite programs and blocks for online programs. Set registration deadlines and track academic periods.
                </div>
                <div class="action-link">
                    Manage Calendar <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
            <a href="programs/index.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="action-title">Programs Management</div>
                <div class="action-description">
                    Create, edit, and manage academic programs. Configure program types (onsite/online), fees, duration, and prerequisites.
                </div>
                <div class="action-link">
                    Manage Programs <i class="fas fa-arrow-right"></i>
                </div>
            </a>
            
            <a href="courses/index.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="action-title">Courses Management</div>
                <div class="action-description">
                    Manage courses within programs. Set course codes, descriptions, duration, difficulty levels, and prerequisites.
                </div>
                <div class="action-link">
                    Manage Courses <i class="fas fa-arrow-right"></i>
                </div>
            </a>

            <a href="classes/list.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="action-title">Classes</div>
                <div class="action-description">
                    Manage courses within programs. Set course codes, descriptions, duration, difficulty levels, and prerequisites.
                </div>
                <div class="action-link">
                    Manage Courses <i class="fas fa-arrow-right"></i>
                </div>
            </a>
        </div>
        
        <!-- Recent Activity -->
        <div class="recent-activity">
            <h2 class="section-title">
                <i class="fas fa-history"></i>
                Recent Academic Activity
            </h2>
            
            <ul class="activity-list">
                <li class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-plus"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            <strong>New program added:</strong> Digital Marketing Mastery (Online)
                            <span class="badge badge-online">Online</span>
                        </div>
                        <div class="activity-time">2 hours ago</div>
                    </div>
                </li>
                
                <li class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            <strong>Term status updated:</strong> First Term 2024/2025 is now active
                            <span class="badge badge-active">Active</span>
                        </div>
                        <div class="activity-time">Yesterday, 3:45 PM</div>
                    </div>
                </li>
                
                <li class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            <strong>Course modified:</strong> Updated SEO course materials
                            <span class="badge badge-online">Online</span>
                        </div>
                        <div class="activity-time">Dec 19, 2024</div>
                    </div>
                </li>
                
                <li class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-text">
                            <strong>Academic year generated:</strong> 2025/2026 online blocks
                            <span class="badge badge-upcoming">Upcoming</span>
                        </div>
                        <div class="activity-time">Dec 18, 2024</div>
                    </div>
                </li>
            </ul>
        </div>
    </div>
    
    <script>
        // Add animation on load
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stats cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Animate action cards
            const actionCards = document.querySelectorAll('.action-card');
            actionCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, (index + statCards.length) * 100);
            });
        });
        
        // Add hover effects
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
    </script>
</body>
</html>