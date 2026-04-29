<?php
// CRITICAL FIX: For link mode, do NOT start a session to prevent overwriting parent window's session cookie
// Debug mode detection
@error_log('[KC_CALLBACK_DEBUG] GET[mode]: ' . (isset($_GET['mode']) ? $_GET['mode'] : 'not set'));
@error_log('[KC_CALLBACK_DEBUG] POST[mode]: ' . (isset($_POST['mode']) ? $_POST['mode'] : 'not set'));

$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_POST['mode']) ? $_POST['mode'] : '');
$isLinkMode = ($mode === 'link');

@error_log('[KC_CALLBACK_DEBUG] Detected mode: ' . $mode . ', isLinkMode: ' . ($isLinkMode ? 'YES' : 'NO'));

// For link mode with token, we don't need a session at all - just link and exit
if ($isLinkMode) {
    @error_log('[KC_CALLBACK] ===== ENTERING LINK MODE PATH (NO SESSION) =====');
    // Don't include config.php which starts session - just load what we need
    require_once __DIR__ . '/includes/database.php';
    require_once __DIR__ . '/includes/kc_link.php';
    define('BASE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/reportingportal-kclogin');
    
    @error_log('[KC_CALLBACK] ===== CALLBACK START (LINK MODE - NO SESSION) =====');
    @error_log('[KC_CALLBACK] GET params: ' . json_encode($_GET));
    @error_log('[KC_CALLBACK] POST params: ' . json_encode($_POST));
    @error_log('[KC_CALLBACK] REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'not set'));
} else {
    @error_log('[KC_CALLBACK] ===== ENTERING NON-LINK MODE PATH (STARTING SESSION) =====');
    // For login/register mode, we need the session
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/includes/kc_link.php';
    require_once __DIR__ . '/includes/auth.php';
    
    @error_log('[KC_CALLBACK] ===== CALLBACK START ===== Session ID: ' . session_id());
    @error_log('[KC_CALLBACK] User logged in: ' . (isset($_SESSION['user_id']) ? 'YES (ID: ' . $_SESSION['user_id'] . ')' : 'NO'));
    @error_log('[KC_CALLBACK] GET params: ' . json_encode($_GET));
    @error_log('[KC_CALLBACK] POST params: ' . json_encode($_POST));
    @error_log('[KC_CALLBACK] Session data: ' . json_encode(array_keys($_SESSION)));
    @error_log('[KC_CALLBACK] REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'not set'));
    @error_log('[KC_CALLBACK] HTTP_REFERER: ' . ($_SERVER['HTTP_REFERER'] ?? 'not set'));
}

// CRITICAL: If we're in link mode and user is already logged in, store their ID to prevent logout
$originalUserId = ($isLinkMode ? null : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null));

// Accept token from GET/POST/JSON/Authorization header
$token = null;
$input_data = file_get_contents('php://input');

if (!empty($_POST['accessToken'])) { $token = $_POST['accessToken']; }
if (!$token && !empty($_GET['access_token'])) { $token = $_GET['access_token']; }
if (!$token && !empty($_GET['accessToken'])) { $token = $_GET['accessToken']; }
if (!$token && !empty($input_data)) {
    $json_data = json_decode($input_data, true);
    if ($json_data && isset($json_data['accessToken'])) { $token = $json_data['accessToken']; }
}
if (!$token) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($headers['Authorization']) && strpos($headers['Authorization'], 'Bearer ') === 0) {
        $token = substr($headers['Authorization'], 7);
    }
}

// Helper to finish in popup: update opener and close self
function finish_popup_message_and_close($payload, $fallbackUrl) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Authorizing...</title></head><body>';
    echo '<script>';
    echo 'try{ if (window.opener && !window.opener.closed) { window.opener.postMessage(' . json_encode($payload) . ', "*"); } }catch(e){}';
    // DO NOT use window.location.href fallback - it would use popup's session and log out parent
    echo 'window.close();';
    echo '</script>';
    echo '<p>Authorization complete. You may close this window.</p>';
    echo '</body></html>';
    exit;
}

if (!$token) {
    // Nothing to process; send back to login
    finish_popup_message_and_close(['type'=>'KC_AUTH','error'=>'no_token'], BASE_URL . '/login.php?error=' . urlencode('No authentication data received'));
}

