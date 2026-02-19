<?php
// modules/admin/content/analytics.php

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

// Get date range parameters
$range = $_GET['range'] ?? 'month';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$program_id = $_GET['program_id'] ?? 'all';

// Validate and set date ranges
if ($range === 'week') {
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime('sunday this week'));
} elseif ($range === 'month') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
} elseif ($range === 'quarter') {
    $quarter = ceil(date('n') / 3);
    $start_date = date('Y-m-01', mktime(0, 0, 0, ($quarter * 3) - 2, 1, date('Y')));
    $end_date = date('Y-m-t', mktime(0, 0, 0, ($quarter * 3), 1, date('Y')));
} elseif ($range === 'year') {
    $start_date = date('Y-01-01');
    $end_date = date('Y-12-31');
}

// Get program list for filter
$programs_query = "SELECT id, name FROM programs WHERE status = 'active' ORDER BY name";
$programs_result = $conn->query($programs_query);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Build WHERE clause for program filter
$program_where = '';
$program_params = [];
$program_types = '';

if ($program_id !== 'all' && is_numeric($program_id)) {
    $program_where = "AND p.id = ?";
    $program_params[] = $program_id;
    $program_types .= 'i';
}

// Get content upload statistics
$uploads_query = "
    SELECT 
        DATE(m.created_at) as date,
        COUNT(*) as uploads,
        SUM(m.file_size) as total_size,
        AVG(m.file_size) as avg_size
    FROM materials m
    JOIN class_batches cb ON m.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    WHERE m.created_at BETWEEN ? AND ?
    $program_where
    GROUP BY DATE(m.created_at)
    ORDER BY date
";

$uploads_params = array_merge([$start_date, $end_date], $program_params);
$uploads_types = 'ss' . $program_types;

$uploads_stmt = $conn->prepare($uploads_query);
if (!empty($uploads_params)) {
    $uploads_stmt->bind_param($uploads_types, ...$uploads_params);
}
$uploads_stmt->execute();
$uploads_data = $uploads_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get content type distribution
$type_distribution_query = "
    SELECT 
        m.file_type,
        COUNT(*) as count,
        SUM(m.file_size) as total_size,
        AVG(m.file_size) as avg_size,
        SUM(m.views_count) as total_views,
        SUM(m.downloads_count) as total_downloads
    FROM materials m
    JOIN class_batches cb ON m.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    WHERE m.created_at BETWEEN ? AND ?
    $program_where
    GROUP BY m.file_type
    ORDER BY count DESC
";

$type_params = array_merge([$start_date, $end_date], $program_params);
$type_types = 'ss' . $program_types;

$type_stmt = $conn->prepare($type_distribution_query);
if (!empty($type_params)) {
    $type_stmt->bind_param($type_types, ...$type_params);
}
$type_stmt->execute();
$type_distribution = $type_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get most viewed materials
$most_viewed_query = "
    SELECT 
        m.id,
        m.title,
        m.file_type,
        m.views_count,
        m.downloads_count,
        m.created_at,
        cb.batch_code,
        c.title as course_title,
        p.name as program_name,
        u.first_name,
        u.last_name
    FROM materials m
    JOIN class_batches cb ON m.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    JOIN users u ON m.instructor_id = u.id
    WHERE m.created_at BETWEEN ? AND ?
    $program_where
    ORDER BY m.views_count DESC
    LIMIT 10
";

$viewed_params = array_merge([$start_date, $end_date], $program_params);
$viewed_types = 'ss' . $program_types;

$viewed_stmt = $conn->prepare($most_viewed_query);
if (!empty($viewed_params)) {
    $viewed_stmt->bind_param($viewed_types, ...$viewed_params);
}
$viewed_stmt->execute();
$most_viewed = $viewed_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get most downloaded materials
$most_downloaded_query = "
    SELECT 
        m.id,
        m.title,
        m.file_type,
        m.downloads_count,
        m.views_count,
        m.created_at,
        cb.batch_code,
        c.title as course_title,
        p.name as program_name,
        u.first_name,
        u.last_name
    FROM materials m
    JOIN class_batches cb ON m.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    JOIN users u ON m.instructor_id = u.id
    WHERE m.created_at BETWEEN ? AND ?
    $program_where
    ORDER BY m.downloads_count DESC
    LIMIT 10
