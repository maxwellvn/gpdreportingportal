<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/env.php';
loadEnvFile(__DIR__ . '/../.env');
require_once __DIR__ . '/../includes/hierarchy.php';

$shouldApply = in_array('--apply', $argv, true);
$hierarchy = loadRegionsZonesHierarchy();
if ($hierarchy === []) {
    fwrite(STDERR, "Canonical hierarchy is unavailable.\n");
    exit(1);
}

$normalizeName = static function ($value) {
    return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', trim((string)$value)));
};

$canonicalZones = [];
foreach ($hierarchy as $regionName => $zoneNames) {
    foreach ($zoneNames as $zoneName) {
        $canonicalZones[$normalizeName($zoneName)][] = [
            'region' => $regionName,
            'zone' => $zoneName,
        ];
    }
}

// Reviewed aliases only. Targets must still exist uniquely in the live API.
$reviewedAliases = [
    'Abuja Zone' => 'ABUJA ZONE 1',
    'Durban' => 'DURBAN ZONE',
    'Middle East Asia' => 'MIDDLE EAST ZONE',
    'Onitsha Zone' => 'Onitsha Zone 1',
    'UK Zone 1 Region 1' => 'UK Zone 1 DSP',
    'UK Zone 2 Region 1' => 'UK Zone 2 DSP',
    'UK Zone 4 Region 1' => 'UK Zone 4 DSP',
    'CMMC Region' => 'CMMC',
    'EWCA Zone 1' => 'ECA Zone 1',
    'EWCA Zone 2' => 'W.A.R Zone 1',
    'EWCA Zone 3' => 'ECA Zone 3',
    'EWCA Zone 4' => 'ECA Zone 4',
    'EWCA Zone 5' => 'WAR Zone 2',
    'EWCA Zone 6' => 'ECA Zone 6',
    'EWCA Zone 7' => 'ECA Zone 7',
    'EWCA Zone 8' => 'ECA Zone 8',
    'Ministry Center Warri' => 'MC Warri',
    'Ministry Center Abuja' => 'Abuja Ministry Center',
    'Ministry Center Calabar' => 'CALABAR MINISTRY CENTER',
    'Edo North & Central' => 'Edo North and Central',
];

$databaseHost = getenv('DB_HOST') ?: 'localhost';
if ($databaseHost === 'localhost') {
    $databaseHost = '127.0.0.1';
}
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $databaseHost,
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'gpdreports'
);
$connection = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$users = $connection->query('SELECT id, region, zone FROM users ORDER BY id')->fetchAll();
$updates = [];
$unresolved = [];

foreach ($users as $user) {
    $storedZone = trim((string)($user['zone'] ?? ''));
    if ($storedZone === '') {
        $unresolved[] = $user;
        continue;
    }

    $targetName = $reviewedAliases[$storedZone] ?? $storedZone;
    $matches = $canonicalZones[$normalizeName($targetName)] ?? [];
    if (count($matches) !== 1) {
        $unresolved[] = $user;
        continue;
    }

    $target = $matches[0];
    if ($user['region'] !== $target['region'] || $storedZone !== $target['zone']) {
        $updates[] = [
            'id' => (int)$user['id'],
            'old_region' => $user['region'],
            'old_zone' => $storedZone,
            'region' => $target['region'],
            'zone' => $target['zone'],
        ];
    }
}

echo ($shouldApply ? 'APPLY' : 'DRY RUN') . ': ' . count($updates) . " user assignment(s) to update\n";
echo count($unresolved) . " user assignment(s) unresolved or unassigned\n";

foreach ($updates as $update) {
    printf(
        "#%d: %s / %s -> %s / %s\n",
        $update['id'],
        $update['old_region'],
        $update['old_zone'],
        $update['region'],
        $update['zone']
    );
}

if (!$shouldApply || $updates === []) {
    exit(0);
}

$statement = $connection->prepare('UPDATE users SET region = :region, zone = :zone WHERE id = :id');
$connection->beginTransaction();
try {
    foreach ($updates as $update) {
        $statement->execute([
            ':region' => $update['region'],
            ':zone' => $update['zone'],
            ':id' => $update['id'],
        ]);
    }
    $connection->commit();
    echo "Hierarchy assignments updated successfully.\n";
} catch (Throwable $error) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    fwrite(STDERR, "Hierarchy assignment update failed: " . $error->getMessage() . "\n");
    exit(1);
}