// Store token in session cookie for reuse (only in login/register mode)
if (!$isLinkMode && isset($_SESSION)) {
    $_SESSION['kc_access_token'] = $token;
    // Try to capture refresh token too
    $refreshToken = $_POST['refreshToken'] ?? ($_GET['refresh_token'] ?? ($_GET['refreshToken'] ?? null));
    if ($refreshToken) { $_SESSION['kc_refresh_token'] = $refreshToken; }
}

// Fetch user profile from KingsChat API
$ch = curl_init('https://connect.kingsch.at/api/profile');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    finish_popup_message_and_close(['type'=>'KC_AUTH','error'=>'auth_failed'], BASE_URL . '/login.php?error=' . urlencode('KingsChat authentication failed'));
}

$profile = json_decode($response, true);
if (!is_array($profile)) {
    finish_popup_message_and_close(['type'=>'KC_AUTH','error'=>'profile_parse'], BASE_URL . '/login.php?error=' . urlencode('KingsChat profile parse error'));
}

if (!$isLinkMode && isset($_SESSION)) {
    $_SESSION['kc_user'] = $profile;
}
$kcUserId = isset($profile['profile']['user']['user_id']) ? (string)$profile['profile']['user']['user_id'] : '';
$kcName   = isset($profile['profile']['user']['name']) ? (string)$profile['profile']['user']['name'] : 'User';

// Determine mode
$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_POST['mode']) ? $_POST['mode'] : '');

// If user is already logged into the portal, ALWAYS link this KC to that account (server-side)
if ($originalUserId && $kcUserId !== '') {
    @error_log('[KC_CALLBACK] Session available in popup! Linking KC ' . $kcUserId . ' to user ' . $originalUserId . ' (Session ID: ' . session_id() . ')');
    $linkSuccess = kc_link_user_to_kc($originalUserId, $kcUserId);
    @error_log('[KC_CALLBACK] Direct link result: ' . ($linkSuccess ? 'SUCCESS' : 'FAILED'));

    // CRITICAL: Update last_activity to prevent session timeout check from failing
    $_SESSION['last_activity'] = time();

    // CRITICAL: Preserve the original session data - DO NOT regenerate or modify
    // The parent window's session must remain intact
    $returnTo = isset($_GET['return_to']) ? $_GET['return_to'] : (BASE_URL . '/dashboard.php');
    $payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'linked','returnTo'=>$returnTo];
    @error_log('[KC_CALLBACK] Closing popup WITHOUT touching session. Original user ID: ' . $originalUserId);
    finish_popup_message_and_close($payload, $returnTo);
}

// If not logged in (popup session), but we have a link token in return_to, consume it and link
if (!$originalUserId && $kcUserId !== '') {
    $sessionInfo = $isLinkMode ? 'NO SESSION' : ('Session ID: ' . session_id());
    @error_log('[KC_CALLBACK] Session NOT available in popup (' . $sessionInfo . '), attempting token-based linking');
    $lt = isset($_GET['return_to']) ? $_GET['return_to'] : '';
    @error_log('[KC_CALLBACK] return_to parameter: ' . $lt);
    if (is_string($lt) && strpos($lt, 'lt=') !== false) {
        // Extract lt value
        $parts = parse_url($lt);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $qs);
            if (!empty($qs['lt'])) {
                @error_log('[KC_CALLBACK] Found link token: ' . substr($qs['lt'], 0, 10) . '...');
                $userId = kc_consume_link_token($qs['lt']);
                if ($userId) {
                    @error_log('[KC_CALLBACK] Token valid! Linking user ' . (int)$userId . ' to KC ' . $kcUserId);
                    $linkSuccess = kc_link_user_to_kc((int)$userId, $kcUserId);
                    @error_log('[KC_CALLBACK] Token-based link result: ' . ($linkSuccess ? 'SUCCESS' : 'FAILED'));
                    @error_log('[KC_CALLBACK] EXITING NOW - Parent window session should remain intact');
                    $payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'linked','returnTo'=> (BASE_URL . '/dashboard.php')];
                    finish_popup_message_and_close($payload, BASE_URL . '/dashboard.php');
                    // This line should NEVER execute
                    @error_log('[KC_CALLBACK] ERROR: Did not exit after finish_popup_message_and_close!');
                    die('CRITICAL: finish_popup_message_and_close did not exit');
                } else {
                    @error_log('[KC_CALLBACK] ERROR: Token invalid or expired');
                    // Exit here to prevent falling through to login logic which would regenerate session
                    $payload = ['type'=>'KC_AUTH','error'=>'token_expired','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
                    finish_popup_message_and_close($payload, BASE_URL . '/profile.php?error=token_expired');
                }
            } else {
                @error_log('[KC_CALLBACK] ERROR: No lt parameter found in query string');
            }
        } else {
            @error_log('[KC_CALLBACK] ERROR: Could not parse return_to URL query string');
        }
    } else {
        @error_log('[KC_CALLBACK] ERROR: return_to parameter missing or does not contain lt=');
    }
    // If we're in link mode but token-based linking failed, exit to prevent session issues
    if ($mode === 'link') {
        @error_log('[KC_CALLBACK] Link mode but no valid token, closing popup to prevent session issues');
        $payload = ['type'=>'KC_AUTH','error'=>'link_failed','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
        finish_popup_message_and_close($payload, BASE_URL . '/profile.php?error=link_failed');
    }
}

