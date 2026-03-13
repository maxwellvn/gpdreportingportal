<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
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
$stats = new Statistics($conn);

// Get filter parameters
$filters = [];
if (!empty($_GET['month'])) $filters['month'] = $_GET['month'];
if (!empty($_GET['region'])) $filters['region'] = $_GET['region'];
if (!empty($_GET['year'])) $filters['year'] = $_GET['year'];
if (!empty($_GET['zone'])) $filters['zone'] = $_GET['zone'];
if (!empty($_GET['date_range'])) $filters['date_range'] = $_GET['date_range'];
if (!empty($_GET['min_copies'])) $filters['min_copies'] = (int)$_GET['min_copies'];
if (!empty($_GET['max_copies'])) $filters['max_copies'] = (int)$_GET['max_copies'];
if (!empty($_GET['min_souls'])) $filters['min_souls'] = (int)$_GET['min_souls'];
if (!empty($_GET['compliance_status'])) $filters['compliance_status'] = $_GET['compliance_status'];
$filters['sort_by'] = $_GET['sort_by'] ?? 'total_copies';
$filters['sort_order'] = $_GET['sort_order'] ?? 'desc';

// Get comprehensive data
$reportStats = $stats->getReportStatistics($filters);
$complianceData = $stats->getZoneCompliance($filters);
$topZones = $stats->getTopZones(100, $filters); // Get more zones for export
$monthlyTrends = $stats->getMonthlyTrends(24, $filters); // Get 24 months for export

// Generate filename
$filename = 'admin_analytics_' . date('Y-m-d_H-i-s') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Write summary statistics
fputcsv($output, ['ADMIN ANALYTICS EXPORT - ' . date('Y-m-d H:i:s')]);
fputcsv($output, []);
fputcsv($output, ['SUMMARY STATISTICS']);
fputcsv($output, ['Metric', 'Value']);
fputcsv($output, ['Total Reports', number_format($reportStats['total_reports'] ?? 0)]);
fputcsv($output, ['Total Copies Sponsored', number_format($reportStats['total_copies'] ?? 0)]);
fputcsv($output, ['Total Souls Won', number_format($reportStats['total_souls'] ?? 0)]);
fputcsv($output, ['Total Distribution', number_format($reportStats['total_distribution'] ?? 0)]);
fputcsv($output, ['Unique Zones', number_format($reportStats['unique_zones'] ?? 0)]);
fputcsv($output, ['Unique Regions', number_format($reportStats['unique_regions'] ?? 0)]);
fputcsv($output, ['Unique Users', number_format($reportStats['unique_users'] ?? 0)]);
fputcsv($output, ['Average Copies per Report', number_format($reportStats['avg_copies_per_report'] ?? 0, 2)]);
fputcsv($output, ['Average Souls per Report', number_format($reportStats['avg_souls_per_report'] ?? 0, 2)]);
fputcsv($output, []);

// Write compliance data
fputcsv($output, ['ZONE COMPLIANCE']);
fputcsv($output, ['Metric', 'Value']);
fputcsv($output, ['Total Zones', number_format($complianceData['total_zones'] ?? 0)]);
fputcsv($output, ['Compliant Zones', number_format($complianceData['compliant_zones'] ?? 0)]);
fputcsv($output, ['Compliance Rate', number_format($complianceData['compliance_rate'] ?? 0, 2) . '%']);
fputcsv($output, []);

// Write active filters
fputcsv($output, ['ACTIVE FILTERS']);
fputcsv($output, ['Filter', 'Value']);
foreach ($filters as $key => $value) {
    if (!empty($value)) {
        $filterName = ucfirst(str_replace('_', ' ', $key));
        fputcsv($output, [$filterName, $value]);
    }
}
fputcsv($output, []);

// Write zone performance data
fputcsv($output, ['ZONE PERFORMANCE DATA']);
fputcsv($output, [
    'Zone', 
    'Region', 
    'Total Reports', 
    'Total Copies', 
    'Total Souls', 
    'Total Distribution', 
    'Average Copies per Report', 
    'Active Months', 
    'Last Submission'
]);

foreach ($topZones as $zone) {
    fputcsv($output, [
        $zone['zone'],
        $zone['region'],
        number_format($zone['report_count'] ?? 0),
        number_format($zone['total_copies'] ?? 0),
        number_format($zone['total_souls'] ?? 0),
        number_format($zone['total_distribution'] ?? 0),
        number_format($zone['avg_copies'] ?? 0, 2),
        number_format($zone['active_months'] ?? 0),
        $zone['last_submission'] ? date('Y-m-d', strtotime($zone['last_submission'])) : 'Never'
    ]);
}

fputcsv($output, []);

// Write monthly trends
fputcsv($output, ['MONTHLY TRENDS']);
fputcsv($output, [
    'Month', 
    'Report Count', 
    'Total Copies', 
    'Total Souls', 
    'Unique Zones', 
    'Average Copies per Report'
]);

foreach ($monthlyTrends as $trend) {
    fputcsv($output, [
        $trend['month_label'],
        number_format($trend['report_count'] ?? 0),
        number_format($trend['total_copies'] ?? 0),
        number_format($trend['total_souls'] ?? 0),
        number_format($trend['unique_zones'] ?? 0),
        number_format($trend['avg_copies'] ?? 0, 2)
    ]);
}

// Close output stream
fclose($output);
exit;
?>