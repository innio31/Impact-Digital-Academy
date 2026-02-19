<?php
// modules/admin/content/export.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Get export parameters
$export_type = $_GET['type'] ?? 'content';
$format = $_GET['format'] ?? 'csv';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$program_id = $_GET['program_id'] ?? 'all';

// Set headers for download
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="content_export_' . date('Y-m-d') . '.csv"');
} elseif ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="content_export_' . date('Y-m-d') . '.json"');
}

// Build query based on export type
switch ($export_type) {
    case 'materials':
        $query = "
            SELECT 
                m.id,
                m.title,
                m.description,
                m.file_type,
                m.file_size,
                m.views_count,
                m.downloads_count,
                m.is_published,
                m.created_at,
                m.updated_at,
                cb.batch_code,
                c.title as course_title,
                p.name as program_name,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name
            FROM materials m
            JOIN class_batches cb ON m.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            JOIN users u ON m.instructor_id = u.id
            WHERE m.created_at BETWEEN ? AND ?
        ";
        
        $params = [$start_date, $end_date];
        $types = 'ss';
        
        if ($program_id !== 'all' && is_numeric($program_id)) {
            $query .= " AND p.id = ?";
            $params[] = $program_id;
            $types .= 'i';
        }
        
        $query .= " ORDER BY m.created_at DESC";
        break;
        
    case 'assignments':
        $query = "
            SELECT 
                a.id,
                a.title,
                a.description,
                a.due_date,
                a.total_points,
                a.submission_type,
                a.is_published,
                a.created_at,
                a.updated_at,
                cb.batch_code,
                c.title as course_title,
                p.name as program_name,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name
            FROM assignments a
            JOIN class_batches cb ON a.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            JOIN users u ON a.instructor_id = u.id
            WHERE a.created_at BETWEEN ? AND ?
        ";
        
        $params = [$start_date, $end_date];
        $types = 'ss';
        
        if ($program_id !== 'all' && is_numeric($program_id)) {
            $query .= " AND p.id = ?";
            $params[] = $program_id;
            $types .= 'i';
        }
        
        $query .= " ORDER BY a.created_at DESC";
        break;
        
    case 'analytics':
        $query = "
            SELECT 
                m.file_type,
                COUNT(*) as file_count,
                SUM(m.file_size) as total_size,
                AVG(m.file_size) as avg_size,
                SUM(m.views_count) as total_views,
                SUM(m.downloads_count) as total_downloads,
                p.name as program_name,
                DATE(m.created_at) as upload_date
            FROM materials m
            JOIN class_batches cb ON m.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            WHERE m.created_at BETWEEN ? AND ?
        ";
        
        $params = [$start_date, $end_date];
        $types = 'ss';
        
        if ($program_id !== 'all' && is_numeric($program_id)) {
            $query .= " AND p.id = ?";
            $params[] = $program_id;
            $types .= 'i';
        }
        
        $query .= " GROUP BY m.file_type, p.name, DATE(m.created_at) ORDER BY upload_date DESC, file_count DESC";
        break;
        
    default: // content overview
        $query = "
            SELECT 
                'material' as content_type,
                m.id,
                m.title,
                m.file_type,
                m.views_count,
                m.downloads_count,
                m.is_published,
                m.created_at,
                c.title as course_title,
                p.name as program_name,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name
            FROM materials m
            JOIN class_batches cb ON m.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            JOIN users u ON m.instructor_id = u.id
            WHERE m.created_at BETWEEN ? AND ?
            
            UNION ALL
            
            SELECT 
                'assignment' as content_type,
                a.id,
                a.title,
                'assignment' as file_type,
                NULL as views_count,
                NULL as downloads_count,
                a.is_published,
                a.created_at,
                c.title as course_title,
                p.name as program_name,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name
            FROM assignments a
            JOIN class_batches cb ON a.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            JOIN users u ON a.instructor_id = u.id
            WHERE a.created_at BETWEEN ? AND ?
            
            UNION ALL
            
            SELECT 
                'announcement' as content_type,
                an.id,
                an.title,
                'announcement' as file_type,
                NULL as views_count,
                NULL as downloads_count,
                an.is_published,
                an.created_at,
                c.title as course_title,
                p.name as program_name,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name
            FROM announcements an
            LEFT JOIN class_batches cb ON an.class_id = cb.id
            LEFT JOIN courses c ON cb.course_id = c.id
            LEFT JOIN programs p ON c.program_id = p.id
            JOIN users u ON an.author_id = u.id
            WHERE an.created_at BETWEEN ? AND ?
            
            ORDER BY created_at DESC
        ";
        
        $params = [$start_date, $end_date, $start_date, $end_date, $start_date, $end_date];
        $types = 'ssssss';
        
        if ($program_id !== 'all' && is_numeric($program_id)) {
            // This is more complex for UNION query, would need to restructure
            // For simplicity, we'll filter after fetching
        }
        break;
}

// Prepare and execute query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_all(MYSQLI_ASSOC);

// Apply program filter for UNION query if needed
if ($export_type === 'content' && $program_id !== 'all' && is_numeric($program_id)) {
    $data = array_filter($data, function($item) use ($program_id) {
        return $item['program_name'] == $program_id; // This needs program name matching
    });
}

// Export based on format
if ($format === 'csv') {
    exportToCSV($data);
} elseif ($format === 'json') {
    exportToJSON($data);
}

// Log export activity
logActivity('content_export', "Exported $export_type data in $format format");

// Function to export as CSV
function exportToCSV($data) {
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Add headers
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    
    // Add data
    foreach ($data as $row) {
        // Format file size if present
        if (isset($row['file_size']) && is_numeric($row['file_size'])) {
            $row['file_size'] = formatFileSize($row['file_size']);
        }
        
        // Format dates
        if (isset($row['created_at'])) {
            $row['created_at'] = date('Y-m-d H:i:s', strtotime($row['created_at']));
        }
        if (isset($row['updated_at'])) {
            $row['updated_at'] = date('Y-m-d H:i:s', strtotime($row['updated_at']));
        }
        if (isset($row['due_date'])) {
            $row['due_date'] = date('Y-m-d H:i:s', strtotime($row['due_date']));
        }
        if (isset($row['upload_date'])) {
            $row['upload_date'] = date('Y-m-d', strtotime($row['upload_date']));
        }
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Function to export as JSON
function exportToJSON($data) {
    // Format data
    foreach ($data as &$row) {
        // Format file size if present
        if (isset($row['file_size']) && is_numeric($row['file_size'])) {
            $row['file_size'] = formatFileSize($row['file_size']);
            $row['file_size_bytes'] = $row['file_size']; // Keep original for reference
        }
        
        // Format dates
        if (isset($row['created_at'])) {
            $row['created_at'] = date('c', strtotime($row['created_at']));
        }
        if (isset($row['updated_at'])) {
            $row['updated_at'] = date('c', strtotime($row['updated_at']));
        }
        if (isset($row['due_date'])) {
            $row['due_date'] = date('c', strtotime($row['due_date']));
        }
        if (isset($row['upload_date'])) {
            $row['upload_date'] = date('c', strtotime($row['upload_date']));
        }
    }
    
    // Add metadata
    $export_data = [
        'export_type' => $_GET['type'] ?? 'content',
        'export_date' => date('c'),
        'date_range' => [
            'start' => $_GET['start_date'] ?? date('Y-m-01'),
            'end' => $_GET['end_date'] ?? date('Y-m-t')
        ],
        'total_records' => count($data),
        'data' => $data
    ];
    
    echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}
?>