<?php

/**
 * get_evangelism_report.php
 * Serves all data needed for the EvangelismReport page
 * Access: IT Admin, Alpha Gulf Serial, Gulf Serial (full)
 *         R&T SCI, R&T SCII, R&T Secretary (limited)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'dashboard';

// -------------------------------------------------------
// Role helpers - passed from frontend via member_id
// -------------------------------------------------------
$member_id = intval($_GET['member_id'] ?? 0);
if (!$member_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$mStmt = $conn->prepare("SELECT role, command FROM members WHERE id = ? AND is_active = 1");
$mStmt->bind_param("i", $member_id);
$mStmt->execute();
$member = $mStmt->get_result()->fetch_assoc();
$mStmt->close();

if (!$member) {
    echo json_encode(['success' => false, 'message' => 'Member not found']);
    exit;
}

$role    = $member['role'];
$command = $member['command'];

// Define access tiers
$fullAccess    = ['IT Admin', 'Alpha Gulf Serial', 'Gulf Serial'];
$limitedAccess = ['Senior Commander I', 'Senior Commander II', 'Secretary'];
$rtCommand     = 'RECRUITMENT & TRAINING';

$isFullAccess    = in_array($role, $fullAccess);
$isLimitedAccess = in_array($role, $limitedAccess) && $command === $rtCommand;

if (!$isFullAccess && !$isLimitedAccess) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// -------------------------------------------------------
// ACTION: dashboard — stats summary
// -------------------------------------------------------
if ($action === 'dashboard') {
    $total     = $conn->query("SELECT COUNT(*) FROM evangelism_recruits")->fetch_row()[0];
    $today     = $conn->query("SELECT COUNT(*) FROM evangelism_recruits WHERE DATE(registration_date) = CURDATE()")->fetch_row()[0];
    $thisMonth = $conn->query("SELECT COUNT(*) FROM evangelism_recruits WHERE MONTH(registration_date) = MONTH(CURDATE()) AND YEAR(registration_date) = YEAR(CURDATE())")->fetch_row()[0];

    $byStatus = $conn->query("SELECT status, COUNT(*) as count FROM evangelism_recruits GROUP BY status")->fetch_all(MYSQLI_ASSOC);
    $statusMap = [];
    foreach ($byStatus as $s) $statusMap[$s['status']] = $s['count'];

    // Recent 5 submissions
    $recentQ = $conn->query("SELECT full_name, phone_number, command_name, registration_date, submitted_by_name FROM evangelism_recruits ORDER BY created_at DESC LIMIT 5");
    $recent = $recentQ->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'success'    => true,
        'total'      => $total,
        'today'      => $today,
        'this_month' => $thisMonth,
        'by_status'  => $statusMap,
        'recent'     => $recent,
    ]);
    exit;
}

// -------------------------------------------------------
// ACTION: recruits — manage recruits list
// -------------------------------------------------------
if ($action === 'recruits') {
    $search  = trim($_GET['search'] ?? '');
    $status  = $_GET['status'] ?? 'all';
    $cmdFilter = $_GET['command'] ?? 'all';
    $page    = max(1, intval($_GET['page'] ?? 1));
    $perPage = 30;
    $offset  = ($page - 1) * $perPage;

    $where  = ['1=1'];
    $params = [];
    $types  = '';

    // Limited access: only see their command
    if ($isLimitedAccess) {
        $where[]  = 'command_name = ?';
        $params[] = $rtCommand;
        $types   .= 's';
    } elseif ($cmdFilter !== 'all') {
        $where[]  = 'command_name = ?';
        $params[] = $cmdFilter;
        $types   .= 's';
    }

    if ($search) {
        $like     = "%$search%";
        $where[]  = '(full_name LIKE ? OR phone_number LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }

    if ($status !== 'all') {
        $where[]  = 'status = ?';
        $params[] = $status;
        $types   .= 's';
    }

    $whereStr = implode(' AND ', $where);

    // Total count
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM evangelism_recruits WHERE $whereStr");
    if ($types) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_row()[0];
    $countStmt->close();

    // Data
    $params[] = $perPage;
    $params[] = $offset;
    $types   .= 'ii';
    $stmt = $conn->prepare("
        SELECT id, full_name, phone_number, alternative_phone, email,
               address, notes, command_name, submitted_by_name,
               registration_date, status, created_at
        FROM evangelism_recruits
        WHERE $whereStr
        ORDER BY registration_date DESC, created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $recruits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success'   => true,
        'recruits'  => $recruits,
        'total'     => intval($total),
        'page'      => $page,
        'per_page'  => $perPage,
        'pages'     => ceil($total / $perPage),
    ]);
    exit;
}

// -------------------------------------------------------
// ACTION: update_status — update a recruit's status
// -------------------------------------------------------
if ($action === 'update_status' && $method === 'POST') {
    $data       = json_decode(file_get_contents('php://input'), true);
    $recruit_id = intval($data['recruit_id'] ?? 0);
    $new_status = $data['status'] ?? '';
    $valid      = ['recruit', 'training', 'deployed', 'dropped'];

    if (!$recruit_id || !in_array($new_status, $valid)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    }

    // Limited access: can only update their command's recruits
    if ($isLimitedAccess) {
        $chk = $conn->prepare("SELECT id FROM evangelism_recruits WHERE id = ? AND command_name = ?");
        $chk->bind_param("is", $recruit_id, $rtCommand);
        $chk->execute();
        if (!$chk->get_result()->fetch_row()) {
            echo json_encode(['success' => false, 'message' => 'Access denied for this recruit']);
            exit;
        }
        $chk->close();
    }

    $stmt = $conn->prepare("UPDATE evangelism_recruits SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $new_status, $recruit_id);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Status updated' : 'Update failed']);
    exit;
}

// -------------------------------------------------------
// FULL ACCESS ONLY BELOW
// -------------------------------------------------------
if (!$isFullAccess) {
    echo json_encode(['success' => false, 'message' => 'Access denied for this section']);
    exit;
}

// -------------------------------------------------------
// ACTION: command_stats
// -------------------------------------------------------
if ($action === 'command_stats') {
    $rows = $conn->query("
        SELECT command_name,
               COUNT(*) as total,
               SUM(CASE WHEN status='recruit'  THEN 1 ELSE 0 END) as recruit_count,
               SUM(CASE WHEN status='training' THEN 1 ELSE 0 END) as training_count,
               SUM(CASE WHEN status='deployed' THEN 1 ELSE 0 END) as deployed_count,
               SUM(CASE WHEN status='dropped'  THEN 1 ELSE 0 END) as dropped_count,
               MAX(registration_date) as last_submission
        FROM evangelism_recruits
        GROUP BY command_name
        ORDER BY total DESC
    ")->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'stats' => $rows]);
    exit;
}

// -------------------------------------------------------
// ACTION: generate_report
// -------------------------------------------------------
if ($action === 'generate_report') {
    $start   = $_GET['start'] ?? date('Y-m-01');
    $end     = $_GET['end']   ?? date('Y-m-d');
    $cmd     = $_GET['command'] ?? 'all';
    $type    = $_GET['type']  ?? 'summary'; // summary | detailed

    $where  = ['DATE(registration_date) BETWEEN ? AND ?'];
    $params = [$start, $end];
    $types  = 'ss';

    if ($cmd !== 'all') {
        $where[]  = 'command_name = ?';
        $params[] = $cmd;
        $types   .= 's';
    }

    $whereStr = implode(' AND ', $where);

    // Stats
    $sStmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status='recruit'  THEN 1 ELSE 0 END) as recruit_count,
               SUM(CASE WHEN status='training' THEN 1 ELSE 0 END) as training_count,
               SUM(CASE WHEN status='deployed' THEN 1 ELSE 0 END) as deployed_count,
               SUM(CASE WHEN status='dropped'  THEN 1 ELSE 0 END) as dropped_count
        FROM evangelism_recruits WHERE $whereStr
    ");
    $sStmt->bind_param($types, ...$params);
    $sStmt->execute();
    $stats = $sStmt->get_result()->fetch_assoc();
    $sStmt->close();

    $records = [];
    if ($type === 'detailed') {
        $rStmt = $conn->prepare("
            SELECT full_name, phone_number, command_name, status,
                   registration_date, submitted_by_name, notes
            FROM evangelism_recruits
            WHERE $whereStr
            ORDER BY registration_date DESC
        ");
        $rStmt->bind_param($types, ...$params);
        $rStmt->execute();
        $records = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rStmt->close();
    }

    echo json_encode([
        'success'  => true,
        'stats'    => $stats,
        'records'  => $records,
        'meta'     => [
            'start'    => $start,
            'end'      => $end,
            'command'  => $cmd === 'all' ? 'All Commands' : $cmd,
            'type'     => $type,
            'generated' => date('d M Y, g:i A'),
        ]
    ]);
    exit;
}

// -------------------------------------------------------
// ACTION: training_status
// -------------------------------------------------------
if ($action === 'training_status') {
    $rows = $conn->query("
        SELECT id, full_name, phone_number, command_name,
               status, registration_date, updated_at, submitted_by_name
        FROM evangelism_recruits
        WHERE status IN ('recruit','training')
        ORDER BY command_name ASC, registration_date ASC
    ")->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'recruits' => $rows]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
$conn->close();
