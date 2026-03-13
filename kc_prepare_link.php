<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/kc_link.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

// CRITICAL: Set timestamp flag to prevent session timeout during linking
// Using timestamp allows multiple timeout checks during the OAuth flow
$_SESSION['kc_linking_in_progress'] = time();
$_SESSION['last_activity'] = time();

$token = kc_generate_link_token((int)$_SESSION['user_id']);
echo json_encode(['ok' => true, 'token' => $token]);
exit;
?>
