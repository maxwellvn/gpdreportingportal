<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/statistics.php';
require_once '../vendor/autoload.php';

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

function normalizeExportDate($value, $boundary = 'start') {
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

function formatExportPeriodLabel($dateFrom, $dateTo) {
    if (!empty($dateFrom) && !empty($dateTo) && $dateFrom === $dateTo) {
        return date('F Y', strtotime($dateFrom));
    }

    if (!empty($dateFrom) || !empty($dateTo)) {
        $fromLabel = !empty($dateFrom) ? date('M j, Y', strtotime($dateFrom)) : 'Start';
        $toLabel = !empty($dateTo) ? date('M j, Y', strtotime($dateTo)) : 'Present';
        return $fromLabel . ' to ' . $toLabel;
    }

    return 'All Time';
}

function resolveExportDateRange($selectedYear, $selectedMonth, $dateFrom, $dateTo) {
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
$filterDateFrom = normalizeExportDate($filterDateFrom, 'start');
$filterDateTo = normalizeExportDate($filterDateTo, 'end');
[$filterDateFrom, $filterDateTo] = resolveExportDateRange($filterYear, $filterMonth, $filterDateFrom, $filterDateTo);

// Build filters array
$filters = [];
if (!empty($filterDateFrom)) $filters['date_from'] = $filterDateFrom;
if (!empty($filterDateTo))   $filters['date_to']   = $filterDateTo;
if (!empty($filterRegion))   $filters['region']    = $filterRegion;
if (!empty($filterZone))     $filters['zone']      = $filterZone;

// Get comprehensive statistics
$reportStats = $stats->getReportStatistics($filters);
$complianceData = $stats->getZoneCompliance($filters);
$periodLabel = formatExportPeriodLabel($filterDateFrom, $filterDateTo);

// Get monthly breakdown for the selected year
$monthlyBreakdownQuery = "SELECT
    DATE_FORMAT(r.report_month, '%Y-%m') as month_key,
    DATE_FORMAT(r.report_month, '%M %Y') as month_name,
    COUNT(*) as report_count,
    SUM(r.total_copies) as total_copies,
    SUM(r.total_distribution) as total_distribution,
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

// Get zone performance data
$zonePerformanceQuery = "SELECT
    u.zone,
    u.region,
    COUNT(r.id) as report_count,
    SUM(r.total_copies) as total_copies,
    SUM(r.total_distribution) as total_distribution,
    SUM(r.souls_won) as total_souls,
    COUNT(DISTINCT DATE_FORMAT(r.report_month, '%Y-%m')) as active_months,
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
    $zonePerformanceQuery .= " WHERE " . implode(" AND ", $whereConditions);
}

$zonePerformanceQuery .= " GROUP BY u.zone, u.region
                          ORDER BY report_count DESC, u.region, u.zone";

$stmt = $conn->prepare($zonePerformanceQuery);
$stmt->execute($zoneParams);
$zonePerformance = $stmt->fetchAll();

// All compliant zones (zones with at least one report in the period)
$compliantZonesList = array_filter($zonePerformance, function($z) {
    return ($z['report_count'] ?? 0) > 0;
});

// Get regional summary
$regionalQuery = "SELECT 
    u.region,
    COUNT(DISTINCT u.zone) as total_zones,
    COUNT(r.id) as total_reports,
    SUM(r.total_copies) as total_copies,
    SUM(r.souls_won) as total_souls,
    COUNT(DISTINCT r.user_id) as active_users
    FROM users u
    LEFT JOIN reports r ON u.id = r.user_id";

if (!empty($whereConditions)) {
    $regionalQuery .= " WHERE " . implode(" AND ", $whereConditions);
}

$regionalQuery .= " GROUP BY u.region ORDER BY total_reports DESC";

$stmt = $conn->prepare($regionalQuery);
$stmt->execute($zoneParams);
$regionalSummary = $stmt->fetchAll();

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Reporting Portal');
$pdf->SetAuthor('Admin Dashboard');
$pdf->SetTitle('Comprehensive Statistics Report');
$pdf->SetSubject('Ministry Reports Statistics');

// Set margins
$pdf->SetMargins(15, 27, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 25);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 20);

