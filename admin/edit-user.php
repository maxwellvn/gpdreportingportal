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
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Include header
require_once '../layouts/header.php';
?>

<!-- Edit User Form -->
<div class="max-w-2xl mx-auto">
    <div class="bg-white shadow rounded-lg p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-semibold text-gray-900">Edit User: <?php echo htmlspecialchars($user['username']); ?></h2>
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
                       value="<?php echo htmlspecialchars($user['username']); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" name="name" id="name" required
                       value="<?php echo htmlspecialchars($user['name']); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">New Password (leave blank to keep current)</label>
                <input type="password" name="password" id="password"
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                <select name="role" id="role" required
                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="admin-manager" <?php echo $user['role'] === 'admin-manager' ? 'selected' : ''; ?>>Admin Manager</option>
                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>

            <div>
                <label for="region" class="block text-sm font-medium text-gray-700">Region</label>
                <select name="region" id="region" required onchange="updateZones()"
                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    <option value="">Select Region</option>
                    <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                        <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $user['region'] === $region ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region); ?>
                        </option>
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
                <input type="checkbox" name="active" id="active" 
                       <?php echo isset($user['active']) && $user['active'] ? 'checked' : ''; ?>
                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <label for="active" class="ml-2 block text-sm text-gray-900">Active</label>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-save mr-2"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Load the regions and zones data from the JSON file
fetch('<?php echo $baseUrl; ?>/config/zones.json')
    .then(response => response.json())
    .then(data => {
        window.regionsZones = data;
        console.log('Loaded Regions:', Object.keys(data));
        initializeForm();
    })
    .catch(error => {
        console.error('Error loading zones:', error);
    });

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
</script>

<?php
// Close main tag from header
echo '</main></body></html>';
?>
