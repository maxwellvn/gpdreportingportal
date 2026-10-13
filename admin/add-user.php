<?php
require_once '../config/config.php';
require_once '../includes/auth.php';

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

// Check if user is admin
if (!isset($currentUser['role']) || $currentUser['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $name = $_POST['name'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? ROLE_RZM;
    $region = $_POST['region'] ?? '';
    $zone = trim($_POST['zone'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    $zoneRequired = ($role === ROLE_RZM);

    if (!in_array($role, [ROLE_ADMIN, ROLE_ADMIN_MANAGER, ROLE_RZM], true)) {
        $error = "Invalid role";
    } elseif (empty($username) || empty($name) || empty($password) || empty($region) || ($zoneRequired && empty($zone))) {
        $error = $zoneRequired ? "All fields are required" : "Username, name, password and region are required";
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Check if username exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($stmt->fetch()) {
                $error = "Username already exists";
            } else {
                // Create new user
                $stmt = $conn->prepare("INSERT INTO users (username, name, password, role, region, zone, active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $zoneValue = $zoneRequired ? $zone : null;
                $stmt->execute([$username, $name, $hashedPassword, $role, $region, $zoneValue, $active]);
                
                $success = "User added successfully!";
            }
        } catch (Exception $e) {
            $error = "Failed to add user: " . $e->getMessage();
        }
    }
}

// Include header
require_once '../layouts/header.php';
?>

<style>
.uf-check { display: flex; align-items: center; gap: var(--spacing-2); grid-column: 1 / -1; margin-bottom: var(--spacing-5); font-size: var(--text-sm); color: var(--neutral-800); }
</style>

<nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">People</a><span class="sep" aria-hidden="true">›</span><span aria-current="page">Add</span></nav>
<div class="page-head">
    <div>
        <h1 class="page-title">Add person</h1>
        <p class="page-sub">Create an account. RZMs also need a zone.</p>
    </div>
</div>

<div class="panel" style="max-width:48rem;">
    <div class="panel-body">
        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><div><?php echo htmlspecialchars($error); ?></div></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i><div><?php echo htmlspecialchars($success); ?> <a href="users.php" class="link">Back to People</a></div></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-grid">
                <div class="field">
                    <label for="name" class="field-label">Full name</label>
                    <input type="text" name="name" id="name" required class="input" autocomplete="off" value="<?php echo $error ? htmlspecialchars($name ?? '') : ''; ?>">
                </div>
                <div class="field">
                    <label for="username" class="field-label">Username</label>
                    <input type="text" name="username" id="username" required class="input" autocomplete="off" value="<?php echo $error ? htmlspecialchars($username ?? '') : ''; ?>">
                </div>
                <div class="field">
                    <label for="password" class="field-label">Password</label>
                    <input type="password" name="password" id="password" required class="input" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="role" class="field-label">Role</label>
                    <select name="role" id="role" required class="input">
                        <option value="rzm">RZM</option>
                        <option value="admin-manager"<?php echo ($error && ($role ?? '') === 'admin-manager') ? ' selected' : ''; ?>>Admin Manager</option>
                        <option value="admin"<?php echo ($error && ($role ?? '') === 'admin') ? ' selected' : ''; ?>>Administrator</option>
                    </select>
                </div>
                <div class="field">
                    <label for="region" class="field-label">Region</label>
                    <select name="region" id="region" required class="input">
                        <option value="">Select region</option>
                        <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>"><?php echo htmlspecialchars($region); ?></option>
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
                    <input type="checkbox" name="active" id="active" checked>
                    Active (can sign in immediately)
                </label>
            </div>
            <div class="form-actions">
                <a href="users.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary" data-loading-text="Adding…">Add person</button>
            </div>
        </form>
    </div>
</div>

<script>
// Load the regions and zones data from the JSON file
const regionsZones = <?php echo json_encode($REGIONS_ZONES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function updateZones() {
    const regionSelect = document.getElementById('region');
    const zoneSelect = document.getElementById('zone');
    const selectedRegion = regionSelect.value;
    
    // Clear current options
    zoneSelect.innerHTML = '<option value="">Select zone</option>';
    
    // Add new options if a region is selected
    if (selectedRegion && regionsZones[selectedRegion]) {
        // Sort zones alphabetically
        const sortedZones = [...regionsZones[selectedRegion]].sort((a, b) => 
            a.localeCompare(b, undefined, {sensitivity: 'base'})
        );
        
        sortedZones.forEach(zone => {
            const option = document.createElement('option');
            option.value = zone;
            option.textContent = zone;
            zoneSelect.appendChild(option);
        });
    }
}

function toggleZoneRequirement() {
    const role = document.getElementById('role').value;
    const zoneGroup = document.getElementById('zoneGroup');
    const zone = document.getElementById('zone');
    const requiresZone = role === 'rzm';
    zoneGroup.style.display = requiresZone ? 'block' : 'none';
    zone.required = requiresZone;
    if (!requiresZone) {
        zone.value = '';
    }
}

// Add event listener for region changes
document.getElementById('region').addEventListener('change', updateZones);
document.getElementById('role').addEventListener('change', toggleZoneRequirement);

// Initialize zones on page load
document.addEventListener('DOMContentLoaded', function() {
    updateZones();
    toggleZoneRequirement();
});
</script>

<?php require_once '../layouts/footer.php'; ?>
