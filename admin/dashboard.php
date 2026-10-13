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
          AND u.role = 'user'
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
        WHERE u.active = 1 AND u.role = 'user'
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
$currentYear = date('Y');
if (!in_array($currentYear, $availableYears, true)) {
    array_unshift($availableYears, $currentYear);
}
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
.ad-page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.ad-topbar { margin-bottom: 1.75rem; }
.ad-topbar h1 { font-size: 1.125rem; font-weight: 700; color: var(--neutral-900); margin: 0 0 0.2rem; }
.ad-topbar p { font-size: 0.85rem; color: var(--neutral-500); margin: 0 0 0.25rem; }
.ad-period { font-size: 0.8rem; font-weight: 600; color: var(--primary-600); }
.ad-section { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; margin-bottom: 1.25rem; overflow: hidden; }
/* Compliance panels */
.ad-compliance-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.25rem; }
@media(max-width:768px){ .ad-compliance-grid { grid-template-columns:1fr; } }
.ad-comp-panel { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; }
.ad-comp-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); display:flex; align-items:center; justify-content:space-between; gap:0.75rem; }
.ad-comp-head h2 { font-size:0.875rem; font-weight:600; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.04em; }
.ad-comp-count { font-size:0.75rem; font-weight:600; padding:0.15rem 0.55rem; border-radius:9999px; }
.ad-comp-count-warn { background:var(--warning-100); color:var(--warning-700); }
.ad-comp-count-danger { background:var(--danger-100); color:var(--danger-700); }
.ad-comp-count-ok { background:var(--success-100); color:var(--success-700); }
.ad-comp-list { max-height:16rem; overflow-y:auto; }
.ad-comp-row { display:flex; align-items:center; gap:0.6rem; padding:0.6rem 1.1rem; border-bottom:1px solid var(--neutral-100); font-size:0.8125rem; }
.ad-comp-row:last-child { border-bottom:none; }
.ad-comp-row:hover { background:var(--neutral-50); }
.ad-comp-name { flex:1; font-weight:500; color:var(--neutral-800); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ad-comp-sub { font-size:0.72rem; color:var(--neutral-400); display:block; }
.ad-comp-empty { padding:2rem 1.25rem; text-align:center; font-size:0.8125rem; color:var(--neutral-400); }
.ad-comp-empty i { display:block; font-size:1.5rem; color:var(--neutral-300); margin-bottom:0.4rem; }
/* Reminder modal */
.ad-reminder-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9000; align-items:center; justify-content:center; padding:1rem; }
.ad-reminder-modal.open { display:flex; }
.ad-reminder-box { background:#fff; border-radius:var(--radius-xl); width:100%; max-width:26rem; padding:1.5rem; box-shadow:var(--shadow-xl); }
.ad-reminder-box h3 { font-size:1rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.25rem; }
.ad-reminder-box p { font-size:0.8125rem; color:var(--neutral-500); margin:0 0 1rem; }
.ad-reminder-label { font-size:0.78rem; font-weight:600; color:var(--neutral-700); margin-bottom:0.3rem; display:block; }
/* Toast display in admin */
.ad-toast-bar { position:fixed; bottom:1.5rem; right:1.5rem; z-index:8000; display:flex; flex-direction:column; gap:0.5rem; pointer-events:none; }
.ad-section-head { padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--neutral-200); display: flex; align-items: center; justify-content: space-between; }
.ad-section-head h2 { font-size: 0.875rem; font-weight: 600; color: var(--neutral-900); margin: 0; text-transform: uppercase; letter-spacing: 0.04em; }
.ad-section-body { padding: 1.25rem; }
.ad-metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1px; background: var(--neutral-200); }
.ad-metric { background: #fff; padding: 1rem 1.1rem; }
.ad-metric-label { font-size: 0.75rem; color: var(--neutral-500); margin-bottom: 0.2rem; line-height: 1.3; }
.ad-metric-value { font-size: 1.35rem; font-weight: 700; color: var(--neutral-900); line-height: 1.15; overflow-wrap: anywhere; }
.ad-metric-note { font-size: 0.75rem; color: var(--neutral-400); margin-top: 0.2rem; }
.ad-filter-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.875rem; }
@media(max-width:768px){ .ad-filter-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:480px){ .ad-filter-grid { grid-template-columns: 1fr; } }
.ad-filter-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 1rem; align-items: center; }
.ad-compliance-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--neutral-200); margin-bottom: 1px; }
.ad-compliance-cell { background: #fff; padding: 1rem 1.25rem; }
.ad-compliance-cell h3 { font-size: 0.75rem; font-weight: 600; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 0.35rem; }
.ad-compliance-cell p { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.15rem; }
.ad-compliance-cell span { font-size: 0.8rem; color: var(--neutral-500); }
.ad-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.ad-table th { padding: 0.65rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 600; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid var(--neutral-200); }
.ad-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--neutral-100); color: var(--neutral-800); }
.ad-table tr:last-child td { border-bottom: none; }
.ad-table tbody tr:hover td { background: var(--neutral-50); }

