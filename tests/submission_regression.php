<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$failures = [];
$assertSame = static function ($expected, $actual, $message) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$assertSame(10 * 1024 * 1024, MAX_FILE_SIZE, 'Upload limit matches the 10 MB UI promise');
$assertSame(
    '2026-07',
    getLatestAllowedReportMonth(new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'))),
    'Normal days allow only the current month'
);
$assertSame(
    '2026-08',
    getLatestAllowedReportMonth(new DateTimeImmutable('2026-07-31 12:00:00', new DateTimeZone('UTC'))),
    'The final UTC day allows users ahead of UTC to submit the next month'
);
$assertSame(
    '2027-01',
    getLatestAllowedReportMonth(new DateTimeImmutable('2026-12-31 12:00:00', new DateTimeZone('UTC'))),
    'Month-boundary handling works across years'
);

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Submission regression checks passed.\n";
