<?php
/**
 * Security regression checks against a running local copy.
 * Usage: php tests/security_regression.php [base_url]
 * Default base: http://localhost/reportingportal-kclogin
 */
$base = rtrim($argv[1] ?? 'http://localhost/reportingportal-kclogin', '/');
$failures = [];

function http(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $opts['jar'] ?? '',
        CURLOPT_COOKIEJAR => $opts['jar'] ?? '',
        CURLOPT_HTTPHEADER => $opts['headers'] ?? [],
        CURLOPT_POSTFIELDS => $opts['body'] ?? null,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
$expect = function (bool $ok, string $message) use (&$failures) {
    if (!$ok) $failures[] = $message;
};

// Sensitive paths must not be served
foreach (['.env', '.user.ini', 'error.log', 'secure_data/kc_config.json', 'database/schema.sql',
          'includes/functions.php', 'vendor/autoload.php', 'composer.lock', 'kingschat/',
          'uploads/shell.php', 'distribution/install_db.php'] as $path) {
    [$code] = http('GET', "$base/$path");
    $expect(in_array($code, [403, 404], true), "$path should be blocked, got $code");
}

// CSRF gate: POST without token is rejected, with token passes the gate
$jar = tempnam(sys_get_temp_dir(), 'jar');
[, $loginHtml] = http('GET', "$base/login.php", ['jar' => $jar]);
preg_match('/CSRF_TOKEN = "([a-f0-9]{64})"/', $loginHtml, $m);
$token = $m[1] ?? '';
$expect($token !== '', 'login page exposes a CSRF token');
[$code] = http('POST', "$base/login.php", ['jar' => $jar, 'body' => 'username=x&password=y']);
$expect($code === 403, "POST without CSRF token should be 403, got $code");
[$code] = http('POST', "$base/login.php", ['jar' => $jar, 'body' => "username=__nobody__&password=y&csrf_token=$token"]);
$expect($code === 302, "POST with CSRF token should reach the handler (302), got $code");

// KingsChat callback stays reachable cross-site but only messages its own origin
[$code, $body] = http('POST', "$base/kc_callback.php", ['body' => 'accessToken=invalid']);
$expect($code === 200 && strpos($body, 'window.location.origin') !== false && strpos($body, '"*"') === false,
    'kc_callback posts messages to its own origin only');

// Forged KingsChat link is refused (needs a logged-in session to be meaningful; anonymous gets not_logged_in)
[$code, $body] = http('POST', "$base/kc_link_account.php", ['jar' => $jar, 'headers' => ["X-CSRF-Token: $token"], 'body' => 'kc_user_id=attacker']);
$expect(strpos($body, '"ok":false') !== false, 'kc_link_account refuses unverified KingsChat ids');

// Role enum no longer has the legacy 'user' value
require_once __DIR__ . '/../includes/env.php';
loadEnvFile(__DIR__ . '/../.env');
try {
    $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'gpdreports'),
        getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '');
    $type = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '';
    $expect(strpos($type, "'rzm'") !== false && strpos($type, "'user'") === false, "users.role enum should be admin/admin-manager/rzm, got $type");
    $pdo->prepare("DELETE FROM login_attempts WHERE username = '__nobody__'")->execute();
} catch (PDOException $e) {
    $failures[] = 'DB check skipped: ' . $e->getMessage();
}
@unlink($jar);

if ($failures) {
    fwrite(STDERR, "Security regression FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Security regression checks passed.\n";
