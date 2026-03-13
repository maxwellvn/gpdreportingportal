<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class Auth {
    private $db;
    private $security;
    private $userId;

    public function __construct() {
        $this->db = new Database();
        $this->security = new Security();
    }

    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Check if the session has timed out
     * @return bool True if session has timed out, false otherwise
     */
    public static function checkSessionTimeout() {
        // CRITICAL: If KC linking is in progress, DON'T check timeout or destroy session
        // Use timestamp instead of boolean to allow multiple checks during OAuth flow
        if (isset($_SESSION['kc_linking_in_progress']) && is_numeric($_SESSION['kc_linking_in_progress'])) {
            $linkingStartTime = (int)$_SESSION['kc_linking_in_progress'];
            $linkingAge = time() - $linkingStartTime;

            // Allow up to 5 minutes for the OAuth flow to complete
            if ($linkingAge < 300) {
                @error_log('[SESSION_TIMEOUT] KC linking in progress (started ' . $linkingAge . 's ago), skipping timeout check');
                $_SESSION['last_activity'] = time(); // Keep updating activity
                return false;
            } else {
                // Linking took too long, clear flag and resume normal timeout checks
                @error_log('[SESSION_TIMEOUT] KC linking expired after ' . $linkingAge . 's, resuming timeout checks');
                unset($_SESSION['kc_linking_in_progress']);
            }
        }

        @error_log('[SESSION_TIMEOUT] Checking timeout - Session ID: ' . session_id() . ', last_activity: ' . (isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : 'not set') . ', time diff: ' . (isset($_SESSION['last_activity']) ? (time() - $_SESSION['last_activity']) : 'N/A') . ', timeout: ' . SESSION_TIMEOUT);
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
            @error_log('[SESSION_TIMEOUT] No last_activity set, setting to now');
            return false;
        }

        if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
            // Session has expired
            @error_log('[SESSION_TIMEOUT] Session expired, destroying session');
            session_unset();
            session_destroy();
            return true;
        }

        // Update last activity time
        $_SESSION['last_activity'] = time();
        @error_log('[SESSION_TIMEOUT] Session still valid, updated last_activity');
        return false;
    }

    /**
     * Require active login session
     */
    public static function requireLogin() {
        @error_log('[REQUIRE_LOGIN] Checking login - user_id set: ' . (isset($_SESSION['user_id']) ? 'yes' : 'no'));
        if (!isset($_SESSION['user_id']) || self::checkSessionTimeout()) {
            @error_log('[REQUIRE_LOGIN] Login check failed, redirecting to login.php');
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        @error_log('[REQUIRE_LOGIN] Login check passed');
    }

    public function getUser($userId) {
        try {
            $stmt = $this->db->getConnection()->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting user: " . $e->getMessage());
            return null;
        }
    }

    public function register($username, $name, $password, $email, $region, $zone, $role = 'user') {
        try {
            // Validate input
            $required = ['username', 'name', 'password', 'email', 'region'];
            if ($role === 'user') {
                $required[] = 'zone';
            }
            Security::validateRequired($required, compact('username', 'name', 'password', 'email', 'region', 'zone'));

            // Validate email
            if (!Security::validateEmail($email)) {
                throw new Exception('Invalid email format');
            }

            // Check if username or email already exists
            $stmt = $this->db->getConnection()->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->rowCount() > 0) {
                throw new Exception('Username or email already exists');
            }

            // Hash password
            $hashedPassword = Security::hashPassword($password);

            // Insert user
            $stmt = $this->db->getConnection()->prepare(
                "INSERT INTO users (username, name, password, email, role, region, zone) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$username, $name, $hashedPassword, $email, $role, $region, $zone]);

            return true;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function login($username, $password) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $this->userId = $user['id'];
            return true;
        }
        return false;
    }

    /**
     * Find a user by KingsChat user id
     */
    public function findUserIdByKingsChatId($kcUserId) {
        try {
            $stmt = $this->db->getConnection()->prepare("SELECT id FROM users WHERE kc_user_id = ? AND active = 1");
            $stmt->execute([$kcUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (Exception $e) {
            error_log('Error finding user by KingsChat ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Link an authenticated user account to KingsChat user id
     */
    public function linkKingsChatToUser($userId, $kcUserId) {
        try {
            $stmt = $this->db->getConnection()->prepare("UPDATE users SET kc_user_id = ?, kc_linked_at = NOW() WHERE id = ?");
            return $stmt->execute([$kcUserId, $userId]);
        } catch (Exception $e) {
            error_log('Error linking KingsChat to user: ' . $e->getMessage());
            return false;
        }
    }

    public function logout() {
        // Unset all session variables
        $_SESSION = array();

        // Destroy the session
        session_destroy();

        // Redirect to login page
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    public static function isAdmin() {
        return isset($_SESSION['user_data']['role']) && $_SESSION['user_data']['role'] === 'admin';
    }

    public static function isAdminManager() {
        return isset($_SESSION['user_data']['role']) && $_SESSION['user_data']['role'] === 'admin-manager';
    }

    public static function canManageRegionalReports() {
        return self::isAdmin() || self::isAdminManager();
    }

    public static function getScopedRegion() {
        if (self::isAdminManager()) {
            return $_SESSION['user_data']['region'] ?? '';
        }
        return '';
    }

    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }

    // Password reset functionality removed
    // public function resetPasswordRequest($email) {
    //     try {
    //         // Validate email
    //         if (!Security::validateEmail($email)) {
    //             throw new Exception('Invalid email format');
    //         }

    //         // Check if email exists
    //         $stmt = $this->db->getConnection()->prepare("SELECT id FROM users WHERE email = ?");
    //         $stmt->execute([$email]);
    //         if ($stmt->rowCount() === 0) {
    //             throw new Exception('Email not found');
    //         }

    //         // Generate reset token
    //         $token = Security::generateToken();
    //         $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    //         // Save token
    //         $stmt = $this->db->getConnection()->prepare(
    //             "UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE email = ?"
    //         );
    //         $stmt->execute([$token, $expires, $email]);

    //         return $token;
    //     } catch (Exception $e) {
    //         throw new Exception($e->getMessage());
    //     }
    // }

    // public function resetPassword($token, $newPassword) {
    //     try {
    //         // Validate token
    //         $stmt = $this->db->getConnection()->prepare(
    //             "SELECT id FROM users
    //              WHERE reset_token = ? AND reset_token_expires > NOW()"
    //         );
    //         $stmt->execute([$token]);
    //         if ($stmt->rowCount() === 0) {
    //             throw new Exception('Invalid or expired token');
    //         }

    //         // Hash new password
    //         $hashedPassword = Security::hashPassword($newPassword);

    //         // Update password and clear token
    //         $stmt = $this->db->getConnection()->prepare(
    //             "UPDATE users
    //              SET password = ?, reset_token = NULL, reset_token_expires = NULL
    //              WHERE reset_token = ?"
    //         );
    //         $stmt->execute([$hashedPassword, $token]);

    //         return true;
    //     } catch (Exception $e) {
    //         throw new Exception($e->getMessage());
    //     }
    // }

    /**
     * Get the ID of the authenticated user
     * @return int|null The user ID if set, null otherwise
     */
    public function getUserId() {
        return $this->userId;
    }
}
?>
