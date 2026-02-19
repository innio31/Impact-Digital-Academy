<?php
// modules/instructor/classes/export_students.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Check if class_id is provided
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];
$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify the instructor has access to this class
$sql = "SELECT cb.*, c.course_code, c.title as course_title
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.id = ? AND cb.instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Get export format (default: CSV)
$format = isset($_GET['format']) && in_array($_GET['format'], ['csv', 'excel', 'pdf'])
    ? $_GET['format']
    : 'csv';

// Get students data
$sql = "SELECT 
            u.id, u.first_name, u.last_name, u.email, u.phone,
            e.enrollment_date, e.status as enrollment_status, e.final_grade,
            up.date_of_birth, up.gender, up.city, up.state,
            sfs.total_fee, sfs.paid_amount, sfs.balance,
            sfs.registration_paid, sfs.block1_paid, sfs.block2_paid,
            sfs.is_cleared, sfs.is_suspended
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN student_financial_status sfs ON u.id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE e.class_id = ? AND e.status = 'active'
        ORDER BY u.last_name, u.first_name";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get assignment statistics for each student
foreach ($students as &$student) {
    $sql = "SELECT 
                COUNT(DISTINCT a.id) as total_assignments,
                COUNT(DISTINCT s.id) as submitted_assignments,
                AVG(s.grade) as average_grade
            FROM assignments a
            LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
            WHERE a.class_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student['id'], $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();

    $student['total_assignments'] = $stats['total_assignments'] ?? 0;
    $student['submitted_assignments'] = $stats['submitted_assignments'] ?? 0;
    $student['average_grade'] = $stats['average_grade'] ? round($stats['average_grade'], 1) : 'N/A';
    $student['submission_rate'] = $stats['total_assignments'] > 0 ?
        round(($stats['submitted_assignments'] / $stats['total_assignments']) * 100, 1) : 0;
}

$conn->close();

// Set headers based on format
switch ($format) {
    case 'csv':
        exportToCSV($students, $class);
        break;
    case 'excel':
        exportToExcel($students, $class);
        break;
    case 'pdf':
        exportToPDF($students, $class);
        break;
}

function exportToCSV($students, $class)
{
    $filename = "students_" . preg_replace('/[^a-z0-9]/i', '_', strtolower($class['batch_code'])) . "_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // CSV headers
    fputcsv($output, [
        'Student ID',
        'First Name',
        'Last Name',
        'Email',
        'Phone',
        'Enrollment Date',
        'Status',
        'Final Grade',
        'Date of Birth',
        'Gender',
        'City',
        'State',
        'Total Fee',
        'Paid Amount',
        'Balance',
        'Registration Paid',
        'Block 1 Paid',
        'Block 2 Paid',
        'Financially Cleared',
        'Suspended',
        'Total Assignments',
        'Submitted Assignments',
        'Submission Rate',
        'Average Grade'
    ]);

    // Data rows
    foreach ($students as $student) {
        fputcsv($output, [
            $student['id'],
            $student['first_name'],
            $student['last_name'],
            $student['email'],
            $student['phone'] ?? '',
            $student['enrollment_date'],
            ucfirst($student['enrollment_status']),
            $student['final_grade'] ?? 'N/A',
            $student['date_of_birth'] ?? '',
            $student['gender'] ?? '',
            $student['city'] ?? '',
            $student['state'] ?? '',
            $student['total_fee'] ?? '0.00',
            $student['paid_amount'] ?? '0.00',
            $student['balance'] ?? '0.00',
            $student['registration_paid'] ? 'Yes' : 'No',
            $student['block1_paid'] ? 'Yes' : 'No',
            $student['block2_paid'] ? 'Yes' : 'No',
            $student['is_cleared'] ? 'Yes' : 'No',
            $student['is_suspended'] ? 'Yes' : 'No',
            $student['total_assignments'],
            $student['submitted_assignments'],
            $student['submission_rate'] . '%',
            $student['average_grade']
        ]);
    }

    fclose($output);
}

