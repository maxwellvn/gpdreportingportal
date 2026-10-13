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
        $role = $_POST['role'] ?? ROLE_RZM;
        $zone = trim($_POST['zone'] ?? '');
        $zoneRequired = ($role === ROLE_RZM);
        if (!in_array($role, [ROLE_ADMIN, ROLE_ADMIN_MANAGER, ROLE_RZM], true)) {
            throw new Exception('Invalid role');
        }

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
.uf-check { display: flex; align-items: center; gap: var(--spacing-2); grid-column: 1 / -1; margin-bottom: var(--spacing-5); font-size: var(--text-sm); color: var(--neutral-800); }
</style>

<nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">People</a><span class="sep" aria-hidden="true">›</span><span aria-current="page">Edit</span></nav>
<div class="page-head">
    <div>
        <div class="page-eyebrow"><?php echo htmlspecialchars($user['username']); ?></div>
        <h1 class="page-title"><?php echo htmlspecialchars(($user['name'] ?? '') !== '' ? $user['name'] : $user['username']); ?></h1>
    </div>
</div>

<div class="panel" style="max-width:48rem;">
    <div class="panel-body">
        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><div><?php echo htmlspecialchars($error); ?></div></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i><div><?php echo htmlspecialchars($success); ?></div></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-grid">
                <div class="field">
                    <label for="name" class="field-label">Full name</label>
                    <input type="text" name="name" id="name" required class="input" value="<?php echo htmlspecialchars((string)$user['name']); ?>">
                </div>
                <div class="field">
                    <label for="username" class="field-label">Username</label>
                    <input type="text" name="username" id="username" required class="input" value="<?php echo htmlspecialchars($user['username']); ?>">
                </div>
                <div class="field">
                    <label for="password" class="field-label">New password <span class="field-optional">(optional)</span></label>
                    <input type="password" name="password" id="password" class="input" autocomplete="new-password" aria-describedby="password-help">
                    <p class="field-help" id="password-help">Leave blank to keep the current password.</p>
                </div>
                <div class="field">
                    <label for="role" class="field-label">Role</label>
                    <select name="role" id="role" required class="input">
                        <option value="rzm" <?php echo $user['role'] === ROLE_RZM ? 'selected' : ''; ?>>RZM</option>
                        <option value="admin-manager" <?php echo $user['role'] === 'admin-manager' ? 'selected' : ''; ?>>Admin Manager</option>
                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                    </select>
                </div>
                <div class="field">
                    <label for="region" class="field-label">Region</label>
                    <select name="region" id="region" required class="input" onchange="updateZones()">
                        <option value="">Select region</option>
                        <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $user['region'] === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" id="zoneGroup">
                    <label for="zone" class="field-label">Zone</label>
                    <select name="zone" id="zone" required class="input">
                        <option value="">Select zone</option>
                    </select>
                </div>
                <label class="uf-check" for="active">
                    <input type="checkbox" name="active" id="active" <?php echo isset($user['active']) && $user['active'] ? 'checked' : ''; ?>>
                    Active (can sign in)
                </label>
            </div>
            <div class="form-actions">
                <a href="users.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary" data-loading-text="Saving…">Save changes</button>
            </div>
        </form>
    </div>
</div>

<script>
window.regionsZones = <?php echo json_encode($REGIONS_ZONES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function updateZones() {
    const regionSelect = document.getElementById('region');
    const zoneSelect = document.getElementById('zone');
    const selectedRegion = regionSelect.value;
    const currentZone = <?php echo json_encode((string)($user['zone'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    // Clear current options
    zoneSelect.innerHTML = '<option value="">Select zone</option>';
    
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
    const requiresZone = roleSelect.value === 'rzm';

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
    const currentRegion = <?php echo json_encode((string)($user['region'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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

<?php require_once '../layouts/footer.php'; ?>
