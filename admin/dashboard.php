<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/statistics.php';

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
    .admin-analytics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
    }

    .admin-analytics-grid .modern-stats-card {
        min-width: 0;
        height: 100%;
        align-items: flex-start;
    }

    .admin-analytics-grid .modern-stats-content {
        min-width: 0;
    }

    .admin-analytics-grid .modern-stats-label {
        line-height: 1.3;
        overflow-wrap: anywhere;
    }

    .admin-analytics-grid .modern-stats-value {
        font-size: clamp(1.5rem, 2vw, 2rem);
        line-height: 1.1;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .admin-analytics-grid .metric-note {
        font-size: 0.75rem;
        line-height: 1.35;
        color: #6b7280;
        margin-top: 0.375rem;
        overflow-wrap: anywhere;
    }
</style>

<div class="container mx-auto px-4 py-8 max-w-7xl">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Admin Dashboard</h1>
        <p class="text-gray-600">Overview of reports, zone compliance, and sponsorship statistics</p>
        <p class="text-sm font-medium text-primary-600 mt-2">
            Reporting Period: <?php echo htmlspecialchars(formatDashboardPeriodLabel($filterDateFrom, $filterDateTo)); ?>
        </p>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Filters</h2>
        <form method="GET" id="filterForm">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label for="filter_year" class="block text-sm font-medium text-gray-700 mb-1">Selected Year</label>
                    <select id="filter_year" name="filter_year" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Any Year</option>
                        <?php foreach ($availableYears as $year): ?>
                        <option value="<?php echo htmlspecialchars((string)$year); ?>" <?php echo (string)$filterYear === (string)$year ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)$year); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="filter_month" class="block text-sm font-medium text-gray-700 mb-1">Selected Month</label>
                    <select id="filter_month" name="filter_month" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">All Months</option>
                        <?php foreach ($availableMonths as $monthValue => $monthLabel): ?>
                        <option value="<?php echo htmlspecialchars($monthValue); ?>" <?php echo (string)(int)$filterMonth === (string)(int)$monthValue ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($monthLabel); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Start Date -->
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <!-- End Date -->
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <!-- Region -->
                <div>
                    <label for="region" class="block text-sm font-medium text-gray-700 mb-1">Region (Optional)</label>
                    <select id="region" name="region" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $region): ?>
                        <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Zone -->
                <div>
                    <label for="zone" class="block text-sm font-medium text-gray-700 mb-1">Zone (Optional)</label>
                    <select id="zone" name="zone" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">All Zones</option>
                        <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $filterZone === $zone ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($zone); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                Selecting a month and year automatically targets that report month. Use the date fields only if you need a custom report-month range.
            </p>
            <div class="mt-4 flex flex-wrap gap-3 items-center">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md transition-colors duration-200">
                    Apply Filters
                </button>
                <a href="dashboard.php" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-md transition-colors duration-200">
                    Clear Filters
                </a>
                <!-- PDF Export Button -->
                <a href="generate_pdf_report.php<?php
                    $pdfParams = [];
                    if (!empty($filterYear))     $pdfParams[] = 'filter_year=' . urlencode($filterYear);
                    if (!empty($filterMonth))    $pdfParams[] = 'filter_month=' . urlencode($filterMonth);
                    if (!empty($filterDateFrom)) $pdfParams[] = 'date_from=' . urlencode($filterDateFrom);
                    if (!empty($filterDateTo))   $pdfParams[] = 'date_to='   . urlencode($filterDateTo);
                    if (!empty($filterRegion))   $pdfParams[] = 'region='    . urlencode($filterRegion);
                    if (!empty($filterZone))     $pdfParams[] = 'zone='      . urlencode($filterZone);
                    echo !empty($pdfParams) ? '?' . implode('&', $pdfParams) : '';
                ?>"
                   class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md transition-colors duration-200">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Export PDF Report
                </a>
            </div>
        </form>
    </div>

    <!-- Key Metrics -->
    <div class="admin-analytics-grid mb-8">
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #2563eb;">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Total Reports Submitted</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['total_reports'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #1d4ed8;">
                    <i class="fas fa-book"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Zones with Reports</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['unique_zones'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #0f766e;">
                    <i class="fas fa-copy"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Total Copies Sponsored</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['total_copies'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #0891b2;">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Total Distributions</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['total_distribution'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #dc2626;">
                    <i class="fas fa-heart"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Total Souls Won</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['total_souls'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #7c3aed;">
                    <i class="fas fa-handshake"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Total New Partners</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['total_new_partners'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #d97706;">
                    <i class="fas fa-th-large"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Rhapsody Cells</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['total_rhapsody_cells'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #15803d;">
                    <i class="fas fa-church"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">New Churches</h3>
                    <p class="modern-stats-value"><?php echo number_format($reportStats['total_new_churches'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: #111827;">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="modern-stats-content">
                    <h3 class="modern-stats-label">Compliance Rate</h3>
                    <p class="modern-stats-value"><?php echo number_format($complianceData['compliance_rate'] ?? 0, 1); ?>%</p>
                    <p class="metric-note">
                        <?php echo number_format($complianceData['compliant_zones'] ?? 0); ?> of <?php echo number_format($complianceData['total_zones'] ?? 0); ?> zones submitted
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Reports Breakdown by Month -->
    <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Monthly Breakdown<?php
            if (!empty($filterDateFrom) || !empty($filterDateTo)) {
                echo ' (' . htmlspecialchars(formatDashboardPeriodLabel($filterDateFrom, $filterDateTo)) . ')';
            } else {
                echo ' (All Time)';
            }
        ?></h2>
        <?php if (!empty($monthlyBreakdown)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reports</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Copies Sponsored</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Souls Won</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($monthlyBreakdown as $month): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo $month['month_name']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($month['report_count']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($month['total_copies'] ?? 0); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($month['total_souls'] ?? 0); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-gray-500">No reports found for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Zone Compliance -->
    <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Zone Compliance</h2>
            <a href="zone_compliance.php" class="text-blue-600 hover:text-blue-800 text-sm">View Details</a>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="bg-green-50 rounded-lg p-4">
                <h3 class="text-sm font-medium text-green-800 mb-2">Compliant Zones</h3>
                <p class="text-2xl font-bold text-green-900"><?php echo $complianceData['compliant_zones'] ?? 0; ?></p>
                <p class="text-sm text-green-600">Zones with reports submitted</p>
            </div>
            <div class="bg-red-50 rounded-lg p-4">
                <h3 class="text-sm font-medium text-red-800 mb-2">Non-Compliant Zones</h3>
                <p class="text-2xl font-bold text-red-900"><?php echo ($complianceData['total_zones'] ?? 0) - ($complianceData['compliant_zones'] ?? 0); ?></p>
                <p class="text-sm text-red-600">Zones without reports</p>
            </div>
        </div>

        <?php if (!empty($zoneBreakdown)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Region</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reports</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Copies Sponsored</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($zoneBreakdown as $zone): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($zone['zone'] ?? 'Unknown'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($zone['region'] ?? 'Unknown'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($zone['report_count'] ?? 0); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($zone['total_copies'] ?? 0); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if (($zone['report_count'] ?? 0) > 0): ?>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                Compliant
                            </span>
                            <?php else: ?>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                Non-Compliant
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sponsorship Statistics -->
    <div class="bg-white rounded-lg shadow-sm border p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Sponsorship Statistics</h2>
        <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($sponsorshipStats['active_sponsors'] ?? 0); ?></p>
                <p class="text-sm text-gray-500">Active Sponsors</p>
            </div>
        </div>
    </div>
</div>

<script>
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
