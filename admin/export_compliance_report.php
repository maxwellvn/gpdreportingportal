<?php
// Prevent any output before PDF generation
ob_start();

// Ensure no errors are displayed in output
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!isset($_SESSION['user_data']['role']) || $_SESSION['user_data']['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Get filter parameters
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';
$filterMonth = isset($_GET['month']) ? $_GET['month'] : '';
$filterYear = isset($_GET['year']) ? $_GET['year'] : '';
$filterComplianceStatus = isset($_GET['compliance_status']) ? $_GET['compliance_status'] : '';

// Load zones data
$zonesData = json_decode(file_get_contents('../config/zones.json'), true);

// Build filter conditions
$whereClause = "WHERE 1=1";
$params = [];

if (!empty($filterMonth)) {
    $whereClause .= " AND DATE_FORMAT(r.report_month, '%Y-%m') = :month";
    $params[':month'] = $filterMonth;
}

if (!empty($filterRegion)) {
    $whereClause .= " AND u.region = :region";
    $params[':region'] = $filterRegion;
}

if (!empty($filterYear)) {
    $whereClause .= " AND YEAR(r.report_month) = :year";
    $params[':year'] = $filterYear;
}

// Database connection
$db = new Database();
$conn = $db->getConnection();

// Get detailed zone compliance data
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

$complianceParams = [];
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

// Apply compliance status filter
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

// Calculate statistics
$totalZonesAll = count($allZoneCompliance);
$zonesWithReportsAll = 0;
$totalReportsAll = 0;
$totalCopiesAll = 0;

foreach ($allZoneCompliance as $zone) {
    if (($zone['total_reports'] ?? 0) > 0) {
        $zonesWithReportsAll++;
        $totalReportsAll += ($zone['total_reports'] ?? 0);
        $totalCopiesAll += ($zone['total_copies'] ?? 0);
    }
}

$overallCompliance = $totalZonesAll > 0 ? ($zonesWithReportsAll / $totalZonesAll) * 100 : 0;

$filteredZones = count($zoneCompliance);
$filteredCompliantZones = 0;
$filteredTotalReports = 0;
$filteredTotalCopies = 0;

foreach ($zoneCompliance as $zone) {
    if (($zone['total_reports'] ?? 0) > 0) {
        $filteredCompliantZones++;
    }
    $filteredTotalReports += ($zone['total_reports'] ?? 0);
    $filteredTotalCopies += ($zone['total_copies'] ?? 0);
}

// Create PDF
class ComplianceReportPDF extends TCPDF {
    // Page header
    public function Header() {
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(34, 197, 94); // Green color
        $this->Cell(0, 15, 'Zone Performance Report', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(10);

        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(107, 114, 128);
        $this->Cell(0, 5, 'Generated on: ' . date('F j, Y \a\t g:i A'), 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(15);
    }

    // Page footer
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(107, 114, 128);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// Create new PDF document
$pdf = new ComplianceReportPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Reporting Portal');
$pdf->SetAuthor('Zone Performance Tracker');
$pdf->SetTitle('Zone Compliance Report');
$pdf->SetSubject('Zone Participation Analysis');
$pdf->SetKeywords('Compliance, Zones, Performance, Participation');

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Add a page
$pdf->AddPage();

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(31, 41, 55);
$pdf->Cell(0, 10, 'Zone Performance Report', 0, 1, 'L');
$pdf->Ln(5);

// Executive Summary
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(34, 197, 94);
$pdf->Cell(0, 10, 'Executive Summary', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(31, 41, 55);

// Summary stats table
$html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr style="background-color: #f0fdf4;">
        <th style="font-weight: bold; text-align: center;">Metric</th>
        <th style="font-weight: bold; text-align: center;">Total</th>
        <th style="font-weight: bold; text-align: center;">Active</th>
        <th style="font-weight: bold; text-align: center;">Participation Rate</th>
    </tr>
    <tr>
        <td style="text-align: center;"><strong>Zones</strong></td>
        <td style="text-align: center; color: #1f2937;">' . number_format($totalZonesAll) . '</td>
        <td style="text-align: center; color: #059669;">' . number_format($zonesWithReportsAll) . '</td>
        <td style="text-align: center; color: #7c3aed;">' . number_format($overallCompliance, 1) . '%</td>
    </tr>
    <tr>
        <td style="text-align: center;"><strong>Activity</strong></td>
        <td style="text-align: center; color: #1f2937;">' . number_format($totalReportsAll) . ' reports</td>
        <td style="text-align: center; color: #059669;">' . number_format($filteredTotalCopies) . ' materials</td>
        <td style="text-align: center; color: #7c3aed;">' . number_format($filteredTotalReports) . ' reports</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, false, false, '');
$pdf->Ln(10);

// Applied Filters
$activeFilters = array_filter([
    'compliance_status' => $filterComplianceStatus,
    'region' => $filterRegion,
    'year' => $filterYear,
    'month' => $filterMonth
]);

if (!empty($activeFilters)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, '🔍 Applied Filters', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 10);
    $filterText = '';
    if (!empty($filterComplianceStatus)) $filterText .= '• Status: ' . ucwords(str_replace('_', ' ', $filterComplianceStatus)) . "\n";
    if (!empty($filterRegion)) $filterText .= '• Region: ' . htmlspecialchars($filterRegion) . "\n";
    if (!empty($filterYear)) $filterText .= '• Year: ' . htmlspecialchars($filterYear) . "\n";
    if (!empty($filterMonth)) $filterText .= '• Month: ' . htmlspecialchars($filterMonth) . "\n";

    $pdf->MultiCell(0, 6, $filterText, 0, 'L', false);
    $pdf->Ln(5);
}

// Detailed Zone Analysis
if (!empty($zoneCompliance)) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(34, 197, 94);
    $pdf->Cell(0, 10, 'Detailed Zone Analysis', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(31, 41, 55);

    $html = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">
        <tr style="background-color: #f0fdf4;">
            <th style="font-weight: bold; text-align: center; width: 20%;">Zone Name</th>
            <th style="font-weight: bold; text-align: center; width: 15%;">Region</th>
            <th style="font-weight: bold; text-align: center; width: 10%;">Status</th>
            <th style="font-weight: bold; text-align: center; width: 12%;">Reports</th>
            <th style="font-weight: bold; text-align: center; width: 15%;">Materials</th>
            <th style="font-weight: bold; text-align: center; width: 12%;">Avg per Report</th>
            <th style="font-weight: bold; text-align: center; width: 16%;">Last Active</th>
        </tr>';

    foreach ($zoneCompliance as $zone) {
        $isCompliant = ($zone['total_reports'] ?? 0) > 0;
        $statusColor = $isCompliant ? '#059669' : '#dc2626';
        $statusText = $isCompliant ? 'Active' : 'Inactive';
        $avgCopies = ($zone['total_reports'] ?? 0) > 0 ? ($zone['avg_copies_per_report'] ?? 0) : 0;

        $html .= '<tr>
            <td style="font-weight: bold;">' . htmlspecialchars($zone['zone']) . '</td>
            <td>' . htmlspecialchars($zone['region']) . '</td>
            <td style="text-align: center; color: ' . $statusColor . '; font-weight: bold;">' . $statusText . '</td>
            <td style="text-align: center;">' . number_format($zone['total_reports'] ?? 0) . '</td>
            <td style="text-align: center;">' . number_format($zone['total_copies'] ?? 0) . '</td>
            <td style="text-align: center;">' . ($zone['total_reports'] > 0 ? number_format($avgCopies ?? 0, 0) : '-') . '</td>
            <td style="text-align: center;">' . ($zone['last_submission'] ? date('M j, Y', strtotime($zone['last_submission'])) : 'Never') . '</td>
        </tr>';
    }
    $html .= '</table>';

    $pdf->writeHTML($html, true, false, false, false, '');
    $pdf->Ln(8);
}

// Recommendations
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Recommendations', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$recommendations = [];

if ($overallCompliance < 60) {
    $recommendations[] = '• CRITICAL: Low participation rate. Immediate outreach needed to inactive zones.';
    $recommendations[] = '• Consider zone training sessions or simplified reporting processes.';
} elseif ($overallCompliance < 80) {
    $recommendations[] = '• Good participation but room for improvement. Focus on consistent engagement.';
} else {
    $recommendations[] = '• Excellent participation rate! Maintain current successful strategies.';
}

$inactiveCount = $totalZonesAll - $zonesWithReportsAll;
if ($inactiveCount > 0) {
    $recommendations[] = '• ' . number_format($inactiveCount) . ' zones need attention. Schedule follow-up meetings.';
}

$recommendations[] = '• Regular performance reviews help maintain high participation.';
    $recommendations[] = '• Share success stories from top-performing zones to motivate others.';

foreach ($recommendations as $rec) {
    $pdf->Cell(0, 6, $rec, 0, 1, 'L');
}

// Clean any buffered output before PDF generation
ob_end_clean();

// Output the PDF
$filename = 'Zone_Compliance_Report_' . date('Y-m-d_H-i-s') . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>
