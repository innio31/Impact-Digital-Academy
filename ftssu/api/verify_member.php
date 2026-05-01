<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';
try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['id_number']) || !isset($input['password'])) {
        echo json_encode(['success' => false, 'message' => 'Missing credentials']);
        exit;
    }

    $id_number = trim($input['id_number']);
    $plain_password = trim($input['password']);

    // Connect to database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query member by ID number
    $stmt = $pdo->prepare("SELECT id, id_number, first_name, last_name, designation, command, role, password, is_active FROM members WHERE id_number = :id_number");
    $stmt->execute([':id_number' => $id_number]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found']);
        exit;
    }

    // Check if account is active
    if (!$member['is_active']) {
        echo json_encode(['success' => false, 'message' => 'Account is deactivated']);
        exit;
    }

    $stored_password = $member['password'];
    $login_success = false;

    // Check password (supports both bcrypt and plain text for backward compatibility)
    if (password_verify($plain_password, $stored_password)) {
        // BCrypt hash matches
        $login_success = true;
    } elseif ($stored_password === $plain_password) {
        // Plain text password (legacy) - hash it for future logins
        $login_success = true;

        // Update to bcrypt hash
        $hashed = password_hash($plain_password, PASSWORD_BCRYPT);
        $update = $pdo->prepare("UPDATE members SET password = :hashed WHERE id = :id");
        $update->execute([':hashed' => $hashed, ':id' => $member['id']]);

        error_log("Migrated member {$member['id_number']} from plain text to bcrypt");
    }

    if ($login_success) {
        // Remove sensitive data
        unset($member['password']);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'member' => $member
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
