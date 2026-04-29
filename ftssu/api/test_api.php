<?php
require_once 'db_connect.php';
echo json_encode(['success' => true, 'message' => 'API is working', 'db_connected' => true]);
