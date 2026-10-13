<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/database.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and get user data
$isLoggedIn = isset($_SESSION['user_id']);
$userData = $isLoggedIn ? $_SESSION['user_data'] : null;
$currentPage = basename($_SERVER['PHP_SELF']);

// Define base URL from config
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$appJsVersion = @filemtime(__DIR__ . '/../assets/js/app.js') ?: time();
// KingsChat linkage check
require_once __DIR__ . '/../includes/kc_link.php';
$needsKcLink = false;
if ($isLoggedIn && isset($_SESSION['user_id'])) {
    // Only show prompt if user just logged in AND is not linked
    $needsKcLink = !kc_is_user_linked((int)$_SESSION['user_id']) && isset($_SESSION['show_kc_link_prompt']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="format-detection" content="telephone=no">
    <title>GPD Reporting Portal - <?php echo ucfirst(str_replace('.php', '', $currentPage)); ?></title>
    <link rel="icon" href="<?php echo $baseUrl; ?>/favicon.ico">
    <link rel="shortcut icon" href="<?php echo $baseUrl; ?>/favicon.ico">
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/components.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/libraries.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/modern-theme.css" rel="stylesheet">
    
    <!-- jQuery - Required for plugins -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Flatpickr - Modern Date Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <!-- Toastify JS - Toast Notifications -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    
    <!-- SweetAlert2 - Beautiful Alerts and Modals -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    
    <script>window.BASE_URL = '<?php echo $baseUrl; ?>'; window.IS_LOGGED_IN = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;</script>
    <!-- Custom App JavaScript -->
    <script src="<?php echo $baseUrl; ?>/assets/js/app.js?v=<?php echo $appJsVersion; ?>"></script>
    <script>
    // Start loading bar immediately as soon as app.js is parsed
    if (window.LoadingBar) { LoadingBar.start(); }
    </script>
    <script src="<?php echo $baseUrl; ?>/assets/js/preloader.js"></script>
    <script src="<?php echo $baseUrl; ?>/assets/js/modern-theme.js"></script>
    
    <!-- Custom Library Styles -->
    <link href="<?php echo $baseUrl; ?>/assets/css/libraries.css" rel="stylesheet" />
    
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
            confirmButtonColor: 'var(--primary-600)'
        });
    }
    
    // Confirmation dialog function
    function showConfirmDialog(title, text, icon = 'warning', confirmButtonText = 'Yes, proceed', onConfirm = null) {
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: 'var(--primary-600)',
            cancelButtonColor: 'var(--gray-500)',
            confirmButtonText: confirmButtonText
        }).then((result) => {
            if (result.isConfirmed && typeof onConfirm === 'function') {
                onConfirm();
            }
        });
    }
    
    // Delete confirmation helper
    function confirmDelete(url, itemName = 'item') {
        showConfirmDialog(
            'Confirm Deletion',
            `Are you sure you want to delete this ${itemName}? This action cannot be undone.`,
            'warning',
            'Yes, delete it',
            function() {
                window.location.href = url;
            }
        );
        return false;
    }
    
    // Form submission helper with loading state
    function submitFormWithLoading(formId, loadingText = 'Processing...') {
        const form = document.getElementById(formId);
        if (!form) return false;
        
        if (!form.checkValidity()) {
            form.reportValidity();
            return false;
        }
        
        // Find submit button
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            // Save original text
            const originalText = submitBtn.innerHTML;
            
            // Set loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> ${loadingText}`;
            
            // Submit form after a short delay to show loading state
            setTimeout(() => {
                form.submit();
            }, 100);
        } else {
            form.submit();
        }
        
        return true;
    }
    
    // Image viewer helper
    function viewImage(imageSrc, altText = 'Image') {
        Swal.fire({
            imageUrl: imageSrc,
            imageAlt: altText,
            width: 'auto',
            padding: '1em',
            showConfirmButton: false,
            showCloseButton: true,
            background: '#fff',
            customClass: {
                image: 'max-h-[80vh] max-w-full'
            }
        });
    }
    
    </script>

    <!-- Custom Flatpickr Styling -->
    <style>
        /* Input field styling */
        .date-picker-container {
            position: relative;
        }

        .date-picker-container .flatpickr-input {
            padding-left: 2.5rem !important;
            background-color: white;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .date-picker-container .flatpickr-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }

        .date-picker-container .calendar-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            pointer-events: none;
            z-index: 1;
        }

        /* Calendar popup styling */
        .flatpickr-calendar {
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border: 1px solid #e5e7eb;
            font-family: 'Inter', sans-serif;
        }

        .flatpickr-months {
            background-color: #3b82f6;
            color: white;
            border-radius: 0.5rem 0.5rem 0 0;
            padding: 0.5rem 0;
        }

        .flatpickr-month {
            height: 40px;
        }

        .flatpickr-current-month {
            padding-top: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }

        .flatpickr-monthDropdown-months {
            background-color: transparent;
            color: white;
            font-weight: 600;
        }

        .flatpickr-monthDropdown-months:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .numInputWrapper span {
            border: none;
        }

        .flatpickr-current-month .numInputWrapper span.arrowUp:after {
            border-bottom-color: rgba(255, 255, 255, 0.9);
        }

        .flatpickr-current-month .numInputWrapper span.arrowDown:after {
            border-top-color: rgba(255, 255, 255, 0.9);
        }

        .flatpickr-months .flatpickr-prev-month,
        .flatpickr-months .flatpickr-next-month {
            color: white;
            fill: white;
            height: 40px;
            padding: 10px;
        }

        .flatpickr-months .flatpickr-prev-month:hover,
        .flatpickr-months .flatpickr-next-month:hover {
            color: white;
            fill: white;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .flatpickr-current-month input.cur-year {
            color: white;
            font-weight: 600;
        }

        .flatpickr-day {
            border-radius: 0.25rem;
            margin: 2px;
            max-width: calc(100% / 7 - 4px);
        }

        .flatpickr-day.selected,
        .flatpickr-day.startRange,
        .flatpickr-day.endRange,
        .flatpickr-day.selected.inRange,
        .flatpickr-day.startRange.inRange,
        .flatpickr-day.endRange.inRange,
        .flatpickr-day.selected:focus,
        .flatpickr-day.startRange:focus,
        .flatpickr-day.endRange:focus,
        .flatpickr-day.selected:hover,
        .flatpickr-day.startRange:hover,
        .flatpickr-day.endRange:hover,
        .flatpickr-day.selected.prevMonthDay,
        .flatpickr-day.startRange.prevMonthDay,
        .flatpickr-day.endRange.prevMonthDay,
        .flatpickr-day.selected.nextMonthDay,
        .flatpickr-day.startRange.nextMonthDay,
        .flatpickr-day.endRange.nextMonthDay {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
            font-weight: 600;
        }

        .flatpickr-day.today {
            border-color: #3b82f6;
            color: #3b82f6;
            font-weight: 600;
        }

        .flatpickr-day.today:hover {
            background: #3b82f6;
            color: white;
        }

        .flatpickr-day:hover {
            background: #f3f4f6;
        }

        .flatpickr-time {
            border-top: 1px solid #e5e7eb;
        }

        .flatpickr-clear-button {
            position: absolute;
            right: 2.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            cursor: pointer;
            z-index: 3;
            transition: color 0.15s ease-in-out;
        }

        .flatpickr-clear-button:hover {
            color: #6b7280;
        }
    </style>
    <style>
        :root {
            --header-height: 4rem;
            --mobile-nav-height: 3.5rem;
        }

        * {
            -webkit-tap-highlight-color: transparent;
        }

        html {
            scroll-behavior: smooth;
            height: -webkit-fill-available;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-image: url('<?php echo $baseUrl; ?>/assets/images/background.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
            min-height: 100vh;
            min-height: -webkit-fill-available;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        input, select, textarea {
            font-size: 16px !important; /* Prevents iOS zoom on focus */
        }

        .content-overlay {
            background-color: rgba(255, 255, 255, 0.9);
            min-height: 100vh;
            width: 100%;
            padding-bottom: env(safe-area-inset-bottom);
        }

        .auth-overlay {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 1rem;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        /* Preloader Styles */
        .preloader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.98);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.5s ease-out;
            pointer-events: none; /* Prevent clicks during fade-out */
        }
        .preloader.fade-out {
            opacity: 0;
            visibility: hidden; /* Completely hide it after fade-out */
        }
        .preloader-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mobile Menu Styles */
        .mobile-menu {
            display: none;
            position: fixed;
            top: var(--header-height);
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            z-index: 40;
            max-height: calc(100vh - var(--header-height));
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .mobile-menu.active {
            display: block;
        }

        /* Mobile Optimizations */
        @media (max-width: 768px) {
            main {
                padding-top: calc(var(--header-height) + 1rem) !important;
            }
            .container {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -1rem;
                padding: 0 1rem;
            }
            .touch-scroll {
                -webkit-overflow-scrolling: touch;
                overflow-x: auto;
            }
        }

        /* iOS Safe Area Support */
        @supports (padding: max(0px)) {
            .content-overlay {
                padding-left: max(1rem, env(safe-area-inset-left));
                padding-right: max(1rem, env(safe-area-inset-right));
                padding-bottom: max(1rem, env(safe-area-inset-bottom));
            }
            header {
                padding-top: env(safe-area-inset-top);
                height: calc(var(--header-height) + env(safe-area-inset-top));
            }
            .mobile-menu {
                top: calc(var(--header-height) + env(safe-area-inset-top));
                max-height: calc(100vh - var(--header-height) - env(safe-area-inset-top));
                padding-bottom: env(safe-area-inset-bottom);
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen modern-layout">
    <!-- Modern Preloader -->
    <div class="modern-preloader" id="preloader">
        <div class="modern-preloader-spinner"></div>
    </div>

    <div class="content-overlay">
    <style>
    /* ── Flat nav overrides ── */
    .modern-header { box-shadow: none; border-bottom: 1px solid var(--neutral-200); }
    .nh-logo { font-size: 0.9375rem; font-weight: 600; color: var(--neutral-900); letter-spacing: -0.01em; display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
    .nh-logo img { height: 1.6rem; width: auto; }
    .nh-nav { display: flex; align-items: center; height: 100%; gap: 0; }
    .nh-nav a { display: flex; align-items: center; height: 100%; padding: 0 0.9rem; font-size: 0.8125rem; font-weight: 500; color: var(--neutral-600); text-decoration: none; border-bottom: 2px solid transparent; transition: color 0.15s, border-color 0.15s; white-space: nowrap; }
    .nh-nav a:hover { color: var(--neutral-900); }
    .nh-nav a.active { color: var(--primary-600); border-bottom-color: var(--primary-600); }
    .nh-user { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.35rem 0.6rem; border-radius: var(--radius-md); transition: background 0.15s; position: relative; }
    .nh-user:hover { background: var(--neutral-100); }
    .nh-avatar { width: 1.75rem; height: 1.75rem; border-radius: 50%; background: var(--primary-100); color: var(--primary-700); font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .nh-user-name { font-size: 0.8125rem; font-weight: 500; color: var(--neutral-800); }
    .nh-chevron { font-size: 0.6rem; color: var(--neutral-400); }
    .nh-dropdown { position: absolute; right: 0; top: calc(100% + 0.35rem); width: 13rem; background: #fff; border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); overflow: hidden; z-index: 60; box-shadow: var(--shadow-md); }
    .nh-dropdown.hidden { display: none; }
    .nh-dropdown-head { padding: 0.65rem 0.9rem; border-bottom: 1px solid var(--neutral-100); }
    .nh-dropdown-head strong { display: block; font-size: 0.8125rem; font-weight: 600; color: var(--neutral-900); }
    .nh-dropdown-head span { font-size: 0.72rem; color: var(--neutral-400); }
    .nh-dropdown a { display: flex; align-items: center; gap: 0.5rem; padding: 0.55rem 0.9rem; font-size: 0.8125rem; color: var(--neutral-700); text-decoration: none; transition: background 0.12s; }
    .nh-dropdown a:hover { background: var(--neutral-50); color: var(--neutral-900); }
    .nh-dropdown a.danger { color: var(--danger-600); }
    .nh-dropdown a.danger:hover { background: var(--danger-50); }
    .nh-divider { border: none; border-top: 1px solid var(--neutral-100); margin: 0.2rem 0; }
    .nh-mobile-btn { display: none; align-items: center; justify-content: center; width: 2rem; height: 2rem; color: var(--neutral-600); border-radius: var(--radius-md); cursor: pointer; }
    .nh-mobile-btn:hover { background: var(--neutral-100); color: var(--neutral-900); }
    /* Tablet & mobile: hide desktop nav, show hamburger */
    @media (max-width: 1024px) {
        .nh-nav { display: none; }
        .nh-mobile-btn { display: flex; }
    }
    @media (max-width: 640px) {
        .nh-user-name, .nh-chevron { display: none; }
        .nh-logo span { display: none; }
    }
    /* Action bar responsive */
    #action-bar { flex-wrap: wrap; gap: 0.4rem !important; }
    @media (max-width: 480px) {
        #action-bar { font-size: 0.75rem; padding: 0.4rem 0.75rem; }
        #action-bar span { flex: 1 1 100%; }
        #action-bar a { margin-left: 0 !important; }
    }
    </style>

    <!-- Flat Header -->
    <header class="modern-header">
        <div class="modern-header-container">
            <!-- Logo -->
            <a href="<?php echo $baseUrl; ?>/dashboard.php" class="nh-logo">
                <img src="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp" alt="GPD">
                GPD Reporting Portal
            </a>

            <?php if ($isLoggedIn): ?>
            <!-- Desktop Nav -->
            <nav class="nh-nav">
                <a href="<?php echo $baseUrl; ?>/dashboard.php" class="<?php echo $currentPage === 'dashboard.php' && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') === false ? 'active' : ''; ?>"><i class="fas fa-home" style="font-size:0.75rem;margin-right:0.3rem;"></i>Dashboard</a>
                <?php if (!isset($userData['role']) || $userData['role'] === 'user' || $userData['role'] === 'admin'): ?>
                <a href="<?php echo $baseUrl; ?>/pages/reports/create.php" class="<?php echo $currentPage === 'create.php' ? 'active' : ''; ?>"><i class="fas fa-paper-plane" style="font-size:0.75rem;margin-right:0.3rem;"></i>Submit Report</a>
                <?php endif; ?>
                <a href="<?php echo $baseUrl; ?>/pages/reports/list.php" class="<?php echo $currentPage === 'list.php' ? 'active' : ''; ?>"><i class="fas fa-list" style="font-size:0.75rem;margin-right:0.3rem;"></i>My Reports</a>
                <a href="<?php echo $baseUrl; ?>/pages/analytics.php" class="<?php echo $currentPage === 'analytics.php' ? 'active' : ''; ?>"><i class="fas fa-chart-area" style="font-size:0.75rem;margin-right:0.3rem;"></i>Analytics</a>
                <a href="<?php echo $baseUrl; ?>/distribution/dashboard" class="<?php echo strpos($_SERVER['REQUEST_URI'] ?? '', '/distribution/') !== false ? 'active' : ''; ?>"><i class="fas fa-hands-helping" style="font-size:0.75rem;margin-right:0.3rem;"></i>Distribution</a>
                <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
                <a href="<?php echo $baseUrl; ?>/admin/users.php" class="<?php echo strpos($currentPage, 'users.php') !== false ? 'active' : ''; ?>"><i class="fas fa-users" style="font-size:0.75rem;margin-right:0.3rem;"></i>Users</a>
                <a href="<?php echo $baseUrl; ?>/admin/dashboard.php" class="<?php echo strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/dashboard') !== false ? 'active' : ''; ?>"><i class="fas fa-chart-bar" style="font-size:0.75rem;margin-right:0.3rem;"></i>Admin Stats</a>
                <?php endif; ?>
                <a href="<?php echo $baseUrl; ?>/search.php" class="<?php echo $currentPage === 'search.php' ? 'active' : ''; ?>" title="Search"><i class="fas fa-search" style="font-size:0.75rem;margin-right:0.3rem;"></i>Search</a>
                <a href="<?php echo $baseUrl; ?>/help.php" class="<?php echo $currentPage === 'help.php' ? 'active' : ''; ?>"><i class="fas fa-question-circle" style="font-size:0.75rem;margin-right:0.3rem;"></i>Help</a>
            </nav>
            <?php endif; ?>

            <div class="modern-header-actions">
                <?php if ($isLoggedIn): ?>
                <!-- User menu -->
                <div class="nh-user" onclick="toggleProfileDropdown(event)">
                    <div class="nh-avatar"><?php echo strtoupper(substr($userData['name'] ?? $userData['username'] ?? 'U', 0, 1)); ?></div>
                    <span class="nh-user-name"><?php echo htmlspecialchars($userData['name'] ?? $userData['username']); ?></span>
                    <i class="fas fa-chevron-down nh-chevron"></i>

                    <div id="profileDropdown" class="nh-dropdown hidden">
                        <div class="nh-dropdown-head">
                            <strong><?php echo htmlspecialchars($userData['name'] ?? $userData['username']); ?></strong>
                            <span><?php echo isset($userData['role']) && $userData['role'] === 'admin' ? 'Administrator' : 'RZM'; ?></span>
                        </div>
                        <a href="<?php echo $baseUrl; ?>/profile.php"><i class="fas fa-user-circle"></i> Account &amp; Profile</a>
                        <a href="<?php echo $baseUrl; ?>/pages/reports/list.php"><i class="fas fa-list"></i> My Reports</a>
                        <?php if (!isset($userData['role']) || $userData['role'] === 'user' || $userData['role'] === 'admin'): ?>
                        <a href="<?php echo $baseUrl; ?>/pages/reports/create.php"><i class="fas fa-paper-plane"></i> Submit Report</a>
                        <?php endif; ?>
                        <a href="<?php echo $baseUrl; ?>/search.php"><i class="fas fa-search"></i> Search</a>
                        <a href="<?php echo $baseUrl; ?>/pages/analytics.php"><i class="fas fa-chart-area"></i> Analytics</a>
                        <a href="<?php echo $baseUrl; ?>/distribution/dashboard"><i class="fas fa-hands-helping"></i> Distribution</a>
                        <a href="<?php echo $baseUrl; ?>/help.php"><i class="fas fa-question-circle"></i> Help &amp; Support</a>
                        <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
                        <hr class="nh-divider">
                        <a href="<?php echo $baseUrl; ?>/admin/dashboard.php"><i class="fas fa-chart-bar"></i> Admin Statistics</a>
                        <a href="<?php echo $baseUrl; ?>/admin/users.php"><i class="fas fa-users"></i> User Management</a>
                        <a href="<?php echo $baseUrl; ?>/admin/settings.php"><i class="fas fa-cog"></i> Settings</a>
                        <?php endif; ?>
                        <hr class="nh-divider">
                        <a href="<?php echo $baseUrl; ?>/logout.php" class="danger" data-link-loading><i class="fas fa-sign-out-alt"></i> Sign Out</a>
                    </div>
                </div>

                <!-- Mobile menu button -->
                <button class="nh-mobile-btn" onclick="toggleMobileMenu(event)" aria-label="Menu">
                    <i class="fas fa-bars"></i>
                </button>
                <?php else: ?>
                <a href="<?php echo $baseUrl; ?>/login.php" class="modern-button modern-button-primary" style="font-size:0.8125rem;">
                    Login
                </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($isLoggedIn): ?>
    <!-- Modern Mobile Menu -->
    <div class="modern-mobile-menu" id="mobileMenu">
        <div class="modern-mobile-menu-user">
            <div class="modern-user-avatar">
                <?php echo strtoupper(substr($userData['name'] ?? $userData['username'] ?? 'U', 0, 1)); ?>
            </div>
            <div>
                <div class="text-base font-medium text-gray-800">
                    <?php echo htmlspecialchars($userData['name'] ?? $userData['username']); ?>
                </div>
                <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
                <div class="modern-badge modern-badge-primary mt-1">
                    Administrator
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="modern-mobile-menu-nav">
            <a href="<?php echo $baseUrl; ?>/dashboard.php"
               class="modern-mobile-menu-item <?php echo $currentPage === 'dashboard.php' && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') === false ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <?php if (!isset($userData['role']) || $userData['role'] === 'user' || $userData['role'] === 'admin'): ?>
            <a href="<?php echo $baseUrl; ?>/pages/reports/create.php"
               class="modern-mobile-menu-item <?php echo $currentPage === 'create.php' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i> Submit Report
            </a>
            <?php endif; ?>
            <a href="<?php echo $baseUrl; ?>/pages/reports/list.php"
               class="modern-mobile-menu-item <?php echo $currentPage === 'list.php' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> My Reports
            </a>
            <a href="<?php echo $baseUrl; ?>/pages/analytics.php"
               class="modern-mobile-menu-item <?php echo $currentPage === 'analytics.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-area"></i> Analytics
            </a>
            <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
            <a href="<?php echo $baseUrl; ?>/admin/dashboard.php"
               class="modern-mobile-menu-item <?php echo strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/dashboard') !== false ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Admin Statistics
            </a>
            <a href="<?php echo $baseUrl; ?>/admin/users.php"
               class="modern-mobile-menu-item <?php echo strpos($currentPage, 'users.php') !== false ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> User Management
            </a>
            <?php endif; ?>
            <a href="<?php echo $baseUrl; ?>/profile.php"
               class="modern-mobile-menu-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-circle"></i> Account &amp; Profile
            </a>
            <a href="<?php echo $baseUrl; ?>/search.php"
               class="modern-mobile-menu-item <?php echo $currentPage === 'search.php' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i> Search
            </a>
            <a href="<?php echo $baseUrl; ?>/help.php"
               class="modern-mobile-menu-item <?php echo $currentPage === 'help.php' ? 'active' : ''; ?>">
                <i class="fas fa-question-circle"></i> Help &amp; Support
            </a>
        </div>
        
        <div class="modern-mobile-menu-footer">
            <a href="<?php echo $baseUrl; ?>/logout.php" class="modern-button modern-button-danger w-full" data-link-loading>
                <i class="fas fa-sign-out-alt mr-2"></i> Logout
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isLoggedIn && (!isset($userData['role']) || $userData['role'] === 'user' || $userData['role'] === 'admin') && $currentPage !== 'create.php'): ?>
    <?php
    // Determine if current month is already submitted for the action bar
    $actionBarShowSubmit = true;
    try {
        $dbAB = new Database(); $connAB = $dbAB->getConnection();
        $stmtAB = $connAB->prepare("SELECT id FROM reports WHERE user_id = ? AND DATE_FORMAT(report_month,'%Y-%m') = ?");
        $stmtAB->execute([$_SESSION['user_id'], date('Y-m')]);
        if ($stmtAB->fetch()) $actionBarShowSubmit = false;
    } catch (Exception $eAB) {}
    ?>
    <?php if ($actionBarShowSubmit): ?>
    <div id="action-bar" style="background:var(--primary-600);color:#fff;padding:0.45rem 1.25rem;display:flex;align-items:center;gap:0.75rem;font-size:0.8rem;position:sticky;top:var(--header-height,3.5rem);z-index:900;">
        <i class="fas fa-exclamation-circle" style="opacity:0.85;flex-shrink:0;"></i>
        <span>You haven't submitted your <strong><?php echo date('F Y'); ?></strong> report yet.</span>
        <a href="<?php echo $baseUrl; ?>/pages/reports/create.php?month=<?php echo date('Y-m'); ?>"
           style="margin-left:auto;background:#fff;color:var(--primary-700);font-size:0.75rem;font-weight:600;padding:0.3rem 0.85rem;border-radius:9999px;text-decoration:none;white-space:nowrap;flex-shrink:0;"
           data-link-loading>
            Submit Now <i class="fas fa-arrow-right" style="margin-left:0.25rem;font-size:0.7rem;"></i>
        </a>
        <button onclick="this.parentElement.style.display='none'" style="background:none;border:none;color:#fff;opacity:0.6;cursor:pointer;padding:0 0.25rem;flex-shrink:0;font-size:0.85rem;" title="Dismiss">&times;</button>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <main class="modern-main">

<script>
// Mobile menu toggle
function toggleMobileMenu(event) {
    // Prevent the event from bubbling up to document
    if (event) {
        event.stopPropagation();
    }
    
    const mobileMenu = document.getElementById('mobileMenu');
    if (mobileMenu) {
        mobileMenu.classList.toggle('active');
        
        // Close menu when clicking outside
        if (mobileMenu.classList.contains('active')) {
            setTimeout(() => {
                document.addEventListener('click', function closeMobileMenu(e) {
                    if (!e.target.closest('.nh-mobile-btn') && !mobileMenu.contains(e.target)) {
                        mobileMenu.classList.remove('active');
                        document.removeEventListener('click', closeMobileMenu);
                    }
                });
            }, 100);
        }
    }
}

// Profile dropdown toggle
function toggleProfileDropdown(event) {
    // Prevent the event from bubbling up to document
    if (event) {
        event.stopPropagation();
    }
    
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) {
        dropdown.classList.toggle('hidden');
        
        // Close dropdown when clicking outside
        if (!dropdown.classList.contains('hidden')) {
            setTimeout(() => {
                document.addEventListener('click', function closeDropdown(e) {
                    if (!e.target.closest('.nh-user')) {
                        dropdown.classList.add('hidden');
                        document.removeEventListener('click', closeDropdown);
                    }
                });
            }, 100);
        }
    }
}

// Initialize dropdown and mobile menu when the page loads
document.addEventListener('DOMContentLoaded', function() {
    // Make sure the dropdown is initially hidden
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) {
        dropdown.classList.add('hidden');
    }
    
    // Add click event listener to the user button
    const userButton = document.querySelector('.modern-user-button');
    if (userButton) {
        userButton.addEventListener('click', function(e) {
            toggleProfileDropdown(e);
        });
    }
    
    // Make sure the mobile menu is initially hidden
    const mobileMenu = document.getElementById('mobileMenu');
    if (mobileMenu) {
        mobileMenu.classList.remove('active');
    }
    
    // Add click event listener to the mobile menu button
    const mobileMenuButton = document.querySelector('.modern-mobile-menu-button');
    if (mobileMenuButton) {
        mobileMenuButton.addEventListener('click', function(e) {
            toggleMobileMenu(e);
        });
    }
});

// Preloader
document.addEventListener('DOMContentLoaded', function() {
    const preloader = document.getElementById('preloader');
    if (preloader) {
        // Hide preloader after a short delay to ensure it works even if page loads very quickly
        setTimeout(() => {
            preloader.classList.add('fade-out');
            setTimeout(() => {
                preloader.style.display = 'none';
            }, 500);
        }, 300);
    }
});

// Initialize libraries
$(document).ready(function() {
    // Initialize flatpickr on all elements with the 'date-picker' class
    flatpickr('.date-picker', {
        dateFormat: 'Y-m-d',
        allowInput: true,
        altInput: true,
        altFormat: 'F j, Y',
        enableTime: false
    });
    
    // Initialize datetime pickers
    flatpickr('.datetime-picker', {
        dateFormat: 'Y-m-d H:i',
        allowInput: true,
        altInput: true,
        altFormat: 'F j, Y at h:i K',
        enableTime: true
    });
    
    // Add validation to forms with the 'needs-validation' class
    const forms = document.querySelectorAll('.needs-validation');
    
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                
                // Show toast for invalid form
                showToast('Please check the form for errors.', 'error');
                
                // Highlight the first invalid field
                const firstInvalid = form.querySelector(':invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
            }
            
            form.classList.add('was-validated');
        }, false);
    });
});

<?php if ($needsKcLink): ?>
// Prompt user to link KingsChat after fresh login
document.addEventListener('DOMContentLoaded', function() {
    <?php
    // Clear the flag after showing the modal once
    unset($_SESSION['show_kc_link_prompt']);
    ?>
    // Small delay to ensure page is fully loaded
    setTimeout(function() {
        Swal.fire({
            title: 'Link your KingsChat',
            html: '<p class="text-sm text-gray-600 mb-2">Connect your KingsChat account to enable:</p>' +
                  '<ul class="text-left text-sm text-gray-600 space-y-1">' +
                  '<li><i class="fas fa-check text-green-500 mr-2"></i>Quick login with KingsChat</li>' +
                  '<li><i class="fas fa-check text-green-500 mr-2"></i>Instant notifications</li>' +
                  '<li><i class="fas fa-check text-green-500 mr-2"></i>Seamless account access</li>' +
                  '</ul>',
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-link mr-1"></i> Link Now',
            cancelButtonText: 'Maybe Later',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#6b7280',
            footer: '<a href="<?php echo $baseUrl; ?>/profile.php" class="text-xs text-gray-500 hover:text-gray-700">You can also link from your profile page</a>'
        }).then(function(result) {
            if (result.isConfirmed) {
                // Generate a one-time link token server-side
                appFetch('<?php echo $baseUrl; ?>/kc_prepare_link.php', { method: 'POST', credentials: 'same-origin' }, { loadingText: 'Preparing…' })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.ok && resp.token) {
                            var href = window.location.href;
                            var sep = href.indexOf('?') >= 0 ? '&' : '?';
                            var target = href + sep + 'lt=' + encodeURIComponent(resp.token);
                            kcOpenPopup({ mode: 'link', returnTo: target });
                        } else {
                            kcOpenPopup({ mode: 'link' });
                        }
                    }).catch(function(){
                        kcOpenPopup({ mode: 'link' });
                    });
            }
        });
    }, 1000); // 1 second delay after page load
});
<?php endif; ?>
</script>
