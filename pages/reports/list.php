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
    $rzmUsersParams = [':rzm_role' => ROLE_RZM];
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
    $submittedParams = [':rzm_role' => ROLE_RZM];
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


// View-only helpers (no data access)
$canSubmit = !$isAdminManager; // RZM + admin
$hasAnyReports = !empty($monthValues); // months that actually have reports in scope (before current month is injected)
$hasActiveFilters = $filterYear !== '' || $filterMonth !== '' || $filterWeek !== '' || $filterZone !== '' || ($isAdmin && $filterRegion !== '');
if ($isAdmin) {
    $pageTitle = 'All reports';
} elseif ($isAdminManager) {
    $pageTitle = ($scopedRegion !== '' ? $scopedRegion : 'Region') . ' reports';
} else {
    $pageTitle = 'Your reports';
}
$weekLabel = '';
foreach ($availableWeeks as $w) {
    if ($w['value'] === $filterWeek) { $weekLabel = $w['label']; }
}
$activeFilters = [];
if ($filterMonth !== '') { $activeFilters[] = date('F Y', strtotime($filterMonth . '-01')); }
elseif ($filterYear !== '') { $activeFilters[] = (string)$filterYear; }
if ($weekLabel !== '') { $activeFilters[] = $weekLabel; }
if ($isAdmin && $filterRegion !== '') { $activeFilters[] = $filterRegion; }
if ($filterZone !== '') { $activeFilters[] = $filterZone; }
$nextSort = $sortOrder === 'asc' ? 'desc' : 'asc';
$sortUrl = '?' . http_build_query(array_merge($paginationBaseParams, ['sort_order' => $nextSort]));
$showPeople = $isAdmin || $isAdminManager;

// Include header
require_once '../../layouts/header.php';
?>