";

$downloaded_params = array_merge([$start_date, $end_date], $program_params);
$downloaded_types = 'ss' . $program_types;

$downloaded_stmt = $conn->prepare($most_downloaded_query);
if (!empty($downloaded_params)) {
    $downloaded_stmt->bind_param($downloaded_types, ...$downloaded_params);
}
$downloaded_stmt->execute();
$most_downloaded = $downloaded_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get top instructors by content
$top_instructors_query = "
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        COUNT(m.id) as materials_count,
        SUM(m.file_size) as total_size,
        SUM(m.views_count) as total_views,
        SUM(m.downloads_count) as total_downloads,
        AVG(m.file_size) as avg_size
    FROM users u
    JOIN materials m ON u.id = m.instructor_id
    JOIN class_batches cb ON m.class_id = cb.id
    JOIN courses c ON cb.course_id = c.id
    JOIN programs p ON c.program_id = p.id
    WHERE m.created_at BETWEEN ? AND ?
    $program_where
    GROUP BY u.id
    ORDER BY materials_count DESC
    LIMIT 10
";

$instructor_params = array_merge([$start_date, $end_date], $program_params);
$instructor_types = 'ss' . $program_types;

$instructor_stmt = $conn->prepare($top_instructors_query);
if (!empty($instructor_params)) {
    $instructor_stmt->bind_param($instructor_types, ...$instructor_params);
}
$instructor_stmt->execute();
$top_instructors = $instructor_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$stats = [
    'total_materials' => array_sum(array_column($type_distribution, 'count')),
    'total_size' => array_sum(array_column($type_distribution, 'total_size')),
    'total_views' => array_sum(array_column($type_distribution, 'total_views')),
    'total_downloads' => array_sum(array_column($type_distribution, 'total_downloads')),
    'avg_views_per_material' => 0,
    'avg_downloads_per_material' => 0,
];

if ($stats['total_materials'] > 0) {
    $stats['avg_views_per_material'] = round($stats['total_views'] / $stats['total_materials'], 1);
    $stats['avg_downloads_per_material'] = round($stats['total_downloads'] / $stats['total_materials'], 1);
}

// Get program-wise distribution
$program_distribution_query = "
    SELECT 
        p.id,
        p.name,
        COUNT(m.id) as materials_count,
        SUM(m.file_size) as total_size,
        SUM(m.views_count) as total_views,
        SUM(m.downloads_count) as total_downloads
    FROM programs p
    JOIN courses c ON p.id = c.program_id
    JOIN class_batches cb ON c.id = cb.course_id
    JOIN materials m ON cb.id = m.class_id
    WHERE m.created_at BETWEEN ? AND ?
    GROUP BY p.id
    ORDER BY materials_count DESC
";

$program_dist_stmt = $conn->prepare($program_distribution_query);
$program_dist_stmt->bind_param("ss", $start_date, $end_date);
$program_dist_stmt->execute();
$program_distribution = $program_dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity('view_content_analytics', "Viewed content analytics for period: $range");

