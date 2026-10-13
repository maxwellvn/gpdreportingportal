<?php
require_once 'config/config.php';
require_once 'includes/database.php';
require_once 'includes/kc_link.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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
                    $success = "Registration submitted successfully. Your account is currently pending approval. Your KingsChat account has been linked. Please message @gpd-tech on KingsChat and request approval for your Reporting Portal account before you try to sign in.";
                } else {
                    $success = "Registration submitted successfully. Your account is currently pending approval. Please message @gpd-tech on KingsChat and request approval for your Reporting Portal account before you try to sign in.";
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
$appJsVersion = @filemtime(__DIR__ . '/assets/js/app.js') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - GPD Reports</title>
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@1,500;1,600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/components.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/modern-theme.css" rel="stylesheet">
    
    <!-- Toastify JS - Toast Notifications -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    
    <!-- SweetAlert2 - Beautiful Alerts and Modals -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Base URL and App JS (button/form loading states, page transitions) -->
    <script>window.BASE_URL = '<?php echo $baseUrl; ?>'; window.IS_LOGGED_IN = false;</script>
    <script src="<?php echo $baseUrl; ?>/assets/js/app.js?v=<?php echo $appJsVersion; ?>"></script>

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
    
    </script>
    <style>
        :root {
            --portal-blue: #0087cd;
            --portal-blue-dark: #004467;
            --portal-bg: #e3e3e3;
            --portal-text: rgba(0, 0, 0, 0.9);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--portal-text);
            background: var(--portal-bg);
            overflow-x: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        /* ─── shared hero environment (mirrors index.php) ─── */
        .portal-home {
            position: relative;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100svh;
        }

        /* yellow corner flares — pinned to the viewport so they stay put while the form scrolls */
        .portal-home::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(42% 58% at 19.5% 90%, rgba(255, 245, 0, 0.95) 0%, rgba(255, 245, 0, 0.58) 44%, transparent 76%),
                radial-gradient(30% 42% at 60.5% 104%, rgba(255, 245, 0, 0.9) 0%, rgba(255, 245, 0, 0.52) 44%, transparent 74%),
                radial-gradient(30% 42% at 88% 92%, rgba(255, 245, 0, 0.88) 0%, rgba(255, 245, 0, 0.5) 44%, transparent 74%);
            pointer-events: none;
            z-index: 0;
        }

        /* Ellipse 14 — large soft gray glow over the glass strips */
        .top-glow {
            position: fixed;
            left: 42.8%;
            top: 7.5%;
            width: 143%;
            height: 106%;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: rgba(214, 214, 214, 0.88);
            filter: blur(120px);
            pointer-events: none;
            z-index: 2;
        }

        /* Frame 42 — refracted glass strips */
        .glass-strips {
            position: fixed;
            inset: 0;
            display: flex;
            gap: 0;
            opacity: 0.44;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }

        .glass-strip {
            width: 3.75rem;
            flex: 0 0 auto;
            background:
                linear-gradient(90deg, rgba(217, 217, 217, 0.4) 0%, rgba(120, 120, 120, 0.18) 50%, rgba(53, 52, 52, 0.4) 100%),
                linear-gradient(180deg, rgba(217, 217, 217, 0.8) 0%, rgba(217, 217, 217, 0.4) 71%, rgba(217, 217, 217, 0.1) 100%);
            background-blend-mode: multiply, normal;
            box-shadow: inset 1px 0 rgba(255, 255, 255, 0.6), inset -1px 0 rgba(0, 0, 0, 0.05);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
        }

        .home-content {
            position: relative;
            z-index: 4;
            flex: 0 0 auto;
            width: min(1309px, calc(100% - 3rem));
            margin: 0 auto;
            padding: 23px 0 0;
        }

        /* ─── auth card ─── */
        .auth-shell {
            position: relative;
            z-index: 4;
            flex: 1 1 auto;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            width: 100%;
            padding: 40px 24px 72px;
        }

        .auth-card {
            position: relative;
            width: 100%;
            max-width: 460px;
            padding: 2.5rem 2.25rem;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            background: rgba(255, 255, 255, 0.72);
            -webkit-backdrop-filter: blur(22px);
            backdrop-filter: blur(22px);
            box-shadow: 0 24px 60px rgba(0, 68, 103, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }

        .auth-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            margin-bottom: 1.25rem;
            border-radius: 100px;
            background: rgba(255, 255, 255, 0.7);
            font-size: 13px;
            font-weight: 500;
            color: #000;
        }

        .auth-title {
            margin: 0 0 0.4rem;
            font-size: 2.25rem;
            line-height: 1.04;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: #0b4865;
            background: linear-gradient(180deg, #0e5278 0%, #063248 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .auth-title-accent {
            font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
            font-style: italic;
            font-weight: 500;
            font-size: 1.12em;
            color: #0a8fd1;
            background: linear-gradient(180deg, #0a8fd1 0%, #075286 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .auth-sub {
            margin: 0 0 1.75rem;
            font-size: 0.9375rem;
            font-weight: 500;
            color: rgba(0, 0, 0, 0.65);
        }

        .auth-foot {
            margin-top: 1.25rem;
            font-size: 0.8125rem;
            color: rgba(0, 0, 0, 0.6);
            text-align: center;
        }

        .auth-foot a {
            color: var(--portal-blue);
            font-weight: 600;
        }

        /* pill-rounded fields & buttons — match the landing buttons (radius 50) */
        .auth-card .modern-form-input,
        .auth-card .modern-button {
            border-radius: 50px;
        }

        .auth-card .modern-form-input {
            padding: 0.72rem 1.15rem;
        }

        .auth-card .modern-button {
            padding: 0.72rem 1.25rem;
        }

        @media (max-width: 640px) {
            .glass-strip {
                width: 2.5rem;
            }

            .top-glow {
                filter: blur(80px);
            }

            .home-content {
                width: min(100% - 1.5rem, 560px);
                padding-top: 14px;
            }

            .auth-shell {
                padding: 20px 16px 48px;
            }

            .auth-card {
                padding: 2rem 1.4rem;
                border-radius: 20px;
            }

            .auth-badge {
                font-size: 12.5px;
            }

            .auth-title {
                font-size: 1.9rem;
            }

            .auth-sub {
                font-size: 0.9rem;
                margin-bottom: 1.5rem;
            }

            /* 16px inputs stop iOS from auto-zooming on focus */
            .auth-card .modern-form-input {
                font-size: 16px;
                padding: 0.72rem 1.1rem;
            }

            .auth-card .modern-button {
                font-size: 0.95rem;
                padding: 0.8rem 1.25rem;
            }
        }

        @media (max-width: 380px) {
            .auth-card {
                padding: 1.75rem 1.15rem;
            }

            .auth-badge {
                font-size: 12px;
                padding: 7px 12px;
            }

            .auth-title {
                font-size: 1.7rem;
            }
        }
    </style>
</head>
<body>
    <main class="portal-home">
        <div class="glass-strips" aria-hidden="true">
            <?php for ($i = 0; $i < 48; $i++): ?>
                <span class="glass-strip"></span>
            <?php endfor; ?>
        </div>

        <div class="top-glow" aria-hidden="true"></div>

        <div class="home-content">
            <?php include __DIR__ . '/layouts/landing-header.php'; ?>
        </div>

        <section class="auth-shell">
            <div class="auth-card">
                <span class="auth-badge">Create your zonal reporting account</span>
                <h1 class="auth-title">Get <span class="auth-title-accent">started</span></h1>
                <p class="auth-sub">Fill in the details below to register.</p>

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

        <form class="space-y-4" method="POST" novalidate>
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
                <select id="role" name="role" class="modern-form-input" required>
                    <option value="user">RZM</option>
                    <option value="admin-manager">Admin Manager</option>
                </select>
            </div>
            
            <div class="modern-form-group">
                <label for="region" class="modern-form-label">Region</label>
                <select id="region" name="region" required
                        class="modern-form-input">
                    <option value="">Select Region</option>
                </select>
            </div>
            
            <div class="modern-form-group" id="zoneGroup">
                <label for="zone" class="modern-form-label">Zone</label>
                <select id="zone" name="zone" required
                        class="modern-form-input">
                    <option value="">Select Zone</option>
                </select>
            </div>

            <button type="submit" class="modern-button modern-button-primary w-full" style="justify-content:center;" data-loading-text="Creating Account…">
                Create Account
            </button>
            <p class="auth-foot">
                Already have an account? <a href="<?php echo $baseUrl; ?>/login.php">Sign in</a>
            </p>
            
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
        </section>
    </main>

<script>
// Loaded server-side from the canonical hierarchy API with cache/fallback handling.
window.regionsZones = <?php echo json_encode($REGIONS_ZONES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function updateZones() {
    const regionSelect = document.getElementById('region');
    const zoneSelect = document.getElementById('zone');
    const selectedRegion = regionSelect.value;
    
    // Clear current options
    $(zoneSelect).empty().append('<option value="">Select Zone</option>');
    
    // Add new options if a region is selected
    if (selectedRegion && window.regionsZones?.[selectedRegion]) {
        // Sort zones alphabetically
        const sortedZones = [...window.regionsZones[selectedRegion]].sort((a, b) => 
            a.localeCompare(b, undefined, {sensitivity: 'base'})
        );
        
        sortedZones.forEach(zone => {
            const option = new Option(zone, zone);
            $(zoneSelect).append(option);
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
    // Get regions from the loaded data
    const regionSelect = document.getElementById('region');
    const regions = Object.keys(window.regionsZones);
    
    // Sort regions case-insensitive
    regions.sort((a, b) => a.localeCompare(b, undefined, {sensitivity: 'base'}));
    
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
    
}

// Add event listener for region changes
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
