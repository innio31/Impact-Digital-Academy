<?php
// Database configuration
$host = 'localhost';
$dbname = 'impactdi_result-checker';
$username = 'impactdi_result-checker';
$password = 'Innioluwa@1995';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all members
    $stmt = $pdo->query("SELECT id, password FROM members WHERE password IS NOT NULL AND password != ''");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($members as $member) {
        $plain_password = $member['password'];

        // Skip if already hashed (starts with $2y$)
        if (strpos($plain_password, '$2y$') === 0) {
            echo "Skipping member ID {$member['id']} - already hashed\n";
            continue;
        }

        // Generate bcrypt hash
        $hashed_password = password_hash($plain_password, PASSWORD_BCRYPT);

        // Update database
        $update = $pdo->prepare("UPDATE members SET password = :hashed WHERE id = :id");
        $update->execute([':hashed' => $hashed_password, ':id' => $member['id']]);

        echo "Hashed password for member ID {$member['id']}\n";
        $count++;
    }

    echo "\n✅ Completed! Hashed $count passwords.\n";
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
