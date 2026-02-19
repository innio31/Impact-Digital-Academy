<?php
// api/execute_python.php - Using Piston API
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get input
$input = file_get_contents('php://input');
$data = json_decode($input, true);
$code = $data['code'] ?? '';

if (empty($code)) {
    echo json_encode(['error' => 'No code provided']);
    exit();
}

// Security check - maximum code length
if (strlen($code) > 10000) {
    echo json_encode(['error' => 'Code too long (max 10000 characters)']);
    exit();
}

// Block dangerous patterns
$dangerous = [
    '/__import__/i',
    '/eval\(/i',
    '/exec\(/i',
    '/open\(/i',
    '/file\(/i',
    '/system\(/i',
    '/popen\(/i',
    '/import\s+os/i',
    '/import\s+subprocess/i',
    '/\.__globals__/i',
    '/\.__code__/i',
];

foreach ($dangerous as $pattern) {
    if (preg_match($pattern, $code)) {
        echo json_encode(['error' => 'Security violation: Restricted code']);
        exit();
    }
}

// Use Piston API (public instance)
$piston_url = 'https://emkc.org/api/v2/piston/execute';

$payload = json_encode([
    'language' => 'python',
    'version' => '3.10.0',
    'files' => [[
        'name' => 'code.py',
        'content' => $code
    ]],
    'stdin' => '',
    'args' => [],
    'compile_timeout' => 5000,
    'run_timeout' => 5000,
    'compile_memory_limit' => -1,
    'run_memory_limit' => -1
]);

$ch = curl_init($piston_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload)
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        'success' => false,
        'stdout' => '',
        'stderr' => 'API request failed: ' . $error,
        'exit_code' => 1
    ]);
    exit();
}

$result = json_decode($response, true);

if (isset($result['message'])) {
    // Piston returned an error message
    echo json_encode([
        'success' => false,
        'stdout' => '',
        'stderr' => 'Execution service error: ' . $result['message'],
        'exit_code' => 1
    ]);
    exit();
}

// Extract output from Piston response
if (isset($result['run']['stdout'])) {
    echo json_encode([
        'success' => $result['run']['code'] === 0,
        'stdout' => $result['run']['stdout'],
        'stderr' => $result['run']['stderr'] ?? '',
        'exit_code' => $result['run']['code'] ?? 1
    ]);
} else {
    echo json_encode([
        'success' => false,
        'stdout' => '',
        'stderr' => 'Unexpected response format from execution service',
        'exit_code' => 1
    ]);
}
?>