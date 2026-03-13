<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Add active column if it doesn't exist
    $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE AFTER role");
    
    // Update existing users to be active
    $conn->exec("UPDATE users SET active = TRUE WHERE active IS NULL");
    
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
}
?>