<style>
/* Page-local: compact KPI row, export modal, pagination (tokens only) */
.rl-stats { grid-template-columns: repeat(auto-fill, minmax(8.25rem, 1fr)); margin-bottom: var(--spacing-6); }
.rl-stats .stat { min-height: 0; padding: var(--spacing-4); gap: var(--spacing-2); justify-content: flex-start; }
.rl-stats .stat-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rl-stats .stat-value { font-size: var(--text-lg); }
#filterForm .field { flex-basis: 8.5rem; }
.rl-filter-summary { display: flex; flex-wrap: wrap; align-items: center; gap: var(--spacing-2); margin-bottom: var(--spacing-3); font-size: var(--text-sm); color: var(--neutral-600); }
.rl-filter-summary .badge { background: var(--neutral-100); }
.rl-table td { white-space: nowrap; }
.rl-table .rl-cell-title { display: flex; flex-direction: column; gap: 2px; }
.rl-table .rl-cell-title .muted { font-size: var(--text-xs); color: var(--neutral-500); }
.rl-table .rl-attach { display: flex; gap: var(--spacing-1); }
.rl-actions { display: flex; gap: var(--spacing-3); justify-content: flex-end; align-items: center; }
.rl-delete { background: none; border: 0; padding: 0; font: inherit; font-weight: 500; color: var(--danger-700); cursor: pointer; }
.rl-delete:hover { text-decoration: underline; text-underline-offset: 2px; }
.rl-sort { color: inherit; display: inline-flex; gap: 0.35rem; align-items: center; }
.rl-sort i { font-size: 0.65rem; color: var(--neutral-500); }
.rl-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-3); margin-top: var(--spacing-4); }
.rl-pages { display: flex; flex-wrap: wrap; gap: var(--spacing-1); }
.rl-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 2.25rem; height: 2.25rem; padding: 0 0.625rem; border: 1px solid var(--neutral-200); border-radius: var(--radius-md); font-size: var(--text-sm); color: var(--neutral-800); font-variant-numeric: tabular-nums; }
.rl-page-btn.active { background: var(--ink); border-color: var(--ink); color: var(--neutral-50); }
@media (hover: hover) and (pointer: fine) { .rl-page-btn:not(.active):hover { background: var(--neutral-50); } }
.rl-modal { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; padding: var(--gutter, 1rem); }
.rl-modal.hidden { display: none; }
.rl-modal-backdrop { position: absolute; inset: 0; background: color-mix(in srgb, var(--ink) 45%, transparent); }
.rl-modal-panel { position: relative; width: 100%; max-width: 30rem; max-height: calc(100vh - 2rem); overflow-y: auto; background: var(--surface, #fff); border-radius: var(--radius-xl); box-shadow: var(--shadow-xl); animation: rl-in var(--dur) var(--ease-out); }
.rl-modal-panel .panel-head { display: flex; align-items: center; justify-content: space-between; }
.rl-modal-panel .panel-body { display: grid; gap: var(--spacing-4); }
.rl-modal-panel .modern-form-group { margin-bottom: 0; }
.rl-modal-foot { display: flex; justify-content: flex-end; gap: var(--spacing-2); }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
@keyframes rl-in { from { opacity: 0; transform: translateY(6px); } }
@media (prefers-reduced-motion: reduce) { .rl-modal-panel { animation: none; } }
</style>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a>
    <span class="sep">/</span>
    <span><?php echo htmlspecialchars($pageTitle); ?></span>
</nav>

<header class="page-head">
    <div>
        <p class="page-eyebrow">Reports</p>
        <h1 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <p class="page-sub">
            <?php if (!empty($filterMonth)): ?>
                <?php echo htmlspecialchars(date('F Y', strtotime($filterMonth . '-01'))); ?>
            <?php elseif (!empty($filterYear)): ?>
                <?php echo htmlspecialchars((string)$filterYear); ?>
            <?php else: ?>
                All time
            <?php endif; ?>
            &middot; <?php echo number_format((int)$totalRecords); ?> <?php echo (int)$totalRecords === 1 ? 'report' : 'reports'; ?>
        </p>
    </div>
    <div class="page-actions">
        <button type="button" onclick="openExportModal()" class="btn btn-secondary">
            <i class="fas fa-download" aria-hidden="true"></i> Export
        </button>
        <?php if ($isAdmin): ?>
        <a href="<?php echo htmlspecialchars($missingRzmsExportUrl); ?>" class="btn btn-secondary">
            <i class="fas fa-file-export" aria-hidden="true"></i> Missing RZMs
        </a>
        <?php endif; ?>
        <?php if ($canSubmit): ?>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus" aria-hidden="true"></i> Submit report
        </a>
        <?php endif; ?>
    </div>
</header>

<?php if ($hasAnyReports): /* first-timers get just the empty state, not zeroed KPIs and filters */ ?>
<!-- Summary counts (current filters) -->
<div class="stat-grid rl-stats" aria-label="Summary for current filters">
    <div class="stat"><span class="stat-label">Reports</span><span class="stat-value"><?php echo number_format($totalReports); ?></span></div>
    <div class="stat"><span class="stat-label">Zones</span><span class="stat-value"><?php echo number_format($uniqueZones); ?></span></div>
    <div class="stat"><span class="stat-label">Copies sponsored</span><span class="stat-value"><?php echo number_format($totalCopies); ?></span></div>
    <div class="stat"><span class="stat-label">Distributions</span><span class="stat-value"><?php echo number_format($totalDistributions); ?></span></div>
    <div class="stat"><span class="stat-label">Souls won</span><span class="stat-value"><?php echo number_format($totalSoulsWon); ?></span></div>
    <div class="stat"><span class="stat-label">New partners</span><span class="stat-value"><?php echo number_format($totalNewPartners); ?></span></div>
    <div class="stat"><span class="stat-label">Rhapsody cells</span><span class="stat-value"><?php echo number_format($totalRhapsodyCells); ?></span></div>
    <div class="stat"><span class="stat-label">New churches</span><span class="stat-value"><?php echo number_format($totalNewChurches); ?></span></div>
    <?php if ($isAdmin): ?>
    <div class="stat">
        <span class="stat-label" title="RZM submission rate">Submission rate</span>
        <span class="stat-value"><?php echo number_format($rzmSubmissionRate, 1); ?>%</span>
        <span class="stat-note"><?php echo number_format($submittedRzms); ?> of <?php echo number_format($totalRzms); ?> submitted</span>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<form method="GET" id="filterForm" class="toolbar" aria-label="Filter reports">
    <div class="field">
        <label for="filter_year" class="field-label">Year</label>
        <select id="filter_year" name="year" class="input">
            <option value="all" <?php echo $filterYear === '' ? 'selected' : ''; ?>>All years</option>
            <?php foreach ($years as $y): ?>
            <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>><?php echo htmlspecialchars($y); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="month" class="field-label">Report month</label>
        <select id="month" name="month" class="input">
            <option value="all" <?php echo $filterMonth === '' ? 'selected' : ''; ?>>All months</option>
            <?php foreach ($months as $month): ?>
            <option value="<?php echo htmlspecialchars($month['month_value']); ?>" <?php echo $filterMonth === $month['month_value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($month['month_label']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="week" class="field-label">Week</label>
        <select id="week" name="week" class="input">
            <option value="all" <?php echo $filterWeek === '' ? 'selected' : ''; ?>>All weeks</option>
            <?php if (empty($availableWeeks)): ?>
            <option value="" disabled>Select a month first</option>
            <?php else: foreach ($availableWeeks as $week): ?>
            <option value="<?php echo htmlspecialchars($week['value']); ?>" <?php echo $filterWeek === $week['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($week['label']); ?></option>
            <?php endforeach; endif; ?>
        </select>
    </div>
    <?php if ($isAdmin): ?>
    <div class="field">
        <label for="region" class="field-label">Region</label>
        <select id="region" name="region" class="input">
            <option value="all" <?php echo $filterRegion === '' ? 'selected' : ''; ?>>All regions</option>
            <?php foreach ($regions as $region): ?>
            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if ($isAdmin || $isAdminManager): ?>
    <?php if ($isAdminManager): ?>
    <input type="hidden" name="region" value="<?php echo htmlspecialchars($scopedRegion); ?>">
    <?php endif; ?>
    <div class="field">
        <label for="zone" class="field-label">Zone</label>
        <select id="zone" name="zone" class="input">
            <option value="all" <?php echo $filterZone === '' ? 'selected' : ''; ?>>All zones</option>
            <?php foreach ($zones as $zone): ?>
            <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="field">
        <label for="sort_order" class="field-label">Sort</label>
        <select id="sort_order" name="sort_order" class="input">
            <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Newest first</option>
            <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Oldest first</option>
        </select>
    </div>
    <div class="toolbar-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="<?php echo htmlspecialchars($allReportsUrl); ?>" class="btn btn-secondary">Clear</a>
    </div>
</form>

<div class="rl-filter-summary" aria-live="polite">
    <span>Showing <strong class="num"><?php echo number_format((int)$totalRecords); ?></strong> <?php echo (int)$totalRecords === 1 ? 'report' : 'reports'; ?></span>
    <?php if ($isAdminManager && $scopedRegion !== ''): ?>
    <span class="badge no-dot">Region: <?php echo htmlspecialchars($scopedRegion); ?></span>
    <?php endif; ?>
    <?php foreach ($activeFilters as $f): ?>
    <span class="badge no-dot"><?php echo htmlspecialchars((string)$f); ?></span>
    <?php endforeach; ?>
    <?php if ($hasActiveFilters): ?>
    <a href="<?php echo htmlspecialchars($allReportsUrl); ?>" class="link">Clear filters</a>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><div><?php echo htmlspecialchars($error); ?></div></div>
<?php endif; ?>

<?php if (empty($reports)): ?>
<div class="panel">
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></div>
        <?php if (!$hasAnyReports): ?>
            <?php if ($isAdminManager): ?>
            <p class="empty-state-title">No reports from <?php echo htmlspecialchars($scopedRegion !== '' ? $scopedRegion : 'your region'); ?> yet</p>
            <p class="empty-state-text">Reports submitted by RZMs in your region will show up here.</p>
            <?php elseif ($isAdmin): ?>
            <p class="empty-state-title">No reports yet</p>
            <p class="empty-state-text">Once RZMs start submitting monthly reports, they will be listed here.</p>
            <a href="create.php" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Submit report</a>
            <?php else: ?>
            <p class="empty-state-title">You haven't submitted a report yet</p>
            <p class="empty-state-text">Your monthly reports will be listed here once you submit them. It takes a few minutes.</p>
            <a href="create.php" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Submit your first report</a>
            <?php endif; ?>
        <?php else: ?>
            <p class="empty-state-title">No reports match these filters</p>
            <p class="empty-state-text">Try a different month or zone, or clear the filters to see every report.</p>
            <a href="<?php echo htmlspecialchars($allReportsUrl); ?>" class="btn btn-secondary">Clear filters</a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<div class="table-wrap">
    <table class="data-table rl-table">
        <thead>
            <tr>
                <th scope="col">
                    <a class="rl-sort" href="<?php echo htmlspecialchars($sortUrl); ?>" aria-label="Sort by month, <?php echo $nextSort === 'asc' ? 'oldest first' : 'newest first'; ?>">
                        Report <i class="fas fa-arrow-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>" aria-hidden="true"></i>
                    </a>
                </th>
                <?php if ($showPeople): ?>
                <th scope="col">RZM</th>
                <th scope="col">Zone</th>
                <?php endif; ?>
                <th scope="col">Submitted</th>
                <th scope="col" class="num">Copies</th>
                <th scope="col" class="num">Distributed</th>
                <th scope="col" class="num">Souls won</th>
                <th scope="col" class="num">Cells</th>
                <th scope="col" class="num">Churches</th>
                <th scope="col">Attachments</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($reports as $report): $reportId = (int)$report['id']; $imageCount = (int)($report['image_count'] ?? 0); ?>
            <tr>
                <td>
                    <div class="rl-cell-title">
                        <a class="link" href="view.php?id=<?php echo $reportId; ?>"><?php echo htmlspecialchars(date('F Y', strtotime($report['report_month']))); ?></a>
                        <span class="meta">#<?php echo $reportId; ?></span>
                    </div>
                </td>
                <?php if ($showPeople): ?>
                <td><?php echo htmlspecialchars($report['name'] ?? ''); ?></td>
                <td>
                    <div class="rl-cell-title">
                        <span><?php echo htmlspecialchars($report['zone'] ?? ''); ?></span>
                        <span class="muted"><?php echo htmlspecialchars($report['region'] ?? ''); ?></span>
                    </div>
                </td>
                <?php endif; ?>
                <td><span class="num"><?php echo htmlspecialchars(date('M j, Y', strtotime($report['created_at']))); ?></span></td>
                <td class="num"><?php echo number_format((int)$report['total_copies']); ?></td>
                <td class="num"><?php echo number_format((int)$report['total_distribution']); ?></td>
                <td class="num"><?php echo number_format((int)$report['souls_won']); ?></td>
                <td class="num"><?php echo number_format((int)$report['rhapsody_cells']); ?></td>
                <td class="num"><?php echo number_format((int)$report['new_churches']); ?></td>
                <td>
                    <div class="rl-attach">
                        <?php if ($report['testimonies_file']): ?><span class="badge badge-info">Testimonies</span><?php endif; ?>
                        <?php if ($report['innovations_file']): ?><span class="badge badge-success">Innovations</span><?php endif; ?>
                        <?php if ($imageCount > 0): ?><span class="badge"><?php echo number_format($imageCount); ?> <?php echo $imageCount === 1 ? 'photo' : 'photos'; ?></span><?php endif; ?>
                        <?php if (!$report['testimonies_file'] && !$report['innovations_file'] && $imageCount === 0): ?><span class="muted">&mdash;</span><?php endif; ?>
                    </div>
                </td>
                <td>
                    <div class="rl-actions">
                        <a href="view.php?id=<?php echo $reportId; ?>" class="link">View</a>
                        <?php if (Auth::isAdmin()): ?>
                        <button type="button" onclick="deleteReport(<?php echo $reportId; ?>)" class="rl-delete">Delete</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="rl-pagination" aria-label="Pagination">
    <span class="meta">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></span>
    <div class="rl-pages">
        <?php if ($page > 1): ?>
        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $page - 1]))); ?>" class="rl-page-btn">&larr; Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $i]))); ?>" class="rl-page-btn <?php echo $i === $page ? 'active' : ''; ?>"<?php echo $i === $page ? ' aria-current="page"' : ''; ?>><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $page + 1]))); ?>" class="rl-page-btn">Next &rarr;</a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>
