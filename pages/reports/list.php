<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Ensure user is logged in
Auth::requireLogin();

// Get user data
$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $user;
$isAdmin = Auth::isAdmin();
$isAdminManager = Auth::isAdminManager();
$scopedRegion = ($isAdminManager && !empty($user['region'])) ? $user['region'] : '';

// Database connection
$db = new Database();
$conn = $db->getConnection();

// Get filter parameters
$isInitialLoad = empty($_GET);
$defaultMonth = date('Y-m');

// On initial load, use current month if it has reports; otherwise use the most recent previous month with reports.
if ($isInitialLoad) {
    if ($isAdmin) {
        $currentMonthCountStmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM reports
            WHERE DATE_FORMAT(report_month, '%Y-%m') = :current_month
        ");
        $currentMonthCountStmt->execute([':current_month' => $defaultMonth]);
    } elseif ($isAdminManager) {
        $currentMonthCountStmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM reports r
            JOIN users u ON r.user_id = u.id
            WHERE u.region = :region
              AND DATE_FORMAT(r.report_month, '%Y-%m') = :current_month
        ");
        $currentMonthCountStmt->execute([
            ':region' => $scopedRegion,
            ':current_month' => $defaultMonth
        ]);
    } else {
        $currentMonthCountStmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM reports
            WHERE user_id = :user_id
              AND DATE_FORMAT(report_month, '%Y-%m') = :current_month
        ");
        $currentMonthCountStmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':current_month' => $defaultMonth
        ]);
    }

    $hasCurrentMonthReports = ((int)($currentMonthCountStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0)) > 0;

    if (!$hasCurrentMonthReports) {
        if ($isAdmin) {
            $previousMonthStmt = $conn->prepare("
                SELECT MAX(DATE_FORMAT(report_month, '%Y-%m')) as previous_month
                FROM reports
                WHERE DATE_FORMAT(report_month, '%Y-%m') < :current_month
            ");
            $previousMonthStmt->execute([':current_month' => $defaultMonth]);
        } elseif ($isAdminManager) {
            $previousMonthStmt = $conn->prepare("
                SELECT MAX(DATE_FORMAT(r.report_month, '%Y-%m')) as previous_month
                FROM reports r
                JOIN users u ON r.user_id = u.id
                WHERE u.region = :region
                  AND DATE_FORMAT(r.report_month, '%Y-%m') < :current_month
            ");
            $previousMonthStmt->execute([
                ':region' => $scopedRegion,
                ':current_month' => $defaultMonth
            ]);
        } else {
            $previousMonthStmt = $conn->prepare("
                SELECT MAX(DATE_FORMAT(report_month, '%Y-%m')) as previous_month
                FROM reports
                WHERE user_id = :user_id
                  AND DATE_FORMAT(report_month, '%Y-%m') < :current_month
            ");
            $previousMonthStmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':current_month' => $defaultMonth
            ]);
        }

        $previousMonth = $previousMonthStmt->fetch(PDO::FETCH_ASSOC)['previous_month'] ?? '';
        if (!empty($previousMonth)) {
            $defaultMonth = $previousMonth;
        }
    }
}

$filterYear = isset($_GET['year']) ? trim((string)$_GET['year']) : date('Y');
$filterMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : ($isInitialLoad ? $defaultMonth : '');
$filterWeek = isset($_GET['week']) ? trim((string)$_GET['week']) : '';
$filterRegion = isset($_GET['region']) ? trim((string)$_GET['region']) : '';
$filterZone = isset($_GET['zone']) ? trim((string)$_GET['zone']) : '';
$sortOrder = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'desc'; // Default to descending (newest first)

if ($filterYear === 'all') {
    $filterYear = '';
}
if ($filterMonth === 'all') {
    $filterMonth = '';
}
if ($filterWeek === 'all') {
    $filterWeek = '';
}
if ($filterRegion === 'all') {
    $filterRegion = '';
}
if ($filterZone === 'all') {
    $filterZone = '';
}

if ($isAdminManager) {
    $filterRegion = $scopedRegion;
}

// Keep year aligned with auto-selected default month on initial load.
if (!isset($_GET['year']) && !empty($filterMonth)) {
    $filterYear = (int)substr($filterMonth, 0, 4);
}

