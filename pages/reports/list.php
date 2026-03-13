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

$filterYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
$filterMonth = isset($_GET['month']) ? $_GET['month'] : ($isInitialLoad ? $defaultMonth : '');
$filterWeek = isset($_GET['week']) ? $_GET['week'] : '';
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';
$filterZone = isset($_GET['zone']) ? $_GET['zone'] : '';
$sortOrder = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'desc'; // Default to descending (newest first)

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
    .reports-list-page .reports-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
    }

    .reports-list-page .reports-metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 0.75rem;
    }

    .reports-list-page .reports-card-header {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .reports-list-page .reports-card-actions {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    @media (max-width: 640px) {
        .reports-list-page .modern-stats-card {
            padding: 0.875rem;
            align-items: flex-start;
            gap: 0.625rem;
        }

        .reports-list-page .modern-stats-icon {
            width: 2.25rem;
            height: 2.25rem;
            margin-right: 0;
        }

        .reports-list-page .modern-stats-icon i {
            font-size: 1rem;
        }

        .reports-list-page .modern-stats-label {
            font-size: 0.78rem;
            line-height: 1.2;
            word-break: break-word;
        }

        .reports-list-page .modern-stats-value {
            font-size: 1.1rem;
            line-height: 1.2;
            word-break: break-word;
        }
    }
</style>

<div class="modern-container reports-list-page">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 space-y-4 sm:space-y-0">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Reports Overview</h1>
            <p class="mt-1 text-sm text-gray-600">
                Comprehensive reporting dashboard with key metrics and insights
            </p>
            <p class="mt-1 text-sm font-medium text-primary-600">
                Reporting Period: <?php echo !empty($filterYear) ? htmlspecialchars($filterYear) : 'All Time'; ?>
            </p>
        </div>
        <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4 w-full sm:w-auto">
            <button type="button" onclick="openExportModal()" class="modern-button modern-button-secondary">
                <i class="fas fa-download modern-button-icon"></i> Export Reports
            </button>
            <?php if ($isAdmin): ?>
            <a href="<?php echo htmlspecialchars($missingRzmsExportUrl); ?>" class="modern-button modern-button-secondary">
                <i class="fas fa-file-export modern-button-icon"></i> Export Missing Compliance List
            </a>
            <?php endif; ?>
            <?php if (!$isAdminManager): ?>
            <a href="create.php" class="modern-button modern-button-primary">
                <i class="fas fa-plus modern-button-icon"></i> Submit Report
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Widgets -->
    <div class="reports-summary-grid mb-6">
        <!-- Total Reports Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--primary-600);">
                <i class="fas fa-file-alt"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Total Reports Submitted</div>
                <div class="modern-stats-value"><?php echo number_format($totalReports); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterZone) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Unique Zones Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--success-600);">
                <i class="fas fa-map-marker-alt"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Zones with Reports</div>
                <div class="modern-stats-value"><?php echo number_format($uniqueZones); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Total Copies Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--primary-700);">
                <i class="fas fa-book"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Total Copies Sponsored</div>
                <div class="modern-stats-value"><?php echo number_format($totalCopies); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterZone) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Total Distributions Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--primary-800);">
                <i class="fas fa-truck"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Total Distributions</div>
                <div class="modern-stats-value"><?php echo number_format($totalDistributions); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterZone) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Total Souls Won Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--danger-500);">
                <i class="fas fa-heart"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Total Souls Won</div>
                <div class="modern-stats-value"><?php echo number_format($totalSoulsWon); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterZone) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Total New Partners Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--info-500);">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Total New Partners</div>
                <div class="modern-stats-value"><?php echo number_format($totalNewPartners); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterZone) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Total Rhapsody Cells Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--warning-500);">
                <i class="fas fa-th-large"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Total Rhapsody Cells</div>
                <div class="modern-stats-value"><?php echo number_format($totalRhapsodyCells); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterZone) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Total New Churches Widget -->
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--success-700);">
                <i class="fas fa-church"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">Total New Churches</div>
                <div class="modern-stats-value"><?php echo number_format($totalNewChurches); ?></div>
                <?php if (!empty($filterMonth) || !empty($filterZone) || !empty($filterRegion)): ?>
                <div class="text-xs text-gray-500 mt-1">Filtered results</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="modern-stats-card">
            <div class="modern-stats-icon" style="background-color: var(--primary-900);">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="modern-stats-content">
                <div class="modern-stats-label">RZM Submission Rate</div>
                <div class="modern-stats-value"><?php echo number_format($rzmSubmissionRate, 1); ?>%</div>
                <div class="text-xs text-gray-500 mt-1">
                    Submitted: <?php echo number_format($submittedRzms); ?> of <?php echo number_format($totalRzms); ?> | Not Submitted: <?php echo number_format($notSubmittedRzms); ?>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    Based on active RZMs in the current filters
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Export Modal -->
    <div id="exportModal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeExportModal()"></div>

            <!-- Modal panel -->
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="modern-card-header">
                    <h3 class="modern-card-title">Export Reports</h3>
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
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="modern-card mb-6">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Filter Reports</h2>
        </div>
        <div class="modern-card-body">
            <form method="GET" id="filterForm" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                <div class="modern-form-group">
                    <label for="filter_year" class="modern-form-label">Year</label>
                    <select id="filter_year" name="year" class="modern-form-input select2">
                        <option value="">All Years</option>
                        <?php foreach ($years as $y): ?>
                        <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($y); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="month" class="modern-form-label">Report Month</label>
                    <select id="month" name="month" class="modern-form-input select2">
                        <option value="">All Months</option>
                        <?php foreach ($months as $month): ?>
                        <option value="<?php echo htmlspecialchars($month['month_value']); ?>"
                                <?php echo $filterMonth === $month['month_value'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($month['month_label']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="week" class="modern-form-label">Week</label>
                    <select id="week" name="week" class="modern-form-input select2">
                        <option value="">All Weeks</option>
                        <?php if (empty($availableWeeks)): ?>
                        <option value="" disabled>Select a month first</option>
                        <?php else: ?>
                        <?php foreach ($availableWeeks as $week): ?>
                        <option value="<?php echo htmlspecialchars($week['value']); ?>"
                                <?php echo $filterWeek === $week['value'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($week['label']); ?>
                        </option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <?php if ($isAdmin): ?>
                <div class="modern-form-group">
                    <label for="region" class="modern-form-label">Region</label>
                    <select id="region" name="region" class="modern-form-input select2">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $region): ?>
                        <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="zone" class="modern-form-label">Zone</label>
                    <select id="zone" name="zone" class="modern-form-input select2">
                        <option value="">All Zones</option>
                        <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($zone); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php elseif ($isAdminManager): ?>
                <input type="hidden" name="region" value="<?php echo htmlspecialchars($scopedRegion); ?>">
                <div class="modern-form-group">
                    <label class="modern-form-label">Region</label>
                    <input type="text" class="modern-form-input" value="<?php echo htmlspecialchars($scopedRegion); ?>" disabled>
                </div>
                <div class="modern-form-group">
                    <label for="zone" class="modern-form-label">Zone</label>
                    <select id="zone" name="zone" class="modern-form-input select2">
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
                    <label for="sort_order" class="modern-form-label">Sort By Submission Time</label>
                    <select id="sort_order" name="sort_order" class="modern-form-input select2">
                        <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Oldest First</option>
                    </select>
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="modern-button modern-button-primary">
                        <i class="fas fa-filter modern-button-icon"></i> Apply Filters
                    </button>
                    <a href="list.php" class="modern-button modern-button-secondary">
                        <i class="fas fa-times modern-button-icon"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($reports)): ?>
    <div class="modern-card p-8 text-center">
        <div class="flex flex-col items-center justify-center py-12">
            <i class="fas fa-file-alt text-4xl text-gray-300 mb-4"></i>
            <p class="text-gray-500 mb-4">No reports found. Start by creating your first report.</p>
            <?php if (!$isAdminManager): ?>
            <a href="create.php" class="modern-button modern-button-primary">
                <i class="fas fa-plus modern-button-icon"></i> Submit Report
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 gap-6">
        <?php foreach ($reports as $report): ?>
        <div class="modern-card overflow-hidden">
            <div class="modern-card-header reports-card-header">
                <div>
                    <h3 class="text-lg font-medium flex items-center">
                        <a href="view.php?id=<?php echo $report['id']; ?>" class="text-primary-600 hover:text-primary-700">
                            Report #<?php echo $report['id']; ?>
                        </a>
                        <span class="mx-2 text-gray-400">|</span>
                        <span class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($report['zone']); ?></span>
                    </h3>
                    <div class="mt-1 text-sm text-gray-500">
                        <span class="font-medium"><?php echo htmlspecialchars($report['name']); ?></span>
                        <span class="mx-1">•</span>
                        <span><?php echo htmlspecialchars($report['region']); ?></span>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">
                        <span class="font-medium">Report Month:</span>
                        <span><?php echo date('F Y', strtotime($report['report_month'])); ?></span>
                        <span class="mx-1">•</span>
                        <span>Submitted: <?php echo date('M j, Y', strtotime($report['created_at'])); ?></span>
                    </div>
                </div>
                <div class="reports-card-actions">
                    <a href="view.php?id=<?php echo $report['id']; ?>" class="modern-button modern-button-primary">
                        View Details
                    </a>
                    <?php if (Auth::isAdmin()): ?>
                    <button onclick="deleteReport(<?php echo $report['id']; ?>)" class="modern-button modern-button-danger">
                        Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modern-card-body">
                <div class="reports-metrics-grid mb-4">
                    <!-- Key Metrics -->
                    <div class="modern-stats-card">
                        <div class="modern-stats-icon" style="background-color: var(--primary-600);">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="modern-stats-content">
                            <div class="modern-stats-label">Total Copies Sponsored</div>
                            <div class="modern-stats-value"><?php echo number_format($report['total_copies']); ?></div>
                        </div>
                    </div>
                    <div class="modern-stats-card">
                        <div class="modern-stats-icon" style="background-color: var(--primary-700);">
                            <i class="fas fa-share-alt"></i>
                        </div>
                        <div class="modern-stats-content">
                            <div class="modern-stats-label">Total Copies Distributed</div>
                            <div class="modern-stats-value"><?php echo number_format($report['total_distribution']); ?></div>
                        </div>
                    </div>
                    <div class="modern-stats-card">
                        <div class="modern-stats-icon" style="background-color: var(--success-500);">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="modern-stats-content">
                            <div class="modern-stats-label">Souls Won</div>
                            <div class="modern-stats-value"><?php echo number_format($report['souls_won']); ?></div>
                        </div>
                    </div>
                    <div class="modern-stats-card">
                        <div class="modern-stats-icon" style="background-color: var(--warning-500);">
                            <i class="fas fa-th-large"></i>
                        </div>
                        <div class="modern-stats-content">
                            <div class="modern-stats-label">Rhapsody Cells</div>
                            <div class="modern-stats-value"><?php echo number_format($report['rhapsody_cells']); ?></div>
                        </div>
                    </div>
                    <div class="modern-stats-card">
                        <div class="modern-stats-icon" style="background-color: var(--success-700);">
                            <i class="fas fa-church"></i>
                        </div>
                        <div class="modern-stats-content">
                            <div class="modern-stats-label">New Churches</div>
                            <div class="modern-stats-value"><?php echo number_format($report['new_churches']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php if ($report['testimonies_file']): ?>
                    <span class="modern-badge modern-badge-primary">
                        <i class="fas fa-file-alt mr-2"></i> Has Testimonies
                    </span>
                    <?php endif; ?>

                    <?php if ($report['innovations_file']): ?>
                    <span class="modern-badge modern-badge-success">
                        <i class="fas fa-lightbulb mr-2"></i> Has Innovations
                    </span>
                    <?php endif; ?>

                    <?php if ((int)($report['image_count'] ?? 0) > 0): ?>
                    <span class="modern-badge modern-badge-primary">
                        <i class="fas fa-images mr-2"></i> <?php echo number_format((int)$report['image_count']); ?> Pictures
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="mt-6 flex justify-center">
        <nav class="flex items-center space-x-1" aria-label="Pagination">
            <?php if ($page > 1): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => ($page - 1)]))); ?>"
               class="modern-button modern-button-secondary">
                <i class="fas fa-chevron-left text-xs mr-1"></i> Previous
            </a>
            <?php endif; ?>

            <div class="hidden sm:flex space-x-1">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $i]))); ?>"
                   class="inline-flex items-center justify-center w-8 h-8 rounded-md border <?php echo $i === $page ? 'border-primary-500 bg-primary-50 text-primary-600' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
            </div>

            <div class="sm:hidden flex items-center px-4 text-sm text-gray-700">
                Page <?php echo $page; ?> of <?php echo $totalPages; ?>
            </div>

            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => ($page + 1)]))); ?>"
               class="modern-button modern-button-secondary">
                Next <i class="fas fa-chevron-right text-xs ml-1"></i>
            </a>
            <?php endif; ?>
        </nav>
    </div>
    <?php endif; ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 for all select elements
            $('.select2').select2({
                theme: 'default',
                width: '100%',
                placeholder: 'Select an option',
                allowClear: true
            });
            
            // Initialize Select2 for export modal dropdowns when modal opens
            // Don't initialize on page load, initialize when modal opens
            
            // Function to delete report with confirmation
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

                // Initialize Select2 for export modal dropdowns after modal is visible
                // Destroy first if already initialized
                $('#exportModal select').each(function() {
                    if ($(this).data('select2')) {
                        $(this).select2('destroy');
                    }
                });

                // Re-initialize with proper settings
                $('#exportModal select').select2({
                    theme: 'default',
                    width: '100%',
                    allowClear: true,
                    dropdownParent: $('#exportModal')
                });
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
