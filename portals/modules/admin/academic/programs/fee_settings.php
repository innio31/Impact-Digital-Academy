<?php
// modules/admin/academic/programs/fee_settings.php
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
requireRole('admin');

$program_id = $_GET['id'] ?? 0;
$program = getProgramById($program_id);

if (!$program) {
    $_SESSION['error'] = "Program not found";
    header("Location: index.php");
    exit();
}

// Handle fee structure update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process fee structure update
    $payment_plan = [
        'registration_fee' => (float)$_POST['registration_fee'],
        'block1_percentage' => (float)$_POST['block1_percentage'],
        'block2_percentage' => (float)$_POST['block2_percentage'],
        'block1_due_days' => (int)$_POST['block1_due_days'],
        'block2_due_days' => (int)$_POST['block2_due_days'],
        'late_fee_percentage' => (float)$_POST['late_fee_percentage'],
        'suspension_days' => (int)$_POST['suspension_days'],
        'refund_policy_days' => (int)$_POST['refund_policy_days']
    ];
    
    // Save to payment_plans table
    savePaymentPlan($program_id, $program['program_type'], $payment_plan);
    
    $_SESSION['success'] = "Fee settings updated successfully";
    header("Location: fee_settings.php?id=" . $program_id);
    exit();
}

// Get existing payment plan
$payment_plan = getPaymentPlan($program_id, $program['program_type']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fee Settings - <?php echo htmlspecialchars($program['name']); ?></title>
    <style>
        .fee-settings-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .fee-breakdown {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .fee-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .fee-item.total {
            font-weight: bold;
            font-size: 1.2em;
            color: #198754;
        }
        .form-group {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="fee-settings-container">
        <h1>Fee Settings: <?php echo htmlspecialchars($program['name']); ?></h1>
        
        <!-- Current Fee Breakdown -->
        <div class="fee-breakdown">
            <h3>Current Fee Structure</h3>
            <div class="fee-item">
                <span>Base Program Fee:</span>
                <span>₦<?php echo number_format($program['base_fee'], 2); ?></span>
            </div>
            <div class="fee-item">
                <span>Registration Fee:</span>
                <span>₦<?php echo number_format($program['registration_fee'], 2); ?></span>
            </div>
            <?php if ($program['program_type'] === 'online' && $program['online_fee']): ?>
            <div class="fee-item">
                <span>Online Program Fee:</span>
                <span>₦<?php echo number_format($program['online_fee'], 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($program['program_type'] === 'onsite' && $program['onsite_fee']): ?>
            <div class="fee-item">
                <span>Onsite Program Fee:</span>
                <span>₦<?php echo number_format($program['onsite_fee'], 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="fee-item total">
                <span>Total Program Fee:</span>
                <span>₦<?php echo number_format($program['fee'], 2); ?></span>
            </div>
        </div>
        
        <!-- Payment Plan Configuration -->
        <form method="POST">
            <h3>Payment Plan Configuration</h3>
            
            <div class="form-group">
                <label>Registration Fee (₦)</label>
                <input type="number" name="registration_fee" class="form-control" 
                       value="<?php echo $payment_plan['registration_fee'] ?? 10000; ?>" step="0.01" min="0">
            </div>
            
            <?php if ($program['program_type'] === 'online'): ?>
            <h4>Block-based Payments (Online)</h4>
            <div class="form-group">
                <label>Block 1 Percentage (%)</label>
                <input type="number" name="block1_percentage" class="form-control" 
                       value="<?php echo $payment_plan['block1_percentage'] ?? 70; ?>" min="0" max="100">
                <small>Percentage of program fee due in first block</small>
            </div>
            
            <div class="form-group">
                <label>Block 1 Due Days</label>
                <input type="number" name="block1_due_days" class="form-control" 
                       value="<?php echo $payment_plan['block1_due_days'] ?? 30; ?>" min="1">
                <small>Days after registration before Block 1 payment is due</small>
            </div>
            
            <div class="form-group">
                <label>Block 2 Percentage (%)</label>
                <input type="number" name="block2_percentage" class="form-control" 
                       value="<?php echo $payment_plan['block2_percentage'] ?? 30; ?>" min="0" max="100">
            </div>
            
            <div class="form-group">
                <label>Block 2 Due Days</label>
                <input type="number" name="block2_due_days" class="form-control" 
                       value="<?php echo $payment_plan['block2_due_days'] ?? 60; ?>" min="1">
            </div>
            <?php endif; ?>
            
            <h4>Late Payment & Suspension</h4>
            <div class="form-group">
                <label>Late Fee Percentage (%)</label>
                <input type="number" name="late_fee_percentage" class="form-control" 
                       value="<?php echo $payment_plan['late_fee_percentage'] ?? 5; ?>" step="0.01" min="0" max="50">
            </div>
            
            <div class="form-group">
                <label>Auto-suspension Days</label>
                <input type="number" name="suspension_days" class="form-control" 
                       value="<?php echo $payment_plan['suspension_days'] ?? 21; ?>" min="1">
                <small>Days after due date before automatic suspension</small>
            </div>
            
            <div class="form-group">
                <label>Refund Policy Days</label>
                <input type="number" name="refund_policy_days" class="form-control" 
                       value="<?php echo $payment_plan['refund_policy_days'] ?? 14; ?>" min="0">
                <small>Days after enrollment for full refund</small>
            </div>
            
            <button type="submit" class="btn btn-primary">Save Fee Settings</button>
            <a href="index.php" class="btn btn-secondary">Back to Programs</a>
        </form>
    </div>
</body>
</html>