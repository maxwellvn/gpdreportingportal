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

// Create settings table if it doesn't exist
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Insert default settings if they don't exist
    $defaultSettings = [];
    
    if (!empty($defaultSettings)) {
        $insertStmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        
        foreach ($defaultSettings as $setting) {
            $insertStmt->execute($setting);
        }
    }
} catch (PDOException $e) {
    error_log("Error creating settings table: " . $e->getMessage());
}

// Function to get a setting value
function getSetting($conn, $key, $default = null) {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error getting setting: " . $e->getMessage());
        return $default;
    }
}

// Function to set a setting value
function setSetting($conn, $key, $value) {
    try {
        // Check if setting exists
        $stmt = $conn->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Update existing setting
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $result = $stmt->execute([$value, $key]);
            error_log("Updated setting {$key} to {$value}. Result: " . ($result ? 'success' : 'failed'));
            
            // Check if any rows were affected
            if ($stmt->rowCount() === 0) {
                error_log("Warning: No rows were affected when updating setting {$key}");
            }
        } else {
            // Insert new setting
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            $result = $stmt->execute([$key, $value]);
            error_log("Inserted new setting {$key} with value {$value}. Result: " . ($result ? 'success' : 'failed'));
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error setting setting {$key} to {$value}: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['success'] = "Settings updated successfully";
    
    // Redirect to prevent form resubmission
    header('Location: ' . BASE_URL . '/admin/settings.php');
    exit;
}

// Include header
require_once '../layouts/header.php';
?>

<style>
.st-page { max-width: 800px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.st-title { font-size:1.125rem; font-weight:700; color:var(--neutral-900); margin:0 0 1.5rem; }
.st-section { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; margin-bottom:1.25rem; overflow:hidden; }
.st-section-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); }
.st-section-head h2 { font-size:0.875rem; font-weight:600; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.04em; }
.st-section-body { padding:1.25rem; }
.st-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
.st-table th { padding:0.65rem 1rem; text-align:left; font-size:0.7rem; font-weight:600; color:var(--neutral-500); text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid var(--neutral-200); }
.st-table td { padding:0.75rem 1rem; border-bottom:1px solid var(--neutral-100); color:var(--neutral-800); font-family:monospace; font-size:0.8rem; }
.st-table tr:last-child td { border-bottom:none; }
</style>

<div class="st-page">
    <h1 class="st-title">System Settings</h1>

    <?php if (isset($_SESSION['error'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function() { showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error', 5000); });</script>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['success'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function() { showAlert('Success', '<?php echo addslashes($_SESSION['success']); ?>', 'success'); });</script>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="st-section">
        <div class="st-section-head"><h2>Application Settings</h2></div>
        <div class="st-section-body">
            <p style="font-size:0.875rem;color:var(--neutral-500);margin:0;">No settings configured. Settings can be added here as needed.</p>
        </div>
    </div>

    <div class="st-section">
        <div class="st-section-head"><h2>Current Settings (Debug)</h2></div>
        <div style="overflow-x:auto;">
            <table class="st-table">
                <thead><tr><th>Key</th><th>Value</th><th>Last Updated</th></tr></thead>
                <tbody>
                    <?php
                    $stmt = $conn->query("SELECT * FROM system_settings ORDER BY setting_key");
                    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($settings as $setting): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($setting['setting_key']); ?></td>
                        <td><?php echo htmlspecialchars($setting['setting_value']); ?></td>
                        <td><?php echo htmlspecialchars($setting['updated_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($settings)): ?>
                    <tr><td colspan="3" style="color:var(--neutral-400);font-family:inherit;">No settings records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:1rem 1.25rem;border-top:1px solid var(--neutral-200);">
            <form method="POST" action="reset_settings.php" onsubmit="return confirm('Are you sure you want to reset all settings to default values?');">
                <button type="submit" class="modern-button modern-button-danger" style="font-size:0.8125rem;"><i class="fas fa-undo modern-button-icon"></i> Reset to Default Values</button>
            </form>
        </div>
    </div>
</div>

<?php
// Close main tag from header
echo '</main></body></html>';
?>