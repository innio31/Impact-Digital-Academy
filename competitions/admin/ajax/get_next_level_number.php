<?php
// [file name]: ajax/get_next_level_number.php
require_once '../../includes/functions.php';

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    die('Unauthorized');
}

$category_id = intval($_GET['category_id']);

$result = $conn->query("SELECT MAX(level_number) as max_level FROM category_levels WHERE category_id = $category_id");
$row = $result->fetch_assoc();
$next_level = ($row['max_level'] ?? 0) + 1;

header('Content-Type: application/json');
echo json_encode(['next_level' => $next_level]);
