<?php
// Include database configuration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/database.php';

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
    
    echo "System settings table created successfully.\n";
    
    // Insert default settings
    $defaultSettings = [];
    
    if (!empty($defaultSettings)) {
        $insertStmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        
        foreach ($defaultSettings as $setting) {
            $insertStmt->execute($setting);
        }
    }
    
    echo "Default settings inserted successfully.\n";
    
    // Check if settings were inserted
    $stmt = $conn->query("SELECT * FROM system_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Current settings:\n";
    foreach ($settings as $setting) {
        echo "- {$setting['setting_key']}: {$setting['setting_value']}\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>