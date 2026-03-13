<?php
// Include database configuration
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
    die("Authentication required. Please log in as an administrator.");
}

// Check if user is admin
if (!isset($_SESSION['user_data']['role']) || $_SESSION['user_data']['role'] !== 'admin') {
    die("Administrator privileges required.");
}

// Create database connection
$db = new Database();
$conn = $db->getConnection();

try {
    // Create system_settings table if it doesn't exist
    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    echo "<p>System settings table created successfully.</p>";
    
    // Insert default settings
    $defaultSettings = [
        ['kingschat_enabled', '1'] // KingsChat integration enabled by default
    ];
    
    $insertStmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    
    foreach ($defaultSettings as $setting) {
        $insertStmt->execute($setting);
    }
    
    echo "<p>Default settings inserted successfully.</p>";
    
    // Check if settings were inserted
    $stmt = $conn->query("SELECT * FROM system_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Current settings:</p><ul>";
    foreach ($settings as $setting) {
        echo "<li>{$setting['setting_key']}: {$setting['setting_value']}</li>";
    }
    echo "</ul>";
    
    echo "<p>Setup complete! <a href='admin/settings.php'>Go to Settings Page</a></p>";
    
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>