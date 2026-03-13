<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/kc_link.php';

header('Content-Type: application/json');

@error_log('[KC_UNLINK] Request received - Session ID: ' . session_id());
@error_log('[KC_UNLINK] User ID from session: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));

if (!isset($_SESSION['user_id'])) {
    @error_log('[KC_UNLINK] ERROR: User not logged in');
    echo json_encode(['ok' => false, 'error' => 'not_logged_in', 'message' => 'You must be logged in to unlink your KingsChat account']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$kcUserId = kc_get_kc_by_user_id($userId);

@error_log('[KC_UNLINK] Current KC link for user ' . $userId . ': ' . ($kcUserId ? $kcUserId : 'NONE'));

if (!$kcUserId) {
    @error_log('[KC_UNLINK] WARNING: User ' . $userId . ' has no linked KC account');
    echo json_encode(['ok' => false, 'error' => 'not_linked', 'message' => 'No KingsChat account is linked']);
    exit;
}

// Get the linkages file path and check permissions
$linksPath = kc_links_path();
@error_log('[KC_UNLINK] Linkages file path: ' . $linksPath);
@error_log('[KC_UNLINK] File exists: ' . (file_exists($linksPath) ? 'YES' : 'NO'));
@error_log('[KC_UNLINK] File writable: ' . (is_writable($linksPath) ? 'YES' : 'NO'));
@error_log('[KC_UNLINK] Directory writable: ' . (is_writable(dirname($linksPath)) ? 'YES' : 'NO'));

// Remove the link by loading the map, removing entries, and saving
$map = kc_links_load();
@error_log('[KC_UNLINK] Loaded map - user_to_kc count: ' . count($map['user_to_kc']) . ', kc_to_user count: ' . count($map['kc_to_user']));

$userKey = (string)$userId;

// Remove both directions of the mapping
if (isset($map['user_to_kc'][$userKey])) {
    $oldKc = (string)$map['user_to_kc'][$userKey];
    @error_log('[KC_UNLINK] Removing link: user=' . $userKey . ' -> kc=' . $oldKc);
    unset($map['user_to_kc'][$userKey]);
    unset($map['kc_to_user'][$oldKc]);
} else {
    @error_log('[KC_UNLINK] WARNING: User key ' . $userKey . ' not found in user_to_kc map');
}

@error_log('[KC_UNLINK] After removal - user_to_kc count: ' . count($map['user_to_kc']) . ', kc_to_user count: ' . count($map['kc_to_user']));

$ok = kc_links_save($map);
@error_log('[KC_UNLINK] Save result: ' . ($ok ? 'SUCCESS' : 'FAILED'));

if (!$ok) {
    // Try to get more details about why it failed
    $errorMsg = 'Failed to save changes';
    if (!is_writable(dirname($linksPath))) {
        $errorMsg = 'Directory not writable: ' . dirname($linksPath);
    } elseif (file_exists($linksPath) && !is_writable($linksPath)) {
        $errorMsg = 'File not writable: ' . $linksPath;
    }
    @error_log('[KC_UNLINK] Failure reason: ' . $errorMsg);
    echo json_encode(['ok' => false, 'error' => 'unlink_failed', 'message' => $errorMsg]);
} else {
    @error_log('[KC_UNLINK] Successfully unlinked user ' . $userId . ' from KC ' . $kcUserId);
    echo json_encode(['ok' => true, 'message' => 'KingsChat account unlinked successfully']);
}
exit;