// Title
$filterText = '';
if (!empty($filterRegion)) $filterText .= " - Region: " . $filterRegion;
if (!empty($filterZone))   $filterText .= " - Zone: " . $filterZone;
$filterText .= " - " . $periodLabel;

$pdf->Cell(0, 15, 'Ministry Statistics Report' . $filterText, 0, 1, 'C');
$pdf->Ln(5);

// Key Metrics Section
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Key Performance Metrics', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$nonCompliantZonesCount = ($complianceData['total_zones'] ?? 0) - ($complianceData['compliant_zones'] ?? 0);
$keyMetrics = [
    ['Total Reports Submitted', number_format($reportStats['total_reports'] ?? 0)],
    ['Zones with Reports', number_format($reportStats['unique_zones'] ?? 0)],
    ['Total Copies Sponsored', number_format($reportStats['total_copies'] ?? 0)],
    ['Total Distributions', number_format($reportStats['total_distribution'] ?? 0)],
    ['Total Souls Won', number_format($reportStats['total_souls'] ?? 0)],
    ['Total New Partners', number_format($reportStats['total_new_partners'] ?? 0)],
    ['Rhapsody Cells', number_format($reportStats['total_rhapsody_cells'] ?? 0)],
    ['New Churches', number_format($reportStats['total_new_churches'] ?? 0)],
    ['Active Sponsors', number_format($reportStats['active_users'] ?? 0)],
    ['Zone Compliance Rate', number_format($complianceData['compliance_rate'] ?? 0, 1) . '%'],
    ['Compliant Zones (Submitted)', number_format($complianceData['compliant_zones'] ?? 0) . ' of ' . number_format($complianceData['total_zones'] ?? 0)],
    ['Non-Compliant Zones (No Submission)', number_format($nonCompliantZonesCount)],
];

foreach ($keyMetrics as $metric) {
    $pdf->Cell(90, 8, $metric[0] . ':', 1, 0, 'L');
    $pdf->Cell(90, 8, $metric[1], 1, 1, 'R');
}

$pdf->Ln(10);