// Get available months for filter
if ($isAdmin) {
    $monthsQuery = "SELECT DISTINCT DATE_FORMAT(report_month, '%Y-%m') as month_value,
                    DATE_FORMAT(report_month, '%M %Y') as month_label
                    FROM reports
                    ORDER BY month_value DESC
                    LIMIT 12";
    $months = $conn->query($monthsQuery)->fetchAll(PDO::FETCH_ASSOC);
} elseif ($isAdminManager) {
    $monthsQuery = "SELECT DISTINCT DATE_FORMAT(r.report_month, '%Y-%m') as month_value,
                    DATE_FORMAT(r.report_month, '%M %Y') as month_label
                    FROM reports r
                    JOIN users u ON r.user_id = u.id
                    WHERE u.region = :region
                    ORDER BY month_value DESC
                    LIMIT 12";
    $monthsStmt = $conn->prepare($monthsQuery);
    $monthsStmt->execute([':region' => $scopedRegion]);
    $months = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $monthsQuery = "SELECT DISTINCT DATE_FORMAT(report_month, '%Y-%m') as month_value,
                    DATE_FORMAT(report_month, '%M %Y') as month_label
                    FROM reports
                    WHERE user_id = :user_id
                    ORDER BY month_value DESC
                    LIMIT 12";
    $monthsStmt = $conn->prepare($monthsQuery);
    $monthsStmt->execute([':user_id' => $_SESSION['user_id']]);
    $months = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ensure current month is always available in month filter options
$currentMonthValue = date('Y-m');
$monthValues = array_column($months, 'month_value');
if (!in_array($currentMonthValue, $monthValues, true)) {
    array_unshift($months, [
        'month_value' => $currentMonthValue,
        'month_label' => date('F Y')
    ]);
}

// Get available years for filter
if ($isAdmin) {
    $yearsQuery = "SELECT DISTINCT YEAR(report_month) as year
                   FROM reports
                   ORDER BY year DESC";
    $years = $conn->query($yearsQuery)->fetchAll(PDO::FETCH_COLUMN);
} elseif ($isAdminManager) {
    $yearsQuery = "SELECT DISTINCT YEAR(r.report_month) as year
                   FROM reports r
                   JOIN users u ON r.user_id = u.id
                   WHERE u.region = :region
                   ORDER BY year DESC";
    $yearsStmt = $conn->prepare($yearsQuery);
    $yearsStmt->execute([':region' => $scopedRegion]);
    $years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $yearsQuery = "SELECT DISTINCT YEAR(report_month) as year
                   FROM reports
                   WHERE user_id = :user_id
                   ORDER BY year DESC";
    $yearsStmt = $conn->prepare($yearsQuery);
    $yearsStmt->execute([':user_id' => $_SESSION['user_id']]);
    $years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Ensure current year is always available in the filter
$currentYear = date('Y');
if (!in_array($currentYear, $years)) {
    array_unshift($years, $currentYear);
}

// Build week options for selected month (week ranges within the selected month)
$availableWeeks = [];
if (!empty($filterMonth) && preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $selectedMonthStart = DateTime::createFromFormat('Y-m-d', $filterMonth . '-01');

    if ($selectedMonthStart instanceof DateTime) {
        $selectedMonthEnd = clone $selectedMonthStart;
        $selectedMonthEnd->modify('last day of this month');

        $cursor = clone $selectedMonthStart;
        $weekNumber = 1;

        while ($cursor <= $selectedMonthEnd) {
            $weekStart = clone $cursor;
            $weekEnd = clone $cursor;
            $weekEnd->modify('+6 days');

            if ($weekEnd > $selectedMonthEnd) {
                $weekEnd = clone $selectedMonthEnd;
            }

            $weekValue = $weekStart->format('Y-m-d') . '|' . $weekEnd->format('Y-m-d');

            $availableWeeks[] = [
                'value' => $weekValue,
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
                'label' => 'Week ' . $weekNumber . ' (' . $weekStart->format('M j') . ' - ' . $weekEnd->format('M j') . ')'
            ];

            $cursor = clone $weekEnd;
            $cursor->modify('+1 day');
            $weekNumber++;
        }
    }
}

// Keep week filter default as "All Weeks" unless explicitly selected.

// If selected week does not belong to selected month options, clear it
if (!empty($filterWeek)) {
    $weekValues = array_column($availableWeeks, 'value');
    if (!in_array($filterWeek, $weekValues, true)) {
        $filterWeek = '';
    }
}

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1

// Handle delete request
if (Auth::isAdmin() && isset($_POST['delete']) && isset($_POST['report_id'])) {
    try {
        // CSRF validation
        if (!isset($_POST['csrf_token'])) {
            throw new Exception('Missing CSRF token');
        }
        Security::verifyCSRFToken($_POST['csrf_token']);

        $db = new Database();
        $conn = $db->getConnection();

        // Start transaction
        $conn->beginTransaction();

        // Delete report images first
        $deleteImagesQuery = "DELETE FROM report_images WHERE report_id = :report_id";
        $stmt = $conn->prepare($deleteImagesQuery);
        $stmt->bindValue(':report_id', $_POST['report_id'], PDO::PARAM_INT);
        $stmt->execute();

        // Delete report
        $deleteReportQuery = "DELETE FROM reports WHERE id = :report_id";
        $stmt = $conn->prepare($deleteReportQuery);
        $stmt->bindValue(':report_id', $_POST['report_id'], PDO::PARAM_INT);
        $stmt->execute();

        // Commit transaction
        $conn->commit();

        // Redirect to refresh the page
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error deleting report: " . $e->getMessage();
    }
}

// Get unique regions and zones for filters
if ($isAdminManager) {
    $regions = [$scopedRegion];
    $zonesQuery = "SELECT DISTINCT zone FROM users WHERE zone IS NOT NULL AND zone != '' AND region = :region ORDER BY zone";
    $zonesStmt = $conn->prepare($zonesQuery);
    $zonesStmt->execute([':region' => $scopedRegion]);
    $zones = $zonesStmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $regionsQuery = "SELECT DISTINCT region FROM users WHERE region IS NOT NULL AND region != '' ORDER BY region";
    $zonesQuery = "SELECT DISTINCT zone FROM users WHERE zone IS NOT NULL AND zone != '' ORDER BY zone";
    $regions = $conn->query($regionsQuery)->fetchAll(PDO::FETCH_COLUMN);
    $zones = $conn->query($zonesQuery)->fetchAll(PDO::FETCH_COLUMN);
}

// Fallback: If no regions found in users table, load from REGIONS_ZONES config
if (empty($regions) && !empty($REGIONS_ZONES)) {
    $regions = array_keys($REGIONS_ZONES);
    sort($regions);
}

// Fallback: If no zones found in users table, load from REGIONS_ZONES config
if (empty($zones) && !empty($REGIONS_ZONES)) {
    if ($isAdminManager && !empty($scopedRegion) && isset($REGIONS_ZONES[$scopedRegion])) {
        $zones = $REGIONS_ZONES[$scopedRegion];
    } else {
        $zones = [];
        foreach ($REGIONS_ZONES as $regionZones) {
            $zones = array_merge($zones, $regionZones);
        }
        $zones = array_unique($zones);
    }
    sort($zones);
}

// Build the base WHERE clause
$whereClause = "WHERE 1=1";
$params = [];
if (!$isAdmin && !$isAdminManager) {
    $whereClause .= " AND r.user_id = :user_id";
    $params[':user_id'] = $_SESSION['user_id'];
}
if ($isAdminManager) {
    $whereClause .= " AND u.region = :scoped_region";
    $params[':scoped_region'] = $scopedRegion;
}

// Add filter conditions
if (!empty($filterYear)) {
    $whereClause .= " AND YEAR(r.report_month) = :year";
    $params[':year'] = $filterYear;
}

if (!empty($filterMonth)) {
    $whereClause .= " AND DATE_FORMAT(r.report_month, '%Y-%m') = :month";
    $params[':month'] = $filterMonth;
}

if (!empty($filterRegion)) {
    $whereClause .= " AND u.region = :region";
    $params[':region'] = $filterRegion;
}

if (!empty($filterZone)) {
    $whereClause .= " AND u.zone = :zone";
    $params[':zone'] = $filterZone;
}

if (!empty($filterWeek)) {
    $weekParts = explode('|', $filterWeek);
    if (count($weekParts) === 2
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParts[0])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParts[1])) {
        $whereClause .= " AND DATE(r.created_at) BETWEEN :week_start AND :week_end";
        $params[':week_start'] = $weekParts[0];
        $params[':week_end'] = $weekParts[1];
    }
}

