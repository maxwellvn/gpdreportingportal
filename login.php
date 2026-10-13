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
$appJsVersion = @filemtime(__DIR__ . '/assets/js/app.js') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GPD Reports</title>
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
    
    <!-- Base URL and App JS (for kcOpenPopup) -->
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
            overflow: hidden;
            background:
                /* Ellipse 11 (low-left) */
                radial-gradient(42% 58% at 19.5% 90%, rgba(255, 245, 0, 0.95) 0%, rgba(255, 245, 0, 0.58) 44%, transparent 76%),
                /* Ellipse 12 (bottom-center) */
                radial-gradient(30% 42% at 60.5% 104%, rgba(255, 245, 0, 0.9) 0%, rgba(255, 245, 0, 0.52) 44%, transparent 74%),
                /* Ellipse 13 (lower-right) */
                radial-gradient(30% 42% at 88% 92%, rgba(255, 245, 0, 0.88) 0%, rgba(255, 245, 0, 0.5) 44%, transparent 74%),
                var(--portal-bg);
        }

        /* Ellipse 14 — large soft gray glow over the glass strips */
        .top-glow {
            position: absolute;
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
            position: absolute;
            inset: 0;
            display: flex;
            gap: 0;
            opacity: 0.44;
            pointer-events: none;
            z-index: 1;
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
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 48px 24px 72px;
        }

        .auth-card {
            position: relative;
            width: 100%;
            max-width: 430px;
            padding: 2.75rem 2.25rem;
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

        .auth-divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.25rem 0;
            color: rgba(0, 0, 0, 0.45);
            font-size: 0.8rem;
        }

        .auth-divider::before, .auth-divider::after {
            content: '';
            flex: 1;
            border-top: 1px solid rgba(0, 68, 103, 0.12);
        }

        .auth-foot {
            margin-top: 1.5rem;
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

        /* Continue-with-KingsChat — match the landing "secondary" pill */
        .auth-kc {
            color: #000;
            background: #fff;
            box-shadow: inset 0 -2px 10px rgba(177, 177, 177, 0.25);
        }

        /* soft white glow easing the card toward the footer */
        .auth-into-next {
            position: absolute;
            left: 50%;
            bottom: -90px;
            transform: translateX(-50%);
            width: 124%;
            height: 250px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            filter: blur(55px);
            z-index: 3;
            pointer-events: none;
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
                padding: 24px 16px 48px;
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
                <span class="auth-badge">Sign in to your zonal reporting portal</span>
                <h1 class="auth-title">Welcome <span class="auth-title-accent">back</span></h1>
                <p class="auth-sub">Enter your credentials to access your account.</p>

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
                        <label for="password" class="modern-form-label">Password</label>
                        <input id="password" name="password" type="password" required
                               class="modern-form-input"
                               placeholder="Enter your password">
                    </div>
                    <button type="submit" class="modern-button modern-button-primary w-full" style="justify-content:center;" data-loading-text="Signing in…">
                        Sign in
                    </button>
                </form>

                <div class="auth-divider">or</div>

                <button type="button" onclick="kcLogin(this)" class="modern-button auth-kc w-full" style="justify-content:center;">
                    <img src="https://kingschat.online/svg/logo-icon.svg" alt="KingsChat" style="width:1.1rem;height:1.1rem;margin-right:0.5rem;">
                    Continue with KingsChat
                </button>

                <p class="auth-foot">
                    No account? <a href="<?php echo $baseUrl; ?>/register.php">Create one</a>
                </p>
            </div>

            <div class="auth-into-next" aria-hidden="true"></div>
        </section>
    </main>

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
