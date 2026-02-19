<?php
// modules/admin/finance/reports/generate_pdf.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Include TCPDF
require_once __DIR__ . '/../../../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Get filter parameters from the query string
$period = $_GET['period'] ?? 'month';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$program_type = $_GET['program_type'] ?? '';
$report_format = $_GET['format'] ?? 'detailed';

// Set default dates based on period (same logic as profit-loss.php)
if ($period === 'today') {
    $date_from = $date_to = date('Y-m-d');
} elseif ($period === 'week') {
    $date_from = date('Y-m-d', strtotime('-7 days'));
} elseif ($period === 'month') {
    $date_from = date('Y-m-01');
} elseif ($period === 'quarter') {
    $quarter = ceil(date('n') / 3);
    $month_start = (($quarter - 1) * 3) + 1;
    $date_from = date('Y-' . str_pad($month_start, 2, '0', STR_PAD_LEFT) . '-01');
} elseif ($period === 'year') {
    $date_from = date('Y-01-01');
} elseif ($period === 'all') {
    $date_from = '2024-01-01';
}

// Get database connection
$conn = getDBConnection();

// Get revenue data using the existing function
$revenue_breakdown = getRevenueBreakdown($period, $date_from, $date_to);

// Get expense data using the existing function
$expense_stats = getExpenseDashboardStats($period, $date_from, $date_to);

// Calculate totals
$total_revenue = $revenue_breakdown['total_revenue'] ?? 0;
$total_registration_revenue = $revenue_breakdown['total_registration'] ?? 0;
$total_course_revenue = $revenue_breakdown['total_course'] ?? 0;
$total_expenses = $expense_stats['total_expenses'] ?? 0;
$total_pending_expenses = $expense_stats['pending_expenses'] ?? 0;

// Calculate net profit/loss
$net_profit_loss = $total_revenue - $total_expenses;
$profit_margin = $total_revenue > 0 ? ($net_profit_loss / $total_revenue * 100) : 0;

// Get automated deductions
$automated_deductions = calculateAutomatedDeductions($period, $date_from, $date_to);

// Get expense breakdown by category
$expense_breakdown = $expense_stats['breakdown'] ?? [];

// Get top expenses
$top_expenses_sql = "SELECT 
    e.expense_number,
    ec.name as category,
    ec.category_type,
    e.description,
    e.amount,
    e.payment_date,
    e.vendor_name,
    CONCAT(u.first_name, ' ', u.last_name) as approved_by
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
LEFT JOIN users u ON u.id = e.approved_by
WHERE e.status IN ('approved', 'paid')
    AND e.payment_date BETWEEN ? AND ?
ORDER BY e.amount DESC
LIMIT 10";

