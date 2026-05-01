<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once 'config.php';

$action = $_GET['action'] ?? '';

// --- Check duplicate phone ---
if ($action === 'check_phone') {
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone) {
        echo json_encode(['exists' => false]);
        exit;
    }
    $stmt = $conn->prepare("SELECT full_name, command_name FROM recruits WHERE phone_number = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if ($result) {
        echo json_encode([
            'exists'  => true,
            'name'    => $result['full_name'],
            'command' => $result['command_name']
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit;
}

// --- Get recruits submitted by a command (for history view) ---
if ($action === 'my_recruits') {
    $command_name = trim($_GET['command_name'] ?? '');
    $limit        = intval($_GET['limit'] ?? 20);
    $offset       = intval($_GET['offset'] ?? 0);

    if (!$command_name) {
        echo json_encode(['success' => false, 'message' => 'Missing command_name']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT id, full_name, phone_number, status, registration_date, submitted_by_name, created_at
        FROM recruits
        WHERE command_name = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("sii", $command_name, $limit, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Total count
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM recruits WHERE command_name = ?");
    $countStmt->bind_param("s", $command_name);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_row()[0];
    $countStmt->close();
    $conn->close();

    echo json_encode([
        'success'  => true,
        'recruits' => $rows,
        'total'    => $total
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
