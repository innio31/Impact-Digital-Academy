<?php
// api/download_report_pdf.php - Generate PDF from result data
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get parameters
$student_id = $_GET['student_id'] ?? null;
$session = $_GET['session'] ?? null;
$term = $_GET['term'] ?? null;

if (!$student_id || !$session || !$term) {
    die("Missing required parameters. student_id: $student_id, session: $session, term: $term");
}

// Include database config
require_once '../config/config.php';

// Include TCPDF
require_once '../includes/tcpdf/tcpdf.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Get student info with school details
    $stmt = $conn->prepare("
        SELECT s.*, sc.school_name, sc.school_address, sc.school_phone, sc.school_email, sc.school_logo
        FROM students s
        JOIN schools sc ON s.school_id = sc.id
        WHERE s.id = ? AND s.status = 'active'
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        die("Student not found with ID: $student_id");
    }

    // Get result
    $stmt = $conn->prepare("
        SELECT * FROM results 
        WHERE student_id = ? AND session_year = ? AND term = ? AND is_published = 1
    ");
    $stmt->execute([$student_id, $session, $term]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        die("Result not found for student: $student_id, session: $session, term: $term");
    }

    // Decode result data
    $result_data = json_decode($result['result_data'], true);
    $affective_traits = json_decode($result['affective_traits'], true);
    $psychomotor_skills = json_decode($result['psychomotor_skills'], true);

    // Get scores - try multiple possible structures
    $scores = [];
    if (isset($result_data['scores']) && is_array($result_data['scores'])) {
        $scores = $result_data['scores'];
    } elseif (isset($result_data['original_data']['scores']) && is_array($result_data['original_data']['scores'])) {
        $scores = $result_data['original_data']['scores'];
    } elseif (isset($result['result_data'])) {
        // Try to decode again
        $temp = json_decode($result['result_data'], true);
        if (isset($temp['scores'])) {
            $scores = $temp['scores'];
        }
    }

    // Calculate age
    $age = '';
    if ($student['date_of_birth'] && $student['date_of_birth'] != '0000-00-00') {
        $birthDate = new DateTime($student['date_of_birth']);
        $today = new DateTime();
        $age = $birthDate->diff($today)->y . ' yrs';
    } else {
        $age = 'N/A';
    }

    // Calculate totals
    $total_scored = $result['total_marks'] ?? 0;
    $total_max = count($scores) * 100;
    if ($total_max == 0) $total_max = 100;
    $percentage = $result['average'] ?? ($total_max > 0 ? round(($total_scored / $total_max) * 100, 2) : 0);

    // Get score types from first score
    $score_types = ['CA1', 'CA2', 'Exam'];
    if (!empty($scores)) {
        $firstScore = $scores[0];
        if (isset($firstScore['components']) && is_array($firstScore['components'])) {
            $score_types = array_keys($firstScore['components']);
        } elseif (isset($firstScore['score_data']) && is_array($firstScore['score_data'])) {
            $types = array_keys($firstScore['score_data']);
            $score_types = array_filter($types, function ($key) {
                return !in_array($key, ['subject_name', 'subject_id', 'raw_values', 'total_score', 'percentage', 'grade']);
            });
            if (empty($score_types)) $score_types = ['CA1', 'CA2', 'Exam'];
        }
    }

    // Create custom PDF class
    class MYPDF extends TCPDF
    {
        public function Header()
        {
            // No header
        }

        public function Footer()
        {
            $this->SetY(-10);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(0, 10, 'Generated on: ' . date('F j, Y') . ' | Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        }
    }

    // Create PDF document
    $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('MyResultChecker');
    $pdf->SetAuthor('School Management System');
    $pdf->SetTitle('Report Card - ' . $student['full_name']);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);

    // Generate HTML content
    $html = generateReportHTML($student, $result, $scores, $affective_traits, $psychomotor_skills, $score_types, $total_scored, $total_max, $percentage, $age);

    // Write HTML to PDF
    $pdf->writeHTML($html, true, false, true, false, '');

    // Output PDF
    $filename = 'report_card_' . preg_replace('/[^a-zA-Z0-9]/', '_', $student['full_name']) . '_' . $session . '_' . $term . '.pdf';
    $pdf->Output($filename, 'D');
    exit();
} catch (Exception $e) {
    die("Error generating PDF: " . $e->getMessage() . "<br>Line: " . $e->getLine());
}

function generateReportHTML($student, $result, $scores, $affective_traits, $psychomotor_skills, $score_types, $total_scored, $total_max, $percentage, $age)
{

    function getGradePDF($p)
    {
        if ($p >= 80) return 'A';
        if ($p >= 70) return 'B';
        if ($p >= 60) return 'C';
        if ($p >= 50) return 'D';
        if ($p >= 40) return 'E';
        return 'F';
    }

    function getRemarkPDF($p)
    {
        if ($p >= 70) return 'Excellent';
        if ($p >= 60) return 'Very good';
        if ($p >= 50) return 'Good';
        if ($p >= 40) return 'Pass';
        if ($p >= 30) return 'Poor';
        return 'Fail';
    }

    function ordinalPDF($n)
    {
        if (!$n || $n == 'N/A') return 'N/A';
        $ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
        if ((($n % 100) >= 11) && (($n % 100) <= 13)) return $n . 'th';
        return $n . $ends[$n % 10];
    }

    $overall_grade = $result['grade'] ?? getGradePDF($percentage);
    $class_position = $result['class_position'] ?? 'N/A';
    $class_total = $result['class_total_students'] ?? 'N/A';

    // Build scores table
    $scores_html = '';
    $counter = 1;

    if (!empty($scores)) {
        foreach ($scores as $score) {
            $subject_total = $score['total_score'] ?? 0;
            $subject_percentage = $score['percentage'] ?? (($subject_total / 100) * 100);
            $subject_grade = $score['grade'] ?? getGradePDF($subject_percentage);
            $subject_remark = getRemarkPDF($subject_percentage);

            $scores_html .= '<tr>';
            $scores_html .= '<td style="border:1px solid #000; padding:6px;"><strong>' . $counter++ . '. ' . htmlspecialchars($score['subject_name']) . '</strong></td>';

            // Add component scores
            if (isset($score['components']) && is_array($score['components'])) {
                foreach ($score_types as $type) {
                    $value = $score['components'][$type] ?? 0;
                    $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . $value . '</td>';
                }
            } elseif (isset($score['score_data']) && is_array($score['score_data'])) {
                foreach ($score_types as $type) {
                    $value = $score['score_data'][$type] ?? 0;
                    $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . $value . '</td>';
                }
            } else {
                foreach ($score_types as $type) {
                    $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">-</td>';
                }
            }

            $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;"><strong>' . $subject_total . '</strong></td>';
            $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . round($subject_percentage, 1) . '%</td>';
            $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;"><strong>' . $subject_grade . '</strong></td>';
            $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . $subject_remark . '</td>';
            $scores_html .= '</tr>';
        }
    }

    // Fill empty rows to reach 15 subjects
    $empty_rows = 15 - count($scores);
    for ($i = 0; $i < $empty_rows; $i++) {
        $scores_html .= '<tr>';
        $scores_html .= '<td style="border:1px solid #000; padding:6px;">' . ($counter++) . '. </td>';
        foreach ($score_types as $type) {
            $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">-</td>';
        }
        $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">-</td>';
        $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">-</td>';
        $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">-</td>';
        $scores_html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">-</td>';
        $scores_html .= '</tr>';
    }

    // Build score headers
    $score_headers = '';
    foreach ($score_types as $type) {
        $score_headers .= '<th style="border:1px solid #000; padding:6px; background:#1a237e; color:white;">' . htmlspecialchars(substr($type, 0, 4)) . '</th>';
    }

    // Affective traits
    $affective_html = '';
    $affective_items = [
        'punctuality' => 'Punctuality',
        'attendance' => 'Attendance',
        'politeness' => 'Politeness',
        'honesty' => 'Honesty',
        'neatness' => 'Neatness',
        'reliability' => 'Reliability',
        'relationship' => 'Relationship with others',
        'self_control' => 'Self Control'
    ];

    if ($affective_traits && is_array($affective_traits)) {
        foreach ($affective_items as $key => $label) {
            $value = $affective_traits[$key] ?? '-';
            $affective_html .= '<tr><td style="border:1px solid #000; padding:4px;">' . $label . '</td>';
            $affective_html .= '<td style="border:1px solid #000; padding:4px; text-align:center;"><strong>' . htmlspecialchars($value) . '</strong></td></tr>';
        }
    }

    // Psychomotor skills
    $psychomotor_html = '';
    $psychomotor_items = [
        'handwriting' => 'Handwriting',
        'verbal_fluency' => 'Verbal Fluency',
        'sports' => 'Sports',
        'handling_tools' => 'Handling Tools',
        'drawing_painting' => 'Drawing & Painting',
        'musical_skills' => 'Musical Skills'
    ];

    if ($psychomotor_skills && is_array($psychomotor_skills)) {
        foreach ($psychomotor_items as $key => $label) {
            $value = $psychomotor_skills[$key] ?? '-';
            $psychomotor_html .= '<tr><td style="border:1px solid #000; padding:4px;">' . $label . '</td>';
            $psychomotor_html .= '<td style="border:1px solid #000; padding:4px; text-align:center;"><strong>' . htmlspecialchars($value) . '</strong></td></tr>';
        }
    }

    $gender_display = '';
    if ($student['gender']) {
        $gender_labels = ['M' => 'Male', 'F' => 'Female', 'Other' => 'Other'];
        $gender_display = $gender_labels[$student['gender']] ?? $student['gender'];
    }

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: helvetica, sans-serif; font-size: 9pt; margin: 0; padding: 0; }
            .header { text-align: center; margin-bottom: 8px; }
            .school-name { font-size: 16pt; font-weight: bold; color: #1a237e; }
            .divider { border-top: 2px solid #000; margin: 5px 0; }
            .section-title { text-align: center; font-weight: bold; font-size: 10pt; margin: 6px 0; background-color: #f2f2f2; padding: 3px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
            .label { font-weight: bold; background: #f8f8f8; width: 18%; }
            .traits-row { display: flex; gap: 10px; margin: 5px 0; }
            .traits-column { flex: 1; }
            .section-header { background: #3949ab; color: white; font-weight: bold; text-align: center; padding: 3px; margin-bottom: 0; }
            .rating-key { font-size: 7pt; text-align: center; margin: 5px 0; }
            .comment-box { margin: 4px 0; padding: 6px; border: 1px solid #000; }
            .footer { text-align: center; font-size: 7pt; margin-top: 8px; padding-top: 5px; border-top: 1px solid #ccc; }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="school-name">' . htmlspecialchars($student['school_name'] ?? 'THE CLIMAX BRAINS ACADEMY') . '</div>
            <div style="font-size:8pt;">' . htmlspecialchars($student['school_address'] ?? '') . '</div>
        </div>
        <div class="divider"></div>
        <div class="section-title">' . htmlspecialchars($result['term']) . ' TERM ' . htmlspecialchars($result['session_year']) . '</div>
        
        <table>
            <tr>
                <td class="label">Student Name</td>
                <td colspan="3"><strong>' . strtoupper(htmlspecialchars($student['full_name'])) . '</strong></td>
                <td class="label">Reg. No</td>
                <td>' . htmlspecialchars($student['admission_number']) . '</td>
            </tr>
            <tr>
                <td class="label">Class</td>
                <td>' . htmlspecialchars($student['class']) . '</td>
                <td class="label">Gender</td>
                <td>' . htmlspecialchars($gender_display) . '</td>
                <td class="label">Age</td>
                <td>' . $age . '</td>
            </tr>
            <tr>
                <td class="label">Position</td>
                <td>' . ordinalPDF($class_position) . '</td>
                <td class="label">Class Size</td>
                <td>' . $class_total . '</td>
                <td class="label">Days Present</td>
                <td>' . ($result['days_present'] ?? 0) . '</td>
            </tr>
            <tr>
                <td class="label">Total Score</td>
                <td>' . $total_scored . ' / ' . $total_max . '</td>
                <td class="label">Percentage</td>
                <td>' . round($percentage, 1) . '%</td>
                <td class="label">Grade</td>
                <td style="font-weight:bold;">' . $overall_grade . '</td>
            </tr>
        </table>
        
        <div class="section-title">Academic Performance</div>
        <table>
            <thead>
                <tr>
                    <th style="border:1px solid #000; padding:6px; background:#1a237e; color:white; text-align:left;">SUBJECT</th>
                    ' . $score_headers . '
                    <th style="border:1px solid #000; padding:6px; background:#1a237e; color:white;">Total</th>
                    <th style="border:1px solid #000; padding:6px; background:#1a237e; color:white;">%</th>
                    <th style="border:1px solid #000; padding:6px; background:#1a237e; color:white;">Grade</th>
                    <th style="border:1px solid #000; padding:6px; background:#1a237e; color:white;">Remark</th>
                </tr>
            </thead>
            <tbody>
                ' . $scores_html . '
            </tbody>
        </table>
        
        <div class="traits-row">
            <div class="traits-column">
                <div class="section-header">AFFECTIVE TRAITS</div>
                <table>' . ($affective_html ?: '<tr><td colspan="2" style="text-align:center;">No data available</td></tr>') . '</table>
            </div>
            <div class="traits-column">
                <div class="section-header">PSYCHOMOTOR SKILLS</div>
                <table>' . ($psychomotor_html ?: '<tr><td colspan="2" style="text-align:center;">No data available</td></tr>') . '</table>
            </div>
        </div>
        
        <table class="rating-key">
            <tr style="background:#f0f0f0; font-weight:bold;">
                <td>5: Excellent</td>
                <td>4: Very Good</td>
                <td>3: Good</td>
                <td>2: Pass</td>
                <td>1: Poor</td>
            </tr>
        </table>
        
        <div class="comment-box">
            <strong>Teacher:</strong> ' . htmlspecialchars($result['teachers_comment'] ?? 'No comment.') . '
        </div>
        <div class="comment-box">
            <strong>Principal:</strong> ' . htmlspecialchars($result['principals_comment'] ?? 'No comment.') . '
        </div>
        
        <div class="footer">
            <strong>Next Term Resumption:</strong> To be announced by the school
        </div>
    </body>
    </html>';

    return $html;
}