$top_expenses = [];
if ($stmt = $conn->prepare($top_expenses_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    $top_expenses = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get top revenue sources
$top_revenue_sources_sql = "SELECT 
    u.first_name,
    u.last_name,
    u.email,
    p.name as program_name,
    COUNT(ft.id) as transaction_count,
    SUM(ft.amount) as total_paid,
    MAX(ft.created_at) as last_payment_date
FROM financial_transactions ft
JOIN users u ON u.id = ft.student_id
LEFT JOIN class_batches cb ON cb.id = ft.class_id
LEFT JOIN courses c ON c.id = cb.course_id
LEFT JOIN programs p ON p.program_code = c.program_id
WHERE ft.status = 'completed'
    AND DATE(ft.created_at) BETWEEN ? AND ?
GROUP BY ft.student_id, p.id
ORDER BY total_paid DESC
LIMIT 10";

$top_revenue_sources = [];
if ($stmt = $conn->prepare($top_revenue_sources_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    $top_revenue_sources = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('IMPACT Digital Academy');
$pdf->SetTitle('Profit & Loss Statement');
$pdf->SetSubject('Financial Report');
$pdf->SetKeywords('Profit, Loss, Statement, Finance, Report');

// Set default header data
$pdf->SetHeaderData('', 0, 'IMPACT Digital Academy', 'Finance Department');

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(15, 25, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 16);

// Title
$pdf->Cell(0, 10, 'PROFIT & LOSS STATEMENT', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'IMPACT Digital Academy - Finance Department', 0, 1, 'C');
$pdf->Cell(0, 5, 'Period: ' . date('F j, Y', strtotime($date_from)) . ' to ' . date('F j, Y', strtotime($date_to)), 0, 1, 'C');
$pdf->Cell(0, 5, 'Generated: ' . date('F j, Y, h:i A'), 0, 1, 'C');
$pdf->Ln(5);

// Summary Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 8, 'FINANCIAL SUMMARY', 0, 1, 'L', true);
$pdf->SetFont('helvetica', '', 10);
$pdf->Ln(2);

// Create summary table
$summary_data = array(
    array('Total Revenue', formatCurrency($total_revenue, false), $net_profit_loss >= 0 ? 'green' : 'black'),
    array('Total Expenses', formatCurrency($total_expenses, false), 'red'),
    array('Net ' . ($net_profit_loss >= 0 ? 'Profit' : 'Loss'), formatCurrency(abs($net_profit_loss), false), $net_profit_loss >= 0 ? 'green' : 'red'),
    array('Profit Margin', number_format(abs($profit_margin), 1) . '%', $net_profit_loss >= 0 ? 'green' : 'red'),
    array('Pending Expenses', formatCurrency($total_pending_expenses, false), 'orange'),
);

$pdf->SetFont('helvetica', '', 10);
foreach ($summary_data as $row) {
    $pdf->Cell(70, 6, $row[0], 0, 0, 'L');
    $pdf->SetTextColor(0, 0, 0); // Reset color
    
    if ($row[2] == 'green') {
        $pdf->SetTextColor(0, 128, 0);
    } elseif ($row[2] == 'red') {
        $pdf->SetTextColor(255, 0, 0);
    } elseif ($row[2] == 'orange') {
        $pdf->SetTextColor(255, 165, 0);
    }
    
    $pdf->Cell(0, 6, $row[1], 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0); // Reset color
}

$pdf->Ln(5);

// Profit & Loss Statement
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(220, 235, 255);
$pdf->Cell(0, 8, 'PROFIT & LOSS STATEMENT', 0, 1, 'L', true);
$pdf->SetFont('helvetica', '', 10);
$pdf->Ln(2);

// Revenue Section
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(0, 6, 'REVENUE', 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);

$revenue_percentage = $total_revenue > 0 ? ($total_registration_revenue/$total_revenue*100) : 0;
$pdf->Cell(100, 6, 'Registration Fees', 0, 0, 'L');
$pdf->Cell(40, 6, formatCurrency($total_registration_revenue, false), 0, 0, 'R');
$pdf->Cell(30, 6, number_format($revenue_percentage, 1) . '%', 0, 1, 'R');

$revenue_percentage = $total_revenue > 0 ? ($total_course_revenue/$total_revenue*100) : 0;
$pdf->Cell(100, 6, 'Course/Tuition Fees', 0, 0, 'L');
$pdf->Cell(40, 6, formatCurrency($total_course_revenue, false), 0, 0, 'R');
$pdf->Cell(30, 6, number_format($revenue_percentage, 1) . '%', 0, 1, 'R');

$service_revenue = $revenue_breakdown['service_revenue'] ?? 0;
$revenue_percentage = $total_revenue > 0 ? ($service_revenue/$total_revenue*100) : 0;
$pdf->Cell(100, 6, 'Service Revenue', 0, 0, 'L');
$pdf->Cell(40, 6, formatCurrency($service_revenue, false), 0, 0, 'R');
$pdf->Cell(30, 6, number_format($revenue_percentage, 1) . '%', 0, 1, 'R');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(100, 6, 'TOTAL REVENUE', 0, 0, 'L');
$pdf->Cell(40, 6, formatCurrency($total_revenue, false), 0, 0, 'R');
$pdf->Cell(30, 6, '100.0%', 0, 1, 'R');

$pdf->Ln(3);

// Expenses Section
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(255, 0, 0);
$pdf->Cell(0, 6, 'EXPENSES', 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);

foreach ($expense_breakdown as $expense) {
    $percentage = $total_revenue > 0 ? ($expense['total_amount'] / $total_revenue * 100) : 0;
    $pdf->Cell(100, 6, htmlspecialchars_decode($expense['name']), 0, 0, 'L');
    $pdf->Cell(40, 6, formatCurrency($expense['total_amount'], false), 0, 0, 'R');
    $pdf->Cell(30, 6, number_format($percentage, 1) . '%', 0, 1, 'R');
}

$pdf->SetFont('helvetica', 'B', 10);
$expense_percentage = $total_revenue > 0 ? ($total_expenses/$total_revenue*100) : 0;
$pdf->Cell(100, 6, 'TOTAL EXPENSES', 0, 0, 'L');
$pdf->Cell(40, 6, formatCurrency($total_expenses, false), 0, 0, 'R');
$pdf->Cell(30, 6, number_format($expense_percentage, 1) . '%', 0, 1, 'R');

$pdf->Ln(3);

// Net Profit/Loss
$pdf->SetFont('helvetica', 'B', 11);
if ($net_profit_loss >= 0) {
    $pdf->SetTextColor(0, 128, 0);
    $pdf->Cell(100, 7, 'NET PROFIT', 0, 0, 'L');
} else {
    $pdf->SetTextColor(255, 0, 0);
    $pdf->Cell(100, 7, 'NET LOSS', 0, 0, 'L');
}
$pdf->Cell(40, 7, formatCurrency(abs($net_profit_loss), false), 0, 0, 'R');
$pdf->Cell(30, 7, number_format(abs($profit_margin), 1) . '%', 0, 1, 'R');

$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(8);

// Top Expenses
if (!empty($top_expenses)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(255, 240, 240);
    $pdf->Cell(0, 8, 'TOP 10 EXPENSES', 0, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Ln(2);
    
    // Table header
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(25, 6, 'Expense #', 1, 0, 'C', true);
    $pdf->Cell(35, 6, 'Category', 1, 0, 'C', true);
    $pdf->Cell(40, 6, 'Description', 1, 0, 'C', true);
    $pdf->Cell(25, 6, 'Amount', 1, 0, 'C', true);
    $pdf->Cell(25, 6, 'Date', 1, 0, 'C', true);
    $pdf->Cell(30, 6, 'Vendor', 1, 1, 'C', true);
    
    // Table rows
    $pdf->SetFont('helvetica', '', 8);
    foreach ($top_expenses as $expense) {
        $pdf->Cell(25, 6, $expense['expense_number'], 1, 0, 'C');
        $pdf->Cell(35, 6, substr($expense['category'], 0, 20), 1, 0, 'L');
        $pdf->Cell(40, 6, substr($expense['description'], 0, 25), 1, 0, 'L');
        $pdf->Cell(25, 6, formatCurrency($expense['amount'], false), 1, 0, 'R');
        $pdf->Cell(25, 6, date('M j, Y', strtotime($expense['payment_date'])), 1, 0, 'C');
        $pdf->Cell(30, 6, substr($expense['vendor_name'], 0, 15), 1, 1, 'L');
    }
    
    $pdf->Ln(8);
}

// Top Revenue Sources
if (!empty($top_revenue_sources)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 255, 240);
    $pdf->Cell(0, 8, 'TOP REVENUE SOURCES', 0, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Ln(2);
    
    // Table header
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(50, 6, 'Student', 1, 0, 'C', true);
    $pdf->Cell(35, 6, 'Program', 1, 0, 'C', true);
    $pdf->Cell(25, 6, 'Transactions', 1, 0, 'C', true);
    $pdf->Cell(30, 6, 'Total Paid', 1, 0, 'C', true);
    $pdf->Cell(30, 6, 'Last Payment', 1, 1, 'C', true);
    
    // Table rows
    $pdf->SetFont('helvetica', '', 8);
    foreach ($top_revenue_sources as $source) {
        $pdf->Cell(50, 6, substr($source['first_name'] . ' ' . $source['last_name'], 0, 20), 1, 0, 'L');
        $pdf->Cell(35, 6, substr($source['program_name'] ?? 'N/A', 0, 15), 1, 0, 'L');
        $pdf->Cell(25, 6, $source['transaction_count'], 1, 0, 'C');
        $pdf->Cell(30, 6, formatCurrency($source['total_paid'], false), 1, 0, 'R');
        $pdf->Cell(30, 6, date('M j, Y', strtotime($source['last_payment_date'])), 1, 1, 'C');
    }
    
    $pdf->Ln(8);
}

// Automated Deductions
if (!empty($automated_deductions)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 255);
    $pdf->Cell(0, 8, 'AUTOMATED DEDUCTIONS', 0, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Ln(2);
    
    // Table header
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(30, 6, 'Type', 1, 0, 'C', true);
    $pdf->Cell(35, 6, 'Amount', 1, 0, 'C', true);
    $pdf->Cell(40, 6, 'Based on Revenue', 1, 0, 'C', true);
    $pdf->Cell(35, 6, 'Expense #', 1, 0, 'C', true);
    $pdf->Cell(30, 6, 'Percentage', 1, 1, 'C', true);
    
    // Table rows
    $pdf->SetFont('helvetica', '', 8);
    foreach ($automated_deductions as $deduction) {
        $deduction_percentage = $deduction['based_on_revenue'] > 0 ? 
            ($deduction['amount'] / $deduction['based_on_revenue'] * 100) : 0;
        
        $pdf->Cell(30, 6, ucfirst($deduction['type']), 1, 0, 'C');
        $pdf->Cell(35, 6, formatCurrency($deduction['amount'], false), 1, 0, 'R');
        $pdf->Cell(40, 6, formatCurrency($deduction['based_on_revenue'], false), 1, 0, 'R');
        $pdf->Cell(35, 6, $deduction['expense_number'], 1, 0, 'C');
        $pdf->Cell(30, 6, number_format($deduction_percentage, 1) . '%', 1, 1, 'C');
    }
    
    $pdf->Ln(8);
}

// Audit Information
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 8, 'AUDIT INFORMATION', 0, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->Ln(2);

$pdf->Cell(50, 5, 'Report Generated By:', 0, 0, 'L');
$pdf->Cell(0, 5, $_SESSION['user_name'] ?? 'System', 0, 1, 'L');

$pdf->Cell(50, 5, 'Generation Time:', 0, 0, 'L');
$pdf->Cell(0, 5, date('Y-m-d H:i:s'), 0, 1, 'L');

$pdf->Cell(50, 5, 'Period Covered:', 0, 0, 'L');
$pdf->Cell(0, 5, date('F j, Y', strtotime($date_from)) . ' to ' . date('F j, Y', strtotime($date_to)), 0, 1, 'L');

$pdf->Cell(50, 5, 'Data Source:', 0, 0, 'L');
$pdf->Cell(0, 5, 'Financial Transactions & Expense Records', 0, 1, 'L');

$pdf->Cell(50, 5, 'Notes:', 0, 0, 'L');
$pdf->MultiCell(0, 5, 'This report is generated for audit and review purposes. All amounts are in NGN.', 0, 'L');

// Footer
$pdf->SetY(-15);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'C');

// Close and output PDF document
$filename = 'profit_loss_' . date('Y-m-d') . '_' . time() . '.pdf';
$pdf->Output($filename, 'D');

// Close database connection
$conn->close();

?>