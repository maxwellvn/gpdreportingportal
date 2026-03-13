<?php
require_once 'config/config.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'includes/auth.php';
    $auth = new Auth();
    
    try {
        if ($auth->login($_POST['username'], $_POST['password'])) {
            // Prevent session fixation
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            // Set session variables
            $_SESSION['user_id'] = $auth->getUserId();
            $_SESSION['last_activity'] = time();

            // Set flag to show KingsChat link prompt on first page load
            $_SESSION['show_kc_link_prompt'] = true;

            // Get user data
            $user = $auth->getUser($auth->getUserId());
            // Store only needed, sanitized fields in session
            if ($user) {
                $_SESSION['user_data'] = [
                    'id' => (int)$user['id'],
                    'username' => htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'),
                    'name' => htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'),
                    'role' => $user['role'],
                    'region' => htmlspecialchars((string)$user['region'], ENT_QUOTES, 'UTF-8'),
                    'zone' => htmlspecialchars((string)$user['zone'], ENT_QUOTES, 'UTF-8')
                ];
            }
            
            // Redirect all users to main dashboard
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password';
            // Redirect to the same page to prevent form resubmission
            header('Location: ' . BASE_URL . '/login.php?error=' . urlencode($error));
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        // Redirect to the same page to prevent form resubmission
        header('Location: ' . BASE_URL . '/login.php?error=' . urlencode($error));
        exit;
    }
}

// Get error and success from URL if they exist
if (isset($_GET['error']) && empty($error)) {
    $error = $_GET['error'];
}
$success = '';
if (isset($_GET['success'])) {
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
    <title>Login - GPD Reports</title>
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
    
    <!-- Base URL and App JS (for kcOpenPopup) -->
    <script>window.BASE_URL = '<?php echo $baseUrl; ?>'; window.IS_LOGGED_IN = false;</script>
    <script src="<?php echo $baseUrl; ?>/assets/js/app.js"></script>
    
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
            <h2 class="mt-2 text-3xl font-extrabold text-gray-900 mb-4">Sign in to your account</h2>
            <a href="register.php" class="modern-button modern-button-secondary w-full flex items-center justify-center">
                <i class="fas fa-user-plus mr-2"></i> Create a new account
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
                <label for="password" class="modern-form-label">Password</label>
                <input id="password" name="password" type="password" required 
                       class="modern-form-input" 
                       placeholder="Enter your password">
            </div>

            <button type="submit" class="modern-button modern-button-primary w-full">
                Sign in
            </button>
        </form>

        <div class="relative flex items-center justify-center mt-6 mb-6">
            <div class="flex-grow border-t border-gray-300"></div>
            <span class="flex-shrink mx-4 text-gray-600 text-sm">or</span>
            <div class="flex-grow border-t border-gray-300"></div>
        </div>

        <div>
            <button type="button" onclick="kcOpenPopup({ mode: 'login', returnTo: '<?php echo $baseUrl; ?>/dashboard.php' })" class="modern-button modern-button-secondary w-full flex items-center justify-center">
                <img src="https://kingschat.online/svg/logo-icon.svg" alt="KingsChat" class="h-5 w-5 mr-2">
                Continue with KingsChat
            </button>
        </div>
    </div>
</body>
</html>
