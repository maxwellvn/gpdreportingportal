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

<?php
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
$stmt = $conn->query("SELECT * FROM system_settings ORDER BY setting_key");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<nav class="breadcrumb" aria-label="Breadcrumb"><a href="dashboard.php">Admin</a><span class="sep" aria-hidden="true">›</span><span aria-current="page">Settings</span></nav>
<div class="page-head">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-sub">System-wide configuration for the portal.</p>
    </div>
</div>

<?php if ($flashSuccess): ?>
<div class="alert alert-success" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i><div><?php echo htmlspecialchars($flashSuccess); ?></div></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><div><?php echo htmlspecialchars($flashError); ?></div></div>
<?php endif; ?>

<div style="display:grid;gap:var(--spacing-6);max-width:56rem;">
    <section class="panel">
        <div class="panel-head">Application settings</div>
        <div class="panel-body">
            <p class="muted">No settings are configured yet. New options will appear here.</p>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <span>Stored values</span>
            <span class="meta"><?php echo count($settings); ?> keys</span>
        </div>
        <?php if (empty($settings)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-sliders" aria-hidden="true"></i></div>
            <div class="empty-state-title">No stored settings</div>
            <div class="empty-state-text">The system_settings table is empty.</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead><tr><th scope="col">Key</th><th scope="col">Value</th><th scope="col">Last updated</th></tr></thead>
                <tbody>
                    <?php foreach ($settings as $setting): ?>
                    <tr>
                        <td style="font-family:var(--font-mono);font-size:var(--text-xs);"><?php echo htmlspecialchars($setting['setting_key']); ?></td>
                        <td style="font-family:var(--font-mono);font-size:var(--text-xs);overflow-wrap:anywhere;"><?php echo htmlspecialchars((string)$setting['setting_value']); ?></td>
                        <td class="meta" style="white-space:nowrap;"><?php echo htmlspecialchars($setting['updated_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="panel" style="border-color:var(--danger-200);" aria-labelledby="danger-zone-title">
        <div class="panel-head" id="danger-zone-title" style="border-color:var(--danger-200);">Danger zone</div>
        <div class="panel-body" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:var(--spacing-4);">
            <div>
                <div style="font-weight:500;">Reset settings</div>
                <p class="muted" style="font-size:var(--text-sm);">Restore every setting to its default value. This cannot be undone.</p>
            </div>
            <form method="POST" action="reset_settings.php" onsubmit="return confirm('Are you sure you want to reset all settings to default values?');">
                <button type="submit" class="btn btn-danger"><i class="fas fa-rotate-left" aria-hidden="true"></i> Reset settings</button>
            </form>
        </div>
    </section>
</div>

<?php require_once '../layouts/footer.php'; ?>
