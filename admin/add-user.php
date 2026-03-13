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

<div class="max-w-2xl mx-auto">
    <div class="bg-white shadow rounded-lg p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-semibold text-gray-900">Add New User</h2>
            <a href="users.php" class="text-blue-600 hover:text-blue-700">
                <i class="fas fa-arrow-left mr-1"></i> Back to Users
            </a>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username" required
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" name="name" id="name" required
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" id="password" required
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                <select name="role" id="role" required
                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="user">User</option>
                    <option value="admin-manager">Admin Manager</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div>
                <label for="region" class="block text-sm font-medium text-gray-700">Region</label>
                <select name="region" id="region" required
                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="">Select Region</option>
                    <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                        <option value="<?php echo htmlspecialchars($region); ?>"><?php echo htmlspecialchars($region); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="zoneGroup">
                <label for="zone" class="block text-sm font-medium text-gray-700">Zone</label>
                <select name="zone" id="zone" required
                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="">Select Zone</option>
                </select>
            </div>

            <div class="flex items-center">
                <input type="checkbox" name="active" id="active" checked
                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <label for="active" class="ml-2 block text-sm text-gray-900">Active</label>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-user-plus mr-2"></i>
                    Add User
                </button>
            </div>
        </form>
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
