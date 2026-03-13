<?php
require_once 'config/config.php';
require_once 'includes/database.php';
require_once 'includes/kc_link.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $name = $_POST['name'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? ROLE_USER;
    $region = $_POST['region'] ?? '';
    $zone = trim($_POST['zone'] ?? '');

    $allowedRoles = [ROLE_USER, ROLE_ADMIN_MANAGER];
    if (!in_array($role, $allowedRoles, true)) {
        $role = ROLE_USER;
    }

    $zoneRequired = ($role === ROLE_USER);
    if (empty($username) || empty($name) || empty($password) || empty($region) || ($zoneRequired && empty($zone))) {
        $error = $zoneRequired ? "All fields are required" : "Username, name, password, and region are required";
        // Redirect to prevent form resubmission
        header('Location: ' . BASE_URL . '/register.php?error=' . urlencode($error));
        exit;
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Check if username exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($stmt->fetch()) {
                $error = "Username already exists";
                // Redirect to prevent form resubmission
                header('Location: ' . BASE_URL . '/register.php?error=' . urlencode($error));
                exit;
            } else {
                // Create new user in pending state; admin approves later
                $stmt = $conn->prepare("INSERT INTO users (username, name, password, region, zone, role, active) VALUES (?, ?, ?, ?, ?, ?, 0)");
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $zoneValue = $zoneRequired ? $zone : null;
                $stmt->execute([$username, $name, $hashedPassword, $region, $zoneValue, $role]);
                
                // Link pending KingsChat ID if present
                $newUserId = (int)$conn->lastInsertId();
                if (!empty($_SESSION['pending_kc_user_id']) && $newUserId > 0) {
                    kc_link_user_to_kc($newUserId, (string)$_SESSION['pending_kc_user_id']);
                    unset($_SESSION['pending_kc_user_id']);
                    unset($_SESSION['pending_kc_name']);
                    $success = "Registration submitted for approval. KingsChat linked.";
                } else {
                    $success = "Registration submitted for approval.";
                }
                // Redirect to login page with success message
                header('Location: ' . BASE_URL . '/login.php?success=' . urlencode($success));
                exit;
            }
        } catch (Exception $e) {
            $error = "Registration failed: " . $e->getMessage();
            // Redirect to prevent form resubmission
            header('Location: ' . BASE_URL . '/register.php?error=' . urlencode($error));
            exit;
        }
    }
}

// Get error and success from URL if they exist
if (isset($_GET['error']) && empty($error)) {
    $error = $_GET['error'];
}
if (isset($_GET['success']) && empty($success)) {
    $success = $_GET['success'];
}

