<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check if user is admin
if (!isset($_SESSION['user_data']['role']) || $_SESSION['user_data']['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Database connection
$db = new Database();
$conn = $db->getConnection();

try {
    // Default settings
    $defaultSettings = [];
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Clear existing settings
    $conn->exec("TRUNCATE TABLE system_settings");
    
    // Insert default settings
    if (!empty($defaultSettings)) {
        $insertStmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        
        foreach ($defaultSettings as $setting) {
            $insertStmt->execute($setting);
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Settings have been reset to default values";
} catch (PDOException $e) {
    // Rollback transaction
    $conn->rollBack();
    
    error_log("Error resetting settings: " . $e->getMessage());
    $_SESSION['error'] = "Failed to reset settings: " . $e->getMessage();
}

// Redirect back to settings page
header('Location: ' . BASE_URL . '/admin/settings.php');
exit;
?>