/* ── Regional milestones ── */
.ad-milestone-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:0.75rem; }
.ad-milestone-card { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; padding:0.9rem 1rem; }
.ad-milestone-card h4 { font-size:0.78rem; font-weight:700; color:var(--neutral-800); margin:0 0 0.55rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ad-milestone-bar-track { height:7px; background:var(--neutral-100); border-radius:4px; overflow:hidden; margin-bottom:0.35rem; }
.ad-milestone-bar-fill  { height:100%; border-radius:4px; transition:width 0.6s ease; }
.ad-milestone-meta { display:flex; justify-content:space-between; font-size:0.7rem; color:var(--neutral-500); }
.ad-milestone-meta strong { color:var(--neutral-800); }

/* ── Team progress board ── */
.ad-team-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:0.6rem; }
.ad-team-card { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; padding:0.75rem 0.85rem; text-align:center; }
.ad-team-rate { font-size:1.3rem; font-weight:800; line-height:1.1; }
.ad-team-zone { font-size:0.68rem; font-weight:600; color:var(--neutral-700); margin:0.2rem 0 0.1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ad-team-sub  { font-size:0.65rem; color:var(--neutral-400); }
.ad-team-card.top1 { border-color:#fde68a; background:#fefce8; }
.ad-team-card.top2 { border-color:#e2e8f0; background:#f8fafc; }
.ad-team-card.top3 { border-color:#fed7aa; background:#fff7ed; }
.ad-team-card.zero { border-color:var(--danger-100); background:var(--danger-50); }
</style>

<div class="ad-page">
    <div class="ad-topbar">
        <h1>Admin Dashboard</h1>
        <p>Overview of reports, zone compliance, and sponsorship statistics</p>
        <div class="ad-period">Reporting Period: <?php echo htmlspecialchars(formatDashboardPeriodLabel($filterDateFrom, $filterDateTo)); ?></div>
    </div>

    <!-- Filters -->
    <div class="ad-section">
        <div class="ad-section-head"><h2>Filters</h2></div>
        <div class="ad-section-body">
            <form method="GET" id="filterForm">
                <div class="ad-filter-grid">
                    <div class="modern-form-group">
                        <label for="filter_year" class="modern-form-label">Year</label>
                        <select id="filter_year" name="filter_year" class="modern-form-input">
                            <option value="">Any Year</option>
                            <?php foreach ($availableYears as $year): ?>
                            <option value="<?php echo htmlspecialchars((string)$year); ?>" <?php echo (string)$filterYear === (string)$year ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$year); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modern-form-group">
                        <label for="filter_month" class="modern-form-label">Month</label>
                        <select id="filter_month" name="filter_month" class="modern-form-input">
                            <option value="">All Months</option>
                            <?php foreach ($availableMonths as $monthValue => $monthLabel): ?>
                            <option value="<?php echo htmlspecialchars($monthValue); ?>" <?php echo (string)(int)$filterMonth === (string)(int)$monthValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modern-form-group">
                        <label for="date_from" class="modern-form-label">Start Date</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" class="modern-form-input">
                    </div>
                    <div class="modern-form-group">
                        <label for="date_to" class="modern-form-label">End Date</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>" class="modern-form-input">
                    </div>
                    <div class="modern-form-group">
                        <label for="region" class="modern-form-label">Region</label>
                        <select id="region" name="region" class="modern-form-input">
                            <option value="">All Regions</option>
                            <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modern-form-group">
                        <label for="zone" class="modern-form-label">Zone</label>
                        <select id="zone" name="zone" class="modern-form-input">
                            <option value="">All Zones</option>
                            <?php foreach ($zones as $zone): ?>
                            <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p style="font-size:0.75rem;color:var(--neutral-400);margin:0.75rem 0 0;">Selecting a month and year targets that report month. Use date fields only for a custom range.</p>
                <div class="ad-filter-actions">
                    <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;">Apply Filters</button>
                    <a href="dashboard.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">Clear</a>
                    <a href="generate_pdf_report.php<?php
                        $pdfParams = [];
                        if (!empty($filterYear))     $pdfParams[] = 'filter_year=' . urlencode($filterYear);
                        if (!empty($filterMonth))    $pdfParams[] = 'filter_month=' . urlencode($filterMonth);
                        if (!empty($filterDateFrom)) $pdfParams[] = 'date_from=' . urlencode($filterDateFrom);
                        if (!empty($filterDateTo))   $pdfParams[] = 'date_to='   . urlencode($filterDateTo);
                        if (!empty($filterRegion))   $pdfParams[] = 'region='    . urlencode($filterRegion);
                        if (!empty($filterZone))     $pdfParams[] = 'zone='      . urlencode($filterZone);
                        echo !empty($pdfParams) ? '?' . implode('&', $pdfParams) : '';
                    ?>" class="modern-button" style="font-size:0.8125rem;background:var(--danger-600);color:#fff;border-color:var(--danger-600);">
                        <i class="fas fa-file-pdf modern-button-icon"></i> Export PDF
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="ad-section">
        <div class="ad-section-head"><h2>Key Metrics</h2></div>
        <div class="ad-metric-grid">
            <div class="ad-metric"><div class="ad-metric-label">Reports Submitted</div><div class="ad-metric-value"><?php echo number_format($reportStats['total_reports'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Zones with Reports</div><div class="ad-metric-value"><?php echo number_format($reportStats['unique_zones'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Avg. Visits / Month</div><div class="ad-metric-value"><?php echo number_format($engagementStats['avg_visits_per_month'] ?? 0, 1); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Avg. Unique Visitors / Month</div><div class="ad-metric-value"><?php echo number_format($engagementStats['avg_unique_visitors_per_month'] ?? 0, 1); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Visits This Month</div><div class="ad-metric-value"><?php echo number_format($engagementStats['visits_this_month'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Unique Visitors This Month</div><div class="ad-metric-value"><?php echo number_format($engagementStats['unique_visitors_this_month'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Reports This Month</div><div class="ad-metric-value"><?php echo number_format($engagementStats['posts_this_month'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Copies Sponsored</div><div class="ad-metric-value"><?php echo number_format($reportStats['total_copies'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Total Distributions</div><div class="ad-metric-value"><?php echo number_format($reportStats['total_distribution'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Souls Won</div><div class="ad-metric-value"><?php echo number_format($reportStats['total_souls'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">New Partners</div><div class="ad-metric-value"><?php echo number_format($reportStats['total_new_partners'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Rhapsody Cells</div><div class="ad-metric-value"><?php echo number_format($reportStats['total_rhapsody_cells'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">New Churches</div><div class="ad-metric-value"><?php echo number_format($reportStats['total_new_churches'] ?? 0); ?></div></div>
            <div class="ad-metric"><div class="ad-metric-label">Compliance Rate</div><div class="ad-metric-value"><?php echo number_format($complianceData['compliance_rate'] ?? 0, 1); ?>%</div><div class="ad-metric-note"><?php echo number_format($complianceData['compliant_zones'] ?? 0); ?> of <?php echo number_format($complianceData['total_zones'] ?? 0); ?> zones</div></div>
        </div>
    </div>

    <!-- Monthly Breakdown -->
    <div class="ad-section">
        <div class="ad-section-head">
            <h2>Monthly Breakdown<?php echo (!empty($filterDateFrom) || !empty($filterDateTo)) ? ' &mdash; ' . htmlspecialchars(formatDashboardPeriodLabel($filterDateFrom, $filterDateTo)) : ' &mdash; All Time'; ?></h2>
        </div>
        <?php if (!empty($monthlyBreakdown)): ?>
        <div style="overflow-x:auto;">
            <table class="ad-table">
                <thead><tr>
                    <th>Month</th><th>Reports</th><th>Copies Sponsored</th><th>Souls Won</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($monthlyBreakdown as $month): ?>
                    <tr>
                        <td style="font-weight:500;"><?php echo htmlspecialchars($month['month_name']); ?></td>
                        <td><?php echo number_format($month['report_count']); ?></td>
                        <td><?php echo number_format($month['total_copies'] ?? 0); ?></td>
                        <td><?php echo number_format($month['total_souls'] ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:1.25rem;font-size:0.85rem;color:var(--neutral-500);">No reports found for the selected period.</div>
        <?php endif; ?>
    </div>

    <!-- Zone Compliance -->
    <div class="ad-section">
        <div class="ad-section-head">
            <h2>Zone Compliance</h2>
            <a href="zone_compliance.php" style="font-size:0.8rem;color:var(--primary-600);text-decoration:none;">View Details &rarr;</a>
        </div>
        <div class="ad-compliance-grid">
            <div class="ad-compliance-cell" style="border-right:1px solid var(--neutral-200);">
                <h3>Compliant Zones</h3>
                <p style="color:var(--success-700);"><?php echo $complianceData['compliant_zones'] ?? 0; ?></p>
                <span>Zones with reports submitted</span>
            </div>
            <div class="ad-compliance-cell">
                <h3>Non-Compliant Zones</h3>
                <p style="color:var(--danger-700);"><?php echo ($complianceData['total_zones'] ?? 0) - ($complianceData['compliant_zones'] ?? 0); ?></p>
                <span>Zones without reports</span>
            </div>
        </div>
        <?php if (!empty($zoneBreakdown)): ?>
        <div style="overflow-x:auto;">
            <table class="ad-table">
                <thead><tr>
                    <th>Zone</th><th>Region</th><th>Reports</th><th>Copies Sponsored</th><th>Status</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($zoneBreakdown as $zone): ?>
                    <tr>
                        <td style="font-weight:500;"><?php echo htmlspecialchars($zone['zone'] ?? 'Unknown'); ?></td>
                        <td><?php echo htmlspecialchars($zone['region'] ?? 'Unknown'); ?></td>
                        <td><?php echo number_format($zone['report_count'] ?? 0); ?></td>
                        <td><?php echo number_format($zone['total_copies'] ?? 0); ?></td>
                        <td><?php if (($zone['report_count'] ?? 0) > 0): ?><span class="modern-badge modern-badge-success">Compliant</span><?php else: ?><span class="modern-badge modern-badge-danger">Non-Compliant</span><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Compliance Panels ── -->
    <div class="ad-compliance-grid">

        <!-- Missing this month -->
        <div class="ad-comp-panel">
            <div class="ad-comp-head">
                <h2><i class="fas fa-calendar-times" style="color:var(--danger-500);margin-right:0.35rem;font-size:0.8rem;"></i>No <?php echo date('F'); ?> Report</h2>
                <span class="ad-comp-count <?php echo count($missingThisMonth) > 0 ? 'ad-comp-count-danger' : 'ad-comp-count-ok'; ?>">
                    <?php echo count($missingThisMonth); ?> user<?php echo count($missingThisMonth) !== 1 ? 's' : ''; ?>
                </span>
            </div>
            <?php if (empty($missingThisMonth)): ?>
            <div class="ad-comp-empty"><i class="fas fa-check-circle" style="color:var(--success-400);"></i>All active users have submitted this month!</div>
            <?php else: ?>
            <div class="ad-comp-list">
                <?php foreach ($missingThisMonth as $mu): ?>
                <div class="ad-comp-row">
                    <div style="flex:1;min-width:0;">
                        <span class="ad-comp-name"><?php echo htmlspecialchars($mu['name'] ?: $mu['username']); ?></span>
                        <span class="ad-comp-sub"><?php echo htmlspecialchars($mu['zone'] ?? ''); ?><?php if (!empty($mu['region'])): ?> &middot; <?php echo htmlspecialchars($mu['region']); ?><?php endif; ?></span>
                    </div>
                    <button onclick="openReminder(<?php echo (int)$mu['id']; ?>, '<?php echo htmlspecialchars(addslashes($mu['name'] ?: $mu['username'])); ?>')"
                            class="modern-button modern-button-secondary" style="font-size:0.7rem;padding:0.2rem 0.55rem;flex-shrink:0;" title="Send Reminder">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="padding:0.65rem 1.1rem;border-top:1px solid var(--neutral-100);display:flex;gap:0.5rem;flex-wrap:wrap;">
                <button onclick="openReminder(0, 'all pending users')" class="modern-button modern-button-primary" style="font-size:0.75rem;">
                    <i class="fas fa-bullhorn modern-button-icon"></i> Broadcast Reminder
                </button>
                <a href="<?php echo BASE_URL; ?>/search.php?q=<?php echo urlencode(date('F')); ?>" class="modern-button modern-button-secondary" style="font-size:0.75rem;">View All &rarr;</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Chronic late submitters -->
        <div class="ad-comp-panel">
            <div class="ad-comp-head">
                <h2><i class="fas fa-clock" style="color:var(--warning-500);margin-right:0.35rem;font-size:0.8rem;"></i>Chronic Late Submitters</h2>
                <span class="ad-comp-count <?php echo count($chronicLate) > 0 ? 'ad-comp-count-warn' : 'ad-comp-count-ok'; ?>">
                    <?php echo count($chronicLate); ?> user<?php echo count($chronicLate) !== 1 ? 's' : ''; ?>
                </span>
            </div>
            <div style="padding:0.45rem 1.1rem;background:var(--neutral-50);border-bottom:1px solid var(--neutral-100);font-size:0.7rem;color:var(--neutral-500);">
                Missed 2+ of the last 6 months
            </div>
            <?php if (empty($chronicLate)): ?>
            <div class="ad-comp-empty"><i class="fas fa-star" style="color:var(--warning-400);"></i>No chronic late submitters — great compliance!</div>
            <?php else: ?>
            <div class="ad-comp-list">
                <?php foreach ($chronicLate as $cl): ?>
                <div class="ad-comp-row">
                    <div style="flex:1;min-width:0;">
                        <span class="ad-comp-name"><?php echo htmlspecialchars($cl['name'] ?: $cl['username']); ?></span>
                        <span class="ad-comp-sub"><?php echo htmlspecialchars($cl['zone'] ?? ''); ?><?php if (!empty($cl['region'])): ?> &middot; <?php echo htmlspecialchars($cl['region']); ?><?php endif; ?></span>
                    </div>
                    <span style="font-size:0.72rem;font-weight:600;color:var(--danger-600);flex-shrink:0;margin-right:0.4rem;"
                          title="<?php echo (int)$cl['months_submitted']; ?>/6 months submitted">
                        <?php echo (int)$cl['months_missed']; ?>mo missed
                    </span>
                    <button onclick="openReminder(<?php echo (int)$cl['id']; ?>, '<?php echo htmlspecialchars(addslashes($cl['name'] ?: $cl['username'])); ?>')"
                            class="modern-button modern-button-secondary" style="font-size:0.7rem;padding:0.2rem 0.55rem;flex-shrink:0;" title="Send Reminder">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /.ad-compliance-grid -->

    <!-- ── Reminder Modal ── -->
    <div class="ad-reminder-modal" id="reminderModal">
        <div class="ad-reminder-box">
            <h3><i class="fas fa-bell" style="color:var(--primary-500);margin-right:0.4rem;"></i>Send Reminder</h3>
            <p id="reminderModalDesc">Send a reminder to the selected user.</p>
            <form method="POST" id="reminderForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="reminder_action" value="1">
                <input type="hidden" name="reminder_target_id" id="reminderTargetId" value="0">
                <div style="margin-bottom:0.85rem;">
                    <label class="ad-reminder-label">Message</label>
                    <textarea name="reminder_message" id="reminderMessage" rows="4"
                              class="modern-form-input" style="resize:vertical;"
                              placeholder="e.g. Please submit your monthly report — it is now overdue."
                              required></textarea>
                </div>
                <div style="display:flex;gap:0.5rem;justify-content:flex-end;flex-wrap:wrap;">
                    <button type="button" onclick="closeReminder()" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">Cancel</button>
                    <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;">
                        <i class="fas fa-paper-plane modern-button-icon"></i> Send
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Sponsorship -->
    <div class="ad-section">
        <div class="ad-section-head"><h2>Sponsorship Statistics</h2></div>
        <div class="ad-metric-grid">
            <div class="ad-metric"><div class="ad-metric-label">Active Sponsors</div><div class="ad-metric-value"><?php echo number_format($sponsorshipStats['active_sponsors'] ?? 0); ?></div></div>
        </div>
    </div>

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

    <!-- Regional Compliance Milestones -->
    <?php if (!empty($regionMilestones)): ?>
    <div class="ad-section">
        <div class="ad-section-head">
            <h2>Regional Compliance Milestones<?php echo !empty($filterYear) ? ' — ' . htmlspecialchars($filterYear) : ''; ?></h2>
            <a href="../pages/analytics.php<?php echo !empty($filterYear) ? '?year='.urlencode($filterYear) : ''; ?>" style="font-size:0.78rem;color:var(--primary-600);text-decoration:none;">Full Analytics &rarr;</a>
        </div>
        <div class="ad-section-body">
            <div class="ad-milestone-grid">
                <?php foreach ($regionMilestones as $rm):
                    $tz    = max(1, (int)$rm['total_zones']);
                    $zs    = (int)$rm['zones_submitted'];
                    $rate  = min(100, round(($zs / $tz) * 100));
                    $color = $rate >= 80 ? '#10b981' : ($rate >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="ad-milestone-card">
                    <h4 title="<?php echo htmlspecialchars($rm['region']); ?>"><?php echo htmlspecialchars($rm['region']); ?></h4>
                    <div class="ad-milestone-bar-track">
                        <div class="ad-milestone-bar-fill" style="width:<?php echo $rate; ?>%;background:<?php echo $color; ?>;"></div>
                    </div>
                    <div class="ad-milestone-meta">
                        <span><?php echo $zs; ?> / <?php echo $tz; ?> zones</span>
                        <strong style="color:<?php echo $color; ?>;"><?php echo $rate; ?>%</strong>
                    </div>
                    <div class="ad-milestone-meta" style="margin-top:0.25rem;">
                        <span><?php echo number_format($rm['copies']); ?> copies</span>
                        <span><?php echo number_format($rm['souls']); ?> souls</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
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

    <!-- Team Progress Board -->
    <?php if (!empty($teamZones)): ?>
    <div class="ad-section">
        <div class="ad-section-head">
            <h2>Team Progress Board<?php echo !empty($filterRegion) ? ' — '.htmlspecialchars($filterRegion) : ''; ?></h2>
            <span style="font-size:0.75rem;color:var(--neutral-400);"><?php echo $expectedMonthsAdm; ?> months expected · top <?php echo count($teamZones); ?> zones</span>
        </div>
        <div class="ad-section-body">
            <div class="ad-team-grid">
                <?php foreach ($teamZones as $ti => $tz):
                    $rate  = $expectedMonthsAdm > 0 ? min(100, round(((int)$tz['months_submitted'] / $expectedMonthsAdm) * 100)) : 0;
                    $color = $rate >= 80 ? '#10b981' : ($rate >= 50 ? '#f59e0b' : '#ef4444');
                    $cardClass = $ti === 0 ? 'top1' : ($ti === 1 ? 'top2' : ($ti === 2 ? 'top3' : ((int)$tz['months_submitted'] === 0 ? 'zero' : '')));
                    $medal = $ti === 0 ? '🥇' : ($ti === 1 ? '🥈' : ($ti === 2 ? '🥉' : ''));
                ?>
                <div class="ad-team-card <?php echo $cardClass; ?>" title="<?php echo htmlspecialchars($tz['zone']); ?> · <?php echo htmlspecialchars($tz['region'] ?? ''); ?>">
                    <div class="ad-team-rate" style="color:<?php echo $color; ?>;"><?php echo $medal; ?><?php echo $rate; ?>%</div>
                    <div class="ad-team-zone"><?php echo htmlspecialchars($tz['zone']); ?></div>
                    <div class="ad-team-sub"><?php echo (int)$tz['months_submitted']; ?>/<?php echo $expectedMonthsAdm; ?> months &middot; <?php echo number_format($tz['copies']); ?> copies</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
    // Reminder modal
    function openReminder(userId, name) {
        document.getElementById('reminderTargetId').value = userId;
        document.getElementById('reminderModalDesc').textContent =
            userId > 0
                ? 'Send a reminder to ' + name + '.'
                : 'Broadcast a reminder to all ' + name + '.';
        document.getElementById('reminderMessage').value = '';
        document.getElementById('reminderModal').classList.add('open');
    }
    function closeReminder() {
        document.getElementById('reminderModal').classList.remove('open');
    }
    document.getElementById('reminderModal').addEventListener('click', function(e) {
        if (e.target === this) closeReminder();
    });

    // Session toasts
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_SESSION['success'])): ?>
        showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
        showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
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
