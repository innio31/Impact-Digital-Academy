<?php
require_once 'config.php';

function authenticateSchool()
{
    global $pdo;

    // Get API key from header
    $headers = getallheaders();
    $api_key = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : '';

    // Also check GET parameter (for testing)
    if (empty($api_key) && isset($_GET['api_key'])) {
        $api_key = $_GET['api_key'];
    }

    if (empty($api_key)) {
        http_response_code(401);
        die(json_encode(['error' => 'API key required']));
    }

    // Validate API key format (32 characters hex)
    if (!preg_match('/^[a-f0-9]{32}$/', $api_key)) {
        http_response_code(401);
        die(json_encode(['error' => 'Invalid API key format']));
    }

    // Check database
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE api_key = ? AND subscription_status = 'active'");
    $stmt->execute([$api_key]);
    $school = $stmt->fetch();

    if (!$school) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid or inactive API key']));
    }

    // Check subscription expiry
    if (strtotime($school['subscription_expiry']) < time()) {
        http_response_code(403);
        die(json_encode(['error' => 'Subscription expired']));
    }

    return $school;
}

function logApiCall($pdo, $school_id, $endpoint, $response_code)
{
    $stmt = $pdo->prepare("
        INSERT INTO api_logs (school_id, endpoint, request_data, response_code, ip_address) 
        VALUES (?, ?, ?, ?, ?)
    ");

    $request_data = json_encode([
        'get' => $_GET,
        'post' => $_POST
    ]);

    $stmt->execute([
        $school_id,
        $endpoint,
        $request_data,
        $response_code,
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}
