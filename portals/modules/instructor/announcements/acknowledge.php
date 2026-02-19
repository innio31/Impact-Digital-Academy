<?php
// modules/instructor/announcements/acknowledge.php
session_start();
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log the request for debugging
error_log("Acknowledgment API called: " . file_get_contents('php://input'));

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. User not logged in or not instructor.']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$announcement_id = $data['announcement_id'] ?? 0;

error_log("Announcement ID: " . $announcement_id . ", User ID: " . $_SESSION['user_id']);

if (!$announcement_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid announcement ID']);
    exit();
}

$conn = getDBConnection();
if (!$conn) {
    error_log("Database connection failed");
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Check if announcement exists and requires acknowledgment
    $sql = "SELECT id, title, requires_acknowledgment FROM announcements WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("i", $announcement_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
        exit();
    }

    $announcement = $result->fetch_assoc();
    $stmt->close();

    if ($announcement['requires_acknowledgment'] != 1) {
        echo json_encode(['success' => false, 'message' => 'Announcement does not require acknowledgment']);
        exit();
    }

    // Check if already acknowledged
    $sql = "SELECT 1 FROM announcement_acknowledgments 
            WHERE announcement_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("ii", $announcement_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Already acknowledged']);
        exit();
    }
    $stmt->close();

    // Record acknowledgment
    $sql = "INSERT INTO announcement_acknowledgments 
            (announcement_id, user_id, acknowledged_at, ip_address, user_agent) 
            VALUES (?, ?, NOW(), ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $stmt->bind_param("iiss", $announcement_id, $user_id, $ip_address, $user_agent);

    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to save acknowledgment: ' . $stmt->error]);
        $stmt->close();
        exit();
    }
    $stmt->close();

    // Update acknowledgment count
    $sql = "UPDATE announcements 
            SET acknowledged_count = acknowledged_count + 1 
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("i", $announcement_id);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to update count: ' . $stmt->error]);
        $stmt->close();
        exit();
    }
    $stmt->close();

    // Log activity
    logActivity(
        $user_id,
        'announcement_acknowledged',
        "Acknowledged announcement: " . $announcement['title'],
        'announcements',
        $announcement_id
    );

    echo json_encode([
        'success' => true,
        'message' => 'Announcement acknowledged successfully',
        'announcement_id' => $announcement_id
    ]);
} catch (Exception $e) {
    error_log("Acknowledgment API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
