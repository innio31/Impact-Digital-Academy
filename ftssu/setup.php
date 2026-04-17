<?php
require_once 'config.php';

// Create tables
$sql = "
-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    description TEXT,
    has_custom_price TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'paid', 'completed', 'cancelled') DEFAULT 'pending',
    payment_proof TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Admins table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- First delete existing admin if exists
DELETE FROM admins WHERE username = 'admin';

-- Insert default admin with plain text password (will work immediately)
INSERT INTO admins (username, password) VALUES ('admin', 'admin123');

-- Insert default products
INSERT IGNORE INTO products (name, price, description, has_custom_price, sort_order) VALUES
('LONG TIE', 5000, 'Classic formal tie, security regalia', 0, 1),
('SCARF', 5000, 'Official neck scarf (ceremonial)', 0, 2),
('MALE BOW TIE', 2500, 'Elegant bow tie for male officers', 0, 3),
('FEMALE BOW TIE', 2500, 'Tailored bow tie for female officers', 0, 4),
('LANYARD', 2200, 'ID lanyard with security branding', 0, 5),
('BROOCH', 1200, 'Lapel brooch / pin insignia', 0, 6),
('S.S HANDBOOK', 500, 'Security Strategies handbook', 0, 7),
('ID CARD HOLDER', 500, 'Protective ID card holder', 0, 8),
('LOVE SEED', 0, 'Give what\'s in your heart - sow a seed', 1, 9);
";

// Execute SQL
try {
    // Execute multiple statements
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec($sql);
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "<h2 style='color: green;'>✅ Database setup completed successfully!</h2>";
    echo "<p>Admin Login: <strong>admin</strong> / <strong>admin123</strong></p>";
    echo "<a href='admin_login.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #cc0000; color: white; text-decoration: none; border-radius: 5px;'>Go to Admin Login →</a>";
} catch(PDOException $e) {
    echo "<h2 style='color: red;'>Error: " . $e->getMessage() . "</h2>";
}
?>