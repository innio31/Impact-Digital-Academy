<?php
// modules/admin/instructors/workload.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Get filter parameters
$period = $_GET['period'] ?? 'current';
$status = $_GET['status'] ?? 'all';
$sort = $_GET['sort'] ?? 'workload';
$order = $_GET['order'] ?? 'desc';

// Define date ranges based on period
$date_ranges = [
    'current' => ['start' => date('Y-m-01'), 'end' => date('Y-m-t')],
    'next' => ['start' => date('Y-m-01', strtotime('+1 month')), 'end' => date('Y-m-t', strtotime('+1 month'))],
    'quarter' => ['start' => date('Y-m-01'), 'end' => date('Y-m-t', strtotime('+2 months'))],
    'year' => ['start' => date('Y-01-01'), 'end' => date('Y-12-31')],
];

$start_date = $date_ranges[$period]['start'] ?? $date_ranges['current']['start'];
$end_date = $date_ranges[$period]['end'] ?? $date_ranges['current']['end'];

// Build query for instructor workload
$query = "
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        up.current_job_title,
        up.experience_years,
        COUNT(DISTINCT cb.id) as total_classes,
        SUM(CASE WHEN cb.status = 'ongoing' THEN 1 ELSE 0 END) as active_classes,
        SUM(CASE WHEN cb.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_classes,
        SUM(CASE WHEN cb.status = 'completed' THEN 1 ELSE 0 END) as completed_classes,
        SUM(CASE WHEN cb.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_classes,
        COALESCE(SUM(student_counts.student_count), 0) as total_students,
        COALESCE(SUM(assignment_counts.assignment_count), 0) as total_assignments,
        COALESCE(SUM(material_counts.material_count), 0) as total_materials,
        (COUNT(DISTINCT cb.id) * 10) + (COALESCE(SUM(student_counts.student_count), 0) * 0.5) as workload_score
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    LEFT JOIN class_batches cb ON u.id = cb.instructor_id 
        AND cb.start_date BETWEEN ? AND ?
    LEFT JOIN (
        SELECT cb2.id, COUNT(DISTINCT e.id) as student_count
        FROM class_batches cb2
        LEFT JOIN enrollments e ON cb2.id = e.class_id AND e.status = 'active'
        WHERE cb2.start_date BETWEEN ? AND ?
        GROUP BY cb2.id
    ) as student_counts ON cb.id = student_counts.id
    LEFT JOIN (
        SELECT a.class_id, COUNT(DISTINCT a.id) as assignment_count
        FROM assignments a
        JOIN class_batches cb3 ON a.class_id = cb3.id
        WHERE cb3.start_date BETWEEN ? AND ?
        GROUP BY a.class_id
    ) as assignment_counts ON cb.id = assignment_counts.class_id
    LEFT JOIN (
        SELECT m.class_id, COUNT(DISTINCT m.id) as material_count
        FROM materials m
        JOIN class_batches cb4 ON m.class_id = cb4.id
        WHERE cb4.start_date BETWEEN ? AND ?
        GROUP BY m.class_id
    ) as material_counts ON cb.id = material_counts.class_id
    WHERE u.role = 'instructor' AND u.status = 'active'
";

$params = [$start_date, $end_date, $start_date, $end_date, $start_date, $end_date, $start_date, $end_date];
$types = 'ssssssss';

// Apply status filter
if ($status !== 'all') {
    if ($status === 'overloaded') {
        $query .= " HAVING workload_score > 30";
    } elseif ($status === 'balanced') {
        $query .= " HAVING workload_score BETWEEN 15 AND 30";
    } elseif ($status === 'underloaded') {
        $query .= " HAVING workload_score < 15";
    }
}

// Group by and order
$query .= " GROUP BY u.id ORDER BY $sort $order";

// Prepare and execute query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$workload_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$stats = [
    'total_instructors' => count($workload_data),
    'total_classes' => array_sum(array_column($workload_data, 'total_classes')),
    'active_classes' => array_sum(array_column($workload_data, 'active_classes')),
    'total_students' => array_sum(array_column($workload_data, 'total_students')),
    'avg_workload' => count($workload_data) > 0 ? 
        round(array_sum(array_column($workload_data, 'workload_score')) / count($workload_data), 1) : 0,
    'overloaded_count' => count(array_filter($workload_data, function($item) {
        return $item['workload_score'] > 30;
    })),
    'balanced_count' => count(array_filter($workload_data, function($item) {
        return $item['workload_score'] >= 15 && $item['workload_score'] <= 30;
    })),
    'underloaded_count' => count(array_filter($workload_data, function($item) {
        return $item['workload_score'] < 15;
    })),
];

// Log activity
logActivity('view_instructor_workload', "Viewed instructor workload report for period: $period");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Workload - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .page-title {
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .chart-container {
            height: 300px;
            margin: 2rem 0;
        }

        .workload-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .workload-table th {
            text-align: left;
            padding: 1rem;
            background: var(--light);
            color: var(--gray);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--light-gray);
            cursor: pointer;
        }

        .workload-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }

        .workload-table tr:hover {
            background: var(--light);
        }

        .workload-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .workload-high { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .workload-medium { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .workload-low { background: rgba(16, 185, 129, 0.1); color: #10b981; }

        .btn {
            padding: 0.5rem 1rem;
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

        @media (max-width: 768px) {
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .workload-table {
                font-size: 0.85rem;
            }
            
            .workload-table th,
            .workload-table td {
                padding: 0.5rem;
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
            <a href="index.php">Instructors</a>
            <i class="fas fa-chevron-right"></i>
            <span>Workload Management</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Instructor Workload Management</h1>
            <p style="color: var(--gray); margin-top: 0.5rem;">
                Monitor and balance instructor assignments for optimal teaching efficiency
            </p>
        </div>

        <!-- Filters -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Filter Options</h3>
            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="period">Time Period</label>
                        <select id="period" name="period" class="form-control" onchange="this.form.submit()">
                            <option value="current" <?php echo $period === 'current' ? 'selected' : ''; ?>>Current Month</option>
                            <option value="next" <?php echo $period === 'next' ? 'selected' : ''; ?>>Next Month</option>
                            <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>Current Quarter</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>Current Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Workload Status</label>
                        <select id="status" name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="overloaded" <?php echo $status === 'overloaded' ? 'selected' : ''; ?>>Overloaded</option>
                            <option value="balanced" <?php echo $status === 'balanced' ? 'selected' : ''; ?>>Balanced</option>
                            <option value="underloaded" <?php echo $status === 'underloaded' ? 'selected' : ''; ?>>Underloaded</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select id="sort" name="sort" class="form-control" onchange="this.form.submit()">
                            <option value="workload_score" <?php echo $sort === 'workload_score' ? 'selected' : ''; ?>>Workload Score</option>
                            <option value="total_classes" <?php echo $sort === 'total_classes' ? 'selected' : ''; ?>>Total Classes</option>
                            <option value="total_students" <?php echo $sort === 'total_students' ? 'selected' : ''; ?>>Total Students</option>
                            <option value="last_name" <?php echo $sort === 'last_name' ? 'selected' : ''; ?>>Instructor Name</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="order">Order</label>
                        <select id="order" name="order" class="form-control" onchange="this.form.submit()">
                            <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>Descending</option>
                            <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card total">
                <div class="stat-value"><?php echo number_format($stats['total_instructors']); ?></div>
                <div class="stat-label">Active Instructors</div>
            </div>
            <div class="stat-card classes">
                <div class="stat-value"><?php echo number_format($stats['total_classes']); ?></div>
                <div class="stat-label">Total Classes</div>
            </div>
            <div class="stat-card students">
                <div class="stat-value"><?php echo number_format($stats['total_students']); ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card workload">
                <div class="stat-value"><?php echo number_format($stats['avg_workload'], 1); ?></div>
                <div class="stat-label">Avg. Workload Score</div>
            </div>
        </div>

        <!-- Workload Distribution -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Workload Distribution</h3>
            <div class="chart-container">
                <canvas id="workloadChart"></canvas>
            </div>
            
            <div style="display: flex; justify-content: space-around; margin-top: 2rem;">
                <div style="text-align: center;">
                    <div style="font-size: 2rem; font-weight: 700; color: var(--danger);">
                        <?php echo $stats['overloaded_count']; ?>
                    </div>
                    <div style="color: var(--gray); font-size: 0.9rem;">Overloaded</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 2rem; font-weight: 700; color: var(--warning);">
                        <?php echo $stats['balanced_count']; ?>
                    </div>
                    <div style="color: var(--gray); font-size: 0.9rem;">Balanced</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 2rem; font-weight: 700; color: var(--success);">
                        <?php echo $stats['underloaded_count']; ?>
                    </div>
                    <div style="color: var(--gray); font-size: 0.9rem;">Underloaded</div>
                </div>
            </div>
        </div>

        <!-- Workload Table -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="margin: 0;">Instructor Workload Details</h3>
                <div style="color: var(--gray); font-size: 0.9rem;">
                    Period: <?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?>
                </div>
            </div>
            
            <table class="workload-table">
                <thead>
                    <tr>
                        <th onclick="sortTable('last_name')">Instructor</th>
                        <th onclick="sortTable('total_classes')">Classes</th>
                        <th onclick="sortTable('total_students')">Students</th>
                        <th onclick="sortTable('total_assignments')">Assignments</th>
                        <th onclick="sortTable('total_materials')">Materials</th>
                        <th onclick="sortTable('workload_score')">Workload Score</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($workload_data)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: var(--gray);">
                                <i class="fas fa-chart-bar" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                <p>No workload data available for selected period</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($workload_data as $instructor): 
                            $workload_level = $instructor['workload_score'] > 30 ? 'high' : 
                                            ($instructor['workload_score'] >= 15 ? 'medium' : 'low');
                            $workload_label = $instructor['workload_score'] > 30 ? 'Overloaded' : 
                                            ($instructor['workload_score'] >= 15 ? 'Balanced' : 'Underloaded');
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?></strong>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($instructor['email']); ?>
                                    </div>
                                    <?php if ($instructor['current_job_title']): ?>
                                        <div style="font-size: 0.8rem; color: var(--gray);">
                                            <?php echo htmlspecialchars($instructor['current_job_title']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="text-align: center;">
                                        <strong style="font-size: 1.2rem; color: var(--primary);">
                                            <?php echo $instructor['total_classes']; ?>
                                        </strong>
                                        <div style="font-size: 0.75rem; color: var(--gray);">
                                            <?php echo $instructor['active_classes']; ?> active
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="font-size: 1.2rem; color: var(--success);">
                                        <?php echo $instructor['total_students']; ?>
                                    </strong>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="font-size: 1.2rem; color: var(--warning);">
                                        <?php echo $instructor['total_assignments']; ?>
                                    </strong>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="font-size: 1.2rem; color: var(--info);">
                                        <?php echo $instructor['total_materials']; ?>
                                    </strong>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="font-size: 1.2rem; color: var(--dark);">
                                        <?php echo round($instructor['workload_score'], 1); ?>
                                    </strong>
                                    <div style="font-size: 0.75rem; color: var(--gray);">
                                        Score
                                    </div>
                                </td>
                                <td>
                                    <span class="workload-badge workload-<?php echo $workload_level; ?>">
                                        <?php echo $workload_label; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.25rem;">
                                        <a href="view.php?id=<?php echo $instructor['id']; ?>" 
                                           class="btn" style="background: var(--light-gray); padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="assign.php?id=<?php echo $instructor['id']; ?>" 
                                           class="btn" style="background: var(--primary); color: white; padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-tasks"></i>
                                        </a>
                                        <?php if ($workload_level === 'high'): ?>
                                            <a href="assign.php?id=<?php echo $instructor['id']; ?>" 
                                               class="btn" style="background: var(--danger); color: white; padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                               title="Redistribute Workload">
                                                <i class="fas fa-balance-scale"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recommendations -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Workload Management Recommendations</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                <?php 
                // Get overloaded instructors for recommendations
                $overloaded = array_filter($workload_data, function($item) {
                    return $item['workload_score'] > 30;
                });
                
                $underloaded = array_filter($workload_data, function($item) {
                    return $item['workload_score'] < 15;
                });
                
                if (!empty($overloaded)): ?>
                <div style="background: rgba(239, 68, 68, 0.05); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--danger);">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--danger);">
                        <i class="fas fa-exclamation-triangle"></i> Overloaded Instructors
                    </h4>
                    <p style="font-size: 0.9rem; margin-bottom: 1rem; color: var(--gray);">
                        Consider redistributing workload from these instructors:
                    </p>
                    <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem;">
                        <?php foreach (array_slice($overloaded, 0, 3) as $instructor): ?>
                            <li>
                                <a href="assign.php?id=<?php echo $instructor['id']; ?>" style="color: var(--primary);">
                                    <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                </a>
                                (Score: <?php echo round($instructor['workload_score'], 1); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($underloaded)): ?>
                <div style="background: rgba(16, 185, 129, 0.05); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--success);">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--success);">
                        <i class="fas fa-user-plus"></i> Available Capacity
                    </h4>
                    <p style="font-size: 0.9rem; margin-bottom: 1rem; color: var(--gray);">
                        These instructors have available capacity for additional assignments:
                    </p>
                    <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem;">
                        <?php foreach (array_slice($underloaded, 0, 3) as $instructor): ?>
                            <li>
                                <a href="assign.php?id=<?php echo $instructor['id']; ?>" style="color: var(--primary);">
                                    <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                </a>
                                (Score: <?php echo round($instructor['workload_score'], 1); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div style="background: rgba(37, 99, 235, 0.05); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--primary);">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--primary);">
                        <i class="fas fa-lightbulb"></i> Optimization Tips
                    </h4>
                    <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem; color: var(--gray);">
                        <li>Consider cross-training instructors in multiple subjects</li>
                        <li>Review class sizes and consider splitting large classes</li>
                        <li>Monitor assignment submission deadlines to avoid clustering</li>
                        <li>Schedule regular workload review meetings</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Create workload distribution chart
        const ctx = document.getElementById('workloadChart').getContext('2d');
        const workloadChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Overloaded', 'Balanced', 'Underloaded'],
                datasets: [{
                    data: [
                        <?php echo $stats['overloaded_count']; ?>,
                        <?php echo $stats['balanced_count']; ?>,
                        <?php echo $stats['underloaded_count']; ?>
                    ],
                    backgroundColor: [
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(16, 185, 129, 0.8)'
                    ],
                    borderColor: [
                        'rgb(239, 68, 68)',
                        'rgb(245, 158, 11)',
                        'rgb(16, 185, 129)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });

        // Sort table by column
        function sortTable(column) {
            const url = new URL(window.location.href);
            const currentSort = url.searchParams.get('sort');
            const currentOrder = url.searchParams.get('order');
            
            let newOrder = 'asc';
            if (currentSort === column && currentOrder === 'asc') {
                newOrder = 'desc';
            }
            
            url.searchParams.set('sort', column);
            url.searchParams.set('order', newOrder);
            window.location.href = url.toString();
        }

        // Export functionality
        function exportToCSV() {
            const table = document.querySelector('.workload-table');
            const rows = table.querySelectorAll('tr');
            let csv = [];
            
            rows.forEach(row => {
                const rowData = [];
                const cells = row.querySelectorAll('th, td');
                cells.forEach(cell => {
                    // Remove action buttons and icons
                    let text = cell.textContent.replace(/\n/g, ' ').trim();
                    text = text.replace(/[\u200B-\u200D\uFEFF]/g, '');
                    rowData.push(`"${text}"`);
                });
                csv.push(rowData.join(','));
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `instructor-workload-${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>