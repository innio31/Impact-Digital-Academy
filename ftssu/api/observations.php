<?php

/**
 * observations.php
 * GET  — fetch observations (admin only)
 * POST — submit new observation (any member) or update status (admin)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'submit';

// Admin roles allowed to view/update
$adminRoles = ['IT Admin', 'Golf Charlie', 'Alpha Golf Charlie', 'Golf Serial', 'Alpha Golf Serial'];

// -------------------------------------------------------
// POST: submit new observation (anonymous — no member_id stored)
// -------------------------------------------------------
if ($method === 'POST' && $action === 'submit') {
    $data = json_decode(file_get_contents('php://input'), true);

    $category = trim($data['category'] ?? '');
    $priority = trim($data['priority'] ?? '');
    $type     = trim($data['type']     ?? '');
    $content  = trim($data['content']  ?? '');

    $validCategories = ['Operations', 'Administration', 'Welfare', 'General'];
    $validPriorities = ['High', 'Medium', 'Low'];
    $validTypes      = ['Observation', 'Recommendation'];

    if (
        !in_array($category, $validCategories) ||
        !in_array($priority, $validPriorities) ||
        !in_array($type, $validTypes) ||
        strlen($content) < 10
    ) {
        echo json_encode(['success' => false, 'message' => 'Invalid or incomplete submission']);
        exit;
    }

    if (strlen($content) > 2000) {
        echo json_encode(['success' => false, 'message' => 'Content too long (max 2000 characters)']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO observations (category, priority, type, content)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("ssss", $category, $priority, $type, $content);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Submitted successfully. Thank you!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit. Please try again.']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// -------------------------------------------------------
// POST: update status (admin only)
// -------------------------------------------------------
if ($method === 'POST' && $action === 'update_status') {
    $data      = json_decode(file_get_contents('php://input'), true);
    $member_id = intval($data['member_id'] ?? 0);
    $obs_id    = intval($data['observation_id'] ?? 0);
    $status    = trim($data['status'] ?? '');
    $note      = trim($data['admin_note'] ?? '');

    // Verify admin role
    $mStmt = $conn->prepare("SELECT role FROM members WHERE id = ? AND is_active = 1");
    $mStmt->bind_param("i", $member_id);
    $mStmt->execute();
    $m = $mStmt->get_result()->fetch_assoc();
    $mStmt->close();

    if (!$m || !in_array($m['role'], $adminRoles)) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $validStatuses = ['Pending', 'Reviewed', 'Addressed'];
    if (!in_array($status, $validStatuses) || !$obs_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE observations
        SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ssii", $status, $note, $member_id, $obs_id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Status updated' : 'Update failed']);
    exit;
}

// -------------------------------------------------------
// GET: fetch observations (admin only)
// -------------------------------------------------------
if ($method === 'GET' && $action === 'fetch') {
    $member_id = intval($_GET['member_id'] ?? 0);

    $mStmt = $conn->prepare("SELECT role FROM members WHERE id = ? AND is_active = 1");
    $mStmt->bind_param("i", $member_id);
    $mStmt->execute();
    $m = $mStmt->get_result()->fetch_assoc();
    $mStmt->close();

    if (!$m || !in_array($m['role'], $adminRoles)) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    // Filters
    $where  = ['1=1'];
    $params = [];
    $types  = '';

    $status   = $_GET['status']   ?? 'all';
    $priority = $_GET['priority'] ?? 'all';
    $category = $_GET['category'] ?? 'all';

    if ($status !== 'all') {
        $where[]  = 'status = ?';
        $params[] = $status;
        $types   .= 's';
    }
    if ($priority !== 'all') {
        $where[]  = 'priority = ?';
        $params[] = $priority;
        $types   .= 's';
    }
    if ($category !== 'all') {
        $where[]  = 'category = ?';
        $params[] = $category;
        $types   .= 's';
    }

    $whereStr = implode(' AND ', $where);

    // Summary counts
    $counts = [
        'total'     => $conn->query("SELECT COUNT(*) FROM observations")->fetch_row()[0],
        'pending'   => $conn->query("SELECT COUNT(*) FROM observations WHERE status='Pending'")->fetch_row()[0],
        'reviewed'  => $conn->query("SELECT COUNT(*) FROM observations WHERE status='Reviewed'")->fetch_row()[0],
        'addressed' => $conn->query("SELECT COUNT(*) FROM observations WHERE status='Addressed'")->fetch_row()[0],
        'high'      => $conn->query("SELECT COUNT(*) FROM observations WHERE priority='High'")->fetch_row()[0],
    ];

    // Data
    $stmt = $conn->prepare("
        SELECT id, category, priority, type, content, status, admin_note, reviewed_at, created_at
        FROM observations
        WHERE $whereStr
        ORDER BY
            CASE priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 WHEN 'Low' THEN 3 END,
            created_at DESC
    ");
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    echo json_encode([
        'success'       => true,
        'observations'  => $rows,
        'counts'        => $counts,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
