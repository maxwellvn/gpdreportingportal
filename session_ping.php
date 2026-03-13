<?php
// Simple session ping to update last_activity before page reload
require_once __DIR__ . '/config/config.php';

// Just update last_activity to prevent timeout
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    // Clear KC linking flag if set (linking completed successfully)
    if (isset($_SESSION['kc_linking_in_progress'])) {
        unset($_SESSION['kc_linking_in_progress']);
    }
    echo json_encode(['ok' => true, 'user_id' => $_SESSION['user_id']]);
} else {
    echo json_encode(['ok' => false]);
}
exit;
