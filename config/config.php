<?php
require_once __DIR__ . '/../includes/env.php';
loadEnvFile(__DIR__ . '/../.env');
require_once __DIR__ . '/../includes/hierarchy.php';

// Set default timezone to prevent date/time issues
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

// Session configuration - MUST be set before session_start()
$sessionLifetime = (int)(getenv('SESSION_LIFETIME') ?: 86400);
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
ini_set('session.cookie_lifetime', (string)$sessionLifetime);

// Determine if we're on HTTPS (explicit check for localhost)
$isSecure = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $isSecure = true;
}

// Determine the cookie path from the script path
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$cookiePath = getenv('SESSION_COOKIE_PATH') ?: '/';
if (strpos($scriptPath, '/reportingportal') !== false) {
    // Extract app directory for cookie path
    $parts = explode('/', trim($scriptPath, '/'));
    if (!empty($parts[0])) {
        $cookiePath = '/' . $parts[0];
    }
}

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => $cookiePath,
    'domain' => '',
    'secure' => $isSecure,  // Explicitly false on localhost HTTP
    'httponly' => true,
    'samesite' => getenv('SESSION_SAMESITE') ?: 'Lax'
]);

// Define session timeout duration
define('SESSION_TIMEOUT', (int)(getenv('SESSION_TIMEOUT') ?: 86400));

// Now start the session
session_start();

// Force session to be writable (common fix for XAMPP/localhost)
if (session_status() === PHP_SESSION_ACTIVE) {
    // Ensure session data is written immediately
    register_shutdown_function('session_write_close');
}

// Include database configuration
require_once __DIR__ . '/../includes/database.php';

// Define server environment
// Auto-detect environment based on hostname
$productionDomains = ['gpdreportingportal.org', 'www.gpdreportingportal.org'];
$currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isProduction = in_array($currentHost, $productionDomains);
$envFromFile = strtolower((string)(getenv('APP_ENV') ?: ''));
if (in_array($envFromFile, ['production', 'development'], true)) {
    define('ENVIRONMENT', $envFromFile);
} else {
    define('ENVIRONMENT', $isProduction ? 'production' : 'development');
}

// Base URL configuration
if (php_sapi_name() === 'cli') {
    define('BASE_URL', '');
} else {
    $configuredBaseUrl = trim((string)(getenv('APP_BASE_URL') ?: ''));
    if ($configuredBaseUrl !== '') {
        if ($configuredBaseUrl === '/') {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            define('BASE_URL', $protocol . '://' . $host);
        } elseif (preg_match('#^https?://#i', $configuredBaseUrl)) {
            define('BASE_URL', rtrim($configuredBaseUrl, '/'));
        } elseif (strpos($configuredBaseUrl, '/') === 0) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            define('BASE_URL', $protocol . '://' . $host . rtrim($configuredBaseUrl, '/'));
        } else {
            define('BASE_URL', rtrim($configuredBaseUrl, '/'));
        }
    } else {
    if (ENVIRONMENT === 'production') {
        define('BASE_URL', 'https://gpdreportingportal.org');  // Production URL
    } else {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // For local development, include the subdirectory path
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $appDir = '';

        // Extract the application directory from script path
        // Method 1: Check for specific directory name
        if (strpos($scriptPath, '/reportingportal/') !== false) {
            $appDir = '/reportingportal';
        }

        // Method 2: Dynamically determine the application directory
        if (empty($appDir)) {
            $scriptDir = dirname($scriptPath);
            // If we're in a subdirectory of the web root, use that
            if ($scriptDir !== '/' && $scriptDir !== '\\') {
                // Get the first directory level
                $parts = explode('/', trim($scriptDir, '/'));
                if (!empty($parts[0])) {
                    $appDir = '/' . $parts[0];
                }
            }
        }

        define('BASE_URL', $protocol . '://' . $host . $appDir);
    }
    }
}

// Error reporting - Production settings
if (ENVIRONMENT === 'production') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);  // Updated error reporting
    ini_set('display_errors', 0);  // Disable error display in production
    ini_set('display_startup_errors', 0);  // Disable startup errors in production
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');  // Updated error log path

    // Set PHP error handler
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        $message = date('Y-m-d H:i:s') . " - Error: [$errno] $errstr - $errfile:$errline\n";
        error_log($message);
        return true;
    });

    // Set exception handler
    set_exception_handler(function($e) {
        $message = date('Y-m-d H:i:s') . " - Exception: " . $e->getMessage() .
                  " in " . $e->getFile() . " on line " . $e->getLine() . "\n" .
                  "Stack trace: " . $e->getTraceAsString() . "\n";
        error_log($message);
    });
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

// Pagination configuration
define('ITEMS_PER_PAGE', 10);

// Function to load regions and zones
function getRegionsZones() {
    return loadRegionsZonesHierarchy();
}

// Load regions and zones
$REGIONS_ZONES = getRegionsZones();

// User roles
define('ROLE_ADMIN', 'admin');
define('ROLE_ADMIN_MANAGER', 'admin-manager');
define('ROLE_USER', 'user');

// Report statuses
define('STATUS_DRAFT', 'draft');
define('STATUS_SUBMITTED', 'submitted');
define('STATUS_APPROVED', 'approved');
define('STATUS_REJECTED', 'rejected');

// File upload settings
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf']);

// Upload directories
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('DOCUMENTS_DIR', UPLOAD_DIR . '/documents');
define('PICTURES_DIR', UPLOAD_DIR . '/pictures');

// Define a constant for debugging
define('DEBUG_MODE', ENVIRONMENT !== 'production');

// For debugging environment issues (remove in production)
if (DEBUG_MODE && isset($_GET['debug_env'])) {
    echo '<pre>';
    echo "Environment: " . ENVIRONMENT . "\n";
    echo "Base URL: " . BASE_URL . "\n";
    echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'not set') . "\n";
    echo "HTTPS: " . (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'not set') . "\n";
    echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'not set') . "\n";
    echo "PHP_SELF: " . ($_SERVER['PHP_SELF'] ?? 'not set') . "\n";
    echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set') . "\n";
    echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "\n";
    echo '</pre>';
}
?>
