<?php
// KingsChat posts the access token here cross-site (post_redirect), so this endpoint is exempt from the
// global CSRF gate. Link mode deliberately avoids starting a session so the opener's session is untouched.
$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_POST['mode']) ? $_POST['mode'] : '');
$isLinkMode = ($mode === 'link');

if ($isLinkMode) {
    require_once __DIR__ . '/includes/env.php';
    loadEnvFile(__DIR__ . '/.env');
    require_once __DIR__ . '/includes/database.php';
    require_once __DIR__ . '/includes/kc_link.php';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $configuredBase = rtrim((string)(getenv('APP_BASE_URL') ?: ''), '/');
    define('BASE_URL', preg_match('#^https?://#i', $configuredBase)
        ? $configuredBase
        : $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $configuredBase);
} else {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/includes/kc_link.php';
    require_once __DIR__ . '/includes/auth.php';
}

/** Only allow redirects back into this portal. */
function kc_safe_return_to($url, $default) {
    if (!is_string($url) || $url === '') return $default;
    $parts = parse_url($url);
    if ($parts === false) return $default;
    $baseHost = parse_url(BASE_URL, PHP_URL_HOST);
    if (isset($parts['host'])) {
        return (strcasecmp($parts['host'], (string)$baseHost) === 0) ? $url : $default;
    }
    // Relative: must be a single-slash path, not protocol-relative (//evil.com)
    return (strpos($url, '/') === 0 && strpos($url, '//') !== 0) ? $url : $default;
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
    echo 'try{ if (window.opener && !window.opener.closed) { window.opener.postMessage(' . json_encode($payload) . ', window.location.origin); } }catch(e){}';
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

$kcUserId = isset($profile['profile']['user']['user_id']) ? (string)$profile['profile']['user']['user_id'] : '';
$kcName   = isset($profile['profile']['user']['name']) ? (string)$profile['profile']['user']['name'] : 'User';

// Remember which KingsChat id the server actually verified; kc_link_account.php only links this one.
if (!$isLinkMode && $kcUserId !== '') {
    $_SESSION['kc_verified_user_id'] = $kcUserId;
}

// Determine mode
$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_POST['mode']) ? $_POST['mode'] : '');

// If user is already logged into the portal, ALWAYS link this KC to that account (server-side)
if ($originalUserId && $kcUserId !== '') {
    $linkSuccess = kc_link_user_to_kc($originalUserId, $kcUserId);

    // CRITICAL: Update last_activity to prevent session timeout check from failing
    $_SESSION['last_activity'] = time();

    // CRITICAL: Preserve the original session data - DO NOT regenerate or modify
    // The parent window's session must remain intact
    $returnTo = kc_safe_return_to($_GET['return_to'] ?? '', BASE_URL . '/dashboard.php');
    $payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'linked','returnTo'=>$returnTo];
    finish_popup_message_and_close($payload, $returnTo);
}

// If not logged in (popup session), but we have a link token in return_to, consume it and link
if (!$originalUserId && $kcUserId !== '') {
    $lt = isset($_GET['return_to']) ? $_GET['return_to'] : '';
    if (is_string($lt) && strpos($lt, 'lt=') !== false) {
        // Extract lt value
        $parts = parse_url($lt);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $qs);
            if (!empty($qs['lt'])) {
                $userId = kc_consume_link_token($qs['lt']);
                if ($userId) {
                    $linkSuccess = kc_link_user_to_kc((int)$userId, $kcUserId);
                    $payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'linked','returnTo'=> (BASE_URL . '/dashboard.php')];
                    finish_popup_message_and_close($payload, BASE_URL . '/dashboard.php');
                    // This line should NEVER execute
                    die('CRITICAL: finish_popup_message_and_close did not exit');
                } else {
                    // Exit here to prevent falling through to login logic which would regenerate session
                    $payload = ['type'=>'KC_AUTH','error'=>'token_expired','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
                    finish_popup_message_and_close($payload, BASE_URL . '/profile.php?error=token_expired');
                }
            }
        }
    }
    // If we're in link mode but token-based linking failed, exit to prevent session issues
    if ($mode === 'link') {
        $payload = ['type'=>'KC_AUTH','error'=>'link_failed','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
        finish_popup_message_and_close($payload, BASE_URL . '/profile.php?error=link_failed');
    }
}

// IMPORTANT: Never regenerate session if mode is 'link' - this would destroy the parent window's session
if ($mode === 'link') {
    $payload = ['type'=>'KC_AUTH','error'=>'unexpected_flow','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
    finish_popup_message_and_close($payload, BASE_URL . '/profile.php?error=unexpected_flow');
}

// DOUBLE CHECK: If original user was logged in, NEVER regenerate session (even if mode wasn't set correctly)
if ($originalUserId) {
    $payload = ['type'=>'KC_AUTH','error'=>'session_protection','mode'=>'link','returnTo'=> (BASE_URL . '/profile.php')];
    finish_popup_message_and_close($payload, BASE_URL . '/profile.php?session_protected=1');
}

// Login via KC mapping (ONLY for login mode when NOT already logged in)
if ($kcUserId !== '') {
    $mappedUserId = kc_get_user_id_by_kc($kcUserId);
    if ($mappedUserId) {

        // Fresh session id on every login (prevents fixation). Logged-in users never reach here.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
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
        $returnTo = kc_safe_return_to($_GET['return_to'] ?? '', BASE_URL . '/dashboard.php');
        $payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'login','returnTo'=>$returnTo];
        finish_popup_message_and_close($payload, $returnTo);
    }
}

// No mapping: go to register and remember KC id
$payload = ['type'=>'KC_AUTH','kcUserId'=>$kcUserId,'mode'=>'register','returnTo'=> (BASE_URL . '/register.php')];
finish_popup_message_and_close($payload, BASE_URL . '/register.php');
?>
