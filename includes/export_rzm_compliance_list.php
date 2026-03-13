<?php
require_once '../config/config.php';
require_once '../includes/auth.php';

try {
    Auth::requireLogin();

    $auth = new Auth();
    $currentUser = $auth->getUser($_SESSION['user_id'] ?? 0);
    if (!$currentUser || ($currentUser['role'] ?? '') !== ROLE_ADMIN) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }

    $_SESSION['user_data'] = $currentUser;

    $filterYear = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
    $filterMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
    $filterWeek = isset($_GET['week']) ? trim((string)$_GET['week']) : '';
    $filterRegion = isset($_GET['region']) ? trim((string)$_GET['region']) : '';
    $filterZone = isset($_GET['zone']) ? trim((string)$_GET['zone']) : '';

    $db = new Database();
    $conn = $db->getConnection();

    $userWhereParts = [
        "u.role = :rzm_role",
        "u.active = 1"
    ];
    $userParams = [
        ':rzm_role' => ROLE_USER
    ];

    $reportWhereParts = [
        "u.role = :rzm_role",
        "u.active = 1"
    ];
    $reportParams = [
        ':rzm_role' => ROLE_USER
    ];

    if ($filterRegion !== '') {
        $userWhereParts[] = "u.region = :region";
        $reportWhereParts[] = "u.region = :region";
        $userParams[':region'] = $filterRegion;
        $reportParams[':region'] = $filterRegion;
    }

    if ($filterZone !== '') {
        $userWhereParts[] = "u.zone = :zone";
        $reportWhereParts[] = "u.zone = :zone";
        $userParams[':zone'] = $filterZone;
        $reportParams[':zone'] = $filterZone;
    }

    if ($filterYear !== '') {
        $reportWhereParts[] = "YEAR(r.report_month) = :year";
        $reportParams[':year'] = $filterYear;
    }

    if ($filterMonth !== '') {
        $reportWhereParts[] = "DATE_FORMAT(r.report_month, '%Y-%m') = :month";
        $reportParams[':month'] = $filterMonth;
    }

    if ($filterWeek !== '') {
        $weekParts = explode('|', $filterWeek);
        if (
            count($weekParts) === 2 &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParts[0]) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParts[1])
        ) {
            $reportWhereParts[] = "DATE(r.created_at) BETWEEN :week_start AND :week_end";
            $reportParams[':week_start'] = $weekParts[0];
            $reportParams[':week_end'] = $weekParts[1];
        }
    }

    $usersQuery = "
        SELECT
            u.id,
            u.username,
            u.name,
            u.region,
            u.zone,
            u.created_at
        FROM users u
        WHERE " . implode(' AND ', $userWhereParts) . "
        ORDER BY u.region ASC, u.zone ASC, u.name ASC, u.username ASC
    ";

    $usersStmt = $conn->prepare($usersQuery);
    foreach ($userParams as $key => $value) {
        $usersStmt->bindValue($key, $value);
    }
    $usersStmt->execute();
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $reportsQuery = "
        SELECT
            r.user_id,
            COUNT(*) AS matching_reports_count,
            MAX(r.report_month) AS latest_report_month,
            MAX(r.created_at) AS latest_submitted_at
        FROM reports r
        JOIN users u ON u.id = r.user_id
        WHERE " . implode(' AND ', $reportWhereParts) . "
        GROUP BY r.user_id
    ";

    $reportsStmt = $conn->prepare($reportsQuery);
    foreach ($reportParams as $key => $value) {
        $reportsStmt->bindValue($key, $value);
    }
    $reportsStmt->execute();
    $reportStatsRows = $reportsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $reportStatsByUserId = [];
    foreach ($reportStatsRows as $reportRow) {
        $reportStatsByUserId[(int)$reportRow['user_id']] = $reportRow;
    }

    $rows = [];
    foreach ($users as $userRow) {
        $userId = (int)$userRow['id'];
        $stats = $reportStatsByUserId[$userId] ?? null;

        $rows[] = [
            'username' => $userRow['username'],
            'name' => $userRow['name'],
            'email' => '',
            'region' => $userRow['region'],
            'zone' => $userRow['zone'],
            'created_at' => $userRow['created_at'],
            'matching_reports_count' => $stats['matching_reports_count'] ?? 0,
            'latest_report_month' => $stats['latest_report_month'] ?? null,
            'latest_submitted_at' => $stats['latest_submitted_at'] ?? null
        ];
    }

    $submittedRows = [];
    $notSubmittedRows = [];
    foreach ($rows as $row) {
        if ((int)($row['matching_reports_count'] ?? 0) > 0) {
            $submittedRows[] = $row;
        } else {
            $notSubmittedRows[] = $row;
        }
    }

    $filename = 'export_missing_compliance_list';
    if ($filterYear !== '') {
        $filename .= '_' . preg_replace('/[^0-9]/', '', $filterYear);
    }
    if ($filterMonth !== '') {
        $filename .= '_' . preg_replace('/[^0-9-]/', '', $filterMonth);
    }
    if ($filterRegion !== '') {
        $filename .= '_' . preg_replace('/[^a-zA-Z0-9]/', '', $filterRegion);
    }
    if ($filterZone !== '') {
        $filename .= '_' . preg_replace('/[^a-zA-Z0-9]/', '', $filterZone);
    }
    $filename .= '_' . date('Y-m-d_His') . '.csv';

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $weekLabel = 'All Weeks';
    if (isset($reportParams[':week_start'], $reportParams[':week_end'])) {
        $weekLabel = $reportParams[':week_start'] . ' to ' . $reportParams[':week_end'];
    }

    $csvHeader = [
        'Username',
        'RZM Name',
        'Email',
        'Region',
        'Zone Name',
        'Submission Status',
        'Matching Reports Count',
        'Filtered Year',
        'Filtered Month',
        'Filtered Week',
        'Latest Matching Report Month',
        'Latest Matching Submitted At',
        'Account Created'
    ];

    fputcsv($output, ['Submitted']);
    fputcsv($output, $csvHeader);
    foreach ($submittedRows as $row) {
        $matchingReportsCount = (int)($row['matching_reports_count'] ?? 0);

        fputcsv($output, [
            $row['username'],
            $row['name'],
            $row['email'],
            $row['region'],
            $row['zone'],
            'Submitted',
            $matchingReportsCount,
            $filterYear !== '' ? $filterYear : 'All Years',
            $filterMonth !== '' ? $filterMonth : 'All Months',
            $weekLabel,
            !empty($row['latest_report_month']) ? date('F Y', strtotime($row['latest_report_month'])) : '',
            !empty($row['latest_submitted_at']) ? date('M j, Y g:i A', strtotime($row['latest_submitted_at'])) : '',
            !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : ''
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['Not Submitted']);
    fputcsv($output, $csvHeader);
    foreach ($notSubmittedRows as $row) {
        fputcsv($output, [
            $row['username'],
            $row['name'],
            $row['email'],
            $row['region'],
            $row['zone'],
            'Not Submitted',
            0,
            $filterYear !== '' ? $filterYear : 'All Years',
            $filterMonth !== '' ? $filterMonth : 'All Months',
            $weekLabel,
            '',
            '',
            !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : ''
        ]);
    }

    fclose($output);
    exit;
} catch (Throwable $e) {
    error_log('RZM compliance export failed: ' . $e->getMessage());
    error_log('RZM compliance export trace: ' . $e->getTraceAsString());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo 'Export failed: ' . $e->getMessage();
    exit;
}
