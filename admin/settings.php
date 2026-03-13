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

<!-- Main Content -->
<div class="modern-container">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">System Settings</h1>
        
        <?php if (isset($_SESSION['error'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error', 5000);
                });
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showAlert('Success', '<?php echo addslashes($_SESSION['success']); ?>', 'success');
                });
            </script>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <div class="modern-card">
            <div class="modern-card-header">
                <h2 class="modern-card-title">Application Settings</h2>
            </div>
            <div class="modern-card-body">
                <p class="text-gray-600">No settings configured. Settings can be added here as needed.</p>
            </div>
        </div>
        
        <!-- Debug Information (Only visible to admins) -->
        <div class="modern-card mt-6">
            <div class="modern-card-header">
                <h2 class="modern-card-title">Debug Information</h2>
            </div>
            <div class="modern-card-body">
                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Current Settings</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Key</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Updated</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php
                                $stmt = $conn->query("SELECT * FROM system_settings ORDER BY setting_key");
                                $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($settings as $setting):
                                ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($setting['setting_key']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($setting['setting_value']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($setting['updated_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="mt-4">
                    <form method="POST" action="reset_settings.php" onsubmit="return confirm('Are you sure you want to reset all settings to default values?');">
                        <button type="submit" class="modern-button modern-button-danger">
                            <i class="fas fa-undo modern-button-icon"></i> Reset to Default Values
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Close main tag from header
echo '</main></body></html>';
?>