<?php
// KingsChat linkage stored in JSON for portability

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function kc_links_path() {
    $secureDir = __DIR__ . '/../secure_data';
    if (!is_dir($secureDir)) {
        @mkdir($secureDir, 0775, true);
        @chmod($secureDir, 0775);
    }
    $path = $secureDir . '/kc_linkages.json';
    // Fallback to kingschat dir if secure_data not writable
    if (!is_writable(dirname($path))) {
        $fallback = __DIR__ . '/../kingschat/kc_linkages.json';
        return $fallback;
    }
    return $path;
}

function kc_links_load() {
    $path = kc_links_path();
    if (!file_exists($path)) {
        return [ 'kc_to_user' => [], 'user_to_kc' => [] ];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [ 'kc_to_user' => [], 'user_to_kc' => [] ];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [ 'kc_to_user' => [], 'user_to_kc' => [] ];
    }
    $json['kc_to_user'] = isset($json['kc_to_user']) && is_array($json['kc_to_user']) ? $json['kc_to_user'] : [];
    $json['user_to_kc'] = isset($json['user_to_kc']) && is_array($json['user_to_kc']) ? $json['user_to_kc'] : [];
    return $json;
}

function kc_links_save($data) {
    $path = kc_links_path();
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT);

    // Make file writable if it exists
    if (file_exists($path)) {
        @chmod($path, 0666);
    }

    // Try atomic write
    if (@file_put_contents($tmp, $json) !== false) {
        @chmod($tmp, 0666);
        if (@rename($tmp, $path)) {
            @chmod($path, 0666);
            return true;
        }
        // Clean up temp file if rename failed
        @unlink($tmp);
    }

    // Fallback direct write
    if (@file_put_contents($path, $json) !== false) {
        @chmod($path, 0666);
        return true;
    }

    return false;
}

// One-time link token storage (to bypass third-party cookie issues)
function kc_link_tokens_path() {
    $secureDir = __DIR__ . '/../secure_data';
    if (!is_dir($secureDir)) {
        @mkdir($secureDir, 0775, true);
        @chmod($secureDir, 0775);
    }
    return $secureDir . '/kc_link_tokens.json';
}

function kc_link_tokens_load() {
    $path = kc_link_tokens_path();
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function kc_link_tokens_save($tokens) {
    $path = kc_link_tokens_path();
    $json = json_encode($tokens, JSON_PRETTY_PRINT);
    if (@file_put_contents($path . '.tmp', $json) !== false && @rename($path . '.tmp', $path)) {
        @chmod($path, 0664);
        return true;
    }
    if (@file_put_contents($path, $json) !== false) {
        @chmod($path, 0664);
        return true;
    }
    return false;
}

function kc_generate_link_token($userId) {
    $token = bin2hex(random_bytes(16));
    $tokens = kc_link_tokens_load();

    // Clean up expired tokens to prevent file bloat
    $now = time();
    foreach ($tokens as $key => $data) {
        if (isset($data['expires_at']) && $data['expires_at'] < $now) {
            unset($tokens[$key]);
        }
    }

    // Extend token lifetime to 30 minutes (1800 seconds) to account for OAuth delays
    $tokens[$token] = [ 'user_id' => (int)$userId, 'expires_at' => time() + 1800 ];
    kc_link_tokens_save($tokens);
    @error_log('[KC_TOKEN] Generated token for user ' . $userId . ', expires at ' . date('Y-m-d H:i:s', time() + 1800));
    return $token;
}

function kc_consume_link_token($token) {
    $tokens = kc_link_tokens_load();
    if (!isset($tokens[$token])) {
        @error_log('[KC_TOKEN] Token not found: ' . substr($token, 0, 10) . '...');
        return null;
    }
    $entry = $tokens[$token];

    // Check expiration before removing
    if (!isset($entry['expires_at']) || $entry['expires_at'] < time()) {
        @error_log('[KC_TOKEN] Token expired: ' . substr($token, 0, 10) . '... (expired at ' . date('Y-m-d H:i:s', $entry['expires_at'] ?? 0) . ')');
        unset($tokens[$token]);
        kc_link_tokens_save($tokens);
        return null;
    }

    // Token is valid, consume it
    $userId = isset($entry['user_id']) ? (int)$entry['user_id'] : null;
    @error_log('[KC_TOKEN] Consuming valid token for user ' . $userId);
    unset($tokens[$token]);
    kc_link_tokens_save($tokens);
    return $userId;
}

function kc_get_user_id_by_kc($kcUserId) {
    $map = kc_links_load();
    $kcUserId = (string)$kcUserId;
    if (isset($map['kc_to_user'][$kcUserId])) {
        $id = (int)$map['kc_to_user'][$kcUserId];
        return $id > 0 ? $id : null;
    }
    return null;
}

function kc_get_kc_by_user_id($userId) {
    $map = kc_links_load();
    $userId = (string)(int)$userId;
    if (isset($map['user_to_kc'][$userId])) {
        $kc = (string)$map['user_to_kc'][$userId];
        return $kc !== '' ? $kc : null;
    }
    return null;
}

function kc_is_user_linked($userId) {
    return kc_get_kc_by_user_id($userId) !== null;
}

function kc_link_user_to_kc($userId, $kcUserId) {
    $userId = (int)$userId;
    $kcUserId = (string)$kcUserId;
    if ($userId <= 0 || $kcUserId === '') return false;
    $map = kc_links_load();
    // Remove previous mappings to keep one-to-one
    if (isset($map['kc_to_user'][$kcUserId])) {
        $oldUser = (string)(int)$map['kc_to_user'][$kcUserId];
        unset($map['user_to_kc'][$oldUser]);
    }
    $userKey = (string)$userId;
    if (isset($map['user_to_kc'][$userKey])) {
        $oldKc = (string)$map['user_to_kc'][$userKey];
        unset($map['kc_to_user'][$oldKc]);
    }
    $map['kc_to_user'][$kcUserId] = $userId;
    $map['user_to_kc'][$userKey] = $kcUserId;
    return kc_links_save($map);
}

?>


