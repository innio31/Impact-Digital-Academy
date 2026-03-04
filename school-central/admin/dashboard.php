<?php
$page_title = 'Dashboard';
require_once 'auth_check.php';
require_once 'header.php';

// Initialize stats array with defaults
$stats = [
    'total_objective' => 0,
    'total_theory' => 0,
    'total_schools' => 0,
    'active_schools' => 0,
    'downloads_today' => 0
];

// Get statistics with error handling
try {
    // Total objective questions
    $result = $pdo->query("SELECT COUNT(*) FROM central_objective_questions");
    $stats['total_objective'] = $result ? $result->fetchColumn() : 0;
} catch (PDOException $e) {
    error_log("Error counting objective questions: " . $e->getMessage());
}

try {
    // Total theory questions
    $result = $pdo->query("SELECT COUNT(*) FROM central_theory_questions");
    $stats['total_theory'] = $result ? $result->fetchColumn() : 0;
} catch (PDOException $e) {
    error_log("Error counting theory questions: " . $e->getMessage());
}

try {
    // Total schools
    $result = $pdo->query("SELECT COUNT(*) FROM schools");
    $stats['total_schools'] = $result ? $result->fetchColumn() : 0;
} catch (PDOException $e) {
    error_log("Error counting schools: " . $e->getMessage());
}

try {
    // Active schools
    $result = $pdo->query("SELECT COUNT(*) FROM schools WHERE subscription_status = 'active'");
    $stats['active_schools'] = $result ? $result->fetchColumn() : 0;
} catch (PDOException $e) {
    error_log("Error counting active schools: " . $e->getMessage());
}

try {
    // Downloads today
    $result = $pdo->query("SELECT COUNT(*) FROM question_downloads WHERE DATE(downloaded_at) = CURDATE()");
    $stats['downloads_today'] = $result ? $result->fetchColumn() : 0;
} catch (PDOException $e) {
    error_log("Error counting downloads: " . $e->getMessage());
    $stats['downloads_today'] = 0;
}

// Popular subjects with error handling
$popular_subjects = [];
try {
    $stmt = $pdo->query("
        SELECT s.subject_name, COUNT(q.id) as question_count
        FROM master_subjects s
        LEFT JOIN central_objective_questions q ON s.id = q.subject_id
        GROUP BY s.id
        ORDER BY question_count DESC
        LIMIT 5
    ");
    $popular_subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching popular subjects: " . $e->getMessage());
}

// Recent downloads with error handling
$recent_downloads = [];
try {
    $stmt = $pdo->query("
        SELECT d.*, s.school_name, 
               COALESCE(q.question_text, 'Question deleted') as question_text
        FROM question_downloads d
        JOIN schools s ON d.school_id = s.id
        LEFT JOIN central_objective_questions q ON d.question_id = q.id
        WHERE d.question_type = 'objective'
        ORDER BY d.downloaded_at DESC
        LIMIT 10
    ");
    $recent_downloads = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent downloads: " . $e->getMessage());
}

// Get current time for display
$current_time = date('h:i A');
$current_date = date('M d, Y');
?>

<div class="dashboard">
    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #e3f2fd; color: #1976d2;">
                <i class="fas fa-question-circle"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo number_format($stats['total_objective'] + $stats['total_theory']); ?></h3>
                <p>Total Questions</p>
                <small><?php echo $stats['total_objective']; ?> Objective | <?php echo $stats['total_theory']; ?> Theory</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #e8f5e8; color: #388e3c;">
                <i class="fas fa-school"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $stats['active_schools']; ?> / <?php echo $stats['total_schools']; ?></h3>
                <p>Active Schools</p>
                <small>Total registered schools</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #fff3e0; color: #f57c00;">
                <i class="fas fa-download"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo number_format($stats['downloads_today']); ?></h3>
                <p>Downloads Today</p>
                <small>Last 24 hours</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #f3e5f5; color: #7b1fa2;">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $current_time; ?></h3>
                <p>Server Time</p>
                <small><?php echo $current_date; ?></small>
            </div>
        </div>
    </div>

    <div class="dashboard-grid">
        <!-- Popular Subjects -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-star"></i> Popular Subjects</h3>
                <a href="subjects.php" class="btn-small">Manage</a>
            </div>
            <div class="card-body">
                <?php if (empty($popular_subjects)): ?>
                    <p class="no-data">No subjects found. <a href="subjects.php">Add your first subject</a></p>
                <?php else: ?>
                    <table class="mini-table">
                        <?php foreach ($popular_subjects as $subject): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                <td class="text-right"><?php echo number_format($subject['question_count']); ?> questions</td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="card-body">
                <div class="quick-actions">
                    <a href="upload.php" class="action-btn">
                        <i class="fas fa-upload"></i>
                        <span>Upload Questions</span>
                    </a>
                    <a href="manage_questions.php" class="action-btn">
                        <i class="fas fa-edit"></i>
                        <span>Edit Questions</span>
                    </a>
                    <a href="manage_schools.php" class="action-btn">
                        <i class="fas fa-plus-circle"></i>
                        <span>Add School</span>
                    </a>
                    <a href="subjects.php" class="action-btn">
                        <i class="fas fa-book"></i>
                        <span>Add Subject</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Recent Downloads -->
        <div class="dashboard-card full-width">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Downloads</h3>
                <a href="view_stats.php" class="btn-small">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_downloads)): ?>
                    <p class="no-data">No downloads yet. Once schools start downloading questions, they'll appear here.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>School</th>
                                <th>Question</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_downloads as $download): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($download['school_name']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($download['question_text'], 0, 50)); ?>...</td>
                                    <td><?php echo date('H:i', strtotime($download['downloaded_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .no-data {
        text-align: center;
        padding: 30px;
        color: #999;
        background: #f9f9f9;
        border-radius: 5px;
    }

    .no-data a {
        color: #667eea;
        text-decoration: none;
    }

    .no-data a:hover {
        text-decoration: underline;
    }
</style>

<?php require_once 'footer.php'; ?>