// Define base URL from config
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - GPD Reports</title>
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo.webp">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/components.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/modern-theme.css" rel="stylesheet">
    
    <!-- Toastify JS - Toast Notifications -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    
    <!-- SweetAlert2 - Beautiful Alerts and Modals -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- jQuery and Select2 for enhanced dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="<?php echo $baseUrl; ?>/assets/css/select2-custom.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
    // Toast notification function
    function showToast(message, type = 'success', duration = 3000) {
        Toastify({
            text: message,
            duration: duration,
            close: true,
            gravity: "top",
            position: "right",
            className: `toast-${type}`,
            stopOnFocus: true
        }).showToast();
    }

    // SweetAlert function
    function showAlert(title, text, icon = 'info') {
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            confirmButtonColor: '#3B82F6'
        });
    }
    
    // Initialize Select2
    $(document).ready(function() {
        // Initialize Select2 after the form is populated
        setTimeout(function() {
            $('.select2').select2({
                theme: 'default',
                width: '100%',
                placeholder: 'Select an option',
                allowClear: true,
                minimumResultsForSearch: 6
            });
        }, 500); // Small delay to ensure form is populated first
    });
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-image: url('<?php echo $baseUrl; ?>/assets/images/background.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
        }
        .auth-overlay {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 1rem;
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full modern-card p-8">
        <div class="text-center mb-8">
            <div class="flex justify-center">
                <img src="<?php echo $baseUrl; ?>/assets/images/logo.webp" alt="GPD Reports Logo" class="h-16 w-16">
            </div>
            <h1 class="mt-4 text-2xl font-bold text-gray-800">GPD Reporting Portal</h1>
            <h2 class="mt-2 text-3xl font-extrabold text-gray-900 mb-4">Create your account</h2>
            <a href="login.php" class="modern-button modern-button-secondary w-full flex items-center justify-center">
                <i class="fas fa-sign-in-alt mr-2"></i> Sign in to your account
            </a>
        </div>

        <?php if ($error): ?>
            <script>
                // Show error toast when page loads
                window.onload = function() {
                    showToast('<?php echo htmlspecialchars($error); ?>', 'error');
                };
            </script>
        <?php endif; ?>

        <?php if ($success): ?>
            <script>
                // Show success alert when page loads
                window.onload = function() {
                    showAlert('Success', '<?php echo htmlspecialchars($success); ?>', 'success');
                };
            </script>
        <?php endif; ?>

        <form class="space-y-6 needs-validation" method="POST" novalidate>
            <div class="modern-form-group">
                <label for="username" class="modern-form-label">Username</label>
                <input id="username" name="username" type="text" required 
                       class="modern-form-input" 
                       placeholder="Enter your username">
            </div>
            
            <div class="modern-form-group">
                <label for="name" class="modern-form-label">Full Name</label>
                <input id="name" name="name" type="text" required 
                       class="modern-form-input" 
                       placeholder="Enter your full name">
            </div>
            
            <div class="modern-form-group">
                <label for="password" class="modern-form-label">Password</label>
                <input id="password" name="password" type="password" required 
                       class="modern-form-input" 
                       placeholder="Create a password">
            </div>

            <div class="modern-form-group">
                <label for="role" class="modern-form-label">Role</label>
                <select id="role" name="role" class="modern-form-input select2" required>
                    <option value="user">User</option>
                    <option value="admin-manager">Admin Manager</option>
                </select>
            </div>
            
            <div class="modern-form-group">
                <label for="region" class="modern-form-label">Region</label>
                <select id="region" name="region" required
                        class="modern-form-input select2">
                    <option value="">Select Region</option>
                </select>
            </div>
            
            <div class="modern-form-group" id="zoneGroup">
                <label for="zone" class="modern-form-label">Zone</label>
                <select id="zone" name="zone" required
                        class="modern-form-input select2">
                    <option value="">Select Zone</option>
                </select>
            </div>

            <button type="submit" class="modern-button modern-button-primary w-full">
                Create Account
            </button>
            
            <!-- KingsChat register option commented out
            <div class="relative flex items-center justify-center mt-6 mb-6">
                <div class="flex-grow border-t border-gray-300"></div>
                <span class="flex-shrink mx-4 text-gray-600 text-sm">or register with</span>
                <div class="flex-grow border-t border-gray-300"></div>
            </div>
            
            <div>
                <a href="<?php echo $baseUrl; ?>/kingschat/register.php" class="modern-button modern-button-secondary w-full flex items-center justify-center">
                    <img src="https://kingschat.online/svg/logo-icon.svg" alt="KingsChat" class="h-5 w-5 mr-2">
                    Register with KingsChat
                </a>
            </div>
            -->
        </form>
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
    
    // Debug log
    console.log('Selected Region:', selectedRegion);
    console.log('Available Zones for Region:', window.regionsZones?.[selectedRegion]);
    
    // Clear current options
    $(zoneSelect).empty().append('<option value="">Select Zone</option>');
    
    // Add new options if a region is selected
    if (selectedRegion && window.regionsZones?.[selectedRegion]) {
        // Sort zones alphabetically
        const sortedZones = [...window.regionsZones[selectedRegion]].sort((a, b) => 
            a.localeCompare(b, undefined, {sensitivity: 'base'})
        );
        
        // Debug log
        console.log('Sorted Zones:', sortedZones);
        
        sortedZones.forEach(zone => {
            const option = new Option(zone, zone);
            $(zoneSelect).append(option);
        });
        
        // Trigger change event to refresh Select2
        $(zoneSelect).trigger('change');
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
        $(zoneSelect).val('').trigger('change');
    }
}

function initializeForm() {
    // Get regions from the loaded data
    const regionSelect = document.getElementById('region');
    const regions = Object.keys(window.regionsZones);
    
    // Sort regions case-insensitive
    regions.sort((a, b) => a.localeCompare(b, undefined, {sensitivity: 'base'}));
    
    // Debug log
    console.log('Sorted Regions:', regions);
    
    // Reset with first option
    $(regionSelect).empty().append('<option value="">Select Region</option>');
    
    // Add sorted regions
    regions.forEach(region => {
        const option = new Option(region, region);
        $(regionSelect).append(option);
    });
    
    // Initialize zones
    updateZones();
    toggleZoneRequirement();
    
    // Initialize Select2 after populating the dropdowns
    setTimeout(function() {
        $('#role, #region, #zone').select2({
            theme: 'default',
            width: '100%',
            placeholder: 'Select an option',
            allowClear: true,
            minimumResultsForSearch: 6
        });
    }, 100);
}

// Add event listener for region changes with Select2
$(document).ready(function() {
    $('#region').on('change', function() {
        updateZones();
    });
    $('#role').on('change', function() {
        toggleZoneRequirement();
    });
});
</script>
</body>
</html>
