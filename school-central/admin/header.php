<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central CBT - <?php echo $page_title ?? 'Dashboard'; ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <h3>Central CBT</h3>
                <p>Admin Panel</p>
            </div>

            <ul class="nav-links">
                <li>
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-dashboard"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="subjects.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'subjects.php' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i> Subjects
                    </a>
                </li>
                <li>
                    <a href="topics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'topics.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tags"></i> Topics
                    </a>
                </li>
                <li>
                    <a href="manage_questions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_questions.php' ? 'active' : ''; ?>">
                        <i class="fas fa-question-circle"></i> Questions
                    </a>
                </li>
                <li>
                    <a href="upload.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'upload.php' ? 'active' : ''; ?>">
                        <i class="fas fa-upload"></i> Bulk Upload
                    </a>
                </li>
                <li>
                    <a href="manage_schools.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_schools.php' ? 'active' : ''; ?>">
                        <i class="fas fa-school"></i> Schools
                    </a>
                </li>
                <li>
                    <a href="view_stats.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'view_stats.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i> Statistics
                    </a>
                </li>
                <li class="logout">
                    <a href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>

            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
            </div>
        </nav>

        <!-- Main content -->
        <main class="main-content">
            <header class="top-bar">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title"><?php echo $page_title ?? 'Dashboard'; ?></div>
                <div class="top-bar-right">
                    <span class="date"><?php echo date('l, F j, Y'); ?></span>
                </div>
            </header>

            <div class="content-wrapper">