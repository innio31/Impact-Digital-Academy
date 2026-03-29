<?php
// admin/create_admin.php - Temporary script to create admin user
// DELETE THIS FILE AFTER USE!

require_once '../config/config.php';

// Your desired admin credentials
$username = 'admin';
$password = 'Impact2026';  // Change this to your desired password
$full_name = 'Super Administrator';
$email = 'admin@impactdigitalacademy.com.ng';
$role = 'super_admin';

try {
    $db = getDB();

    // Check if admin already exists
    $stmt = $db->prepare("SELECT id FROM portal_admins WHERE username = ?");
    $stmt->execute([$username]);

    if ($stmt->fetch()) {
        // Update existing admin with proper password hash
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE portal_admins SET password = ? WHERE username = ?");
        $stmt->execute([$hashed_password, $username]);
        echo "Admin user UPDATED successfully!<br>";
    } else {
        // Create new admin
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            INSERT INTO portal_admins (username, password, full_name, email, role, status, created_at) 
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$username, $hashed_password, $full_name, $email, $role]);
        echo "Admin user CREATED successfully!<br>";
    }

    echo "<br><strong>Login Credentials:</strong><br>";
    echo "Username: " . $username . "<br>";
    echo "Password: " . $password . "<br>";
    echo "<br><a href='login.php'>Go to Login Page</a>";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
