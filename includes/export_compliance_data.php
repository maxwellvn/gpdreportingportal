<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

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

// Get filter parameters
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';
$filterMonth = isset($_GET['month']) ? $_GET['month'] : '';
$filterYear = isset($_GET['year']) ? $_GET['year'] : '';
$filterComplianceStatus = isset($_GET['compliance_status']) ? $_GET['compliance_status'] : '';

// Build filter conditions for the query
$complianceParams = [];
$zoneComplianceQuery = "SELECT 
    u.zone,
    u.region,
    COUNT(DISTINCT r.id) as total_reports,
    COUNT(DISTINCT DATE_FORMAT(r.report_month, '%Y-%m')) as months_active,
    SUM(r.total_copies) as total_copies,
    SUM(r.souls_won) as total_souls,
    MAX(r.created_at) as last_submission,
    MIN(r.created_at) as first_submission,
    AVG(r.total_copies) as avg_copies_per_report
    FROM users u
    LEFT JOIN reports r ON u.id = r.user_id AND r.id IS NOT NULL" . 
    (empty($filterMonth) ? "" : " AND DATE_FORMAT(r.report_month, '%Y-%m') = :month") .
    (empty($filterYear) ? "" : " AND YEAR(r.report_month) = :year") . "
    WHERE u.zone IS NOT NULL
    " . (empty($filterRegion) ? "" : " AND u.region = :region_filter") . "
    GROUP BY u.zone, u.region
    ORDER BY u.region, total_reports DESC, u.zone";

if (!empty($filterMonth)) {
    $complianceParams[':month'] = $filterMonth;
}
if (!empty($filterYear)) {
    $complianceParams[':year'] = $filterYear;
}
if (!empty($filterRegion)) {
    $complianceParams[':region_filter'] = $filterRegion;
}

$stmt = $conn->prepare($zoneComplianceQuery);
$stmt->execute($complianceParams);
$allZoneCompliance = $stmt->fetchAll();

// Apply compliance status filter if specified
$zoneCompliance = [];
if (!empty($filterComplianceStatus)) {
    foreach ($allZoneCompliance as $zone) {
        $isCompliant = ($zone['total_reports'] ?? 0) > 0;
        
        if ($filterComplianceStatus === 'compliant' && $isCompliant) {
            $zoneCompliance[] = $zone;
        } elseif ($filterComplianceStatus === 'non_compliant' && !$isCompliant) {
            $zoneCompliance[] = $zone;
        }
    }
} else {
    $zoneCompliance = $allZoneCompliance;
}

// Generate filename
$filename = 'zone_compliance_' . date('Y-m-d_H-i-s');
if (!empty($filterComplianceStatus)) {
    $filename .= '_' . $filterComplianceStatus;
}
if (!empty($filterRegion)) {
    $filename .= '_' . str_replace(' ', '_', $filterRegion);
}
$filename .= '.csv';

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Write header information
fputcsv($output, ['ZONE COMPLIANCE EXPORT - ' . date('Y-m-d H:i:s')]);
fputcsv($output, []);

// Write filter information
fputcsv($output, ['APPLIED FILTERS']);
fputcsv($output, ['Filter', 'Value']);
if (!empty($filterComplianceStatus)) {
    fputcsv($output, ['Compliance Status', ucfirst(str_replace('_', ' ', $filterComplianceStatus))]);
}
if (!empty($filterRegion)) {
    fputcsv($output, ['Region', $filterRegion]);
}
if (!empty($filterYear)) {
    fputcsv($output, ['Year', $filterYear]);
}
if (!empty($filterMonth)) {
    fputcsv($output, ['Month', date('F Y', strtotime($filterMonth . '-01'))]);
}
if (empty($filterComplianceStatus) && empty($filterRegion) && empty($filterYear) && empty($filterMonth)) {
    fputcsv($output, ['No filters applied', 'All zones included']);
}
fputcsv($output, []);

// Write summary statistics
$totalZones = count($zoneCompliance);
$compliantZones = 0;
$totalReports = 0;
$totalCopies = 0;
$totalSouls = 0;

foreach ($zoneCompliance as $zone) {
    if (($zone['total_reports'] ?? 0) > 0) {
        $compliantZones++;
    }
    $totalReports += ($zone['total_reports'] ?? 0);
    $totalCopies += ($zone['total_copies'] ?? 0);
    $totalSouls += ($zone['total_souls'] ?? 0);
}

$complianceRate = $totalZones > 0 ? ($compliantZones / $totalZones) * 100 : 0;

fputcsv($output, ['SUMMARY STATISTICS']);
fputcsv($output, ['Metric', 'Value']);
fputcsv($output, ['Total Zones (Filtered)', number_format($totalZones)]);
fputcsv($output, ['Compliant Zones', number_format($compliantZones)]);
fputcsv($output, ['Non-Compliant Zones', number_format($totalZones - $compliantZones)]);
fputcsv($output, ['Compliance Rate', number_format($complianceRate, 2) . '%']);
fputcsv($output, ['Total Reports', number_format($totalReports)]);
fputcsv($output, ['Total Copies', number_format($totalCopies)]);
fputcsv($output, ['Total Souls Won', number_format($totalSouls)]);
fputcsv($output, []);

// Write detailed zone data
fputcsv($output, ['DETAILED ZONE COMPLIANCE DATA']);
fputcsv($output, [
    'Zone',
    'Region', 
    'Compliance Status',
    'Total Reports',
    'Total Copies',
    'Total Souls',
    'Average Copies per Report',
    'Active Months',
    'First Submission',
    'Last Submission'
]);

foreach ($zoneCompliance as $zone) {
    $isCompliant = ($zone['total_reports'] ?? 0) > 0;
    $avgCopies = ($zone['total_reports'] ?? 0) > 0 ? ($zone['avg_copies_per_report'] ?? 0) : 0;
    
    fputcsv($output, [
        $zone['zone'],
        $zone['region'],
        $isCompliant ? 'Compliant' : 'Non-Compliant',
        number_format($zone['total_reports'] ?? 0),
        number_format($zone['total_copies'] ?? 0),
        number_format($zone['total_souls'] ?? 0),
        number_format($avgCopies, 2),
        number_format($zone['months_active'] ?? 0),
        $zone['first_submission'] ? date('Y-m-d', strtotime($zone['first_submission'])) : 'Never',
        $zone['last_submission'] ? date('Y-m-d', strtotime($zone['last_submission'])) : 'Never'
    ]);
}

// Close output stream
fclose($output);
exit;
?>