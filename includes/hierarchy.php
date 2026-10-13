<?php

/**
 * Return the canonical region => zone-name hierarchy.
 *
 * The remote hierarchy is cached for availability. The last-known-good cache
 * is populated only from the API, so no independently maintained zone list is
 * merged into canonical data.
 */
function loadRegionsZonesHierarchy() {
    static $loadedHierarchy = null;
    if (is_array($loadedHierarchy)) {
        return $loadedHierarchy;
    }

    $apiUrl = getenv('HIERARCHY_API_URL') ?: 'https://churches-api.rorportal.org/api/v1/hierarchy';
    $cacheTtl = max(60, (int)(getenv('HIERARCHY_CACHE_TTL') ?: 300));
    $cacheDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gpd-reportingportal';
    $cacheFile = $cacheDirectory . DIRECTORY_SEPARATOR . 'hierarchy.json';
    $cachedHierarchy = readRegionsZonesFile($cacheFile);

    if ($cachedHierarchy !== null && (time() - (int)filemtime($cacheFile)) < $cacheTtl) {
        $loadedHierarchy = $cachedHierarchy;
        return $loadedHierarchy;
    }

    $remoteHierarchy = fetchRegionsZonesHierarchy($apiUrl);
    if ($remoteHierarchy !== null) {
        writeRegionsZonesCache($cacheDirectory, $cacheFile, $remoteHierarchy);
        $loadedHierarchy = $remoteHierarchy;
        return $loadedHierarchy;
    }

    if ($cachedHierarchy !== null) {
        error_log('Hierarchy API unavailable; using stale hierarchy cache');
        $loadedHierarchy = $cachedHierarchy;
        return $loadedHierarchy;
    }

    error_log('Hierarchy API unavailable and no last-known-good API cache exists');
    $loadedHierarchy = [];
    return $loadedHierarchy;
}

function fetchRegionsZonesHierarchy($apiUrl) {
    if (!function_exists('curl_init')) {
        error_log('Hierarchy API unavailable: PHP cURL extension is missing');
        return null;
    }

    $curl = curl_init($apiUrl);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'GPD-Reporting-Portal/1.0',
    ]);
    $response = curl_exec($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        error_log('Hierarchy API request failed with status ' . $statusCode . ($error !== '' ? ': ' . $error : ''));
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? normalizeRegionsZonesHierarchy($decoded) : null;
}

function normalizeRegionsZonesHierarchy($payload) {
    $normalized = [];
    foreach ($payload as $regionName => $regionData) {
        if (!is_string($regionName) || trim($regionName) === '' || !is_array($regionData)) {
            continue;
        }

        $zoneLookup = [];
        foreach ($regionData as $zoneKey => $zoneData) {
            // `_updated_at` is metadata, while actual zones are objects.
            if (!is_array($zoneData)) {
                continue;
            }
            $zoneName = trim((string)($zoneData['name'] ?? $zoneKey));
            if ($zoneName !== '') {
                $zoneLookup[$zoneName] = true;
            }
        }

        if ($zoneLookup !== []) {
            $zones = array_keys($zoneLookup);
            natcasesort($zones);
            $normalized[$regionName] = array_values($zones);
        }
    }

    uksort($normalized, 'strnatcasecmp');
    return $normalized;
}

function readRegionsZonesFile($filePath) {
    if (!is_file($filePath)) {
        return null;
    }
    $decoded = json_decode((string)@file_get_contents($filePath), true);
    return isValidRegionsZonesHierarchy($decoded) ? $decoded : null;
}

function isValidRegionsZonesHierarchy($hierarchy) {
    if (!is_array($hierarchy) || $hierarchy === []) {
        return false;
    }
    foreach ($hierarchy as $regionName => $zones) {
        if (!is_string($regionName) || trim($regionName) === '' || !is_array($zones)) {
            return false;
        }
        foreach ($zones as $zoneName) {
            if (!is_string($zoneName) || trim($zoneName) === '') {
                return false;
            }
        }
    }
    return true;
}

function writeRegionsZonesCache($cacheDirectory, $cacheFile, $hierarchy) {
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
        error_log('Unable to create hierarchy cache directory');
        return;
    }
    $encoded = json_encode($hierarchy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }
    $temporaryFile = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temporaryFile, $encoded, LOCK_EX) === false || !@rename($temporaryFile, $cacheFile)) {
        @unlink($temporaryFile);
        error_log('Unable to update hierarchy cache');
    }
}
