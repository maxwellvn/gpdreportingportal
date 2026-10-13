<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/kc_link.php';

header('Content-Type: application/json');

// Log the incoming request for debugging
@error_log('[KC_LINK] Request received - Session ID: ' . session_id() . ', User ID: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set'));

if (!isset($_SESSION['user_id'])) {
    @error_log('[KC_LINK] ERROR: User not logged in (session user_id not set)');
    echo json_encode(['ok' => false, 'error' => 'not_logged_in', 'message' => 'You must be logged in to link your KingsChat account']);
    exit;
}

$kcUserId = isset($_POST['kc_user_id']) ? trim($_POST['kc_user_id']) : '';
if ($kcUserId === '') {
    @error_log('[KC_LINK] ERROR: Missing KC user ID in POST data');
    echo json_encode(['ok' => false, 'error' => 'missing_kc_user_id', 'message' => 'KingsChat user ID is required']);
    exit;
}

// Only link an id this server verified with KingsChat in kc_callback.php; never trust the client's value.
if (!hash_equals((string)($_SESSION['kc_verified_user_id'] ?? ''), $kcUserId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unverified_kc_user', 'message' => 'Please sign in with KingsChat again to link your account']);
    exit;
}

// Check if already linked to the SAME KC account
$existingKcId = kc_get_kc_by_user_id((int)$_SESSION['user_id']);
if ($existingKcId === $kcUserId) {
    @error_log('[KC_LINK] Account already linked to this KC ID, returning success');
    echo json_encode(['ok' => true, 'message' => 'KingsChat account already linked', 'already_linked' => true]);
    exit;
}

// Check if already linked to a DIFFERENT KC account
if ($existingKcId && $existingKcId !== $kcUserId) {
    @error_log('[KC_LINK] WARNING: User ' . (int)$_SESSION['user_id'] . ' already linked to different KC account: ' . $existingKcId . ', unlinking to relink to ' . $kcUserId);
}

$ok = kc_link_user_to_kc((int)$_SESSION['user_id'], $kcUserId);
@error_log('[KC_LINK] Result: user_id=' . (int)$_SESSION['user_id'] . ' kc=' . $kcUserId . ' success=' . ($ok ? 'YES' : 'NO'));

if (!$ok) {
    echo json_encode(['ok' => false, 'error' => 'link_failed', 'message' => 'Failed to save account link. Please try again.']);
} else {
    echo json_encode(['ok' => true, 'message' => 'KingsChat account linked successfully']);
}
exit;
?>
