<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/audit.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get user data
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $currentUser;

// Check if user is admin
if (!isset($currentUser['role']) || $currentUser['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
$success = '';
$user = null;

// Get user ID from URL
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get user data
try {
    $user = $auth->getUser($userId);
    if (!$user) {
        header('Location: users.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $role = $_POST['role'] ?? 'user';
        $zone = trim($_POST['zone'] ?? '');
        $zoneRequired = ($role === ROLE_USER);

        if (empty($_POST['username']) || empty($_POST['name']) || empty($_POST['region']) || ($zoneRequired && empty($zone))) {
            throw new Exception($zoneRequired ? 'Username, name, region and zone are required' : 'Username, name and region are required');
        }

        $updates = [
            'username' => $_POST['username'],
            'name' => $_POST['name'],
            'role' => $role,
            'region' => $_POST['region'],
            'zone' => $zoneRequired ? $zone : null,
            'active' => isset($_POST['active']) ? 1 : 0
        ];
        
        // Only update password if a new one is provided
        if (!empty($_POST['password'])) {
            $updates['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        
        // Update user
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = "UPDATE users SET ";
        $params = [];
        foreach ($updates as $key => $value) {
            if ($key === 'password' && empty($_POST['password'])) continue;
            $sql .= "$key = ?, ";
            $params[] = $value;
        }
        $sql = rtrim($sql, ", ");
        $sql .= " WHERE id = ?";
        $params[] = $userId;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $success = "User updated successfully";
        $user = $auth->getUser($userId); // Refresh user data
        Audit::log('edit_user', 'user', $userId, 'Edited user #' . $userId . ' (' . ($user['name'] ?? $user['username'] ?? '') . ')');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Include header
require_once '../layouts/header.php';
?>

<style>
.eu-page { max-width: 560px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.eu-topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; }
.eu-topbar h1 { font-size:1.125rem; font-weight:700; color:var(--neutral-900); margin:0; }
.eu-card { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; }
.eu-card-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); }
.eu-card-head h2 { font-size:0.875rem; font-weight:600; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.04em; }
.eu-card-body { padding:1.25rem; }
.eu-check-row { display:flex; align-items:center; gap:0.5rem; margin-top:0.25rem; }
.eu-check-row input { width:1rem; height:1rem; accent-color:var(--primary-600); }
.eu-check-row label { font-size:0.85rem; color:var(--neutral-700); }
</style>

<div class="eu-page">
    <div class="eu-topbar">
        <h1>Edit User: <span style="color:var(--primary-600);"><?php echo htmlspecialchars($user['username']); ?></span></h1>
        <a href="users.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-arrow-left modern-button-icon"></i> Back</a>
    </div>

    <?php if ($error): ?>
    <div style="background:var(--danger-50);border:1px solid var(--danger-200);color:var(--danger-700);padding:0.75rem 1rem;border-radius:var(--radius-md);margin-bottom:1rem;font-size:0.85rem;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div style="background:var(--success-50);border:1px solid var(--success-200);color:var(--success-700);padding:0.75rem 1rem;border-radius:var(--radius-md);margin-bottom:1rem;font-size:0.85rem;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="eu-card">
        <div class="eu-card-head"><h2>User Details</h2></div>
        <div class="eu-card-body">
            <form method="POST" class="space-y-4">
                <div class="modern-form-group">
                    <label for="username" class="modern-form-label">Username</label>
                    <input type="text" name="username" id="username" required class="modern-form-input" value="<?php echo htmlspecialchars($user['username']); ?>">
                </div>
                <div class="modern-form-group">
                    <label for="name" class="modern-form-label">Full Name</label>
                    <input type="text" name="name" id="name" required class="modern-form-input" value="<?php echo htmlspecialchars($user['name']); ?>">
                </div>
                <div class="modern-form-group">
                    <label for="password" class="modern-form-label">New Password <span style="color:var(--neutral-400);font-weight:400;">(leave blank to keep current)</span></label>
                    <input type="password" name="password" id="password" class="modern-form-input">
                </div>
                <div class="modern-form-group">
                    <label for="role" class="modern-form-label">Role</label>
                    <select name="role" id="role" required class="modern-form-input">
                        <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>RZM</option>
                        <option value="admin-manager" <?php echo $user['role'] === 'admin-manager' ? 'selected' : ''; ?>>Admin Manager</option>
                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="region" class="modern-form-label">Region</label>
                    <select name="region" id="region" required class="modern-form-input" onchange="updateZones()">
                        <option value="">Select Region</option>
                        <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $user['region'] === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group" id="zoneGroup">
                    <label for="zone" class="modern-form-label">Zone</label>
                    <select name="zone" id="zone" required class="modern-form-input">
                        <option value="">Select Zone</option>
                    </select>
                </div>
                <div class="eu-check-row">
                    <input type="checkbox" name="active" id="active" <?php echo isset($user['active']) && $user['active'] ? 'checked' : ''; ?>>
                    <label for="active">Active (user can log in)</label>
                </div>
                <div style="padding-top:0.75rem;border-top:1px solid var(--neutral-200);margin-top:0.5rem;">
                    <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;" data-loading-text="Saving…"><i class="fas fa-save modern-button-icon"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.regionsZones = <?php echo json_encode($REGIONS_ZONES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function updateZones() {
    const regionSelect = document.getElementById('region');
    const zoneSelect = document.getElementById('zone');
    const selectedRegion = regionSelect.value;
    const currentZone = '<?php echo $user['zone'] ?? ''; ?>';
    
    // Clear current options
    zoneSelect.innerHTML = '<option value="">Select Zone</option>';
    
    // Add new options if a region is selected
    if (selectedRegion && window.regionsZones?.[selectedRegion]) {
        // Sort zones alphabetically
        const sortedZones = [...window.regionsZones[selectedRegion]].sort((a, b) => 
            a.localeCompare(b, undefined, {sensitivity: 'base'})
        );
        
        sortedZones.forEach(zone => {
            const option = document.createElement('option');
            option.value = zone;
            option.textContent = zone;
            if (zone === currentZone) {
                option.selected = true;
            }
            zoneSelect.appendChild(option);
        });
    }
}

function toggleZoneRequirement() {
    const roleSelect = document.getElementById('role');
    const zoneGroup = document.getElementById('zoneGroup');
    const zoneSelect = document.getElementById('zone');
    const requiresZone = roleSelect.value === 'user';

    zoneGroup.style.display = requiresZone ? 'block' : 'none';
    zoneSelect.required = requiresZone;
    if (!requiresZone) {
        zoneSelect.value = '';
    }
}

function initializeForm() {
    // Don't rebuild the region dropdown - it's already correctly populated by PHP
    // Just ensure the current region is selected
    const regionSelect = document.getElementById('region');
    const currentRegion = '<?php echo $user['region'] ?? ''; ?>';

    // Set the selected region value
    if (currentRegion) {
        regionSelect.value = currentRegion;
    }

    // Initialize zones based on the selected region
    updateZones();
    toggleZoneRequirement();
}

// Add event listener for region changes
document.getElementById('region').addEventListener('change', updateZones);
document.getElementById('role').addEventListener('change', toggleZoneRequirement);
initializeForm();
</script>

<?php
// Close main tag from header
echo '</main></body></html>';
?>
