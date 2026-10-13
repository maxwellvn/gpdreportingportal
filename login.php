<?php
require_once 'config/config.php';

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
            $_SESSION['flash_error'] = 'Invalid username or password';
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    } catch (AuthException $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Sign-in is temporarily unavailable. Please try again shortly.';
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

// One-time messages travel in the session, never the URL (prevents crafted-link message spoofing)
$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// Define base URL from config
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$appJsVersion = @filemtime(__DIR__ . '/assets/js/app.js') ?: time();
$themeCssVersion = @filemtime(__DIR__ . '/assets/css/modern-theme.css') ?: time();
$utilCssVersion = @filemtime(__DIR__ . '/assets/css/utilities.css') ?: time();

// Pending-approval state: show the status tracker instead of the generic error
$isPending = $error !== '' && stripos($error, 'awaiting approval') !== false;
$kcLinked = $success !== '' && stripos($success, 'KingsChat account has been linked') !== false;
$zoneCount = is_array($REGIONS_ZONES ?? null) ? array_sum(array_map('count', $REGIONS_ZONES)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - GPD Reports</title>
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

    <!-- Base URL and App JS (for kcOpenPopup) -->
    <script>window.BASE_URL = '<?php echo $baseUrl; ?>'; window.IS_LOGGED_IN = false; window.CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;</script>
    <script src="<?php echo $baseUrl; ?>/assets/js/app.js?v=<?php echo $appJsVersion; ?>"></script>

    <script>
    // Toast helper used by app.js (KingsChat popup errors)
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
        /* page-local brand bits (candidates for modern-theme.css "Brand surfaces") */
        .auth-logo { display: inline-flex; align-items: center; gap: var(--spacing-2); font-weight: 500; color: inherit; text-decoration: none; }
        .auth-logo img { width: 2rem; height: 2rem; object-fit: contain; }
        .auth-aside-meta { color: rgba(255, 255, 255, 0.75); }
        .auth-aside-body h2 { margin-top: var(--spacing-4); }
        .auth-kicker { display: inline-flex; align-items: center; gap: var(--spacing-2); font-family: var(--font-mono); font-size: 0.6875rem; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(255, 255, 255, 0.75); }
        .auth-kicker::before { content: ""; width: 1.5rem; height: 1px; background: var(--brand-gold); }
        .auth-aside-body p:last-child { margin-top: var(--spacing-5); line-height: 1.6; }
        .auth-aside-meta { font-size: var(--text-xs); }
        .auth-card .page-sub { margin-bottom: var(--spacing-6); }
        .auth-kc img { width: 1.25rem; height: 1.25rem; }
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
                <p class="auth-kicker">Monthly reporting</p>
                <h2 class="display">Every zone&rsquo;s monthly report, in one place.</h2>
                <p>Submit the monthly GPD report, see what is still due, and follow your zone across the year.</p>
            </div>
            <p class="auth-aside-meta"><?php echo (int)$zoneCount; ?> zones &middot; Rhapsody of Realities &middot; Global Partnership Distribution</p>
        </aside>

        <main class="auth-main">
            <div class="auth-card">
                <?php if ($isPending || $success): ?>
                    <h1 class="page-title"><?php echo $success ? 'Request received' : 'Awaiting approval'; ?></h1>
                    <p class="page-sub"><?php echo $success
                        ? 'Your account request is with the GPD team.'
                        : 'Your account exists but has not been activated yet.'; ?></p>

                    <ol class="tracker" aria-label="Account status">
                        <li class="tracker-step done">
                            <span class="tracker-dot" aria-hidden="true"><i class="fas fa-check"></i></span>
                            <div>
                                <div class="tracker-title">Registration received</div>
                                <div class="tracker-text"><?php echo $kcLinked
                                    ? 'Your details and KingsChat account are on file.'
                                    : 'Your details are on file.'; ?></div>
                            </div>
                        </li>
                        <li class="tracker-step current" aria-current="step">
                            <span class="tracker-dot" aria-hidden="true">2</span>
                            <div>
                                <div class="tracker-title">Admin review</div>
                                <div class="tracker-text">Message <strong>@gpd-support</strong> on KingsChat and ask for your Reporting Portal account to be approved.</div>
                            </div>
                        </li>
                        <li class="tracker-step">
                            <span class="tracker-dot" aria-hidden="true">3</span>
                            <div>
                                <div class="tracker-title">Account active</div>
                                <div class="tracker-text">Sign in with KingsChat or your username.</div>
                            </div>
                        </li>
                    </ol>

                    <a class="btn btn-secondary btn-block" href="<?php echo $baseUrl; ?>/login.php">Back to sign in</a>
                <?php else: ?>
                    <h1 class="page-title">Welcome back</h1>
                    <p class="page-sub">Sign in to submit and review your zone&rsquo;s reports.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                            <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endif; ?>

                    <button type="button" onclick="kcLogin(this)" class="btn btn-brand btn-lg btn-block auth-kc">
                        <img src="<?php echo $baseUrl; ?>/assets/images/kingschat-logo.png" alt="" width="20" height="20">
                        Sign in with KingsChat
                    </button>

                    <div class="auth-divider">or use your username</div>

                    <form method="POST" novalidate>
                        <div class="field">
                            <label for="username" class="field-label">Username</label>
                            <input id="username" name="username" type="text" required autocomplete="username"
                                   class="modern-form-input"<?php echo $error ? ' aria-invalid="true"' : ''; ?>>
                        </div>
                        <div class="field">
                            <label for="password" class="field-label">Password</label>
                            <input id="password" name="password" type="password" required autocomplete="current-password"
                                   class="modern-form-input"<?php echo $error ? ' aria-invalid="true"' : ''; ?>>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-lg btn-block" data-loading-text="Signing in…">
                            Sign in
                        </button>
                    </form>

                    <p class="auth-foot">
                        New to the portal? <a href="<?php echo $baseUrl; ?>/register.php">Request an account</a>
                    </p>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // KingsChat sign-in with a button loading state that restores if the
        // popup is closed/cancelled (window regains focus without navigating away).
        function kcLogin(btn) {
            var restore = (typeof setButtonLoading === 'function')
                ? setButtonLoading(btn, 'Opening KingsChat…')
                : function () {};
            var handled = false;
            function onFocus() {
                if (handled) return;
                handled = true;
                window.removeEventListener('focus', onFocus);
                // small delay so a successful redirect wins over the restore
                setTimeout(restore, 600);
            }
            window.addEventListener('focus', onFocus);
            kcOpenPopup({ mode: 'login', returnTo: '<?php echo $baseUrl; ?>/dashboard.php' });
        }
    </script>
</body>
</html>
