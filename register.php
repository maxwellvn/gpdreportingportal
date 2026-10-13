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
    $role = ROLE_RZM; // Public sign-up is always RZM; admins assign other roles
    $region = $_POST['region'] ?? '';
    $zone = trim($_POST['zone'] ?? '');

    $zoneRequired = ($role === ROLE_RZM);
    if (empty($username) || empty($name) || empty($password) || empty($region) || ($zoneRequired && empty($zone)) || strlen($password) < 8) {
        $error = strlen($password) < 8 && $password !== '' ? "Password must be at least 8 characters" : "All fields are required";
        // Redirect to prevent form resubmission
        $_SESSION['flash_error'] = $error;
            header('Location: ' . BASE_URL . '/register.php');
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
                $_SESSION['flash_error'] = $error;
            header('Location: ' . BASE_URL . '/register.php');
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
                    $success = "Registration submitted successfully. Your account is currently pending approval. Your KingsChat account has been linked. Please message @gpd-support on KingsChat and request approval for your Reporting Portal account before you try to sign in.";
                } else {
                    $success = "Registration submitted successfully. Your account is currently pending approval. Please message @gpd-support on KingsChat and request approval for your Reporting Portal account before you try to sign in.";
                }
                // Redirect to login page with success message
                $_SESSION['flash_success'] = $success;
                header('Location: ' . BASE_URL . '/login.php');
                exit;
            }
        } catch (Exception $e) {
            error_log('Registration failed: ' . $e->getMessage());
            $error = "Registration failed. Please try again or contact @gpd-support on KingsChat.";
            // Redirect to prevent form resubmission
            $_SESSION['flash_error'] = $error;
            header('Location: ' . BASE_URL . '/register.php');
            exit;
        }
    }
}

