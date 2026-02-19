<?php
session_start();
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $service_category = $_POST['service_category'] ?? '';
    $client_name = $_POST['client_name'] ?? '';
    $client_contact = $_POST['client_contact'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $description = $_POST['description'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $created_by = $_SESSION['user_id'];
    
    // Generate invoice number if not provided
    if (empty($reference_number)) {
        $reference_number = 'SERV-' . date('Ymd') . '-' . strtoupper(substr($service_category, 0, 3)) . '-' . rand(1000, 9999);
    }
    
    // First, check if service_categories table exists, if not create it
    $checkTable = $conn->query("SHOW TABLES LIKE 'service_categories'");
    if ($checkTable->num_rows === 0) {
        // Create service_categories table
        $createTableSQL = "CREATE TABLE IF NOT EXISTS `service_categories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `revenue_type` enum('product','service','consultancy','other') NOT NULL DEFAULT 'service',
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $conn->query($createTableSQL);
        
        // Insert default categories
        $defaultCategories = [
            ['Software Development', 'software'],
            ['Computer Procurement', 'procurement'],
            ['Computer Accessories', 'accessories'],
            ['IT Consultancy', 'consultancy'],
            ['CBT Setup', 'cbt'],
            ['System Maintenance', 'maintenance'],
            ['IT Training', 'training'],
            ['Networking Services', 'networking'],
            ['Website Development', 'website'],
            ['Digital Marketing', 'digital_marketing'],
            ['Other Services', 'other']
        ];
        
        foreach ($defaultCategories as $category) {
            $stmt = $conn->prepare("INSERT INTO service_categories (name, revenue_type) VALUES (?, ?)");
            $stmt->bind_param("ss", $category[0], $category[1]);
            $stmt->execute();
        }
    }
    
    // Check if category exists, if not create it
    $categoryCheck = $conn->prepare("SELECT id FROM service_categories WHERE revenue_type = ? LIMIT 1");
    $categoryCheck->bind_param("s", $service_category);
    $categoryCheck->execute();
    $categoryResult = $categoryCheck->get_result();
    
    if ($categoryResult->num_rows === 0) {
        // Create new category
        $categoryName = ucwords(str_replace('_', ' ', $service_category));
        $createCategory = $conn->prepare("INSERT INTO service_categories (name, revenue_type) VALUES (?, ?)");
        $createCategory->bind_param("ss", $categoryName, $service_category);
        $createCategory->execute();
        $service_category_id = $conn->insert_id;
    } else {
        $categoryRow = $categoryResult->fetch_assoc();
        $service_category_id = $categoryRow['id'];
    }
    
    // Now create service_revenue table if it doesn't exist
    $checkRevenueTable = $conn->query("SHOW TABLES LIKE 'service_revenue'");
    if ($checkRevenueTable->num_rows === 0) {
        $createRevenueTableSQL = "CREATE TABLE IF NOT EXISTS `service_revenue` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `service_category_id` int(11) NOT NULL,
            `client_name` varchar(255) NOT NULL,
            `client_email` varchar(100) DEFAULT NULL,
            `client_phone` varchar(20) DEFAULT NULL,
            `description` text NOT NULL,
            `amount` decimal(15,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `payment_method` enum('bank_transfer','cash','cheque','online','pos','mobile_money') DEFAULT 'bank_transfer',
            `payment_date` date NOT NULL,
            `invoice_number` varchar(50) DEFAULT NULL,
            `receipt_url` varchar(500) DEFAULT NULL,
            `status` enum('pending','completed','refunded','cancelled') DEFAULT 'completed',
            `notes` text DEFAULT NULL,
            `created_by` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `service_category_id` (`service_category_id`),
            KEY `payment_date` (`payment_date`),
            KEY `client_name` (`client_name`),
            KEY `created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $conn->query($createRevenueTableSQL);
    }
    
    // Insert the service revenue record
    $stmt = $conn->prepare("
        INSERT INTO service_revenue 
        (service_category_id, client_name, client_contact, description, amount, 
         payment_method, payment_date, invoice_number, notes, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Determine if contact is email or phone
    $client_contact_field = '';
    if (!empty($client_contact)) {
        if (filter_var($client_contact, FILTER_VALIDATE_EMAIL)) {
            $client_contact_field = $client_contact;
        } else {
            $client_contact_field = $client_contact;
        }
    }
    
    $stmt->bind_param("isssdssssi", 
        $service_category_id,
        $client_name,
        $client_contact_field,
        $description,
        $amount,
        $payment_method,
        $payment_date,
        $reference_number,
        $notes,
        $created_by
    );
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($created_by, 'service_revenue_added', 
            "Added service revenue: $client_name - ₦$amount for $service_category");
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Service revenue recorded successfully!',
            'invoice_number' => $reference_number
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $conn->error
        ]);
    }
    
    $stmt->close();
    $conn->close();
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>