// IMPORTANT: Never regenerate session if mode is 'link' - this would destroy the parent window's session
if ($mode === 'link') {
    @error_log('[KC_CALLBACK] CRITICAL: Link mode detected but no exit occurred above. Forcing exit to prevent session destruction.');
    @error_log('[KC_CALLBACK] Original user ID was: ' . ($originalUserId ?: 'NONE'));
    $payload = ['type'=>'KC_AUTH','error'=>'unexpected_flow','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
    finish_popup_message_and_close($payload, BASE_URL . '/profile.php?error=unexpected_flow');
}

// DOUBLE CHECK: If original user was logged in, NEVER regenerate session (even if mode wasn't set correctly)
if ($originalUserId) {
    @error_log('[KC_CALLBACK] CRITICAL: Original user ' . $originalUserId . ' was logged in. Refusing to regenerate session to prevent logout.');
    $payload = ['type'=>'KC_AUTH','error'=>'session_protection','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
    finish_popup_message_and_close($payload, BASE_URL . '/profile.php?session_protected=1');
}

// Login via KC mapping (ONLY for login mode when NOT already logged in)
if ($kcUserId !== '') {
    $mappedUserId = kc_get_user_id_by_kc($kcUserId);
    if ($mappedUserId) {
        @error_log('[KC_CALLBACK] Login flow: Current user: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NONE') . ', Logging in as: ' . $mappedUserId);

        // CRITICAL: If the user was originally logged in, NEVER regenerate the session
        // because it would change the session ID and log out the parent window
        if ($originalUserId !== null) {
            @error_log('[KC_CALLBACK] Original user was logged in (' . $originalUserId . '), skipping session regeneration to prevent parent logout');
            // Don't regenerate session if user was originally logged in
        } else {
            // Check if this looks like a linking operation (token in return_to)
            $returnTo = isset($_GET['return_to']) ? $_GET['return_to'] : '';
            $hasToken = strpos($returnTo, 'lt=') !== false;
            @error_log('[KC_CALLBACK] Linking check: return_to=' . $returnTo . ', hasToken=' . ($hasToken ? 'yes' : 'no') . ', originalUserId=null');

            if ($hasToken) {
                @error_log('[KC_CALLBACK] Detected linking operation via token, skipping session regeneration');
                // Don't regenerate session for linking operations
            } else {
                @error_log('[KC_CALLBACK] About to regenerate session for login... Session ID before: ' . session_id());
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                    @error_log('[KC_CALLBACK] Session regenerated. New session ID: ' . session_id());
                }
            }
        }
        $_SESSION['user_id'] = (int)$mappedUserId;
        $_SESSION['last_activity'] = time();
        Auth::recordUserVisit((int)$mappedUserId, 'kingschat');

        // Don't show KC link prompt if they're logging in via KingsChat (already linked)
        // The prompt is only for username/password logins

        $auth = new Auth();
        $user = $auth->getUser((int)$mappedUserId);
        if ($user) {
            $_SESSION['user_data'] = [
                'id' => (int)$user['id'],
                'username' => htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'),
                'name' => htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'),
                'role' => $user['role'],
                'region' => htmlspecialchars((string)$user['region'], ENT_QUOTES, 'UTF-8'),
                'zone' => htmlspecialchars((string)$user['zone'], ENT_QUOTES, 'UTF-8')
            ];
        }
        $returnTo = isset($_GET['return_to']) ? $_GET['return_to'] : (BASE_URL . '/dashboard.php');
        $payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'login','returnTo'=>$returnTo];
        finish_popup_message_and_close($payload, $returnTo);
    }
}

// No mapping: go to register and remember KC id
$payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'register','returnTo'=> (BASE_URL . '/register.php')];
finish_popup_message_and_close($payload, BASE_URL . '/register.php');
?>