// Monthly Breakdown Section
if (!empty($monthlyBreakdown)) {
    $pdf->SetFont('helvetica', 'B', 14);
    if (!empty($filterDateFrom) || !empty($filterDateTo)) {
        $periodLabel = formatExportPeriodLabel($filterDateFrom, $filterDateTo);
    } else {
        $periodLabel = 'All Time';
    }
    $pdf->Cell(0, 10, 'Monthly Report Frequency (' . $periodLabel . ')', 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    
    // Table header
    $pdf->Cell(38, 8, 'Month', 1, 0, 'C');
    $pdf->Cell(22, 8, 'Reports', 1, 0, 'C');
    $pdf->Cell(47, 8, 'Total Copies Sponsored', 1, 0, 'C');
    $pdf->Cell(38, 8, 'Distribution', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Souls Won', 1, 1, 'C');

    $pdf->SetFont('helvetica', '', 9);

    foreach ($monthlyBreakdown as $month) {
        $pdf->Cell(38, 7, $month['month_name'], 1, 0, 'L');
        $pdf->Cell(22, 7, number_format($month['report_count']), 1, 0, 'R');
        $pdf->Cell(47, 7, number_format($month['total_copies'] ?? 0), 1, 0, 'R');
        $pdf->Cell(38, 7, number_format($month['total_distribution'] ?? 0), 1, 0, 'R');
        $pdf->Cell(35, 7, number_format($month['total_souls'] ?? 0), 1, 1, 'R');
    }
    
    $pdf->Ln(10);
}

// Regional Summary Section
if (!empty($regionalSummary)) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Regional Performance Summary', 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    
    // Table header
    $pdf->Cell(33, 8, 'Region', 1, 0, 'C');
    $pdf->Cell(25, 8, 'Zones', 1, 0, 'C');
    $pdf->Cell(50, 8, 'Total Copies Sponsored', 1, 0, 'C');
    $pdf->Cell(32, 8, 'Souls', 1, 0, 'C');
    $pdf->Cell(40, 8, 'Active RZMs', 1, 1, 'C');

    $pdf->SetFont('helvetica', '', 9);

    foreach ($regionalSummary as $region) {
        $pdf->Cell(33, 7, $region['region'] ?? 'Unknown', 1, 0, 'L');
        $pdf->Cell(25, 7, number_format($region['total_zones'] ?? 0), 1, 0, 'R');
        $pdf->Cell(50, 7, number_format($region['total_copies'] ?? 0), 1, 0, 'R');
        $pdf->Cell(32, 7, number_format($region['total_souls'] ?? 0), 1, 0, 'R');
        $pdf->Cell(40, 7, number_format($region['active_users'] ?? 0), 1, 1, 'R');
    }
    
    $pdf->Ln(10);
}

// Compliant Zones Section
if (!empty($compliantZonesList)) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Compliant Zones (' . count($compliantZonesList) . ')', 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 9);

    // Table header
    $pdf->Cell(38, 8, 'Zone', 1, 0, 'C');
    $pdf->Cell(28, 8, 'Region', 1, 0, 'C');
    $pdf->Cell(45, 8, 'Total Copies Sponsored', 1, 0, 'C');
    $pdf->Cell(33, 8, 'Distribution', 1, 0, 'C');
    $pdf->Cell(22, 8, 'Souls', 1, 0, 'C');
    $pdf->Cell(14, 8, 'Months', 1, 1, 'C');

    $pdf->SetFont('helvetica', '', 8);

    foreach ($compliantZonesList as $zone) {
        $pdf->Cell(38, 6, substr($zone['zone'] ?? 'Unknown', 0, 22), 1, 0, 'L');
        $pdf->Cell(28, 6, substr($zone['region'] ?? 'Unknown', 0, 16), 1, 0, 'L');
        $pdf->Cell(45, 6, number_format($zone['total_copies'] ?? 0), 1, 0, 'R');
        $pdf->Cell(33, 6, number_format($zone['total_distribution'] ?? 0), 1, 0, 'R');
        $pdf->Cell(22, 6, number_format($zone['total_souls'] ?? 0), 1, 0, 'R');
        $pdf->Cell(14, 6, number_format($zone['active_months'] ?? 0), 1, 1, 'R');
    }
}

// Non-Compliant Zones Section (zones with no submissions)
$pdf->Ln(10);

// Get non-compliant zones - those with no reports in the period
$nonCompliantQuery = "SELECT DISTINCT u.zone, u.region
    FROM users u
    WHERE u.zone IS NOT NULL
    AND u.role = 'user'";

$ncParams = [];

if (!empty($filterRegion)) {
    $nonCompliantQuery .= " AND u.region = :region";
    $ncParams[':region'] = $filterRegion;
}

$nonCompliantQuery .= " AND u.zone NOT IN (
    SELECT DISTINCT u2.zone
    FROM users u2
    JOIN reports r ON r.user_id = u2.id
    WHERE u2.zone IS NOT NULL";

if (!empty($filterDateFrom)) {
    $nonCompliantQuery .= " AND DATE(r.report_month) >= :nc_date_from";
    $ncParams[':nc_date_from'] = $filterDateFrom;
}
if (!empty($filterDateTo)) {
    $nonCompliantQuery .= " AND DATE(r.report_month) <= :nc_date_to";
    $ncParams[':nc_date_to'] = $filterDateTo;
}

$nonCompliantQuery .= ")
ORDER BY u.region, u.zone";

$stmt = $conn->prepare($nonCompliantQuery);
$stmt->execute($ncParams);
$nonCompliantZones = $stmt->fetchAll();

