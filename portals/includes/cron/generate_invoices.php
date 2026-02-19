<?php
// cron/generate_invoices.php
/**
 * Monthly cron job to generate invoices for upcoming blocks
 * Runs on the 1st of every month
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/finance_functions.php';

$conn = getDBConnection();
echo "[" . date('Y-m-d H:i:s') . "] Starting invoice generation...\n";

// Get current month and year
$current_month = date('n');
$current_year = date('Y');

// Determine which blocks/terms need invoices based on current month
// For online programs (6 blocks per year)
$block_months = [
    1 => 'block1',  // Jan-Feb
    2 => 'block1',
    3 => 'block2',  // Mar-Apr
    4 => 'block2',
    5 => 'block3',  // May-Jun
    6 => 'block3',
    7 => 'block4',  // Jul-Aug
    8 => 'block4',
    9 => 'block5',  // Sep-Oct
    10 => 'block5',
    11 => 'block6', // Nov-Dec
    12 => 'block6'
];

$block_to_generate = $block_months[$current_month] ?? null;
$invoice_type = "tuition_" . $block_to_generate;

if ($block_to_generate) {
    echo "Generating invoices for $block_to_generate (Month: $current_month)\n";
    
    // Find students who need invoices for this block
    $sql = "SELECT DISTINCT sfs.student_id, sfs.class_id, sfs.current_block,
                   cb.block_number, cb.program_type
            FROM student_financial_status sfs
            JOIN class_batches cb ON cb.id = sfs.class_id
            WHERE cb.program_type = 'online'
            AND sfs.is_cleared = 0
            AND sfs.is_suspended = 0
            AND ((sfs.current_block = 1 AND ? = 'block1')
                 OR (sfs.current_block = 2 AND ? = 'block2'))
            AND NOT EXISTS (
                SELECT 1 FROM invoices i 
                WHERE i.student_id = sfs.student_id 
                AND i.class_id = sfs.class_id 
                AND i.invoice_type = ?
                AND i.status IN ('pending', 'partial', 'paid')
            )";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $block_to_generate, $block_to_generate, $invoice_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $invoices_generated = 0;
    while ($student = $result->fetch_assoc()) {
        // Generate invoice
        $invoice = generateInvoice($student['student_id'], $student['class_id'], $invoice_type);
        
        if ($invoice) {
            echo "Generated invoice #{$invoice['invoice_number']} for student {$student['student_id']}\n";
            $invoices_generated++;
        }
    }
    
    echo "Generated $invoices_generated invoices for $block_to_generate\n";
    
    // For onsite programs (3 terms per year)
    $term_months = [
        1 => 'term1',  // Jan-Apr
        2 => 'term1',
        3 => 'term1',
        4 => 'term1',
        5 => 'term2',  // May-Aug
        6 => 'term2',
        7 => 'term2',
        8 => 'term2',
        9 => 'term3',  // Sep-Dec
        10 => 'term3',
        11 => 'term3',
        12 => 'term3'
    ];
    
    $term_to_generate = $term_months[$current_month] ?? null;
    
    if ($term_to_generate && in_array($current_month, [1, 5, 9])) {
        // Generate term invoices at the beginning of each term
        echo "Generating term invoices for $term_to_generate\n";
        
        $sql = "SELECT DISTINCT sfs.student_id, sfs.class_id
                FROM student_financial_status sfs
                JOIN class_batches cb ON cb.id = sfs.class_id
                WHERE cb.program_type = 'onsite'
                AND sfs.is_cleared = 0
                AND sfs.is_suspended = 0
                AND NOT EXISTS (
                    SELECT 1 FROM invoices i 
                    WHERE i.student_id = sfs.student_id 
                    AND i.class_id = sfs.class_id 
                    AND i.invoice_type = 'tuition'
                    AND YEAR(i.created_at) = ?
                    AND MONTH(i.created_at) = ?
                )";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $current_year, $current_month);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $term_invoices = 0;
        while ($student = $result->fetch_assoc()) {
            $invoice = generateInvoice($student['student_id'], $student['class_id'], 'tuition');
            if ($invoice) {
                $term_invoices++;
            }
        }
        
        echo "Generated $term_invoices term invoices for $term_to_generate\n";
        $invoices_generated += $term_invoices;
    }
} else {
    echo "No invoices to generate this month.\n";
}

// Log completion
logActivity("Invoice generation completed via cron: $invoices_generated invoices generated");
echo "[" . date('Y-m-d H:i:s') . "] Invoice generation completed.\n";
?>