$paginationBaseParams = [
    'year' => $filterYear,
    'month' => $filterMonth,
    'week' => $filterWeek,
    'region' => $filterRegion,
    'zone' => $filterZone,
    'sort_order' => $sortOrder
];
$paginationBaseParams = array_filter(
    $paginationBaseParams,
    static function ($value) {
        return $value !== '' && $value !== null;
    }
);
$missingRzmsExportUrl = BASE_URL . '/includes/export_rzm_compliance_list.php';
if (!empty($paginationBaseParams)) {
    $missingRzmsExportUrl .= '?' . http_build_query($paginationBaseParams);
}

$allReportsUrl = 'list.php?' . http_build_query([
    'year' => 'all',
    'month' => 'all',
    'week' => 'all',
    'region' => 'all',
    'zone' => 'all',
    'sort_order' => $sortOrder
]);

// Get total records for pagination
$countQuery = "SELECT COUNT(*) as total FROM reports r
               LEFT JOIN users u ON r.user_id = u.id
               $whereClause";
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];
$totalPages = ceil($totalRecords / ITEMS_PER_PAGE);

// Get reports
$reportsQuery = "SELECT r.*, u.name, u.region, u.zone, COALESCE(ri.image_count, 0) AS image_count
                 FROM reports r
                 LEFT JOIN users u ON r.user_id = u.id
                 LEFT JOIN (
                    SELECT report_id, COUNT(*) AS image_count
                    FROM report_images
                    GROUP BY report_id
                 ) ri ON ri.report_id = r.id
                 $whereClause
                 ORDER BY r.report_month " . strtoupper($sortOrder) . ", r.created_at " . strtoupper($sortOrder) . "
                 LIMIT :limit OFFSET :offset";
$stmt = $conn->prepare($reportsQuery);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', ITEMS_PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * ITEMS_PER_PAGE, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll();

// Get summary metrics in a single aggregate query
$summaryQuery = "SELECT
                    COALESCE(SUM(r.total_copies), 0) AS total_copies,
                    COALESCE(SUM(r.total_distribution), 0) AS total_distributions,
                    COALESCE(SUM(r.souls_won), 0) AS total_souls,
                    COALESCE(SUM(r.new_partners), 0) AS total_new_partners,
                    COALESCE(SUM(r.rhapsody_cells), 0) AS total_rhapsody_cells,
                    COALESCE(SUM(r.new_churches), 0) AS total_new_churches,
                    COUNT(*) AS total_reports,
                    COUNT(DISTINCT CASE WHEN u.zone IS NOT NULL AND u.zone != '' THEN u.zone END) AS unique_zones
                 FROM reports r
                 LEFT JOIN users u ON r.user_id = u.id
                 $whereClause";
