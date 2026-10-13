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
$appJsVersion = @filemtime(__DIR__ . '/../assets/js/app.js') ?: time();
$themeCssVersion = @filemtime(__DIR__ . '/../assets/css/modern-theme.css') ?: time();
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
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token()); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="format-detection" content="telephone=no">
    <title>GPD Reporting Portal - <?php echo ucfirst(str_replace('.php', '', $currentPage)); ?></title>
    <link rel="icon" href="<?php echo $baseUrl; ?>/favicon.ico">
    <link rel="shortcut icon" href="<?php echo $baseUrl; ?>/favicon.ico">
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/modern-theme.css?v=<?php echo $themeCssVersion; ?>" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/utilities.css?v=<?php echo $themeCssVersion; ?>" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/libraries.css?v=<?php echo $themeCssVersion; ?>" rel="stylesheet">
    
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
    
    
    <script>window.BASE_URL = '<?php echo $baseUrl; ?>'; window.IS_LOGGED_IN = <?php echo $isLoggedIn ? 'true' : 'false'; ?>; window.CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;</script>
    <!-- Custom App JavaScript -->
    <script src="<?php echo $baseUrl; ?>/assets/js/app.js?v=<?php echo $appJsVersion; ?>"></script>
    <script>
    // Start loading bar immediately as soon as app.js is parsed
    if (window.LoadingBar) { LoadingBar.start(); }
    </script>
    <script src="<?php echo $baseUrl; ?>/assets/js/preloader.js"></script>
    <script src="<?php echo $baseUrl; ?>/assets/js/modern-theme.js"></script>
    
    
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
                    });
    }
    
    // Confirmation dialog function
    function showConfirmDialog(title, text, icon = 'warning', confirmButtonText = 'Yes, proceed', onConfirm = null) {
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            showCancelButton: true,
            
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
            submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> ${loadingText}`;
            
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
            imageHeight: Math.round(window.innerHeight * 0.8)
        });
    }
    
    </script>

</head>
<?php
$navRole = $userData['role'] ?? ROLE_RZM;
$navIsAdminRole = $navRole === ROLE_ADMIN;
$navIsManagerRole = $navRole === ROLE_ADMIN_MANAGER;
$navRoleLabel = $navIsAdminRole ? 'Administrator' : ($navIsManagerRole ? 'Admin Manager' : 'RZM');
$navRequestUri = $_SERVER['REQUEST_URI'] ?? '';
$navInAdmin = strpos($navRequestUri, '/admin/') !== false;
$navNavActive = function ($navMatch) use ($currentPage, $navInAdmin) {
    [$page, $admin] = $navMatch;
    return ($currentPage === $page && $navInAdmin === $admin) ? 'active' : '';
};

// Admin: count accounts waiting for approval for the People badge
$navPendingApprovals = 0;
if ($isLoggedIn && $navIsAdminRole) {
    try {
        $navPendingApprovals = (int)(new Database())->getConnection()->query("SELECT COUNT(*) FROM users WHERE active = 0")->fetchColumn();
    } catch (Exception $e) {
        error_log('Pending approvals count failed: ' . $e->getMessage());
    }
}

// Primary nav per role: [label, href, icon, [page, inAdmin]]
$navPrimaryNav = [];
$navMoreNav = [];
if ($isLoggedIn) {
    if ($navIsAdminRole) {
        $navPrimaryNav = [
            ['Overview', '/dashboard.php', 'fa-house', ['dashboard.php', false]],
            ['Reports', '/pages/reports/list.php', 'fa-file-lines', ['list.php', false]],
            ['Statistics', '/admin/dashboard.php', 'fa-chart-column', ['dashboard.php', true]],
            ['Compliance', '/admin/zone_compliance.php', 'fa-list-check', ['zone_compliance.php', true]],
            ['People', '/admin/users.php', 'fa-users', ['users.php', true]],
        ];
        $navMoreNav = [
            ['Analytics', '/pages/analytics.php', 'fa-chart-line'],
            ['Submit a report', '/pages/reports/create.php', 'fa-paper-plane'],
            ['Audit log', '/admin/audit_log.php', 'fa-clock-rotate-left'],
            ['Settings', '/admin/settings.php', 'fa-gear'],
            ['Distribution', '/distribution/dashboard', 'fa-arrow-up-right-from-square'],
        ];
    } elseif ($navIsManagerRole) {
        $navPrimaryNav = [
            ['Overview', '/dashboard.php', 'fa-house', ['dashboard.php', false]],
            ['Region reports', '/pages/reports/list.php', 'fa-file-lines', ['list.php', false]],
            ['Analytics', '/pages/analytics.php', 'fa-chart-line', ['analytics.php', false]],
        ];
        $navMoreNav = [['Distribution', '/distribution/dashboard', 'fa-arrow-up-right-from-square']];
    } else {
        $navPrimaryNav = [
            ['Home', '/dashboard.php', 'fa-house', ['dashboard.php', false]],
            ['Submit report', '/pages/reports/create.php', 'fa-paper-plane', ['create.php', false]],
            ['History', '/pages/reports/list.php', 'fa-file-lines', ['list.php', false]],
            ['Analytics', '/pages/analytics.php', 'fa-chart-line', ['analytics.php', false]],
        ];
        $navMoreNav = [['Distribution', '/distribution/dashboard', 'fa-arrow-up-right-from-square']];
    }
}
$navInitial = strtoupper(substr($userData['name'] ?? $userData['username'] ?? 'U', 0, 1));
$navDisplayName = $userData['name'] ?? $userData['username'] ?? '';
?>
<body class="modern-layout">
    <div class="modern-preloader" id="preloader"><div class="modern-preloader-spinner"></div></div>

    <div class="content-overlay">
    <header class="modern-header">
        <div class="modern-header-container">
            <a href="<?php echo $baseUrl; ?>/dashboard.php" class="nh-logo">
                <img src="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp" alt="" width="28" height="28">
                <span>GPD Reporting Portal</span>
            </a>

            <?php if ($isLoggedIn): ?>
            <nav class="nh-nav" aria-label="Main">
                <?php foreach ($navPrimaryNav as [$navLabel, $navHref, $navIcon, $navMatch]): ?>
                <a href="<?php echo $baseUrl . $navHref; ?>" class="<?php echo $navNavActive($navMatch); ?>"<?php echo $navNavActive($navMatch) ? ' aria-current="page"' : ''; ?>>
                    <?php echo htmlspecialchars($navLabel); ?>
                    <?php if ($navLabel === 'People' && $navPendingApprovals > 0): ?><span class="nh-count" title="Awaiting approval"><?php echo $navPendingApprovals; ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php if ($navMoreNav): ?>
                <details class="nh-more">
                    <summary>More <i class="fas fa-chevron-down nh-chevron" aria-hidden="true"></i></summary>
                    <div class="nh-more-menu">
                        <?php foreach ($navMoreNav as [$navLabel, $navHref, $navIcon]): ?>
                        <a href="<?php echo $baseUrl . $navHref; ?>"><i class="fas <?php echo $navIcon; ?>"></i><?php echo htmlspecialchars($navLabel); ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <div class="modern-header-actions">
                <?php if ($isLoggedIn): ?>
                <a href="<?php echo $baseUrl; ?>/search.php" class="nh-icon-btn nh-search-btn <?php echo $currentPage === 'search.php' ? 'active' : ''; ?>" aria-label="Search" title="Search"><i class="fas fa-magnifying-glass"></i></a>
                <div class="nh-user" onclick="toggleProfileDropdown(event)" role="button" tabindex="0" aria-haspopup="menu" aria-label="Account menu">
                    <div class="nh-avatar"><?php echo htmlspecialchars($navInitial); ?></div>
                    <span class="nh-user-name"><?php echo htmlspecialchars($navDisplayName); ?></span>
                    <i class="fas fa-chevron-down nh-chevron"></i>
                    <div id="profileDropdown" class="nh-dropdown hidden" role="menu">
                        <div class="nh-dropdown-head">
                            <strong><?php echo htmlspecialchars($navDisplayName); ?></strong>
                            <span><?php echo $navRoleLabel; ?><?php echo !empty($userData['region']) ? ' · ' . htmlspecialchars($userData['region']) : ''; ?></span>
                        </div>
                        <a href="<?php echo $baseUrl; ?>/profile.php"><i class="fas fa-circle-user"></i> Profile &amp; KingsChat</a>
                        <a href="<?php echo $baseUrl; ?>/help.php"><i class="fas fa-circle-question"></i> Help</a>
                        <hr class="nh-divider">
                        <a href="<?php echo $baseUrl; ?>/logout.php" class="danger" data-link-loading><i class="fas fa-arrow-right-from-bracket"></i> Sign out</a>
                    </div>
                </div>
                <button class="nh-icon-btn nh-mobile-btn" onclick="toggleMobileMenu(event)" aria-label="Open menu" aria-controls="mobileMenu">
                    <i class="fas fa-bars"></i>
                </button>
                <?php else: ?>
                <a href="<?php echo $baseUrl; ?>/login.php" class="modern-button modern-button-primary btn-sm">Sign in</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($isLoggedIn): ?>
    <div class="modern-mobile-menu" id="mobileMenu">
        <div class="modern-mobile-menu-user">
            <div class="modern-user-avatar"><?php echo htmlspecialchars($navInitial); ?></div>
            <div>
                <div style="font-weight:500;"><?php echo htmlspecialchars($navDisplayName); ?></div>
                <div class="meta"><?php echo $navRoleLabel; ?></div>
            </div>
        </div>
        <nav class="modern-mobile-menu-nav" aria-label="Mobile">
            <?php foreach ($navPrimaryNav as [$navLabel, $navHref, $navIcon, $navMatch]): ?>
            <a href="<?php echo $baseUrl . $navHref; ?>" class="modern-mobile-menu-item <?php echo $navNavActive($navMatch); ?>"><i class="fas <?php echo $navIcon; ?>"></i><?php echo htmlspecialchars($navLabel); ?><?php if ($navLabel === 'People' && $navPendingApprovals > 0): ?> <span class="nh-count" style="margin-left:auto;"><?php echo $navPendingApprovals; ?></span><?php endif; ?></a>
            <?php endforeach; ?>
            <div class="modern-mobile-menu-section">More</div>
            <?php foreach ($navMoreNav as [$navLabel, $navHref, $navIcon]): ?>
            <a href="<?php echo $baseUrl . $navHref; ?>" class="modern-mobile-menu-item"><i class="fas <?php echo $navIcon; ?>"></i><?php echo htmlspecialchars($navLabel); ?></a>
            <?php endforeach; ?>
            <a href="<?php echo $baseUrl; ?>/search.php" class="modern-mobile-menu-item <?php echo $currentPage === 'search.php' ? 'active' : ''; ?>"><i class="fas fa-magnifying-glass"></i>Search</a>
            <a href="<?php echo $baseUrl; ?>/profile.php" class="modern-mobile-menu-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>"><i class="fas fa-circle-user"></i>Profile &amp; KingsChat</a>
            <a href="<?php echo $baseUrl; ?>/help.php" class="modern-mobile-menu-item <?php echo $currentPage === 'help.php' ? 'active' : ''; ?>"><i class="fas fa-circle-question"></i>Help</a>
        </nav>
        <div class="modern-mobile-menu-footer">
            <a href="<?php echo $baseUrl; ?>/logout.php" class="modern-button modern-button-danger btn-block" data-link-loading><i class="fas fa-arrow-right-from-bracket"></i> Sign out</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isLoggedIn && ($navRole === ROLE_RZM || $navIsAdminRole) && $currentPage !== 'create.php'): ?>
    <?php
    // Reminder band: only while this month's report is outstanding
    $actionBarShowSubmit = true;
    try {
        $stmtAB = (new Database())->getConnection()->prepare("SELECT id FROM reports WHERE user_id = ? AND DATE_FORMAT(report_month,'%Y-%m') = ?");
        $stmtAB->execute([$_SESSION['user_id'], date('Y-m')]);
        if ($stmtAB->fetch()) $actionBarShowSubmit = false;
    } catch (Exception $eAB) {
        error_log('Action bar check failed: ' . $eAB->getMessage());
    }
    ?>
    <?php if ($actionBarShowSubmit && $navRole === ROLE_RZM && $currentPage !== 'dashboard.php'): ?>
    <div id="action-bar" class="action-bar" role="status">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        <span>Your <strong><?php echo date('F Y'); ?></strong> report hasn't been submitted yet.</span>
        <a href="<?php echo $baseUrl; ?>/pages/reports/create.php?month=<?php echo date('Y-m'); ?>" class="modern-button modern-button-primary btn-sm action-bar-cta" data-link-loading>Submit now <i class="fas fa-arrow-right"></i></a>
        <button type="button" class="action-bar-dismiss" onclick="this.parentElement.hidden = true" aria-label="Dismiss reminder">&times;</button>
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
