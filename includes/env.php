<?php
/**
 * Lightweight .env loader (no external dependency).
 * Loads KEY=VALUE pairs into $_ENV and process environment.
 */
function loadEnvFile($filePath) {
    if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($name === '' || preg_match('/[^A-Z0-9_]/i', $name)) {
            continue;
        }

        // Remove wrapping quotes if present.
        $value = preg_replace('/^([\'"])(.*)\\1$/', '$2', $value);

        if (getenv($name) === false) {
            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }
    }

    return true;
}