function exportToExcel($students, $class)
{
    require_once __DIR__ . '/../../../vendor/autoload.php';

    $filename = "students_" . preg_replace('/[^a-z0-9]/i', '_', strtolower($class['batch_code'])) . "_" . date('Y-m-d') . ".xlsx";

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator("Impact Digital Academy")
        ->setTitle("Students Export - " . $class['batch_code'])
        ->setSubject("Students Data")
        ->setDescription("Export of students for " . $class['course_code'] . " - " . $class['batch_code']);

    // Set headers
    $headers = [
        'Student ID',
        'First Name',
        'Last Name',
        'Email',
        'Phone',
        'Enrollment Date',
        'Status',
        'Final Grade',
        'Date of Birth',
        'Gender',
        'City',
        'State',
        'Total Fee',
        'Paid Amount',
        'Balance',
        'Registration Paid',
        'Block 1 Paid',
        'Block 2 Paid',
        'Financially Cleared',
        'Suspended',
        'Total Assignments',
        'Submitted Assignments',
        'Submission Rate',
        'Average Grade'
    ];

    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }

    // Style headers
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F81BD']]
    ];
    $sheet->getStyle('A1:' . --$col . '1')->applyFromArray($headerStyle);

    // Add data rows
    $row = 2;
    foreach ($students as $student) {
        $sheet->setCellValue('A' . $row, $student['id']);
        $sheet->setCellValue('B' . $row, $student['first_name']);
        $sheet->setCellValue('C' . $row, $student['last_name']);
        $sheet->setCellValue('D' . $row, $student['email']);
        $sheet->setCellValue('E' . $row, $student['phone'] ?? '');
        $sheet->setCellValue('F' . $row, $student['enrollment_date']);
        $sheet->setCellValue('G' . $row, ucfirst($student['enrollment_status']));
        $sheet->setCellValue('H' . $row, $student['final_grade'] ?? 'N/A');
        $sheet->setCellValue('I' . $row, $student['date_of_birth'] ?? '');
        $sheet->setCellValue('J' . $row, $student['gender'] ?? '');
        $sheet->setCellValue('K' . $row, $student['city'] ?? '');
        $sheet->setCellValue('L' . $row, $student['state'] ?? '');
        $sheet->setCellValue('M' . $row, $student['total_fee'] ?? '0.00');
        $sheet->setCellValue('N' . $row, $student['paid_amount'] ?? '0.00');
        $sheet->setCellValue('O' . $row, $student['balance'] ?? '0.00');
        $sheet->setCellValue('P' . $row, $student['registration_paid'] ? 'Yes' : 'No');
        $sheet->setCellValue('Q' . $row, $student['block1_paid'] ? 'Yes' : 'No');
        $sheet->setCellValue('R' . $row, $student['block2_paid'] ? 'Yes' : 'No');
        $sheet->setCellValue('S' . $row, $student['is_cleared'] ? 'Yes' : 'No');
        $sheet->setCellValue('T' . $row, $student['is_suspended'] ? 'Yes' : 'No');
        $sheet->setCellValue('U' . $row, $student['total_assignments']);
        $sheet->setCellValue('V' . $row, $student['submitted_assignments']);
        $sheet->setCellValue('W' . $row, $student['submission_rate'] . '%');
        $sheet->setCellValue('X' . $row, $student['average_grade']);

        $row++;
    }

    // Save to output
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer->save('php://output');
}

function exportToPDF($students, $class)
{
    require_once __DIR__ . '/../../../vendor/autoload.php';

    $filename = "students_" . preg_replace('/[^a-z0-9]/i', '_', strtolower($class['batch_code'])) . "_" . date('Y-m-d') . ".pdf";

    $mpdf = new \Mpdf\Mpdf();

    // PDF content
    $html = '<h1>Students Report - ' . htmlspecialchars($class['batch_code']) . '</h1>';
    $html .= '<h3>' . htmlspecialchars($class['course_code'] . ' - ' . $class['course_title']) . '</h3>';
    $html .= '<p>Generated on: ' . date('F j, Y H:i') . '</p>';
    $html .= '<p>Total Students: ' . count($students) . '</p>';

    $html .= '<table border="1" cellspacing="0" cellpadding="5" style="width: 100%; border-collapse: collapse;">';
    $html .= '<thead><tr>';
    $html .= '<th>ID</th><th>Name</th><th>Email</th><th>Enrollment Date</th><th>Status</th><th>Financial Status</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($students as $student) {
        $financial_status = $student['is_cleared'] ? 'Cleared' : ($student['is_suspended'] ? 'Suspended' : 'Pending');

        $html .= '<tr>';
        $html .= '<td>' . $student['id'] . '</td>';
        $html .= '<td>' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($student['email']) . '</td>';
        $html .= '<td>' . $student['enrollment_date'] . '</td>';
        $html .= '<td>' . ucfirst($student['enrollment_status']) . '</td>';
        $html .= '<td>' . $financial_status . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    // Summary statistics
    $html .= '<h3>Summary Statistics</h3>';
    $html .= '<table border="1" cellspacing="0" cellpadding="5" style="width: 100%; border-collapse: collapse;">';
    $html .= '<tr><th>Metric</th><th>Value</th></tr>';

    $total_balance = array_sum(array_column($students, 'balance'));
    $cleared_count = count(array_filter($students, function ($s) {
        return $s['is_cleared'];
    }));
    $suspended_count = count(array_filter($students, function ($s) {
        return $s['is_suspended'];
    }));

    $html .= '<tr><td>Total Outstanding Balance</td><td>₦' . number_format($total_balance, 2) . '</td></tr>';
    $html .= '<tr><td>Financially Cleared Students</td><td>' . $cleared_count . ' (' . round(($cleared_count / count($students)) * 100, 1) . '%)</td></tr>';
    $html .= '<tr><td>Suspended Students</td><td>' . $suspended_count . ' (' . round(($suspended_count / count($students)) * 100, 1) . '%)</td></tr>';
    $html .= '<tr><td>Average Submission Rate</td><td>' . round(array_sum(array_column($students, 'submission_rate')) / count($students), 1) . '%</td></tr>';
    $html .= '</table>';

    $mpdf->WriteHTML($html);

    // Output PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $mpdf->Output('php://output');
}
