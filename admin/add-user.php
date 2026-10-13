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
    $role = $_POST['role'] ?? 'user';
    $region = $_POST['region'] ?? '';
    $zone = trim($_POST['zone'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    $zoneRequired = ($role === ROLE_USER);

    if (empty($username) || empty($name) || empty($password) || empty($region) || ($zoneRequired && empty($zone))) {
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
.au-page { max-width: 560px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.au-topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; }
.au-topbar h1 { font-size:1.125rem; font-weight:700; color:var(--neutral-900); margin:0; }
.au-card { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; }
.au-card-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); }
.au-card-head h2 { font-size:0.875rem; font-weight:600; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.04em; }
.au-card-body { padding:1.25rem; }
.au-check-row { display:flex; align-items:center; gap:0.5rem; margin-top:0.25rem; }
.au-check-row input { width:1rem; height:1rem; accent-color:var(--primary-600); }
.au-check-row label { font-size:0.85rem; color:var(--neutral-700); }
</style>

<div class="au-page">
    <div class="au-topbar">
        <h1>Add New User</h1>
        <a href="users.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-arrow-left modern-button-icon"></i> Back</a>
    </div>

    <?php if ($error): ?>
    <div style="background:var(--danger-50);border:1px solid var(--danger-200);color:var(--danger-700);padding:0.75rem 1rem;border-radius:var(--radius-md);margin-bottom:1rem;font-size:0.85rem;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div style="background:var(--success-50);border:1px solid var(--success-200);color:var(--success-700);padding:0.75rem 1rem;border-radius:var(--radius-md);margin-bottom:1rem;font-size:0.85rem;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="au-card">
        <div class="au-card-head"><h2>User Details</h2></div>
        <div class="au-card-body">
            <form method="POST" class="space-y-4">
                <div class="modern-form-group">
                    <label for="username" class="modern-form-label">Username</label>
                    <input type="text" name="username" id="username" required class="modern-form-input">
                </div>
                <div class="modern-form-group">
                    <label for="name" class="modern-form-label">Full Name</label>
                    <input type="text" name="name" id="name" required class="modern-form-input">
                </div>
                <div class="modern-form-group">
                    <label for="password" class="modern-form-label">Password</label>
                    <input type="password" name="password" id="password" required class="modern-form-input">
                </div>
                <div class="modern-form-group">
                    <label for="role" class="modern-form-label">Role</label>
                    <select name="role" id="role" required class="modern-form-input">
                        <option value="user">RZM</option>
                        <option value="admin-manager">Admin Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="region" class="modern-form-label">Region</label>
                    <select name="region" id="region" required class="modern-form-input">
                        <option value="">Select Region</option>
                        <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>"><?php echo htmlspecialchars($region); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group" id="zoneGroup">
                    <label for="zone" class="modern-form-label">Zone</label>
                    <select name="zone" id="zone" required class="modern-form-input">
                        <option value="">Select Zone</option>
                    </select>
                </div>
                <div class="au-check-row">
                    <input type="checkbox" name="active" id="active" checked>
                    <label for="active">Active (user can log in immediately)</label>
                </div>
                <div style="padding-top:0.75rem;border-top:1px solid var(--neutral-200);margin-top:0.5rem;">
                    <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;" data-loading-text="Adding User…"><i class="fas fa-user-plus modern-button-icon"></i> Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Load the regions and zones data from the JSON file
const regionsZones = <?php echo json_encode($REGIONS_ZONES); ?>;

function updateZones() {
    const regionSelect = document.getElementById('region');
    const zoneSelect = document.getElementById('zone');
    const selectedRegion = regionSelect.value;
    
    // Clear current options
    zoneSelect.innerHTML = '<option value="">Select Zone</option>';
    
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
    const requiresZone = role === 'user';
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

<?php
// Close main tag from header
echo '</main></body></html>';
?>