$summaryStmt = $conn->prepare($summaryQuery);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalCopies = (int)($summary['total_copies'] ?? 0);
$totalDistributions = (int)($summary['total_distributions'] ?? 0);
$totalSoulsWon = (int)($summary['total_souls'] ?? 0);
$totalNewPartners = (int)($summary['total_new_partners'] ?? 0);
$totalRhapsodyCells = (int)($summary['total_rhapsody_cells'] ?? 0);
$totalNewChurches = (int)($summary['total_new_churches'] ?? 0);
$totalReports = (int)($summary['total_reports'] ?? 0);
$uniqueZones = (int)($summary['unique_zones'] ?? 0);

// Admin-only RZM submission compliance metric
$totalRzms = 0;
$submittedRzms = 0;
$notSubmittedRzms = 0;
$rzmSubmissionRate = 0.0;
if ($isAdmin) {
    $rzmUsersWhere = "WHERE u.role = :rzm_role AND u.active = 1";
    $rzmUsersParams = [':rzm_role' => ROLE_USER];
    if (!empty($filterRegion)) {
        $rzmUsersWhere .= " AND u.region = :region";
        $rzmUsersParams[':region'] = $filterRegion;
    }
    if (!empty($filterZone)) {
        $rzmUsersWhere .= " AND u.zone = :zone";
        $rzmUsersParams[':zone'] = $filterZone;
    }

    $totalRzmsStmt = $conn->prepare("SELECT COUNT(*) AS total_rzms FROM users u $rzmUsersWhere");
    $totalRzmsStmt->execute($rzmUsersParams);
    $totalRzms = (int)($totalRzmsStmt->fetch(PDO::FETCH_ASSOC)['total_rzms'] ?? 0);

    $submittedWhere = "WHERE u.role = :rzm_role AND u.active = 1";
    $submittedParams = [':rzm_role' => ROLE_USER];
    if (!empty($filterYear)) {
        $submittedWhere .= " AND YEAR(r.report_month) = :year";
        $submittedParams[':year'] = $filterYear;
    }
    if (!empty($filterMonth)) {
        $submittedWhere .= " AND DATE_FORMAT(r.report_month, '%Y-%m') = :month";
        $submittedParams[':month'] = $filterMonth;
    }
    if (!empty($filterRegion)) {
        $submittedWhere .= " AND u.region = :region";
        $submittedParams[':region'] = $filterRegion;
    }
    if (!empty($filterZone)) {
        $submittedWhere .= " AND u.zone = :zone";
        $submittedParams[':zone'] = $filterZone;
    }
    if (!empty($filterWeek)) {
        $weekParts = explode('|', $filterWeek);
        if (count($weekParts) === 2
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParts[0])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParts[1])) {
            $submittedWhere .= " AND DATE(r.created_at) BETWEEN :week_start AND :week_end";
            $submittedParams[':week_start'] = $weekParts[0];
            $submittedParams[':week_end'] = $weekParts[1];
        }
    }

    $submittedStmt = $conn->prepare("
        SELECT COUNT(DISTINCT r.user_id) AS submitted_rzms
        FROM reports r
        JOIN users u ON r.user_id = u.id
        $submittedWhere
    ");
    $submittedStmt->execute($submittedParams);
    $submittedRzms = (int)($submittedStmt->fetch(PDO::FETCH_ASSOC)['submitted_rzms'] ?? 0);

    $notSubmittedRzms = max($totalRzms - $submittedRzms, 0);
    $rzmSubmissionRate = $totalRzms > 0 ? round(($submittedRzms / $totalRzms) * 100, 1) : 0.0;
}

// Include header
require_once '../../layouts/header.php';
?>

<style>
/* ── Reports list flat/minimalist ── */
.rl-page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }

.rl-topbar { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.75rem; }
.rl-topbar-left h1 { font-size: 1.25rem; font-weight: 600; color: var(--neutral-900); margin: 0 0 0.2rem; }
.rl-topbar-left p  { font-size: 0.8rem; color: var(--neutral-500); margin: 0; }
.rl-topbar-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }

