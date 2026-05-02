<?php
require_once 'db_connect.php';
require_once('vendor/autoload.php'); // Assuming you have TCPDF or similar installed

use TCPDF;

// Get report ID
$report_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$report_id) {
    die('Report ID required');
}

// Fetch report data
$stmt = $conn->prepare("
    SELECT * FROM weekly_activity_reports WHERE id = ?
");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();

if (!$report) {
    die('Report not found');
}

$report['tools_deployed'] = json_decode($report['tools_deployed'], true);

// Fetch relocated members
$relocate_stmt = $conn->prepare("
    SELECT * FROM relocated_members WHERE report_id = ?
");
$relocate_stmt->bind_param("i", $report_id);
$relocate_stmt->execute();
$relocated = $relocate_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch left members
$left_stmt = $conn->prepare("
    SELECT * FROM left_lfc_members WHERE report_id = ?
");
$left_stmt->bind_param("i", $report_id);
$left_stmt->execute();
$left = $left_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch WSF attendance
$wsf_stmt = $conn->prepare("
    SELECT * FROM wsf_attendance WHERE report_id = ?
");
$wsf_stmt->bind_param("i", $report_id);
$wsf_stmt->execute();
$wsf = $wsf_stmt->get_result()->fetch_assoc();

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

$pdf->SetCreator('FTSSU Portal');
$pdf->SetAuthor('FTSSU Admin');
$pdf->SetTitle('Weekly Command Report - ' . $report['command_name']);
$pdf->SetSubject('Weekly Activity Report');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

$pdf->AddPage();

// Header
$pdf->SetFont('helvetica', 'B', 20);
$pdf->Cell(0, 10, 'FAITH TABERNACLE SECURITY SERVICE UNIT', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'WEEKLY COMMAND REPORT OF ACTIVITIES', 0, 1, 'C');
$pdf->Ln(5);

// Command Info
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(50, 8, 'Command:', 0, 0);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, $report['command_name'], 0, 1);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(50, 8, 'Report Date:', 0, 0);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, date('F j, Y', strtotime($report['report_date'])), 0, 1);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(50, 8, 'Submitted By:', 0, 0);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, $report['submitted_by_name'], 0, 1);

$pdf->Ln(5);

// Service Information
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'SERVICE INFORMATION', 0, 1, 'L');
$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(50, 8, 'Type of Service:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, $report['type_of_service'], 0, 1);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(50, 8, 'Location Served:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, $report['location_served'] ?: 'Not specified', 0, 1);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(50, 8, 'Command Strength:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, $report['command_strength'] . ' personnel', 0, 1);

$pdf->Ln(5);

// Attendance
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'ATTENDANCE REPORT', 0, 1, 'L');
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(70, 8, '1st Service:', 0, 0);
$pdf->Cell(0, 8, $report['service_1_attendance'] . ' members', 0, 1);

$pdf->Cell(70, 8, '2nd Service:', 0, 0);
$pdf->Cell(0, 8, $report['service_2_attendance'] . ' members', 0, 1);

$pdf->Cell(70, 8, '3rd Service:', 0, 0);
$pdf->Cell(0, 8, $report['service_3_attendance'] . ' members', 0, 1);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(70, 8, 'Average Attendance:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, $report['average_attendance'] . '%', 0, 1);

$pdf->Ln(5);

// WSF Attendance
if ($wsf && $wsf['total_present'] > 0) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'WSF ATTENDANCE', 0, 1, 'L');
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(70, 8, 'Total Present:', 0, 0);
    $pdf->Cell(0, 8, $wsf['total_present'] . ' members', 0, 1);
    $pdf->Ln(3);
}

// Personnel Movements
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'PERSONNEL MOVEMENTS', 0, 1, 'L');
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);

if (count($relocated) > 0) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Relocated Members:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    foreach ($relocated as $r) {
        $pdf->Cell(0, 6, '• ' . $r['member_name'] . ' → ' . $r['new_location'] . ($r['reason'] ? ' (' . $r['reason'] . ')' : ''), 0, 1);
    }
    $pdf->Ln(2);
}

if (count($left) > 0) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Left LFC Members:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    foreach ($left as $l) {
        $pdf->Cell(0, 6, '• ' . $l['member_name'] . ' - ' . $l['reason'], 0, 1);
    }
    $pdf->Ln(2);
}

if (count($relocated) === 0 && count($left) === 0) {
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 8, 'No personnel movements reported this week.', 0, 1);
    $pdf->Ln(3);
}

// Tools Deployed
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'TOOLS DEPLOYED', 0, 1, 'L');
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);

$tools = [];
if ($report['tools_deployed']['patrol_vehicle']) $tools[] = 'Patrol Vehicle';
if ($report['tools_deployed']['patrol_bike']) $tools[] = 'Patrol Bike';
if ($report['tools_deployed']['torchlight']) $tools[] = 'Torchlight';
if ($report['tools_deployed']['umbrella']) $tools[] = 'Umbrella';
if ($report['tools_deployed']['radio']) $tools[] = 'Radio';
if ($report['tools_deployed']['others']) $tools[] = $report['tools_deployed']['others'];

if (count($tools) > 0) {
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, implode(', ', $tools), 0, 1);
} else {
    $pdf->Cell(0, 6, 'No tools deployed', 0, 1);
}
$pdf->Ln(3);

// Incident Report
if (!empty($report['incident_report'])) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'INCIDENT REPORT', 0, 1, 'L');
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 6, $report['incident_report'], 0, 1);
    $pdf->Ln(3);
}

// Recommendations
if (!empty($report['recommendations'])) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'RECOMMENDATIONS / COMMENTS', 0, 1, 'L');
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 6, $report['recommendations'], 0, 1);
    $pdf->Ln(3);
}

// Footer
$pdf->SetY(-20);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 5, 'Generated by FTSSU Portal - ' . date('Y-m-d H:i:s'), 0, 0, 'C');

// Output PDF
$pdf->Output('Weekly_Report_' . $report['command_name'] . '_' . $report['report_date'] . '.pdf', 'I');

$conn->close();
