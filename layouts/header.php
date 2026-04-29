<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and get user data
$isLoggedIn = isset($_SESSION['user_id']);
$userData = $isLoggedIn ? $_SESSION['user_data'] : null;
$currentPage = basename($_SERVER['PHP_SELF']);

// Record each authenticated page visit for engagement analytics.
if ($isLoggedIn && isset($_SESSION['user_id'])) {
    Auth::recordUserVisit((int)$_SESSION['user_id'], 'page_view');
}

// Define base URL from config
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
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
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo.webp">
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
    
    <!-- Select2 - Enhanced Select Boxes -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="<?php echo $baseUrl; ?>/assets/css/select2-custom.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>window.BASE_URL = '<?php echo $baseUrl; ?>'; window.IS_LOGGED_IN = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;</script>
    <!-- Custom App JavaScript -->
    <script src="<?php echo $baseUrl; ?>/assets/js/app.js"></script>
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
    
    // Global helper for initializing Select2
    function initializeSelect2(selector = '.select2') {
        $(selector).select2({
            theme: 'default',
            width: '100%',
            placeholder: 'Select an option',
            allowClear: true,
            minimumResultsForSearch: 6
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
    <!-- Modern Header -->
    <header class="modern-header">
        <div class="modern-header-container">
            <div class="modern-header-logo">
                <a href="<?php echo $baseUrl; ?>/dashboard.php" class="flex items-center">
                    <img src="<?php echo $baseUrl; ?>/assets/images/logo.webp" alt="GPD Reports">
                    <span>GPD Reporting Portal</span>
                </a>
            </div>
            
            <?php if ($isLoggedIn): ?>
            <!-- Desktop Navigation -->
            <div class="modern-header-nav">
                <div class="modern-header-nav-items">
                    <a href="<?php echo $baseUrl; ?>/dashboard.php" 
                       class="modern-header-nav-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home mr-2"></i> Dashboard
                    </a>
                    <a href="<?php echo $baseUrl; ?>/pages/reports/list.php" 
                       class="modern-header-nav-item <?php echo $currentPage === 'list.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list mr-2"></i> Reports
                    </a>
                    <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
                    <a href="<?php echo $baseUrl; ?>/admin/users.php" 
                       class="modern-header-nav-item <?php echo strpos($currentPage, 'users.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-users mr-2"></i> User Management
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="modern-header-actions">
                <?php if ($isLoggedIn): ?>
                    <!-- User Menu with Dropdown -->
                    <div class="modern-user-menu hidden md:block relative">
                        <div class="modern-user-button" onclick="toggleProfileDropdown(event)">
                            <div class="modern-user-avatar">
                                <?php echo strtoupper(substr($userData['name'] ?? $userData['username'] ?? 'U', 0, 1)); ?>
                            </div>
                            <div class="modern-user-info">
                                <div class="modern-user-name">
                                    <?php echo htmlspecialchars($userData['name'] ?? $userData['username']); ?>
                                </div>
                                <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
                                <div class="modern-user-role">
                                    Administrator
                                </div>
                                <?php else: ?>
                                <div class="modern-user-role">
                                    User
                                </div>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-chevron-down ml-2 text-gray-500 text-xs"></i>
                        </div>
                        
                        <!-- Profile Dropdown Menu -->
                        <div id="profileDropdown" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50 hidden">
                            <div class="px-4 py-2 border-b border-gray-200">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($userData['name'] ?? $userData['username']); ?>
                                </div>
                                <div class="text-xs text-gray-500">
                                    <?php echo htmlspecialchars($userData['email'] ?? ''); ?>
                                </div>
                            </div>
                            
                            <a href="<?php echo $baseUrl; ?>/profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-user-circle mr-2"></i> My Profile
                            </a>
                            
            <a href="<?php echo $baseUrl; ?>/pages/reports/create.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                <i class="fas fa-plus-circle mr-2"></i> New Report
            </a>
            
            <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
                            <div class="border-t border-gray-200 mt-1 pt-1">
                                <a href="<?php echo $baseUrl; ?>/admin/dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-tachometer-alt mr-2"></i> Admin Dashboard
                                </a>
                                <a href="<?php echo $baseUrl; ?>/admin/settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-cog mr-2"></i> System Settings
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <div class="border-t border-gray-200 mt-1 pt-1">
                                <a href="<?php echo $baseUrl; ?>/logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Mobile Menu Button -->
                    <button class="modern-mobile-menu-button md:hidden" onclick="toggleMobileMenu(event)">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                <?php else: ?>
                    <a href="<?php echo $baseUrl; ?>/login.php" class="modern-button modern-button-primary">
                        <i class="fas fa-sign-in-alt mr-1"></i> Login
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
               class="modern-mobile-menu-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="<?php echo $baseUrl; ?>/pages/reports/list.php" 
               class="modern-mobile-menu-item <?php echo $currentPage === 'list.php' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> Reports
            </a>
            <?php if (isset($userData['role']) && $userData['role'] === 'admin'): ?>
            <a href="<?php echo $baseUrl; ?>/admin/users.php" 
               class="modern-mobile-menu-item <?php echo strpos($currentPage, 'users.php') !== false ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> User Management
            </a>
            <?php endif; ?>
            
            <!-- Profile Link -->
            <a href="<?php echo $baseUrl; ?>/profile.php" 
               class="modern-mobile-menu-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-circle mr-3"></i> My Profile
            </a>
        </div>
        
        <div class="modern-mobile-menu-footer">
            <a href="<?php echo $baseUrl; ?>/logout.php" class="modern-button modern-button-danger w-full">
                <i class="fas fa-sign-out-alt mr-2"></i> Logout
            </a>
        </div>
    </div>
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
                    const menuButton = document.querySelector('.modern-mobile-menu-button');
                    if (menuButton && !menuButton.contains(e.target) && !mobileMenu.contains(e.target)) {
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
                    const userMenu = document.querySelector('.modern-user-button');
                    if (userMenu && !userMenu.contains(e.target) && !dropdown.contains(e.target)) {
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
    // Initialize Select2 on all select elements with the 'select2' class
    $('.select2').select2({
        theme: 'default',
        width: '100%',
        placeholder: 'Select an option',
        allowClear: true,
        minimumResultsForSearch: 6
    });
    
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
                fetch('<?php echo $baseUrl; ?>/kc_prepare_link.php', { method: 'POST', credentials: 'same-origin' })
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