/* Stat grid — same unified-border style as dashboard */
.rl-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1px; background: var(--neutral-200); border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); overflow: hidden; margin-bottom: 1.75rem; }
@media (min-width: 640px)  { .rl-stats { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 1024px) { .rl-stats { grid-template-columns: repeat(4, 1fr); } }
.rl-stat { background: #fff; padding: 1.1rem 1.25rem 1rem; display: flex; flex-direction: column; gap: 0.25rem; }
.rl-stat-label { font-size: 0.72rem; font-weight: 500; color: var(--neutral-400); text-transform: uppercase; letter-spacing: 0.04em; }
.rl-stat-value { font-size: 1.4rem; font-weight: 600; color: var(--neutral-900); line-height: 1.2; }
.rl-stat-sub { font-size: 0.72rem; color: var(--neutral-400); margin-top: 0.1rem; }

/* Filter section */
.rl-filter { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; overflow: hidden; margin-bottom: 1.75rem; }
.rl-filter-head { padding: 0.9rem 1.25rem; border-bottom: 1px solid var(--neutral-200); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; }
.rl-filter-head h2 { font-size: 0.875rem; font-weight: 600; color: var(--neutral-800); margin: 0; }
.rl-filter-body { padding: 1rem 1.25rem; }
.rl-filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.rl-filter-group { display: flex; flex-direction: column; gap: 0.3rem; }
.rl-filter-group label { font-size: 0.72rem; font-weight: 600; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.04em; }
.rl-filter-group select, .rl-filter-group input[type=text] { font-size: 0.8125rem; padding: 0.45rem 0.6rem; border: 1px solid var(--neutral-300); border-radius: var(--radius-md); color: var(--neutral-800); background: #fff; width: 100%; }
.rl-filter-group select:focus, .rl-filter-group input:focus { outline: none; border-color: var(--primary-500); box-shadow: 0 0 0 2px var(--primary-100); }
.rl-filter-group input[disabled] { background: var(--neutral-50); color: var(--neutral-400); }
.rl-filter-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; }
.rl-filter-actions-left { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.rl-filter-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.rl-pill { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; border-radius: var(--radius-full); background: var(--primary-50); color: var(--primary-700); font-size: 0.72rem; font-weight: 600; border: 1px solid var(--primary-100); }

/* Report row cards */
.rl-report { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; overflow: hidden; margin-bottom: 0.75rem; }
.rl-report-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; padding: 0.9rem 1.25rem; border-bottom: 1px solid var(--neutral-100); }
.rl-report-title { font-size: 0.9rem; font-weight: 600; color: var(--neutral-900); margin: 0 0 0.2rem; }
.rl-report-title a { color: var(--primary-600); text-decoration: none; }
.rl-report-title a:hover { text-decoration: underline; }
.rl-report-meta { font-size: 0.78rem; color: var(--neutral-500); display: flex; flex-wrap: wrap; gap: 0.4rem 0.75rem; }
.rl-report-actions { display: flex; gap: 0.4rem; flex-shrink: 0; align-items: flex-start; }
.rl-report-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 1px; background: var(--neutral-100); }
.rl-metric { background: #fff; padding: 0.7rem 1rem; }
.rl-metric-label { font-size: 0.7rem; font-weight: 500; color: var(--neutral-400); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.2rem; }
.rl-metric-value { font-size: 1rem; font-weight: 600; color: var(--neutral-900); }
.rl-report-footer { padding: 0.6rem 1rem; display: flex; flex-wrap: wrap; gap: 0.4rem; }

/* Empty state */
.rl-empty { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; padding: 3rem 1.5rem; text-align: center; }
.rl-empty p { font-size: 0.875rem; color: var(--neutral-400); margin-bottom: 1rem; }

/* Pagination */
.rl-pagination { display: flex; justify-content: center; align-items: center; gap: 0.35rem; margin-top: 1.5rem; flex-wrap: wrap; }
.rl-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; height: 2rem; padding: 0 0.5rem; border: 1px solid var(--neutral-200); border-radius: var(--radius-md); font-size: 0.8125rem; color: var(--neutral-700); background: #fff; text-decoration: none; transition: background 0.12s; white-space: nowrap; }
.rl-page-btn:hover { background: var(--neutral-50); }
.rl-page-btn.active { background: var(--primary-600); border-color: var(--primary-600); color: #fff; font-weight: 600; }
.rl-page-info { font-size: 0.8rem; color: var(--neutral-500); padding: 0 0.5rem; }

@media (max-width: 480px) {
    .rl-page { padding: 1.25rem 0.9rem 2.5rem; }
    .rl-topbar { flex-direction: column; }
    .rl-stat-value { font-size: 1.1rem; }
}
</style>

<div class="rl-page">

    <!-- Breadcrumb -->
    <div style="display:flex;align-items:center;gap:0.35rem;font-size:0.78rem;color:var(--neutral-400);margin-bottom:1rem;flex-wrap:wrap;">
        <a href="<?php echo BASE_URL; ?>/dashboard.php" style="color:var(--neutral-500);text-decoration:none;"><i class="fas fa-home" style="font-size:0.7rem;"></i> Dashboard</a>
        <span style="color:var(--neutral-300);">/</span>
        <span><?php echo ($isAdmin || $isAdminManager) ? 'Reports Overview' : 'My Reports'; ?></span>
    </div>

    <!-- Top bar -->
    <div class="rl-topbar">
        <div class="rl-topbar-left">
            <h1><?php echo ($isAdmin || $isAdminManager) ? 'Reports Overview' : 'My Reports'; ?></h1>
            <p>Period: <?php echo !empty($filterYear) ? htmlspecialchars((string)$filterYear) : 'All Time'; ?><?php if (!empty($filterMonth)): ?> &middot; <?php echo date('F Y', strtotime($filterMonth . '-01')); ?><?php endif; ?></p>
        </div>
        <div class="rl-topbar-actions">
            <button type="button" onclick="openExportModal()" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">
                <i class="fas fa-download modern-button-icon"></i> Export
            </button>
            <?php if ($isAdmin): ?>
            <a href="<?php echo htmlspecialchars($missingRzmsExportUrl); ?>" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">
                <i class="fas fa-file-export modern-button-icon"></i> Compliance List
            </a>
            <?php endif; ?>
            <?php if (!$isAdminManager): ?>
            <a href="create.php" class="modern-button modern-button-primary" style="font-size:0.8125rem;">
                <i class="fas fa-plus modern-button-icon"></i> Submit Report
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary stats -->
    <div class="rl-stats">
        <div class="rl-stat">
            <span class="rl-stat-label">Reports</span>
            <span class="rl-stat-value"><?php echo number_format($totalReports); ?></span>
        </div>
        <div class="rl-stat">
            <span class="rl-stat-label">Zones</span>
            <span class="rl-stat-value"><?php echo number_format($uniqueZones); ?></span>
        </div>
        <div class="rl-stat">
            <span class="rl-stat-label">Copies Sponsored</span>
            <span class="rl-stat-value"><?php echo number_format($totalCopies); ?></span>
        </div>
        <div class="rl-stat">
            <span class="rl-stat-label">Distributions</span>
            <span class="rl-stat-value"><?php echo number_format($totalDistributions); ?></span>
        </div>
        <div class="rl-stat">
            <span class="rl-stat-label">Souls Won</span>
            <span class="rl-stat-value"><?php echo number_format($totalSoulsWon); ?></span>
        </div>
        <div class="rl-stat">
            <span class="rl-stat-label">New Partners</span>
            <span class="rl-stat-value"><?php echo number_format($totalNewPartners); ?></span>
        </div>
        <div class="rl-stat">
            <span class="rl-stat-label">Rhapsody Cells</span>
            <span class="rl-stat-value"><?php echo number_format($totalRhapsodyCells); ?></span>
        </div>
        <div class="rl-stat">
            <span class="rl-stat-label">New Churches</span>
            <span class="rl-stat-value"><?php echo number_format($totalNewChurches); ?></span>
        </div>
        <?php if ($isAdmin): ?>
        <div class="rl-stat">
            <span class="rl-stat-label">RZM Submission Rate</span>
            <span class="rl-stat-value"><?php echo number_format($rzmSubmissionRate, 1); ?>%</span>
            <span class="rl-stat-sub"><?php echo number_format($submittedRzms); ?> / <?php echo number_format($totalRzms); ?> submitted</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Export Modal -->
    <div id="exportModal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeExportModal()"></div>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--neutral-200);">
                    <h3 style="font-size:0.9rem;font-weight:600;color:var(--neutral-900);margin:0;">Export Reports</h3>
                </div>
                <form action="../../includes/export_reports.php" method="GET" class="modern-card-body">
                    <div class="space-y-4">
                        <div class="modern-form-group">
                            <label for="export_year" class="modern-form-label">Year</label>
                            <select id="export_year" name="year" class="modern-form-input">
                                <option value="">All Years</option>
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $filterYear == $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="modern-form-group">
                            <label for="export_month" class="modern-form-label">Report Month</label>
                            <select id="export_month" name="month" class="modern-form-input">
                                <option value="">All Months</option>
                                <?php foreach ($months as $month): ?>
                                <option value="<?php echo htmlspecialchars($month['month_value']); ?>" <?php echo $filterMonth === $month['month_value'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($month['month_label']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isAdmin): ?>
                        <div class="modern-form-group">
                            <label for="export_region" class="modern-form-label">Region</label>
                            <select id="export_region" name="region" class="modern-form-input">
                                <option value="">All Regions</option>
                                <?php foreach ($regions as $region): ?>
                                <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($region); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="modern-form-group">
                            <label for="export_zone" class="modern-form-label">Zone</label>
                            <select id="export_zone" name="zone" class="modern-form-input">
                                <option value="">All Zones</option>
                                <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($zone); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php elseif ($isAdminManager): ?>
                        <input type="hidden" id="export_region" name="region" value="<?php echo htmlspecialchars($scopedRegion); ?>">
                        <div class="modern-form-group">
                            <label class="modern-form-label">Region</label>
                            <input type="text" class="modern-form-input" value="<?php echo htmlspecialchars($scopedRegion); ?>" disabled>
                        </div>
                        <div class="modern-form-group">
                            <label for="export_zone" class="modern-form-label">Zone</label>
                            <select id="export_zone" name="zone" class="modern-form-input">
                                <option value="">All Zones</option>
                                <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($zone); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="modern-form-group">
                            <label for="export_sort_order" class="modern-form-label">Sort Order</label>
                            <select id="export_sort_order" name="sort_order" class="modern-form-input">
                                <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Newest First</option>
                                <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Oldest First</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeExportModal()" class="modern-button modern-button-secondary">
                            Cancel
                        </button>
                        <button type="submit" class="modern-button modern-button-primary">
                            <i class="fas fa-download mr-2"></i> Export
                        </button>
                    </div>
                </form>
            </div><!-- /.modal-panel -->
        </div><!-- /.modal-outer -->
    </div><!-- /#exportModal -->

    <!-- Filter Section -->
    <div class="rl-filter">
        <div class="rl-filter-head">
            <h2>Filters</h2>
            <div class="rl-filter-pills">
                <span class="rl-pill"><?php echo !empty($filterMonth) ? date('F Y', strtotime($filterMonth . '-01')) : 'All Months'; ?></span>
                <span class="rl-pill"><?php echo !empty($filterYear) ? htmlspecialchars((string)$filterYear) : 'All Years'; ?></span>
                <?php if ($isAdmin && !empty($filterRegion)): ?><span class="rl-pill"><?php echo htmlspecialchars($filterRegion); ?></span><?php endif; ?>
                <?php if (!empty($filterZone)): ?><span class="rl-pill"><?php echo htmlspecialchars($filterZone); ?></span><?php endif; ?>
            </div>
        </div>
        <div class="rl-filter-body">
            <form method="GET" id="filterForm">
                <div class="rl-filter-grid">
                    <div class="rl-filter-group">
                        <label for="filter_year">Year</label>
                        <select id="filter_year" name="year">
                            <option value="all" <?php echo $filterYear === '' ? 'selected' : ''; ?>>All Years</option>
                            <?php foreach ($years as $y): ?>
                            <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>><?php echo htmlspecialchars($y); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rl-filter-group">
                        <label for="month">Report Month</label>
                        <select id="month" name="month">
                            <option value="all" <?php echo $filterMonth === '' ? 'selected' : ''; ?>>All Months</option>
                            <?php foreach ($months as $month): ?>
                            <option value="<?php echo htmlspecialchars($month['month_value']); ?>" <?php echo $filterMonth === $month['month_value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($month['month_label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rl-filter-group">
                        <label for="week">Week</label>
                        <select id="week" name="week">
                            <option value="all" <?php echo $filterWeek === '' ? 'selected' : ''; ?>>All Weeks</option>
                            <?php if (empty($availableWeeks)): ?>
                            <option value="" disabled>Select a month first</option>
                            <?php else: foreach ($availableWeeks as $week): ?>
                            <option value="<?php echo htmlspecialchars($week['value']); ?>" <?php echo $filterWeek === $week['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($week['label']); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="rl-filter-group">
                        <label for="region">Region</label>
                        <select id="region" name="region">
                            <option value="all" <?php echo $filterRegion === '' ? 'selected' : ''; ?>>All Regions</option>
                            <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rl-filter-group">
                        <label for="zone">Zone</label>
                        <select id="zone" name="zone">
                            <option value="all" <?php echo $filterZone === '' ? 'selected' : ''; ?>>All Zones</option>
                            <?php foreach ($zones as $zone): ?>
                            <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php elseif ($isAdminManager): ?>
                    <input type="hidden" name="region" value="<?php echo htmlspecialchars($scopedRegion); ?>">
                    <div class="rl-filter-group">
                        <label>Region</label>
                        <input type="text" value="<?php echo htmlspecialchars($scopedRegion); ?>" disabled>
                    </div>
                    <div class="rl-filter-group">
                        <label for="zone">Zone</label>
                        <select id="zone" name="zone">
                            <option value="all" <?php echo $filterZone === '' ? 'selected' : ''; ?>>All Zones</option>
                            <?php foreach ($zones as $zone): ?>
                            <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="rl-filter-group">
                        <label for="sort_order">Sort</label>
                        <select id="sort_order" name="sort_order">
                            <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Oldest First</option>
                        </select>
                    </div>
                </div>
                <div class="rl-filter-actions">
                    <div class="rl-filter-actions-left">
                        <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;">
                            <i class="fas fa-filter modern-button-icon"></i> Apply
                        </button>
                        <a href="<?php echo htmlspecialchars($allReportsUrl); ?>" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">All Reports</a>
                        <a href="list.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($reports)): ?>
    <div class="rl-empty">
        <i class="fas fa-file-alt" style="font-size:2rem;color:var(--neutral-300);margin-bottom:0.75rem;"></i>
        <p>No reports found for the selected filters.</p>
        <?php if (!$isAdminManager): ?>
        <a href="create.php" class="modern-button modern-button-primary" style="font-size:0.8125rem;">
            <i class="fas fa-plus modern-button-icon"></i> Submit Report
        </a>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <?php foreach ($reports as $report): ?>
    <div class="rl-report">
        <div class="rl-report-head">
            <div>
                <p class="rl-report-title">
                    <a href="view.php?id=<?php echo $report['id']; ?>">#<?php echo $report['id']; ?> &mdash; <?php echo date('F Y', strtotime($report['report_month'])); ?></a>
                    <?php if ($isAdmin || $isAdminManager): ?>
                    <span style="font-weight:400;color:var(--neutral-500);">&nbsp;&middot;&nbsp;<?php echo htmlspecialchars($report['zone'] ?? ''); ?></span>
                    <?php endif; ?>
                </p>
                <div class="rl-report-meta">
                    <?php if ($isAdmin || $isAdminManager): ?>
                    <span><?php echo htmlspecialchars($report['name'] ?? ''); ?></span>
                    <span><?php echo htmlspecialchars($report['region'] ?? ''); ?></span>
                    <?php endif; ?>
                    <span>Submitted <?php echo date('M j, Y', strtotime($report['created_at'])); ?></span>
                </div>
            </div>
            <div class="rl-report-actions">
                <a href="view.php?id=<?php echo $report['id']; ?>" class="modern-button modern-button-secondary" style="font-size:0.8rem;">View</a>
                <?php if (Auth::isAdmin()): ?>
                <button onclick="deleteReport(<?php echo $report['id']; ?>)" class="modern-button modern-button-danger" style="font-size:0.8rem;">Delete</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="rl-report-metrics">
            <div class="rl-metric"><div class="rl-metric-label">Copies Sponsored</div><div class="rl-metric-value"><?php echo number_format($report['total_copies']); ?></div></div>
            <div class="rl-metric"><div class="rl-metric-label">Distributed</div><div class="rl-metric-value"><?php echo number_format($report['total_distribution']); ?></div></div>
            <div class="rl-metric"><div class="rl-metric-label">Souls Won</div><div class="rl-metric-value"><?php echo number_format($report['souls_won']); ?></div></div>
            <div class="rl-metric"><div class="rl-metric-label">Rhapsody Cells</div><div class="rl-metric-value"><?php echo number_format($report['rhapsody_cells']); ?></div></div>
            <div class="rl-metric"><div class="rl-metric-label">New Churches</div><div class="rl-metric-value"><?php echo number_format($report['new_churches']); ?></div></div>
        </div>
        <?php if ($report['testimonies_file'] || $report['innovations_file'] || (int)($report['image_count'] ?? 0) > 0): ?>
        <div class="rl-report-footer">
            <?php if ($report['testimonies_file']): ?>
            <span class="modern-badge modern-badge-primary"><i class="fas fa-file-alt mr-1"></i> Testimonies</span>
            <?php endif; ?>
            <?php if ($report['innovations_file']): ?>
            <span class="modern-badge modern-badge-success"><i class="fas fa-lightbulb mr-1"></i> Innovations</span>
            <?php endif; ?>
            <?php if ((int)($report['image_count'] ?? 0) > 0): ?>
            <span class="modern-badge modern-badge-primary"><i class="fas fa-images mr-1"></i> <?php echo number_format((int)$report['image_count']); ?> Pictures</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="rl-pagination">
        <?php if ($page > 1): ?>
        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $page - 1]))); ?>" class="rl-page-btn">&larr; Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $i]))); ?>" class="rl-page-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $page + 1]))); ?>" class="rl-page-btn">Next &rarr;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            window.deleteReport = function(reportId) {
                Swal.fire({
                    title: 'Are you sure?',
                    text: "This will delete the report and all associated files. This action cannot be undone!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: 'var(--danger-600)',
                    cancelButtonColor: 'var(--gray-500)',
                    confirmButtonText: 'Yes, delete report',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Create a form and submit it
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'none';

                        const reportIdInput = document.createElement('input');
                        reportIdInput.type = 'hidden';
                        reportIdInput.name = 'report_id';
                        reportIdInput.value = reportId;

                        const deleteInput = document.createElement('input');
                        deleteInput.type = 'hidden';
                        deleteInput.name = 'delete';
                        deleteInput.value = '1';

                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = 'csrf_token';
                        csrfInput.value = '<?php echo Security::generateCSRFToken(); ?>';

                        form.appendChild(reportIdInput);
                        form.appendChild(deleteInput);
                        form.appendChild(csrfInput);
                        document.body.appendChild(form);

                        form.submit();
                    }
                });
            };
            
            // Function to open export modal
            window.openExportModal = function() {
                // Sync current page filter values to export modal before opening
                // This ensures export uses the same filters currently applied on the page
                var currentYear = $('#filter_year').val();
                var currentMonth = $('#month').val();
                var currentRegion = $('#region').length ? $('#region').val() : '<?php echo addslashes($filterRegion); ?>';
                var currentZone = $('#zone').length ? $('#zone').val() : '<?php echo addslashes($filterZone); ?>';
                var currentSortOrder = $('#sort_order').val();

                // Set export modal values to match current page filters
                $('#export_year').val(currentYear);
                $('#export_month').val(currentMonth);
                $('#export_region').val(currentRegion);
                $('#export_zone').val(currentZone);
                $('#export_sort_order').val(currentSortOrder);

                $('#exportModal').removeClass('hidden');
            };
            
            // Function to close export modal
            window.closeExportModal = function() {
                $('#exportModal').addClass('hidden');
            };
            
            // Show success/error messages with Toastr
            <?php if (isset($_SESSION['success'])): ?>
                showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
        });
    </script>
    <?php endif; ?>
</div>

</main>
</body>
</html>
