<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/statistics.php';
require_once '../includes/audit.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check if user is admin
if (!isset($_SESSION['user_data']['role']) || $_SESSION['user_data']['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Database connection
$db = new Database();
$conn = $db->getConnection();

// Initialize Statistics class
$stats = new Statistics($conn);

function normalizeDashboardDate($value, $boundary = 'start') {
    if (empty($value) || !is_string($value)) {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^\d{4}-\d{2}$/', $value)) {
        return $boundary === 'end'
            ? date('Y-m-t', strtotime($value . '-01'))
            : $value . '-01';
    }

    return '';
}

function formatDashboardPeriodLabel($dateFrom, $dateTo) {
    if (!empty($dateFrom) && !empty($dateTo) && $dateFrom === $dateTo) {
        return date('F Y', strtotime($dateFrom));
    }

    if (!empty($dateFrom) || !empty($dateTo)) {
        $fromLabel = !empty($dateFrom) ? date('M j, Y', strtotime($dateFrom)) : 'Start';
        $toLabel = !empty($dateTo) ? date('M j, Y', strtotime($dateTo)) : 'Present';
        return $fromLabel . ' - ' . $toLabel;
    }

    return 'All Time';
}

function resolveDashboardDateRange($selectedYear, $selectedMonth, $dateFrom, $dateTo) {
    $year = (int)$selectedYear;
    $month = (int)$selectedMonth;

    if ($year > 0 && $month >= 1 && $month <= 12) {
        $monthString = sprintf('%04d-%02d', $year, $month);
        return [$monthString . '-01', $monthString . '-01'];
    }

    if ($year > 0) {
        return [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year)
        ];
    }

    return [$dateFrom, $dateTo];
}

// Get filter parameters
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo   = isset($_GET['date_to'])   ? $_GET['date_to']   : '';
$filterRegion   = isset($_GET['region'])    ? $_GET['region']    : '';
$filterZone     = isset($_GET['zone'])      ? $_GET['zone']      : '';
$filterYear     = isset($_GET['filter_year']) ? trim((string)$_GET['filter_year']) : '';
$filterMonth    = isset($_GET['filter_month']) ? trim((string)$_GET['filter_month']) : '';
$filterDateFrom = normalizeDashboardDate($filterDateFrom, 'start');
$filterDateTo = normalizeDashboardDate($filterDateTo, 'end');
[$filterDateFrom, $filterDateTo] = resolveDashboardDateRange($filterYear, $filterMonth, $filterDateFrom, $filterDateTo);

// Build filters array
$filters = [];
if (!empty($filterDateFrom)) $filters['date_from'] = $filterDateFrom;
if (!empty($filterDateTo))   $filters['date_to']   = $filterDateTo;
if (!empty($filterRegion))   $filters['region']    = $filterRegion;
if (!empty($filterZone))     $filters['zone']      = $filterZone;

// Get key statistics
$reportStats = $stats->getReportStatistics($filters);
$complianceData = $stats->getZoneCompliance($filters);
$engagementStats = $stats->getUserEngagementStatistics([
    'region' => $filterRegion,
    'zone' => $filterZone
]);

// Get reports breakdown by month for the selected year
$monthlyBreakdownQuery = "SELECT
    DATE_FORMAT(r.report_month, '%Y-%m') as month_key,
    DATE_FORMAT(r.report_month, '%M %Y') as month_name,
    COUNT(*) as report_count,
    SUM(r.total_copies) as total_copies,
    SUM(r.souls_won) as total_souls
    FROM reports r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE 1=1";

$params = [];

if (!empty($filterDateFrom)) {
    $monthlyBreakdownQuery .= " AND DATE(r.report_month) >= :date_from";
    $params[':date_from'] = $filterDateFrom;
}
if (!empty($filterDateTo)) {
    $monthlyBreakdownQuery .= " AND DATE(r.report_month) <= :date_to";
    $params[':date_to'] = $filterDateTo;
}
if (!empty($filterRegion)) {
    $monthlyBreakdownQuery .= " AND u.region = :region";
    $params[':region'] = $filterRegion;
}
if (!empty($filterZone)) {
    $monthlyBreakdownQuery .= " AND u.zone = :zone";
    $params[':zone'] = $filterZone;
}

$monthlyBreakdownQuery .= " GROUP BY DATE_FORMAT(r.report_month, '%Y-%m')
                           ORDER BY r.report_month";

$stmt = $conn->prepare($monthlyBreakdownQuery);
$stmt->execute($params);
$monthlyBreakdown = $stmt->fetchAll();

// Get zone breakdown
$zoneBreakdownQuery = "SELECT 
    u.zone,
    u.region,
    COUNT(r.id) as report_count,
    SUM(r.total_copies) as total_copies,
    SUM(r.souls_won) as total_souls,
    MAX(r.created_at) as last_submission
    FROM users u
    LEFT JOIN reports r ON u.id = r.user_id";

$whereConditions = [];
$zoneParams = [];

if (!empty($filterDateFrom)) {
    $whereConditions[] = "DATE(r.report_month) >= :date_from";
    $zoneParams[':date_from'] = $filterDateFrom;
}
if (!empty($filterDateTo)) {
    $whereConditions[] = "DATE(r.report_month) <= :date_to";
    $zoneParams[':date_to'] = $filterDateTo;
}
if (!empty($filterRegion)) {
    $whereConditions[] = "u.region = :region";
    $zoneParams[':region'] = $filterRegion;
}
if (!empty($filterZone)) {
    $whereConditions[] = "u.zone = :zone";
    $zoneParams[':zone'] = $filterZone;
}

if (!empty($whereConditions)) {
    $zoneBreakdownQuery .= " WHERE " . implode(" AND ", $whereConditions);
}

$zoneBreakdownQuery .= " GROUP BY u.zone, u.region
                        ORDER BY u.region, report_count DESC";

$stmt = $conn->prepare($zoneBreakdownQuery);
$stmt->execute($zoneParams);
$zoneBreakdown = $stmt->fetchAll();

// Get sponsorship statistics
$sponsorshipQuery = "SELECT 
    SUM(r.total_copies) as total_copies_distributed,
    SUM(r.souls_won) as total_souls_won,
    COUNT(DISTINCT r.user_id) as active_sponsors,
    AVG(r.total_copies) as avg_copies_per_report,
    AVG(r.souls_won) as avg_souls_per_report
    FROM reports r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE 1=1";

$sponsorshipParams = [];

if (!empty($filterDateFrom)) {
    $sponsorshipQuery .= " AND DATE(r.report_month) >= :date_from";
    $sponsorshipParams[':date_from'] = $filterDateFrom;
}
if (!empty($filterDateTo)) {
    $sponsorshipQuery .= " AND DATE(r.report_month) <= :date_to";
    $sponsorshipParams[':date_to'] = $filterDateTo;
}
if (!empty($filterRegion)) {
    $sponsorshipQuery .= " AND u.region = :region";
    $sponsorshipParams[':region'] = $filterRegion;
}
if (!empty($filterZone)) {
    $sponsorshipQuery .= " AND u.zone = :zone";
    $sponsorshipParams[':zone'] = $filterZone;
}

$stmt = $conn->prepare($sponsorshipQuery);
$stmt->execute($sponsorshipParams);
$sponsorshipStats = $stmt->fetch();

// ── Users with NO submission for current month ──
$currentYM = date('Y-m');
$missingThisMonth = [];
try {
    $mtmStmt = $conn->prepare("
        SELECT u.id, u.name, u.username, u.region, u.zone
        FROM users u
        WHERE u.active = 1
          AND u.role = 'rzm'
          AND u.id NOT IN (
              SELECT r.user_id FROM reports r
              WHERE DATE_FORMAT(r.report_month, '%Y-%m') = :ym
          )
        ORDER BY u.region, u.zone, u.name
    ");
    $mtmStmt->execute([':ym' => $currentYM]);
    $missingThisMonth = $mtmStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { error_log('[AdminDash] missingThisMonth: ' . $e->getMessage()); }

// ── Chronic late submitters: users who missed >= 2 of the last 6 months ──
$chronicLate = [];
try {
    // Build a list of the last 6 completed month strings
    $last6 = [];
    for ($m = 1; $m <= 6; $m++) {
        $last6[] = date('Y-m', strtotime("-$m month"));
    }
    $placeholders6 = implode(',', array_fill(0, 6, '?'));

    $clStmt = $conn->prepare("
        SELECT u.id, u.name, u.username, u.region, u.zone,
               COUNT(r.id) AS months_submitted,
               (6 - COUNT(r.id)) AS months_missed
        FROM users u
        LEFT JOIN reports r
            ON r.user_id = u.id
            AND DATE_FORMAT(r.report_month, '%Y-%m') IN ($placeholders6)
        WHERE u.active = 1 AND u.role = 'rzm'
        GROUP BY u.id
        HAVING months_missed >= 2
        ORDER BY months_missed DESC, u.name ASC
        LIMIT 30
    ");
    $clStmt->execute($last6);
    $chronicLate = $clStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { error_log('[AdminDash] chronicLate: ' . $e->getMessage()); }

// ── Handle send-reminder POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reminder_action'])) {
    try {
        if (!isset($_POST['csrf_token'])) throw new Exception('Missing CSRF token');
        Security::verifyCSRFToken($_POST['csrf_token']);

        $reminderMsg    = trim((string)($_POST['reminder_message'] ?? ''));
        $reminderTarget = (int)($_POST['reminder_target_id'] ?? 0);
        if ($reminderMsg === '') throw new Exception('Reminder message cannot be empty');

        // Ensure table exists
        $conn->exec("CREATE TABLE IF NOT EXISTS `reminders` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `sender_id` int(11) NOT NULL,
            `target_user_id` int(11) DEFAULT NULL,
            `message` text NOT NULL,
            `reminder_type` enum('personal','broadcast') NOT NULL DEFAULT 'personal',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $rType = $reminderTarget > 0 ? 'personal' : 'broadcast';
        $rStmt = $conn->prepare("INSERT INTO reminders (sender_id, target_user_id, message, reminder_type) VALUES (?,?,?,?)");
        $rStmt->execute([$_SESSION['user_id'], $reminderTarget > 0 ? $reminderTarget : null, $reminderMsg, $rType]);

        Audit::log('send_reminder', $reminderTarget > 0 ? 'user' : '', $reminderTarget > 0 ? $reminderTarget : null,
            ($rType === 'broadcast' ? 'Broadcast reminder' : "Personal reminder to user #$reminderTarget") . ": $reminderMsg"
        );

        $_SESSION['success'] = $rType === 'broadcast'
            ? 'Broadcast reminder saved for all pending users.'
            : 'Reminder sent to user #' . $reminderTarget . '.';
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: dashboard.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Get available filter options
$regionsQuery = "SELECT DISTINCT region FROM users WHERE region IS NOT NULL ORDER BY region";
$regions = $conn->query($regionsQuery)->fetchAll(PDO::FETCH_COLUMN);

$zonesQuery = "SELECT DISTINCT zone FROM users WHERE zone IS NOT NULL";
if (!empty($filterRegion)) {
    $zonesQuery .= " AND region = :region";
}
$zonesQuery .= " ORDER BY zone";
$stmt = $conn->prepare($zonesQuery);
if (!empty($filterRegion)) {
    $stmt->bindParam(':region', $filterRegion);
}
$stmt->execute();
$zones = $stmt->fetchAll(PDO::FETCH_COLUMN);

$availableYearsQuery = "SELECT DISTINCT YEAR(report_month) AS report_year FROM reports ORDER BY report_year DESC";
$availableYears = $conn->query($availableYearsQuery)->fetchAll(PDO::FETCH_COLUMN);
$currentYear = (int)date('Y');
$availableYears = array_values(array_unique(array_merge([$currentYear], array_map('intval', $availableYears))));
rsort($availableYears);
$availableMonths = [
    '1' => 'January',
    '2' => 'February',
    '3' => 'March',
    '4' => 'April',
    '5' => 'May',
    '6' => 'June',
    '7' => 'July',
    '8' => 'August',
    '9' => 'September',
    '10' => 'October',
    '11' => 'November',
    '12' => 'December',
];

// Include header
require_once '../layouts/header.php';
?>


<style>
/* Page-local: filter disclosure, compact people lists, progress bars, reminder dialog */
.ad-filterbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-3); margin-bottom: var(--spacing-6); }
.ad-period { margin-bottom: 0; }
.ad-period button { display: inline-flex; align-items: center; gap: 0.375rem; }
.ad-period button i { font-size: 0.625rem; }
.ad-scope { display: flex; flex-wrap: wrap; align-items: center; gap: var(--spacing-2); }
.ad-scope .input { width: auto; min-width: 11rem; max-width: 16rem; min-height: 2.25rem; padding-top: 0.375rem; padding-bottom: 0.375rem; border-radius: var(--radius-full); }
.ad-custom { flex-basis: 100%; display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--spacing-3); padding: var(--spacing-4); background: var(--mist); border-radius: var(--radius-lg); }
.ad-custom .field { margin: 0; flex: 1 1 9rem; }
.ad-custom-or { align-self: center; padding-top: 1.25rem; font-size: var(--text-xs); color: var(--neutral-500); }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
@media (max-width: 640px) { .ad-scope, .ad-scope .input { width: 100%; max-width: none; } .ad-period { width: 100%; overflow-x: auto; } }
.ad-scroll { max-height: 28rem; overflow-y: auto; }
.ad-list { max-height: 18rem; overflow-y: auto; }
.ad-row { display: flex; align-items: center; gap: var(--spacing-3); padding: 0.75rem var(--spacing-6); border-bottom: 1px solid var(--neutral-200); font-size: var(--text-sm); }
.ad-row:last-child { border-bottom: 0; }
.ad-row-main { flex: 1; min-width: 0; }
.ad-row-name { display: block; font-weight: 500; color: var(--neutral-900); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ad-row-sub { display: block; font-size: var(--text-xs); color: var(--neutral-600); }
.ad-bar { height: 4px; background: var(--neutral-100); border-radius: var(--radius-full); overflow: hidden; margin: var(--spacing-3) 0 var(--spacing-2); }
.ad-bar > span { display: block; height: 100%; background: var(--neutral-900); border-radius: inherit; }
.ad-bar.is-low > span { background: var(--warning-500); }
.ad-tile { padding: var(--spacing-4) var(--spacing-5); }
.ad-tile-title { font-size: var(--text-sm); font-weight: 500; color: var(--neutral-900); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ad-tile-row { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--spacing-2); font-size: var(--text-xs); color: var(--neutral-600); }
.ad-dialog { width: min(26rem, calc(100vw - 2rem)); padding: 0; border: 1px solid var(--neutral-200); border-radius: var(--radius-xl); box-shadow: var(--shadow-xl); color: var(--neutral-900); }
.ad-dialog::backdrop { background: rgba(16, 33, 38, 0.45); }
.ad-dialog .panel-body p { margin-bottom: var(--spacing-4); }
@media (max-width: 640px) { .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .stat { padding: var(--spacing-4); min-height: 5.5rem; } .stat-value { font-size: 1.375rem; } }
</style>

<?php
$pdfParams = [];
if (!empty($filterYear))     $pdfParams[] = 'filter_year=' . urlencode($filterYear);
if (!empty($filterMonth))    $pdfParams[] = 'filter_month=' . urlencode($filterMonth);
if (!empty($filterDateFrom)) $pdfParams[] = 'date_from=' . urlencode($filterDateFrom);
if (!empty($filterDateTo))   $pdfParams[] = 'date_to='   . urlencode($filterDateTo);
if (!empty($filterRegion))   $pdfParams[] = 'region='    . urlencode($filterRegion);
if (!empty($filterZone))     $pdfParams[] = 'zone='      . urlencode($filterZone);
$pdfHref = 'generate_pdf_report.php' . (!empty($pdfParams) ? '?' . implode('&', $pdfParams) : '');
$periodLabel = formatDashboardPeriodLabel($filterDateFrom, $filterDateTo);
$hasFilters = $filterYear !== '' || $filterMonth !== '' || $filterDateFrom !== '' || $filterDateTo !== '' || $filterRegion !== '' || $filterZone !== '';
$nonCompliant = ($complianceData['total_zones'] ?? 0) - ($complianceData['compliant_zones'] ?? 0);
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<div class="page-head">
    <div>
        <div class="page-eyebrow">Period · <?php echo htmlspecialchars($periodLabel); ?><?php echo $filterRegion !== '' ? ' · ' . htmlspecialchars($filterRegion) : ''; ?><?php echo $filterZone !== '' ? ' · ' . htmlspecialchars($filterZone) : ''; ?></div>
        <h1 class="page-title">Statistics</h1>
        <p class="page-sub">Reports, zone compliance and sponsorship across the network.</p>
    </div>
    <div class="page-actions">
        <a href="<?php echo htmlspecialchars($pdfHref); ?>" class="btn btn-primary"><i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF</a>
    </div>
</div>

<?php if ($flashSuccess): ?>
<div class="alert alert-success" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i><div><?php echo htmlspecialchars($flashSuccess); ?></div></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><div><?php echo htmlspecialchars($flashError); ?></div></div>
<?php endif; ?>

<!-- Filters: period chips + region/zone, applied on change -->
<?php
$rawYear  = trim((string)($_GET['filter_year'] ?? ''));
$rawMonth = trim((string)($_GET['filter_month'] ?? ''));
$rawFrom  = trim((string)($_GET['date_from'] ?? ''));
$rawTo    = trim((string)($_GET['date_to'] ?? ''));
$thisYear = (string)(int)date('Y');
$thisMonth = (string)(int)date('n');
if ($rawYear === '' && $rawMonth === '' && $rawFrom === '' && $rawTo === '') {
    $activePeriod = 'all';
} elseif ($rawYear === $thisYear && $rawMonth === '' && $rawFrom === '' && $rawTo === '') {
    $activePeriod = 'year';
} elseif ($rawYear === $thisYear && (string)(int)$rawMonth === $thisMonth && $rawFrom === '' && $rawTo === '') {
    $activePeriod = 'month';
} else {
    $activePeriod = 'custom';
}
$scopeQuery = array_filter(['region' => $filterRegion, 'zone' => $filterZone], 'strlen');
$periodHref = function (array $period) use ($scopeQuery) {
    $q = http_build_query(array_merge($period, $scopeQuery));
    return 'dashboard.php' . ($q !== '' ? '?' . $q : '');
};
?>
<form method="GET" id="filterForm" class="ad-filterbar" aria-label="Filter statistics">
    <nav class="tabs ad-period" aria-label="Period">
        <a href="<?php echo htmlspecialchars($periodHref([])); ?>" class="<?php echo $activePeriod === 'all' ? 'active' : ''; ?>">All time</a>
        <a href="<?php echo htmlspecialchars($periodHref(['filter_year' => $thisYear])); ?>" class="<?php echo $activePeriod === 'year' ? 'active' : ''; ?>"><?php echo $thisYear; ?></a>
        <a href="<?php echo htmlspecialchars($periodHref(['filter_year' => $thisYear, 'filter_month' => $thisMonth])); ?>" class="<?php echo $activePeriod === 'month' ? 'active' : ''; ?>"><?php echo date('F'); ?></a>
        <button type="button" class="<?php echo $activePeriod === 'custom' ? 'active' : ''; ?>" aria-expanded="<?php echo $activePeriod === 'custom' ? 'true' : 'false'; ?>" aria-controls="adCustomPeriod" onclick="var c=document.getElementById('adCustomPeriod');c.hidden=!c.hidden;c.querySelectorAll('select').forEach(function(el){el.disabled=c.hidden;});this.setAttribute('aria-expanded',String(!c.hidden));">Custom<i class="fas fa-chevron-down" aria-hidden="true"></i></button>
    </nav>

    <div class="ad-scope">
        <label class="sr-only" for="region">Region</label>
        <select id="region" name="region" class="input" onchange="this.form.zone.value='';this.form.submit()">
            <option value="">All regions</option>
            <?php foreach ($regions as $region): ?>
            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="sr-only" for="zone">Zone</label>
        <select id="zone" name="zone" class="input" onchange="this.form.submit()">
            <option value="">All zones</option>
            <?php foreach ($zones as $zone): ?>
            <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($hasFilters): ?><a href="dashboard.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </div>

    <?php if ($activePeriod !== 'custom'): ?>
    <!-- keep the chosen chip period when region/zone change -->
    <?php foreach (['filter_year' => $rawYear, 'filter_month' => $rawMonth] as $k => $v): if ($v !== ''): ?>
    <input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($v); ?>">
    <?php endif; endforeach; ?>
    <?php endif; ?>

    <div id="adCustomPeriod" class="ad-custom" <?php echo $activePeriod === 'custom' ? '' : 'hidden'; ?>>
        <div class="field">
            <label for="filter_year" class="field-label">Year</label>
            <select id="filter_year" name="filter_year" class="input" <?php echo $activePeriod === 'custom' ? '' : 'disabled'; ?>>
                <option value="">Any year</option>
                <?php foreach ($availableYears as $year): ?>
                <option value="<?php echo (int)$year; ?>" <?php echo $rawYear === (string)$year ? 'selected' : ''; ?>><?php echo (int)$year; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter_month" class="field-label">Month</label>
            <select id="filter_month" name="filter_month" class="input" <?php echo $activePeriod === 'custom' ? '' : 'disabled'; ?>>
                <option value="">All months</option>
                <?php foreach ($availableMonths as $monthValue => $monthLabel): ?>
                <option value="<?php echo htmlspecialchars($monthValue); ?>" <?php echo $rawMonth !== '' && (int)$rawMonth === (int)$monthValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthLabel); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <span class="ad-custom-or">or</span>
        <div class="field">
            <label for="date_from" class="field-label">From</label>
            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($rawFrom); ?>" class="input">
        </div>
        <div class="field">
            <label for="date_to" class="field-label">To</label>
            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($rawTo); ?>" class="input">
        </div>
        <button type="submit" class="btn btn-primary">Apply</button>
    </div>
</form>

<!-- Key metrics -->
<section class="section" aria-label="Key metrics">
    <div class="stat-grid stat-grid--lead">
        <div class="stat"><div class="stat-label">Reports submitted</div><div class="stat-value"><?php echo number_format($reportStats['total_reports'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">Compliance rate</div><div class="stat-value"><?php echo number_format($complianceData['compliance_rate'] ?? 0, 1); ?>%</div><div class="stat-note"><?php echo number_format($complianceData['compliant_zones'] ?? 0); ?> of <?php echo number_format($complianceData['total_zones'] ?? 0); ?> zones</div></div>
        <div class="stat"><div class="stat-label">Copies sponsored</div><div class="stat-value"><?php echo number_format($reportStats['total_copies'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">Zones with reports</div><div class="stat-value"><?php echo number_format($reportStats['unique_zones'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">Total distributions</div><div class="stat-value"><?php echo number_format($reportStats['total_distribution'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">Souls won</div><div class="stat-value"><?php echo number_format($reportStats['total_souls'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">New partners</div><div class="stat-value"><?php echo number_format($reportStats['total_new_partners'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">Rhapsody cells</div><div class="stat-value"><?php echo number_format($reportStats['total_rhapsody_cells'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">New churches</div><div class="stat-value"><?php echo number_format($reportStats['total_new_churches'] ?? 0); ?></div></div>
    </div>
</section>


<!-- Monthly breakdown -->
<section class="section">
    <div class="section-head">
        <h2 class="section-title">Monthly breakdown</h2>
        <span class="meta"><?php echo htmlspecialchars($periodLabel); ?></span>
    </div>
    <?php if (!empty($monthlyBreakdown)): ?>
    <div class="table-wrap ad-scroll">
        <table class="data-table">
            <thead><tr><th scope="col">Month</th><th scope="col" class="num">Reports</th><th scope="col" class="num">Copies sponsored</th><th scope="col" class="num">Souls won</th></tr></thead>
            <tbody>
                <?php foreach ($monthlyBreakdown as $month): ?>
                <tr>
                    <td><?php echo htmlspecialchars($month['month_name']); ?></td>
                    <td class="num"><?php echo number_format($month['report_count']); ?></td>
                    <td class="num"><?php echo number_format($month['total_copies'] ?? 0); ?></td>
                    <td class="num"><?php echo number_format($month['total_souls'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="panel"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-table" aria-hidden="true"></i></div><div class="empty-state-title">No reports in this period</div><div class="empty-state-text">Widen the date range or clear the filters.</div><a href="dashboard.php" class="btn btn-secondary">Clear filters</a></div></div>
    <?php endif; ?>
</section>

<!-- Engagement -->
<section class="section">
    <div class="section-head">
        <h2 class="section-title">Engagement</h2>
        <span class="section-meta">Portal visits and posts</span>
    </div>
    <div class="stat-grid">
        <div class="stat"><div class="stat-label">Avg. visits / month</div><div class="stat-value"><?php echo number_format($engagementStats['avg_visits_per_month'] ?? 0, 1); ?></div></div>
        <div class="stat"><div class="stat-label">Avg. unique visitors / month</div><div class="stat-value"><?php echo number_format($engagementStats['avg_unique_visitors_per_month'] ?? 0, 1); ?></div></div>
        <div class="stat"><div class="stat-label">Visits this month</div><div class="stat-value"><?php echo number_format($engagementStats['visits_this_month'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">Unique visitors this month</div><div class="stat-value"><?php echo number_format($engagementStats['unique_visitors_this_month'] ?? 0); ?></div></div>
        <div class="stat"><div class="stat-label">Reports this month</div><div class="stat-value"><?php echo number_format($engagementStats['posts_this_month'] ?? 0); ?></div></div>
    </div>
</section>

<!-- Zone compliance -->
<section class="section">
    <div class="section-head">
        <h2 class="section-title">Zone compliance</h2>
        <a href="zone_compliance.php" class="link" style="font-size:var(--text-sm);">View details</a>
    </div>
    <div class="stat-grid" style="margin-bottom:var(--spacing-4);">
        <div class="stat"><div class="stat-label">Compliant zones</div><div class="stat-value"><?php echo number_format($complianceData['compliant_zones'] ?? 0); ?></div><div class="stat-note">Reports submitted</div></div>
        <div class="stat"><div class="stat-label">Non-compliant zones</div><div class="stat-value"><?php echo number_format($nonCompliant); ?></div><div class="stat-note">No reports yet</div></div>
    </div>
    <?php if (!empty($zoneBreakdown)): ?>
    <div class="table-wrap ad-scroll">
        <table class="data-table">
            <thead><tr><th scope="col">Zone</th><th scope="col">Region</th><th scope="col" class="num">Reports</th><th scope="col" class="num">Copies sponsored</th><th scope="col">Status</th></tr></thead>
            <tbody>
                <?php foreach ($zoneBreakdown as $zone): ?>
                <tr>
                    <td><?php echo htmlspecialchars($zone['zone'] ?? 'Unknown'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($zone['region'] ?? 'Unknown'); ?></td>
                    <td class="num"><?php echo number_format($zone['report_count'] ?? 0); ?></td>
                    <td class="num"><?php echo number_format($zone['total_copies'] ?? 0); ?></td>
                    <td><?php if (($zone['report_count'] ?? 0) > 0): ?><span class="badge badge-success">Compliant</span><?php else: ?><span class="badge badge-warning">No reports</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<!-- Reminders -->
<section class="section">
    <div class="section-head">
        <h2 class="section-title">Reminders</h2>
        <span class="section-meta">Active RZMs behind on reports</span>
    </div>
    <div class="grid-2">
        <div class="panel">
            <div class="panel-head">
                <span>No <?php echo date('F'); ?> report</span>
                <span class="badge <?php echo count($missingThisMonth) > 0 ? 'badge-warning' : 'badge-success'; ?>"><?php echo count($missingThisMonth); ?> user<?php echo count($missingThisMonth) !== 1 ? 's' : ''; ?></span>
            </div>
            <?php if (empty($missingThisMonth)): ?>
            <div class="empty-state"><div class="empty-state-title">Everyone has reported</div><div class="empty-state-text">All active users have submitted this month.</div></div>
            <?php else: ?>
            <div class="ad-list">
                <?php foreach ($missingThisMonth as $mu): $muName = $mu['name'] ?: $mu['username']; ?>
                <div class="ad-row">
                    <div class="ad-row-main">
                        <span class="ad-row-name"><?php echo htmlspecialchars($muName); ?></span>
                        <span class="ad-row-sub"><?php echo htmlspecialchars($mu['zone'] ?? ''); ?><?php if (!empty($mu['region'])): ?> · <?php echo htmlspecialchars($mu['region']); ?><?php endif; ?></span>
                    </div>
                    <button type="button" onclick="openReminder(<?php echo (int)$mu['id']; ?>, this.dataset.name)" data-name="<?php echo htmlspecialchars($muName); ?>" class="btn btn-ghost btn-sm" aria-label="Send reminder to <?php echo htmlspecialchars($muName); ?>"><i class="fas fa-bell" aria-hidden="true"></i> Remind</button>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="panel-foot" style="display:flex;gap:var(--spacing-2);flex-wrap:wrap;">
                <button type="button" onclick="openReminder(0, 'pending users')" class="btn btn-primary btn-sm"><i class="fas fa-bullhorn" aria-hidden="true"></i> Broadcast reminder</button>
                <a href="<?php echo BASE_URL; ?>/search.php?q=<?php echo urlencode(date('F')); ?>" class="btn btn-secondary btn-sm">View all</a>
            </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head">
                <span>Chronic late submitters</span>
                <span class="badge <?php echo count($chronicLate) > 0 ? 'badge-warning' : 'badge-success'; ?>"><?php echo count($chronicLate); ?> user<?php echo count($chronicLate) !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="ad-row meta" style="background:var(--mist);">Missed 2+ of the last 6 months</div>
            <?php if (empty($chronicLate)): ?>
            <div class="empty-state"><div class="empty-state-title">No chronic late submitters</div></div>
            <?php else: ?>
            <div class="ad-list">
                <?php foreach ($chronicLate as $cl): $clName = $cl['name'] ?: $cl['username']; ?>
                <div class="ad-row">
                    <div class="ad-row-main">
                        <span class="ad-row-name"><?php echo htmlspecialchars($clName); ?></span>
                        <span class="ad-row-sub"><?php echo htmlspecialchars($cl['zone'] ?? ''); ?><?php if (!empty($cl['region'])): ?> · <?php echo htmlspecialchars($cl['region']); ?><?php endif; ?></span>
                    </div>
                    <span class="badge badge-warning no-dot num" title="<?php echo (int)$cl['months_submitted']; ?>/6 months submitted"><?php echo (int)$cl['months_missed']; ?> mo missed</span>
                    <button type="button" onclick="openReminder(<?php echo (int)$cl['id']; ?>, this.dataset.name)" data-name="<?php echo htmlspecialchars($clName); ?>" class="btn btn-ghost btn-sm" aria-label="Send reminder to <?php echo htmlspecialchars($clName); ?>"><i class="fas fa-bell" aria-hidden="true"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Reminder dialog -->
<dialog class="ad-dialog" id="reminderModal" aria-labelledby="reminderModalTitle">
    <form method="POST" id="reminderForm">
        <div class="panel-head"><span id="reminderModalTitle">Send reminder</span></div>
        <div class="panel-body">
            <p id="reminderModalDesc" class="muted">Send a reminder to the selected user.</p>
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="reminder_action" value="1">
            <input type="hidden" name="reminder_target_id" id="reminderTargetId" value="0">
            <div class="field" style="margin-bottom:0;">
                <label for="reminderMessage" class="field-label">Message</label>
                <textarea name="reminder_message" id="reminderMessage" rows="4" class="input" placeholder="e.g. Please submit your monthly report. It is now overdue." required></textarea>
            </div>
        </div>
        <div class="panel-foot" style="display:flex;gap:var(--spacing-2);justify-content:flex-end;">
            <button type="button" onclick="closeReminder()" class="btn btn-secondary">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send</button>
        </div>
    </form>
</dialog>

<!-- Sponsorship -->
<section class="section">
    <div class="section-head"><h2 class="section-title">Sponsorship</h2></div>
    <div class="stat-grid">
        <div class="stat"><div class="stat-label">Active sponsors</div><div class="stat-value"><?php echo number_format($sponsorshipStats['active_sponsors'] ?? 0); ?></div></div>
    </div>
</section>

<?php
// ── Regional compliance milestones ──────────────────────────────────────
$expectedMonthsAdm = (!empty($filterYear) && (int)$filterYear == (int)date('Y'))
    ? (int)date('n')
    : ((!empty($filterYear)) ? 12 : (int)date('n'));

$regionMilestoneParams = [];
$regionMilestoneWhere  = "WHERE u.zone IS NOT NULL AND u.zone != '' AND u.region IS NOT NULL AND u.region != ''";
if (!empty($filterRegion)) {
    $regionMilestoneWhere .= " AND u.region = :region";
    $regionMilestoneParams[':region'] = $filterRegion;
}
$yearJoinCond = '';
if (!empty($filterYear)) {
    $yearJoinCond = " AND YEAR(r.report_month) = :year";
    $regionMilestoneParams[':year'] = $filterYear;
}
$rmStmt = $conn->prepare("
    SELECT u.region,
           COUNT(DISTINCT u.zone)                                          AS total_zones,
           COUNT(DISTINCT CASE WHEN r.id IS NOT NULL THEN u.zone END)      AS zones_submitted,
           COALESCE(SUM(r.total_copies), 0)                                AS copies,
           COALESCE(SUM(r.souls_won), 0)                                   AS souls
    FROM users u
    LEFT JOIN reports r ON r.user_id = u.id $yearJoinCond
    $regionMilestoneWhere
    GROUP BY u.region
    ORDER BY zones_submitted DESC, copies DESC
");
$rmStmt->execute($regionMilestoneParams);
$regionMilestones = $rmStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if (!empty($regionMilestones)): ?>
<section class="section">
    <div class="section-head">
        <h2 class="section-title">Regional milestones<?php echo !empty($filterYear) ? ' · ' . htmlspecialchars($filterYear) : ''; ?></h2>
        <a href="../pages/analytics.php<?php echo !empty($filterYear) ? '?year=' . urlencode($filterYear) : ''; ?>" class="link" style="font-size:var(--text-sm);">Full analytics</a>
    </div>
    <div class="grid-3">
        <?php foreach ($regionMilestones as $rm):
            $tz   = max(1, (int)$rm['total_zones']);
            $zs   = (int)$rm['zones_submitted'];
            $rate = min(100, round(($zs / $tz) * 100));
        ?>
        <div class="panel ad-tile">
            <div class="ad-tile-row"><span class="ad-tile-title" title="<?php echo htmlspecialchars($rm['region']); ?>"><?php echo htmlspecialchars($rm['region']); ?></span><span class="num" style="color:var(--neutral-900);font-weight:500;"><?php echo $rate; ?>%</span></div>
            <div class="ad-bar<?php echo $rate < 50 ? ' is-low' : ''; ?>" role="progressbar" aria-valuenow="<?php echo $rate; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo htmlspecialchars($rm['region']); ?> zones reporting"><span style="width:<?php echo $rate; ?>%;"></span></div>
            <div class="ad-tile-row num"><span><?php echo $zs; ?> / <?php echo $tz; ?> zones</span></div>
            <div class="ad-tile-row num"><span><?php echo number_format($rm['copies']); ?> copies · <?php echo number_format($rm['souls']); ?> souls</span></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php
// ── Team progress board (zones ranked by compliance rate) ───────────────
$teamParams = [];
$teamUserWhere = "u.zone IS NOT NULL AND u.zone != ''";
if (!empty($filterRegion)) {
    $teamUserWhere .= " AND u.region = :region";
    $teamParams[':region'] = $filterRegion;
}
$teamYearJoin = '';
if (!empty($filterYear)) {
    $teamYearJoin = " AND YEAR(r.report_month) = :year";
    $teamParams[':year'] = $filterYear;
}
$teamStmt = $conn->prepare("
    SELECT u.zone, u.region,
           COUNT(DISTINCT DATE_FORMAT(r.report_month,'%Y-%m')) AS months_submitted,
           COALESCE(SUM(r.total_copies), 0)                    AS copies,
           COALESCE(SUM(r.souls_won), 0)                       AS souls
    FROM users u
    LEFT JOIN reports r ON r.user_id = u.id $teamYearJoin
    WHERE $teamUserWhere
    GROUP BY u.zone, u.region
    ORDER BY months_submitted DESC, copies DESC
    LIMIT 24
");
$teamStmt->execute($teamParams);
$teamZones = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if (!empty($teamZones)): ?>
<section class="section">
    <div class="section-head">
        <h2 class="section-title">Team progress<?php echo !empty($filterRegion) ? ' · ' . htmlspecialchars($filterRegion) : ''; ?></h2>
        <span class="meta"><?php echo $expectedMonthsAdm; ?> months expected · top <?php echo count($teamZones); ?> zones</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th scope="col" class="num">#</th><th scope="col">Zone</th><th scope="col">Region</th><th scope="col" class="num">Months</th><th scope="col" class="num">Copies</th><th scope="col" class="num">Rate</th></tr></thead>
            <tbody>
                <?php foreach ($teamZones as $ti => $tz):
                    $rate = $expectedMonthsAdm > 0 ? min(100, round(((int)$tz['months_submitted'] / $expectedMonthsAdm) * 100)) : 0;
                ?>
                <tr>
                    <td class="num meta"><?php echo $ti + 1; ?></td>
                    <td><?php echo htmlspecialchars($tz['zone']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($tz['region'] ?? ''); ?></td>
                    <td class="num"><?php echo (int)$tz['months_submitted']; ?>/<?php echo $expectedMonthsAdm; ?></td>
                    <td class="num"><?php echo number_format($tz['copies']); ?></td>
                    <td class="num"><?php if ((int)$tz['months_submitted'] === 0): ?><span class="badge badge-warning">0%</span><?php else: echo $rate . '%'; endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<script>
    // Reminder dialog
    function openReminder(userId, name) {
        var modal = document.getElementById('reminderModal');
        document.getElementById('reminderTargetId').value = userId;
        document.getElementById('reminderModalDesc').textContent =
            userId > 0
                ? 'Send a reminder to ' + name + '.'
                : 'Broadcast a reminder to all ' + name + '.';
        document.getElementById('reminderMessage').value = '';
        if (typeof modal.showModal === 'function') { modal.showModal(); } else { modal.setAttribute('open', ''); }
    }
    function closeReminder() {
        var modal = document.getElementById('reminderModal');
        if (typeof modal.close === 'function') { modal.close(); } else { modal.removeAttribute('open'); }
    }
    document.getElementById('reminderModal').addEventListener('click', function(e) {
        if (e.target === this) closeReminder();
    });

    (function () {
        const yearInput = document.getElementById('filter_year');
        const monthInput = document.getElementById('filter_month');
        const startInput = document.getElementById('date_from');
        const endInput = document.getElementById('date_to');

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function syncDateRangeFromPeriod() {
            const year = yearInput ? yearInput.value : '';
            const month = monthInput ? monthInput.value : '';

            if (!year) {
                return;
            }

            if (month) {
                const start = `${year}-${pad(month)}-01`;
                startInput.value = start;
                endInput.value = start;
                return;
            }

            startInput.value = `${year}-01-01`;
            endInput.value = `${year}-12-31`;
        }

        if (yearInput) {
            yearInput.addEventListener('change', syncDateRangeFromPeriod);
        }

        if (monthInput) {
            monthInput.addEventListener('change', syncDateRangeFromPeriod);
        }
    }());
</script>

<?php require_once '../layouts/footer.php'; ?>
