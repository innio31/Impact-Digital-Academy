<?php
require_once 'includes/config.php';

$input = getJsonInput();
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';
$userType = $input['user_type'] ?? 'student';

$authenticated = false;
$userData = [];

if ($userType === 'student') {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_number = ? AND school_id = ?");
    $stmt->execute([$username, SCHOOL_ID]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $authenticated = true;
        $userData = [
            'id' => $user['id'],
            'name' => $user['full_name'],
            'admission_no' => $user['admission_number'],
            'class' => $user['class'],
            'type' => 'student'
        ];
    }
} elseif ($userType === 'staff') {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ? AND school_id = ? AND is_active = 1");
    $stmt->execute([$username, SCHOOL_ID]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $authenticated = true;
        $userData = [
            'id' => $user['id'],
            'staff_id' => $user['staff_id'],
            'name' => $user['full_name'],
            'role' => $user['role'],
            'type' => 'staff'
        ];
    }
} elseif ($userType === 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND school_id = ?");
    $stmt->execute([$username, SCHOOL_ID]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $authenticated = true;
        $userData = [
            'id' => $user['id'],
            'username' => $user['username'],
            'name' => $user['full_name'],
            'role' => $user['role'],
            'type' => 'admin'
        ];
    }
}

if ($authenticated) {
    $token = base64_encode(json_encode(['user_id' => $userData['id'], 'type' => $userData['type'], 'exp' => time() + 86400]));
    sendResponse(['success' => true, 'token' => $token, 'user' => $userData]);
} else {
    sendResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
}
?>