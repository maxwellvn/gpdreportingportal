<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/audit.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Not logged in';
    header('Location: users.php');
    exit;
}

// Get user data
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id']);

// Check if user is admin
if (!isset($currentUser['role']) || $currentUser['role'] !== 'admin') {
    $_SESSION['error'] = 'Not authorized';
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    // CSRF validation
    try {
        if (!isset($_POST['csrf_token'])) {
            throw new Exception('Missing CSRF token');
        }
        Security::verifyCSRFToken($_POST['csrf_token']);
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: users.php');
        exit;
    }
    try {
        $userId = (int)$_POST['user_id'];
        
        // Don't allow deleting self
        if ($userId === (int)$_SESSION['user_id']) {
            $_SESSION['error'] = 'Cannot delete your own account';
            header('Location: users.php');
            exit;
        }
        
        $db = new Database();
        $conn = $db->getConnection();
        
        // Get user info for success message
        $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        // Delete user
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        Audit::log('delete_user', 'user', $userId, 'Deleted user ' . ($user ? $user['username'] : '#' . $userId));
        $_SESSION['success'] = 'User ' . ($user ? $user['username'] : 'ID: ' . $userId) . ' has been deleted successfully';
        header('Location: users.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting user: ' . $e->getMessage();
        header('Location: users.php');
        exit;
    }
} else {
    $_SESSION['error'] = 'Invalid request';
    header('Location: users.php');
    exit;
}
?>
