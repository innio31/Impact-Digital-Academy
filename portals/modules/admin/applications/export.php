<?php
// modules/admin/applications/export.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

$conn = getDBConnection();

// Get export type
$export_type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? 'approved';
$reviewer_id = $_GET['reviewer_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$program_id = $_GET['program_id'] ?? '';

// Build query (same as history.php)
$sql = "SELECT 
    a.*, 
    u.first_name, 
    u.last_name, 
    u.email, 
    u.role as user_current_role,
    u.status as user_status,
    p.name as program_name,
    p.program_code,
    r.first_name as reviewer_first_name,
    r.last_name as reviewer_last_name,
    TIMESTAMPDIFF(HOUR, a.created_at, a.reviewed_at) as review_hours
FROM applications a
LEFT JOIN users u ON a.user_id = u.id
LEFT JOIN programs p ON a.program_id = p.id
LEFT JOIN users r ON a.reviewed_by = r.id
WHERE a.status IN ('approved', 'rejected')";

$params = [];
$types = "";

// Apply filters (same as history.php)
if ($status && $status !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($reviewer_id) {
    $sql .= " AND a.reviewed_by = ?";
    $params[] = $reviewer_id;
    $types .= "i";
}

if ($date_from) {
    $sql .= " AND DATE(a.reviewed_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $sql .= " AND DATE(a.reviewed_at) <= ?";
    $params[] = date('Y-m-d', strtotime($date_to . ' +1 day'));
    $types .= "s";
}

if ($program_id) {
    $sql .= " AND a.program_id = ?";
    $params[] = $program_id;
    $types .= "i";
}

$sql .= " ORDER BY a.reviewed_at DESC";

// Prepare and execute statement
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$applications = $result->fetch_all(MYSQLI_ASSOC);

// Prepare data for export
$export_data = [];
foreach ($applications as $app) {
    $review_time = '';
    if ($app['created_at'] && $app['reviewed_at']) {
        $hours = $app['review_hours'] ?? round((strtotime($app['reviewed_at']) - strtotime($app['created_at'])) / 3600, 1);
        $review_time = $hours . ' hours';
    }

    $export_data[] = [
        'ID' => $app['id'],
        'Application ID' => '#' . str_pad($app['id'], 4, '0', STR_PAD_LEFT),
        'Applicant Name' => $app['first_name'] . ' ' . $app['last_name'],
        'Applicant Email' => $app['email'],
        'Applying As' => ucfirst($app['applying_as']),
        'Program Code' => $app['program_code'] ?? 'N/A',
        'Program Name' => $app['program_name'] ?? 'N/A',
        'Application Date' => date('Y-m-d H:i:s', strtotime($app['created_at'])),
        'Review Date' => date('Y-m-d H:i:s', strtotime($app['reviewed_at'])),
        'Review Time (Hours)' => $review_time ?: 'N/A',
        'Reviewed By' => $app['reviewer_first_name'] ? $app['reviewer_first_name'] . ' ' . $app['reviewer_last_name'] : 'System',
        'Status' => ucfirst($app['status']),
        'Comments' => $app['comments'] ?? '',
        'User Current Role' => $app['user_current_role'],
        'User Status' => $app['user_status']
    ];
}

switch ($export_type) {
    case 'csv':
        exportToCSV($export_data, $date_from, $date_to, $status);
        break;
    case 'pdf':
        exportToPDF($export_data, $date_from, $date_to, $status);
        break;
    default:
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid export type']);
        exit();
}

/**
 * Export data to CSV
 */
function exportToCSV($data, $date_from, $date_to, $status)
{
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="applications_history_' . date('Y-m-d_H-i-s') . '.csv"');

    // Create output stream
    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");

    // Add filter info as first rows
    fputcsv($output, ['Impact Academy - Applications History Report']);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, ['Date Range: ' . $date_from . ' to ' . $date_to]);
    fputcsv($output, ['Status Filter: ' . ($status === 'all' ? 'All' : ucfirst($status))]);
    fputcsv($output, []); // Empty row

    // Add headers
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));

        // Add data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        // Add summary
        fputcsv($output, []); // Empty row
        fputcsv($output, ['Summary Statistics']);
        fputcsv($output, ['Total Records:', count($data)]);

        $approved_count = 0;
        $rejected_count = 0;
        $total_review_time = 0;
        $review_time_count = 0;

        foreach ($data as $row) {
            if ($row['Status'] === 'Approved') $approved_count++;
            if ($row['Status'] === 'Rejected') $rejected_count++;

            if (preg_match('/(\d+(\.\d+)?)/', $row['Review Time (Hours)'], $matches)) {
                $total_review_time += $matches[1];
                $review_time_count++;
            }
        }

        fputcsv($output, ['Approved:', $approved_count]);
        fputcsv($output, ['Rejected:', $rejected_count]);
        fputcsv($output, ['Approval Rate:', $review_time_count > 0 ? round(($approved_count / count($data)) * 100, 2) . '%' : 'N/A']);
        fputcsv($output, ['Average Review Time (Hours):', $review_time_count > 0 ? round($total_review_time / $review_time_count, 2) : 'N/A']);
    } else {
        fputcsv($output, ['No data found for the selected filters']);
    }

    fclose($output);
    exit();
}