<?php endif; ?>

<!-- Export Modal -->
<div id="exportModal" class="rl-modal hidden" aria-labelledby="exportModalTitle" role="dialog" aria-modal="true">
    <div class="rl-modal-backdrop" aria-hidden="true" onclick="closeExportModal()"></div>
    <div class="rl-modal-panel">
        <div class="panel-head">
            <h2 id="exportModalTitle" class="section-title">Export reports</h2>
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeExportModal()" aria-label="Close export dialog"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
        <form action="../../includes/export_reports.php" method="GET">
            <div class="panel-body">
                <div class="modern-form-group">
                    <label for="export_year" class="modern-form-label">Year</label>
                    <select id="export_year" name="year" class="modern-form-input">
                        <option value="">All years</option>
                        <?php foreach ($years as $year): ?>
                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $filterYear == $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="export_month" class="modern-form-label">Report month</label>
                    <select id="export_month" name="month" class="modern-form-input">
                        <option value="">All months</option>
                        <?php foreach ($months as $month): ?>
                        <option value="<?php echo htmlspecialchars($month['month_value']); ?>" <?php echo $filterMonth === $month['month_value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($month['month_label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isAdmin): ?>
                <div class="modern-form-group">
                    <label for="export_region" class="modern-form-label">Region</label>
                    <select id="export_region" name="region" class="modern-form-input">
                        <option value="">All regions</option>
                        <?php foreach ($regions as $region): ?>
                        <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="export_zone" class="modern-form-label">Zone</label>
                    <select id="export_zone" name="zone" class="modern-form-input">
                        <option value="">All zones</option>
                        <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php elseif ($isAdminManager): ?>
                <input type="hidden" id="export_region" name="region" value="<?php echo htmlspecialchars($scopedRegion); ?>">
                <div class="modern-form-group">
                    <label for="export_region_display" class="modern-form-label">Region</label>
                    <input type="text" id="export_region_display" class="modern-form-input" value="<?php echo htmlspecialchars($scopedRegion); ?>" disabled>
                </div>
                <div class="modern-form-group">
                    <label for="export_zone" class="modern-form-label">Zone</label>
                    <select id="export_zone" name="zone" class="modern-form-input">
                        <option value="">All zones</option>
                        <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="modern-form-group">
                    <label for="export_sort_order" class="modern-form-label">Sort order</label>
                    <select id="export_sort_order" name="sort_order" class="modern-form-input">
                        <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Newest first</option>
                        <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Oldest first</option>
                    </select>
                </div>
            </div>
            <div class="panel-foot rl-modal-foot">
                <button type="button" onclick="closeExportModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-download" aria-hidden="true"></i> Export</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ponytail: script moved out of the "has reports" branch so Export/toasts also work on empty results
    document.addEventListener('DOMContentLoaded', function() {
        window.deleteReport = function(reportId) {
            Swal.fire({
                title: 'Delete this report?',
                text: "This will delete the report and all associated files. This action cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger-600)',
                cancelButtonColor: 'var(--neutral-500)',
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

        // Open export modal, synced to the filters currently applied on the page
        window.openExportModal = function() {
            var currentYear = $('#filter_year').val();
            var currentMonth = $('#month').val();
            var currentRegion = $('#region').length ? $('#region').val() : <?php echo json_encode($filterRegion, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var currentZone = $('#zone').length ? $('#zone').val() : <?php echo json_encode($filterZone, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var currentSortOrder = $('#sort_order').val();

            $('#export_year').val(currentYear === 'all' ? '' : currentYear); // filter uses 'all', export uses ''
            $('#export_month').val(currentMonth === 'all' ? '' : currentMonth);
            $('#export_region').val(currentRegion === 'all' ? '' : currentRegion);
            $('#export_zone').val(currentZone === 'all' ? '' : currentZone);
            $('#export_sort_order').val(currentSortOrder);

            $('#exportModal').removeClass('hidden');
            $('#export_year').trigger('focus');
        };

        window.closeExportModal = function() {
            $('#exportModal').addClass('hidden');
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !$('#exportModal').hasClass('hidden')) { closeExportModal(); }
        });

        <?php if (isset($_SESSION['success'])): ?>
            showToast(<?php echo json_encode((string)$_SESSION['success'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, 'success');
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            showToast(<?php echo json_encode((string)$_SESSION['error'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, 'error');
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    });
</script>

</main>
</body>
</html>