if (!empty($nonCompliantZones)) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(220, 38, 38);
    $pdf->Cell(0, 10, 'Non-Compliant Zones (No Submissions)', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', 'B', 9);

    $pdf->Cell(80, 8, 'Zone', 1, 0, 'C');
    $pdf->Cell(100, 8, 'Region', 1, 1, 'C');

    $pdf->SetFont('helvetica', '', 9);

    foreach ($nonCompliantZones as $zone) {
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(80, 6, substr($zone['zone'] ?? 'Unknown', 0, 40), 1, 0, 'L');
        $pdf->Cell(100, 6, substr($zone['region'] ?? 'Unknown', 0, 50), 1, 1, 'L');
    }

    $pdf->SetTextColor(0, 0, 0);
}

// Add new page for insights
$pdf->AddPage();

// Insights Section
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 15, 'Key Insights & Analysis', 0, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'Performance Insights:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$insights = [];

// Calculate insights
$totalReports = $reportStats['total_reports'] ?? 0;
$totalCopies = $reportStats['total_copies'] ?? 0;
$totalDistribution = $reportStats['total_distribution'] ?? 0;
$totalZones = $complianceData['total_zones'] ?? 0;
$compliantZones = $complianceData['compliant_zones'] ?? 0;
$complianceRate = $complianceData['compliance_rate'] ?? 0;
$nonCompliantZonesInsight = $totalZones - $compliantZones;


if ($totalCopies > 0) {
    $insights[] = "• Total copies sponsored: " . number_format($totalCopies);
}

if ($totalDistribution > 0) {
    $insights[] = "• Total distribution: " . number_format($totalDistribution) . " copies distributed";
}

if ($totalZones > 0) {
    $insights[] = "• Compliant zones (submitted): " . number_format($compliantZones) . " out of " . number_format($totalZones) . " (" . number_format($complianceRate, 1) . "%)";
    $insights[] = "• Non-compliant zones (no submission): " . number_format($nonCompliantZonesInsight);
}

if (!empty($monthlyBreakdown)) {
    $monthsWithReports = count($monthlyBreakdown);
    if (!empty($filterDateFrom) || !empty($filterDateTo)) {
        $periodText  = formatExportPeriodLabel($filterDateFrom, $filterDateTo);
    } else {
        $periodText = 'all time';
    }
    $insights[] = "• Active reporting months in selected period (" . $periodText . "): " . $monthsWithReports . " months";

    // Find peak month
    $peakMonth = null;
    $maxReports = 0;
    foreach ($monthlyBreakdown as $month) {
        if ($month['report_count'] > $maxReports) {
            $maxReports = $month['report_count'];
            $peakMonth = $month['month_name'];
        }
    }
    if ($peakMonth) {
        $insights[] = "• Peak reporting month: " . $peakMonth . " with " . number_format($maxReports) . " reports";
    }
}

if (!empty($regionalSummary)) {
    // Find top performing region
    $topRegion = $regionalSummary[0];
    $insights[] = "• Top performing region: " . ($topRegion['region'] ?? 'Unknown') . " with " . number_format($topRegion['total_reports'] ?? 0) . " reports";
}

foreach ($insights as $insight) {
    $pdf->Cell(0, 8, $insight, 0, 1, 'L');
}

$pdf->Ln(10);

// Recommendations Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'Recommendations:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$recommendations = [];

if ($complianceRate < 80) {
    $recommendations[] = "• Focus on improving zone compliance - currently at " . number_format($complianceRate, 1) . "%";
}

if ($nonCompliantZonesInsight > 0) {
    $recommendations[] = "• Engage with " . number_format($nonCompliantZonesInsight) . " non-compliant zones that have not submitted any reports";
}


if (empty($recommendations)) {
    $recommendations[] = "• Continue current excellent performance and maintain reporting standards";
    $recommendations[] = "• Consider expanding outreach programs to increase impact";
}

foreach ($recommendations as $recommendation) {
    $pdf->Cell(0, 8, $recommendation, 0, 1, 'L');
}

// Footer with generation info
$pdf->Ln(15);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 5, 'Report generated on: ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');
$pdf->Cell(0, 5, 'Generated by: ' . ($_SESSION['user_data']['name'] ?? 'Admin'), 0, 1, 'C');

// Output PDF
$filename = 'Ministry_Statistics_Report_' . date('Y-m-d_H-i-s') . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>