// Function to format bytes to human readable format
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Analytics - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
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

        .stat-card.materials { border-left-color: var(--primary); }
        .stat-card.size { border-left-color: var(--warning); }
        .stat-card.views { border-left-color: var(--info); }
        .stat-card.downloads { border-left-color: var(--success); }
        .stat-card.avg-views { border-left-color: var(--secondary); }
        .stat-card.avg-downloads { border-left-color: var(--danger); }

        .filters-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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
            margin: 1rem 0;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .table th {
            text-align: left;
            padding: 0.75rem;
            background: var(--light);
            color: var(--gray);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }

        .table tr:hover {
            background: var(--light);
        }

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

        .export-options {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .insight-card {
            background: var(--light);
            border-radius: 8px;
            padding: 1rem;
            margin: 0.5rem 0;
        }

        @media (max-width: 768px) {
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 0.85rem;
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
            <a href="index.php">Content Oversight</a>
            <i class="fas fa-chevron-right"></i>
            <span>Analytics</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Content Analytics Dashboard</h1>
            <p style="color: var(--gray); margin-top: 0.5rem;">
                Track content performance, engagement metrics, and usage patterns
            </p>
        </div>

        <!-- Date Range Filters -->
        <div class="filters-card">
            <h3 style="margin-bottom: 1rem;">Analytics Period</h3>
            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="range">Quick Range</label>
                        <select id="range" name="range" class="form-control" onchange="this.form.submit()">
                            <option value="week" <?php echo $range === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $range === 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="quarter" <?php echo $range === 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                            <option value="year" <?php echo $range === 'year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="custom">Custom Range</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control"
                               value="<?php echo $start_date; ?>"
                               <?php echo $range === 'custom' ? '' : 'disabled'; ?>>
                    </div>

                    <div class="filter-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control"
                               value="<?php echo $end_date; ?>"
                               <?php echo $range === 'custom' ? '' : 'disabled'; ?>>
                    </div>

                    <div class="filter-group">
                        <label for="program_id">Program Filter</label>
                        <select id="program_id" name="program_id" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $program_id === 'all' ? 'selected' : ''; ?>>All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>" 
                                    <?php echo $program_id == $program['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-actions" style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn" style="background: var(--light-gray);" onclick="exportAnalytics()">
                        <i class="fas fa-file-export"></i> Export Data
                    </button>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card materials">
                <div class="stat-value"><?php echo number_format($stats['total_materials']); ?></div>
                <div class="stat-label">Materials Uploaded</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo count($uploads_data); ?> days with uploads
                </div>
            </div>
            <div class="stat-card size">
                <div class="stat-value"><?php echo formatBytes($stats['total_size']); ?></div>
                <div class="stat-label">Total Storage Used</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo $stats['total_materials'] > 0 ? formatBytes($stats['total_size'] / $stats['total_materials']) : '0 B'; ?> avg/file
                </div>
            </div>
            <div class="stat-card views">
                <div class="stat-value"><?php echo number_format($stats['total_views']); ?></div>
                <div class="stat-label">Total Views</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo number_format($stats['avg_views_per_material']); ?> views/file
                </div>
            </div>
            <div class="stat-card downloads">
                <div class="stat-value"><?php echo number_format($stats['total_downloads']); ?></div>
                <div class="stat-label">Total Downloads</div>
                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.25rem;">
                    <?php echo number_format($stats['avg_downloads_per_material']); ?> downloads/file
                </div>
            </div>
            <div class="stat-card avg-views">
                <div class="stat-value"><?php echo number_format($stats['total_materials'] > 0 ? $stats['total_views'] / $stats['total_materials'] : 0, 1); ?></div>
                <div class="stat-label">Avg Views/Material</div>
            </div>
            <div class="stat-card avg-downloads">
                <div class="stat-value"><?php echo number_format($stats['total_materials'] > 0 ? $stats['total_downloads'] / $stats['total_materials'] : 0, 1); ?></div>
                <div class="stat-label">Avg Downloads/Material</div>
            </div>
        </div>

        <!-- Uploads Over Time Chart -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Content Uploads Over Time</h3>
            <div class="chart-container">
                <canvas id="uploadsChart"></canvas>
            </div>
        </div>

        <!-- Content Type Distribution -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Content Type Distribution</h3>
            <div class="chart-container">
                <canvas id="typeChart"></canvas>
            </div>
            
            <?php if (!empty($type_distribution)): ?>
            <div style="margin-top: 1rem;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>File Type</th>
                            <th>Count</th>
                            <th>Total Size</th>
                            <th>Avg Size</th>
                            <th>Total Views</th>
                            <th>Total Downloads</th>
                            <th>Engagement Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($type_distribution as $type): 
                            $engagement_rate = $type['total_views'] > 0 ? 
                                round(($type['total_downloads'] / $type['total_views']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <span style="text-transform: capitalize; font-weight: 500;">
                                        <?php echo htmlspecialchars($type['file_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($type['count']); ?></td>
                                <td><?php echo formatBytes($type['total_size']); ?></td>
                                <td><?php echo formatBytes($type['avg_size']); ?></td>
                                <td><?php echo number_format($type['total_views']); ?></td>
                                <td><?php echo number_format($type['total_downloads']); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div style="flex: 1; height: 6px; background: var(--light-gray); border-radius: 3px;">
                                            <div style="width: <?php echo min($engagement_rate, 100); ?>%; height: 100%; 
                                                 background: <?php echo $engagement_rate > 50 ? 'var(--success)' : ($engagement_rate > 25 ? 'var(--warning)' : 'var(--danger)'); ?>;
                                                 border-radius: 3px;"></div>
                                        </div>
                                        <span><?php echo $engagement_rate; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Most Viewed Materials -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Most Viewed Materials</h3>
            <?php if (empty($most_viewed)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray);">
                    <i class="fas fa-chart-line" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>No view data available for selected period</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Program/Class</th>
                            <th>Instructor</th>
                            <th>Uploaded</th>
                            <th>Views</th>
                            <th>Downloads</th>
                            <th>Engagement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($most_viewed as $material): 
                            $engagement = $material['views_count'] > 0 ? 
                                round(($material['downloads_count'] / $material['views_count']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500; margin-bottom: 0.25rem;">
                                        <?php echo htmlspecialchars($material['title']); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo ucfirst($material['file_type']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($material['program_name']); ?></div>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($material['course_title']); ?>
                                        <?php if ($material['batch_code']): ?>
                                            (<?php echo htmlspecialchars($material['batch_code']); ?>)
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($material['first_name'] . ' ' . $material['last_name']); ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--info); font-size: 1.1rem;">
                                        <?php echo number_format($material['views_count']); ?>
                                    </strong>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--success); font-size: 1.1rem;">
                                        <?php echo number_format($material['downloads_count']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <div style="text-align: center;">
                                        <strong style="color: <?php echo $engagement > 50 ? 'var(--success)' : ($engagement > 25 ? 'var(--warning)' : 'var(--danger)'); ?>;">
                                            <?php echo $engagement; ?>%
                                        </strong>
                                        <div style="font-size: 0.75rem; color: var(--gray);">
                                            Download/View
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Most Downloaded Materials -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Most Downloaded Materials</h3>
            <?php if (empty($most_downloaded)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray);">
                    <i class="fas fa-download" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>No download data available for selected period</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Program/Class</th>
                            <th>Instructor</th>
                            <th>Uploaded</th>
                            <th>Downloads</th>
                            <th>Views</th>
                            <th>Engagement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($most_downloaded as $material): 
                            $engagement = $material['views_count'] > 0 ? 
                                round(($material['downloads_count'] / $material['views_count']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500; margin-bottom: 0.25rem;">
                                        <?php echo htmlspecialchars($material['title']); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo ucfirst($material['file_type']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($material['program_name']); ?></div>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($material['course_title']); ?>
                                        <?php if ($material['batch_code']): ?>
                                            (<?php echo htmlspecialchars($material['batch_code']); ?>)
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($material['first_name'] . ' ' . $material['last_name']); ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--success); font-size: 1.1rem;">
                                        <?php echo number_format($material['downloads_count']); ?>
                                    </strong>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--info); font-size: 1.1rem;">
                                        <?php echo number_format($material['views_count']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <div style="text-align: center;">
                                        <strong style="color: <?php echo $engagement > 50 ? 'var(--success)' : ($engagement > 25 ? 'var(--warning)' : 'var(--danger)'); ?>;">
                                            <?php echo $engagement; ?>%
                                        </strong>
                                        <div style="font-size: 0.75rem; color: var(--gray);">
                                            Download/View
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Top Instructors by Content -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Top Instructors by Content Creation</h3>
            <?php if (empty($top_instructors)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray);">
                    <i class="fas fa-chalkboard-teacher" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>No instructor data available for selected period</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Instructor</th>
                            <th>Materials</th>
                            <th>Total Size</th>
                            <th>Avg Size</th>
                            <th>Total Views</th>
                            <th>Total Downloads</th>
                            <th>Engagement Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_instructors as $instructor): 
                            $engagement_score = $instructor['total_views'] > 0 ? 
                                round(($instructor['total_downloads'] / $instructor['total_views']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500;">
                                        <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--primary); font-size: 1.1rem;">
                                        <?php echo number_format($instructor['materials_count']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo formatBytes($instructor['total_size']); ?>
                                </td>
                                <td>
                                    <?php echo formatBytes($instructor['avg_size']); ?>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--info);">
                                        <?php echo number_format($instructor['total_views']); ?>
                                    </strong>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: var(--success);">
                                        <?php echo number_format($instructor['total_downloads']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <div style="text-align: center;">
                                        <strong style="color: <?php echo $engagement_score > 50 ? 'var(--success)' : ($engagement_score > 25 ? 'var(--warning)' : 'var(--danger)'); ?>;">
                                            <?php echo $engagement_score; ?>%
                                        </strong>
                                        <div style="font-size: 0.75rem; color: var(--gray);">
                                            Engagement
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Program Distribution -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Content Distribution by Program</h3>
            <?php if (empty($program_distribution)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray);">
                    <i class="fas fa-project-diagram" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>No program distribution data available</p>
                </div>
            <?php else: ?>
                <div class="chart-container">
                    <canvas id="programChart"></canvas>
                </div>
                
                <div style="margin-top: 1rem;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Materials</th>
                                <th>Total Size</th>
                                <th>Total Views</th>
                                <th>Total Downloads</th>
                                <th>Engagement Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($program_distribution as $program): 
                                $program_engagement = $program['total_views'] > 0 ? 
                                    round(($program['total_downloads'] / $program['total_views']) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($program['name']); ?></strong>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php echo number_format($program['materials_count']); ?>
                                    </td>
                                    <td>
                                        <?php echo formatBytes($program['total_size']); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php echo number_format($program['total_views']); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php echo number_format($program['total_downloads']); ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <div style="flex: 1; height: 6px; background: var(--light-gray); border-radius: 3px;">
                                                <div style="width: <?php echo min($program_engagement, 100); ?>%; height: 100%; 
                                                     background: <?php echo $program_engagement > 50 ? 'var(--success)' : ($program_engagement > 25 ? 'var(--warning)' : 'var(--danger)'); ?>;
                                                     border-radius: 3px;"></div>
                                            </div>
                                            <span><?php echo $program_engagement; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Key Insights -->
        <div class="card">
            <h3 style="margin-bottom: 1rem;">Key Insights & Recommendations</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
                <?php 
                // Calculate insights
                $avg_uploads_per_day = count($uploads_data) > 0 ? $stats['total_materials'] / count($uploads_data) : 0;
                $top_type = !empty($type_distribution) ? $type_distribution[0] : null;
                $most_engaged_instructor = !empty($top_instructors) ? $top_instructors[0] : null;
                ?>
                
                <?php if ($avg_uploads_per_day > 0): ?>
                <div class="insight-card">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--primary);">
                        <i class="fas fa-upload"></i> Upload Activity
                    </h4>
                    <p style="margin: 0; font-size: 0.9rem; color: var(--gray);">
                        Average of <strong><?php echo number_format($avg_uploads_per_day, 1); ?></strong> materials uploaded per active day.
                        <?php if ($avg_uploads_per_day < 1): ?>
                            Consider encouraging more content creation.
                        <?php elseif ($avg_uploads_per_day > 3): ?>
                            Healthy upload frequency maintained.
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
                
                <?php if ($top_type): ?>
                <div class="insight-card">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--success);">
                        <i class="fas fa-file"></i> Popular Format
                    </h4>
                    <p style="margin: 0; font-size: 0.9rem; color: var(--gray);">
                        <strong><?php echo ucfirst($top_type['file_type']); ?></strong> is the most common file type 
                        (<?php echo $top_type['count']; ?> files, <?php echo round(($top_type['count'] / $stats['total_materials']) * 100, 1); ?>% of total).
                    </p>
                </div>
                <?php endif; ?>
                
                <?php if ($most_engaged_instructor): ?>
                <div class="insight-card">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--warning);">
                        <i class="fas fa-star"></i> Top Performer
                    </h4>
                    <p style="margin: 0; font-size: 0.9rem; color: var(--gray);">
                        <strong><?php echo $most_engaged_instructor['first_name'] . ' ' . $most_engaged_instructor['last_name']; ?></strong>
                        created the most engaging content with 
                        <?php echo round(($most_engaged_instructor['total_downloads'] / $most_engaged_instructor['total_views']) * 100, 1); ?>% engagement rate.
                    </p>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($program_distribution)): 
                    $top_program = $program_distribution[0];
                    $top_program_engagement = $top_program['total_views'] > 0 ? 
                        round(($top_program['total_downloads'] / $top_program['total_views']) * 100, 1) : 0;
                ?>
                <div class="insight-card">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--info);">
                        <i class="fas fa-graduation-cap"></i> Leading Program
                    </h4>
                    <p style="margin: 0; font-size: 0.9rem; color: var(--gray);">
                        <strong><?php echo $top_program['name']; ?></strong> has the most content 
                        (<?php echo $top_program['materials_count']; ?> materials) with <?php echo $top_program_engagement; ?>% engagement.
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Enable/disable date inputs based on range selection
        document.getElementById('range').addEventListener('change', function() {
            const isCustom = this.value === 'custom';
            document.getElementById('start_date').disabled = !isCustom;
            document.getElementById('end_date').disabled = !isCustom;
        });

        // Uploads over time chart
        const uploadsCtx = document.getElementById('uploadsChart').getContext('2d');
        const uploadsChart = new Chart(uploadsCtx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($item) { return "'" . $item['date'] . "'"; }, $uploads_data)); ?>],
                datasets: [{
                    label: 'Uploads',
                    data: [<?php echo implode(',', array_column($uploads_data, 'uploads')); ?>],
                    borderColor: 'rgb(37, 99, 235)',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Total Size (MB)',
                    data: [<?php echo implode(',', array_map(function($item) { return round($item['total_size'] / 1048576, 2); }, $uploads_data)); ?>],
                    borderColor: 'rgb(245, 158, 11)',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'day'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Number of Uploads'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Size (MB)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        // Content type distribution chart
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        const typeChart = new Chart(typeCtx, {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(function($type) { return "'" . ucfirst($type['file_type']) . "'"; }, $type_distribution)); ?>],
                datasets: [{
                    label: 'Number of Files',
                    data: [<?php echo implode(',', array_column($type_distribution, 'count')); ?>],
                    backgroundColor: 'rgba(37, 99, 235, 0.8)',
                    borderColor: 'rgb(37, 99, 235)',
                    borderWidth: 1
                }, {
                    label: 'Total Views (thousands)',
                    data: [<?php echo implode(',', array_map(function($type) { return round($type['total_views'] / 1000, 1); }, $type_distribution)); ?>],
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 1,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Files'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Views (thousands)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        // Program distribution chart
        const programCtx = document.getElementById('programChart').getContext('2d');
        const programChart = new Chart(programCtx, {
            type: 'pie',
            data: {
                labels: [<?php echo implode(',', array_map(function($program) { return "'" . htmlspecialchars($program['name']) . "'"; }, $program_distribution)); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($program_distribution, 'materials_count')); ?>],
                    backgroundColor: [
                        'rgba(37, 99, 235, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(59, 130, 246, 0.8)'
                    ],
                    borderColor: [
                        'rgb(37, 99, 235)',
                        'rgb(245, 158, 11)',
                        'rgb(16, 185, 129)',
                        'rgb(239, 68, 68)',
                        'rgb(139, 92, 246)',
                        'rgb(59, 130, 246)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });

        // Export analytics data
        function exportAnalytics() {
            const url = new URL(window.location.href);
            url.searchParams.set('export', 'csv');
            window.location.href = url.toString();
        }

        // Format bytes function for JavaScript
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>