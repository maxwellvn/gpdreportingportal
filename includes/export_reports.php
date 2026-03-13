<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Ensure user is logged in
Auth::requireLogin();
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $currentUser;
$isAdmin = Auth::isAdmin();
$isAdminManager = Auth::isAdminManager();

// Get filter parameters
$filterYear = isset($_GET['year']) ? $_GET['year'] : '';
$filterMonth = isset($_GET['month']) ? $_GET['month'] : '';
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';
$filterZone = isset($_GET['zone']) ? $_GET['zone'] : '';

// Database connection
$db = new Database();
$conn = $db->getConnection();

// Build the base WHERE clause
$whereClause = "WHERE 1=1";
$params = [];
if (!$isAdmin && !$isAdminManager) {
    $whereClause .= " AND r.user_id = :user_id";
    $params[':user_id'] = $_SESSION['user_id'];
}
if ($isAdminManager) {
    $whereClause .= " AND u.region = :scoped_region";
    $params[':scoped_region'] = $currentUser['region'] ?? '';
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
    if ($isAdminManager) {
        $filterRegion = $currentUser['region'] ?? '';
    }
    $whereClause .= " AND u.region = :region";
    $params[':region'] = $filterRegion;
}

if (!empty($filterZone)) {
    $whereClause .= " AND u.zone = :zone";
    $params[':zone'] = $filterZone;
}

// Build filename with filter information
$filename = 'reports_export';
if (!empty($filterYear)) {
    $filename .= '_' . $filterYear;
}
if (!empty($filterMonth)) {
    $filename .= '_' . date('M', strtotime($filterMonth . '-01'));
}
if (!empty($filterRegion)) {
    $filename .= '_' . preg_replace('/[^a-zA-Z0-9]/', '', $filterRegion);
}
if (!empty($filterZone)) {
    $filename .= '_' . preg_replace('/[^a-zA-Z0-9]/', '', $filterZone);
}
$filename .= '_' . date('Y-m-d_His') . '.csv';

// Get sort order (default to DESC if not provided)
$sortOrder = isset($_GET['sort_order']) ? strtoupper($_GET['sort_order']) : 'DESC';
$sortOrder = ($sortOrder === 'ASC') ? 'ASC' : 'DESC'; // Validate sort order

// Get reports with user information
$reportsQuery = "SELECT r.*, u.name, u.region, u.zone
                 FROM reports r
                 LEFT JOIN users u ON r.user_id = u.id
                 $whereClause
                 ORDER BY r.report_month $sortOrder, r.created_at $sortOrder";
$stmt = $conn->prepare($reportsQuery);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create output handle
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel encoding
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV header
fputcsv($output, [
    // Basic Information
    'Report ID',
    'Name',
    'Region',
    'Zone',
    'Report Month',
    'Submitted Date',

    // Total Copies Report
    'Total Copies Sponsored',
    'Total Copies Distributed',
    'Distribution Rate (%)',

    // Strategic Income Alerts
    'Wonder Alerts',
    'Kids Alerts',
    'Language Redemption Missions',

    // Sub Campaigns
    'Penetrating with Truth',
    'Penetrating with Languages',
    'Youth Aglow',
    'Every Minister Campaign',
    'Say Yes to Kids',
    'No One Left Behind',
    'Teevolution',
    'Subscriptions',

    // Program Report
    'Partners Prayer Programs',
    'Partners Programs',

    // Reach and Impact Report
    'Souls Won',
    'Rhapsody Outreaches',
    'Rhapsody Cells',
    'New Churches',
    'New Partners',
    'Lingual Cells',
    'Language Churches',
    'Languages Sponsored',
    'Distribution Centers',
    'External Ministers',

    // Say Yes to Kids Impact
    'SYK Outreaches',
    'SYK Elementary Schools',
    'SYK Orphanages',
    'SYK Children Reached',
    'SYK Souls Won',
    'SYK Souls Retained',
    'SYK RORK Clubs',

    // Attachments
    'Has Testimonies',
    'Has Innovations'
]);

// Write data rows
foreach ($reports as $report) {
    // Calculate distribution rate
    $distributionRate = $report['total_copies'] > 0
        ? round(($report['total_distribution'] / $report['total_copies']) * 100, 1)
        : 0;

    fputcsv($output, [
        // Basic Information
        $report['id'],
        $report['name'],
        $report['region'],
        $report['zone'],
        date('F Y', strtotime($report['report_month'])),
        date('M j, Y', strtotime($report['created_at'])),

        // Total Copies Report
        $report['total_copies'],
        $report['total_distribution'],
        $distributionRate,

        // Strategic Income Alerts
        $report['wonder_alerts'],
        $report['kids_alerts'],
        $report['language_missions'],

        // Sub Campaigns
        $report['penetrating_truth'],
        $report['penetrating_languages'],
        $report['youth_aglow'],
        $report['minister_campaign'],
        $report['say_yes_kids'],
        $report['no_one_left'],
        $report['teevolution'],
        $report['subscriptions'],

        // Program Report
        $report['prayer_programs'],
        $report['partner_programs'],

        // Reach and Impact Report
        $report['souls_won'],
        $report['rhapsody_outreaches'],
        $report['rhapsody_cells'],
        $report['new_churches'],
        $report['new_partners'],
        $report['lingual_cells'],
        $report['language_churches'],
        $report['languages_sponsored'],
        $report['distribution_centers'],
        $report['external_ministers'],

        // Say Yes to Kids Impact
        $report['syk_outreaches'],
        $report['syk_elementary_schools'],
        $report['syk_orphanages'],
        $report['syk_children_reached'],
        $report['syk_souls_won'],
        $report['syk_souls_retained'],
        $report['syk_rork_clubs'],

        // Attachments
        $report['testimonies_file'] ? 'Yes' : 'No',
        $report['innovations_file'] ? 'Yes' : 'No'
    ]);
}

// Close the output
fclose($output);
exit;