/**
 * Export data to PDF using TCPDF
 */
function exportToPDF($data, $date_from, $date_to, $status)
{
    // Include TCPDF library
    require_once __DIR__ . '/../../../vendor/autoload.php';

    // Create new PDF document
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('Impact Academy');
    $pdf->SetAuthor('Impact Academy Admin');
    $pdf->SetTitle('Applications History Report');
    $pdf->SetSubject('Applications History');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', 'B', 16);

    // Title
    $pdf->Cell(0, 10, 'Impact Academy - Applications History Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    $pdf->Cell(0, 5, 'Date Range: ' . $date_from . ' to ' . $date_to, 0, 1, 'C');
    $pdf->Cell(0, 5, 'Status Filter: ' . ($status === 'all' ? 'All' : ucfirst($status)), 0, 1, 'C');

    $pdf->Ln(10);

    if (!empty($data)) {
        // Summary statistics
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Summary Statistics', 0, 1);
        $pdf->SetFont('helvetica', '', 10);

        $approved_count = 0;
        $rejected_count = 0;
        $total_review_time = 0;
        $review_time_count = 0;

        foreach ($data as $row) {
            if ($row['Status'] === 'Approved') $approved_count++;
            if ($row['Status'] === 'Rejected') $rejected_count++;

            if (preg_match('/(\d+(\.\d+)?)/', $row['Review Time (Hours)'], $matches)) {
                $total_review_time += $matches[1];
                $review_time_count++;
            }
        }

        $summary_html = '
        <table border="1" cellpadding="4" cellspacing="0">
            <tr>
                <td width="60%"><b>Metric</b></td>
                <td width="40%"><b>Value</b></td>
            </tr>
            <tr>
                <td>Total Records</td>
                <td>' . count($data) . '</td>
            </tr>
            <tr>
                <td>Approved Applications</td>
                <td>' . $approved_count . '</td>
            </tr>
            <tr>
                <td>Rejected Applications</td>
                <td>' . $rejected_count . '</td>
            </tr>
            <tr>
                <td>Approval Rate</td>
                <td>' . (count($data) > 0 ? round(($approved_count / count($data)) * 100, 2) . '%' : 'N/A') . '</td>
            </tr>
            <tr>
                <td>Average Review Time (Hours)</td>
                <td>' . ($review_time_count > 0 ? round($total_review_time / $review_time_count, 2) : 'N/A') . '</td>
            </tr>
        </table>';

        $pdf->writeHTML($summary_html, true, false, true, false, '');
        $pdf->Ln(10);

        // Applications table
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Applications History (' . count($data) . ' records)', 0, 1);

        // Create table HTML
        $table_html = '
        <style>
            table { border-collapse: collapse; width: 100%; font-size: 8pt; }
            th { background-color: #f2f2f2; font-weight: bold; padding: 4px; border: 1px solid #ddd; }
            td { padding: 4px; border: 1px solid #ddd; }
            .approved { background-color: #d4edda; }
            .rejected { background-color: #f8d7da; }
        </style>
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th width="5%">ID</th>
                    <th width="15%">Applicant</th>
                    <th width="10%">Applying As</th>
                    <th width="15%">Program</th>
                    <th width="10%">Submitted</th>
                    <th width="10%">Reviewed</th>
                    <th width="10%">Review Time</th>
                    <th width="10%">Reviewed By</th>
                    <th width="5%">Status</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($data as $row) {
            $status_class = strtolower($row['Status']);
            $table_html .= '
                <tr class="' . $status_class . '">
                    <td>' . $row['Application ID'] . '</td>
                    <td>' . htmlspecialchars($row['Applicant Name']) . '<br><small>' . htmlspecialchars($row['Applicant Email']) . '</small></td>
                    <td>' . $row['Applying As'] . '</td>
                    <td>' . htmlspecialchars($row['Program Code']) . '<br><small>' . htmlspecialchars($row['Program Name']) . '</small></td>
                    <td>' . date('M j, Y', strtotime($row['Application Date'])) . '<br><small>' . date('g:i A', strtotime($row['Application Date'])) . '</small></td>
                    <td>' . date('M j, Y', strtotime($row['Review Date'])) . '<br><small>' . date('g:i A', strtotime($row['Review Date'])) . '</small></td>
                    <td>' . $row['Review Time (Hours)'] . '</td>
                    <td>' . htmlspecialchars($row['Reviewed By']) . '</td>
                    <td>' . $row['Status'] . '</td>
                </tr>';
        }

        $table_html .= '</tbody></table>';

        $pdf->writeHTML($table_html, true, false, true, false, '');

        // Add page number
        $pdf->SetY(-15);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');
    } else {
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'No applications found for the selected filters.', 0, 1, 'C');
    }

    // Close and output PDF document
    $pdf->Output('applications_history_' . date('Y-m-d_H-i-s') . '.pdf', 'D');
    exit();
}

$conn->close();