$error = $error ?: ($_SESSION['flash_error'] ?? '');
$success = $success ?: ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// Define base URL from config
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$appJsVersion = @filemtime(__DIR__ . '/assets/js/app.js') ?: time();
$themeCssVersion = @filemtime(__DIR__ . '/assets/css/modern-theme.css') ?: time();
$utilCssVersion = @filemtime(__DIR__ . '/assets/css/utilities.css') ?: time();
$zoneCount = is_array($REGIONS_ZONES ?? null) ? array_sum(array_map('count', $REGIONS_ZONES)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - GPD Reports</title>
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/modern-theme.css?v=<?php echo $themeCssVersion; ?>" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/utilities.css?v=<?php echo $utilCssVersion; ?>" rel="stylesheet">

    <!-- Toastify JS - Toast Notifications -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <!-- SweetAlert2 - Beautiful Alerts and Modals -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Base URL and App JS (button/form loading states, page transitions) -->
    <script>window.BASE_URL = '<?php echo $baseUrl; ?>'; window.IS_LOGGED_IN = false; window.CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;</script>
    <script src="<?php echo $baseUrl; ?>/assets/js/app.js?v=<?php echo $appJsVersion; ?>"></script>

    <script>
    // Toast helper used by app.js
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
    </script>
    <style>
        /* page-local brand bits (same as login.php; candidates for modern-theme.css "Brand surfaces") */
        .auth-logo { display: inline-flex; align-items: center; gap: var(--spacing-2); font-weight: 500; color: inherit; text-decoration: none; }
        .auth-logo img { width: 2rem; height: 2rem; object-fit: contain; }
        .auth-aside-meta { color: rgba(255, 255, 255, 0.75); }
        .auth-aside-body h2 { margin-top: var(--spacing-4); }
        .auth-kicker { display: inline-flex; align-items: center; gap: var(--spacing-2); font-family: var(--font-mono); font-size: 0.6875rem; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(255, 255, 255, 0.75); }
        .auth-aside-body p:last-child { margin-top: var(--spacing-5); line-height: 1.6; }
        .auth-aside-meta { font-size: var(--text-xs); }
        .auth-card .page-sub { margin-bottom: var(--spacing-6); }
        .auth-card .field-help { margin-top: 0.375rem; font-size: var(--text-xs); color: var(--neutral-600); }
        .auth-foot { margin-top: var(--spacing-6); font-size: var(--text-sm); color: var(--slate); text-align: center; }
        .auth-foot a { color: var(--ink); font-weight: 500; text-decoration: underline; text-underline-offset: 3px; }
        @media (max-width: 900px) { .auth-aside-meta { display: none; } }
    </style>
</head>
<body class="brand-page">
    <div class="auth-shell">
        <aside class="auth-aside">
            <a class="auth-logo" href="<?php echo $baseUrl; ?>/">
                <img src="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp" alt="" width="32" height="32">
                <span>GPD Reporting Portal</span>
            </a>
            <div class="auth-aside-body">
                <p class="auth-kicker">New zone account</p>
                <h2 class="display">One account per zone, reviewed by the GPD team.</h2>
                <p>After you register, message @gpd-support on KingsChat. Once your account is approved you can submit monthly reports.</p>
            </div>
            <p class="auth-aside-meta"><?php echo (int)$zoneCount; ?> zones &middot; Rhapsody of Realities &middot; Global Partnership Distribution</p>
        </aside>

        <main class="auth-main">
            <div class="auth-card">
                <h1 class="page-title">Request an account</h1>
                <p class="page-sub">It takes a minute. An administrator approves new accounts before first sign-in.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                        <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <div class="field">
                        <label for="name" class="field-label">Full name</label>
                        <input id="name" name="name" type="text" required autocomplete="name" class="modern-form-input">
                    </div>

                    <div class="field">
                        <label for="username" class="field-label">Username</label>
                        <input id="username" name="username" type="text" required autocomplete="username"
                               class="modern-form-input" aria-describedby="username-help">
                        <p class="field-help" id="username-help">You will use this to sign in.</p>
                    </div>

                    <div class="field">
                        <label for="password" class="field-label">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="modern-form-input" minlength="8" aria-describedby="password-help">
                        <p class="field-help" id="password-help">At least 8 characters.</p>
                    </div>

                    <div class="field">
                        <label for="region" class="field-label">Region</label>
                        <select id="region" name="region" required class="modern-form-input">
                            <option value="">Select region</option>
                        </select>
                    </div>

                    <div class="field" id="zoneGroup">
                        <label for="zone" class="field-label">Zone</label>
                        <select id="zone" name="zone" required class="modern-form-input" aria-describedby="zone-help">
                            <option value="">Select zone</option>
                        </select>
                        <p class="field-help" id="zone-help">Choose your region first to see its zones.</p>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block" data-loading-text="Sending request…">
                        Request account
                    </button>
                </form>

                <p class="auth-foot">
                    Already approved? <a href="<?php echo $baseUrl; ?>/login.php">Sign in</a>
                </p>
            </div>
        </main>
    </div>

<script>
// Loaded server-side from the canonical hierarchy API with cache/fallback handling.
window.regionsZones = <?php echo json_encode($REGIONS_ZONES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function updateZones() {
    const regionSelect = document.getElementById('region');
    const zoneSelect = document.getElementById('zone');
    const selectedRegion = regionSelect.value;

    // Clear current options
    $(zoneSelect).empty().append('<option value="">Select zone</option>');

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

function initializeForm() {
    // Get regions from the loaded data
    const regionSelect = document.getElementById('region');
    const regions = Object.keys(window.regionsZones || {});

    // Sort regions case-insensitive
    regions.sort((a, b) => a.localeCompare(b, undefined, {sensitivity: 'base'}));

    // Reset with first option
    $(regionSelect).empty().append('<option value="">Select region</option>');

    // Add sorted regions
    regions.forEach(region => {
        const option = new Option(region, region);
        $(regionSelect).append(option);
    });

    // Initialize zones
    updateZones();
}

$(document).ready(function() {
    initializeForm(); // was never called before, leaving the region list empty
    $('#region').on('change', function() {
        updateZones();
    });
});
</script>
</body>
</html>
