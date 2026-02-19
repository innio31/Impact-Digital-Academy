<?php
// modules/student/dashboard_header.php

// This file should be included in all student pages
// It provides consistent navigation and styling

// Get user details for the header
$user_id = $_SESSION['user_id'];
$user_details = [];

$conn = getDBConnection();
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? AND u.role = 'student'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user_details = $result->fetch_assoc();
    }
    $stmt->close();
}

$initials = strtoupper(substr($user_details['first_name'] ?? '', 0, 1) . substr($user_details['last_name'] ?? '', 0, 1));
?>

<style>
    .student-header {
        background: white;
        padding: 15px 30px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: #2c3e50;
    }

    .logo-icon {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #4361ee, #7209b7);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 18px;
    }

    .logo-text {
        font-weight: 600;
        font-size: 18px;
    }

    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 14px;
        color: #7f8c8d;
    }

    .breadcrumb a {
        color: #4361ee;
        text-decoration: none;
    }

    .breadcrumb a:hover {
        text-decoration: underline;
    }

    .breadcrumb .separator {
        margin: 0 5px;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .header-notification {
        position: relative;
    }

    .notification-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #e63946;
        color: white;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 10px;
        min-width: 18px;
        text-align: center;
    }

    .header-user {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        position: relative;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #4361ee, #7209b7);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 16px;
    }

    .user-name {
        font-weight: 500;
        color: #2c3e50;
    }

    .user-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border: 1px solid #e0e6ed;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        min-width: 200px;
        display: none;
        z-index: 1000;
    }

    .user-dropdown.active {
        display: block;
    }

    .user-dropdown a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        color: #2c3e50;
        text-decoration: none;
        transition: background 0.3s ease;
    }

    .user-dropdown a:hover {
        background: #f8f9fa;
    }

    .user-dropdown a i {
        width: 20px;
        color: #7f8c8d;
    }

    @media (max-width: 768px) {
        .student-header {
            padding: 15px;
            flex-direction: column;
            gap: 15px;
        }

        .header-left,
        .header-right {
            width: 100%;
            justify-content: space-between;
        }

        .user-name {
            display: none;
        }
    }
</style>

<div class="student-header">
    <div class="header-left">
        <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="logo">
            <div class="logo-icon">IDA</div>
            <div class="logo-text">Student Portal</div>
        </a>

        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">Dashboard</a>
            <span class="separator">/</span>
            <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php">Finance</a>
            <span class="separator">/</span>
            <span>Fee Structure</span>
        </div>
    </div>

    <div class="header-right">
        <div class="header-notification">
            <a href="<?php echo BASE_URL; ?>modules/shared/notifications/" style="color: #2c3e50;">
                <i class="fas fa-bell" style="font-size: 18px;"></i>
            </a>
            <span class="notification-badge">3</span> <!-- This should be dynamic -->
        </div>

        <div class="header-user" onclick="toggleUserDropdown()">
            <div class="user-avatar">
                <?php echo $initials ?: 'S'; ?>
            </div>
            <div class="user-name">
                <?php echo htmlspecialchars($user_details['first_name'] ?? 'Student'); ?>
            </div>
            <i class="fas fa-chevron-down"></i>

            <div class="user-dropdown" id="userDropdown">
                <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php">
                    <i class="fas fa-user"></i>
                    <span>My Profile</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/profile/edit_account.php">
                    <i class="fas fa-cog"></i>
                    <span>Account Settings</span>
                </a>
                <div style="height: 1px; background: #e0e6ed; margin: 5px 0;"></div>
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php"
                    onclick="return confirm('Are you sure you want to logout?');"
                    style="color: #e63946;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleUserDropdown() {
        document.getElementById('userDropdown').classList.toggle('active');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const userDropdown = document.getElementById('userDropdown');
        const headerUser = document.querySelector('.header-user');

        if (!headerUser.contains(event.target)) {
            userDropdown.classList.remove('active');
        }
    });
</script>