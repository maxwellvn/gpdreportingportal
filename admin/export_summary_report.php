<?php
// Prevent any output before PDF generation
ob_start();

// Ensure no errors are displayed in output
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/statistics.php';
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
$filterDateRange = isset($_GET['date_range']) ? $_GET['date_range'] : '';
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';
$filterYear = isset($_GET['year']) ? $_GET['year'] : '';
$filterZone = isset($_GET['zone']) ? $_GET['zone'] : '';
$filterComplianceStatus = isset($_GET['compliance_status']) ? $_GET['compliance_status'] : '';

// Build filters array
$filters = [];
if (!empty($filterDateRange)) $filters['date_range'] = $filterDateRange;
if (!empty($filterRegion)) $filters['region'] = $filterRegion;
if (!empty($filterYear)) $filters['year'] = $filterYear;
if (!empty($filterZone)) $filters['zone'] = $filterZone;
if (!empty($filterComplianceStatus)) $filters['compliance_status'] = $filterComplianceStatus;

// Database connection
$db = new Database();
$conn = $db->getConnection();
$stats = new Statistics($conn);

// Get data
$reportStats = $stats->getReportStatistics($filters);
$complianceData = $stats->getZoneCompliance($filters);
$submissionDistribution = $stats->getSubmissionDistribution($filters);
$monthlyTrends = $stats->getMonthlyTrends(12, $filters);
$topZones = $stats->getTopZones(10, $filters);

// Get total zones count from zones configuration
$zonesData = json_decode(file_get_contents('../config/zones.json'), true);
$totalZones = 0;
foreach ($zonesData as $region => $zones) {
    $totalZones += count($zones);
}

// Create PDF
class ActivityReportPDF extends TCPDF {
    // Page header
    public function Header() {
        // Logo or title
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(79, 70, 229); // Indigo color
        $this->Cell(0, 15, 'Activity Overview Report', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(10);

        // Report generation info
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(107, 114, 128); // Gray color
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
$pdf = new ActivityReportPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Reporting Portal');
$pdf->SetAuthor('Admin Dashboard');
$pdf->SetTitle('Activity Overview Report');
$pdf->SetSubject('Zone Performance Summary');
$pdf->SetKeywords('Report, Activity, Zones, Performance');

// Set default header data
$pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Add a page
$pdf->AddPage();

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(31, 41, 55); // Dark gray
$pdf->Cell(0, 10, 'Activity Overview Report', 0, 1, 'L');
$pdf->Ln(5);

// Executive Summary
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(79, 70, 229); // Indigo
$pdf->Cell(0, 10, 'Executive Summary', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(31, 41, 55);

// Key metrics table
$html = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">
    <tr style="background-color: #f3f4f6;">
        <th style="font-weight: bold; text-align: center;">Metric</th>
        <th style="font-weight: bold; text-align: center;">Value</th>
        <th style="font-weight: bold; text-align: center;">Description</th>
    </tr>
    <tr>
        <td style="text-align: center;"><strong>Total Activity</strong></td>
        <td style="text-align: center; color: #1f2937;">' . number_format($reportStats['total_reports'] ?? 0) . '</td>
        <td>Total reports submitted by zones</td>
    </tr>
    <tr>
        <td style="text-align: center;"><strong>Total Copies</strong></td>
        <td style="text-align: center; color: #059669;">' . number_format($reportStats['total_copies'] ?? 0) . '</td>
        <td>Total copies sponsored across all zones</td>
    </tr>
    <tr>
        <td style="text-align: center;"><strong>Total Distributions</strong></td>
        <td style="text-align: center; color: #2563eb;">' . number_format($reportStats['total_distribution'] ?? 0) . '</td>
        <td>Cumulative distribution count to date</td>
    </tr>
    <tr>
        <td style="text-align: center;"><strong>Lives Impacted</strong></td>
        <td style="text-align: center; color: #dc2626;">' . number_format($reportStats['total_souls'] ?? 0) . '</td>
        <td>People who made decisions to follow Christ</td>
    </tr>
    <tr>
        <td style="text-align: center;"><strong>Zone Participation</strong></td>
        <td style="text-align: center; color: #7c3aed;">' . number_format($complianceData['compliance_rate'] ?? 0, 1) . '%</td>
        <td>Percentage of zones actively participating</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, false, false, '');
$pdf->Ln(10);

// Performance Insights
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(79, 70, 229);
$pdf->Cell(0, 10, 'Performance Insights', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(31, 41, 55);

// Top performing zones
if (!empty($topZones)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Top Performing Zones', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">
        <tr style="background-color: #fef3c7;">
            <th style="font-weight: bold; text-align: center; width: 10%;">Rank</th>
            <th style="font-weight: bold; text-align: center; width: 25%;">Zone Name</th>
            <th style="font-weight: bold; text-align: center; width: 20%;">Region</th>
            <th style="font-weight: bold; text-align: center; width: 15%;">Reports</th>
            <th style="font-weight: bold; text-align: center; width: 15%;">Materials</th>
            <th style="font-weight: bold; text-align: center; width: 15%;">Avg per Report</th>
        </tr>';

    $rank = 1;
    foreach (array_slice($topZones, 0, 10) as $zone) {
        $html .= '<tr>
            <td style="text-align: center; font-weight: bold;">' . $rank . '</td>
            <td>' . htmlspecialchars($zone['zone']) . '</td>
            <td>' . htmlspecialchars($zone['region']) . '</td>
            <td style="text-align: center;">' . number_format($zone['report_count']) . '</td>
            <td style="text-align: center;">' . number_format($zone['total_copies']) . '</td>
            <td style="text-align: center;">' . number_format($zone['avg_copies'] ?? 0, 0) . '</td>
        </tr>';
        $rank++;
    }
    $html .= '</table>';

    $pdf->writeHTML($html, true, false, false, false, '');
    $pdf->Ln(8);
}

// Monthly Trends
if (!empty($monthlyTrends)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Monthly Activity Trends', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">
        <tr style="background-color: #e0f2fe;">
            <th style="font-weight: bold; text-align: center;">Month</th>
            <th style="font-weight: bold; text-align: center;">Reports</th>
            <th style="font-weight: bold; text-align: center;">Materials</th>
            <th style="font-weight: bold; text-align: center;">Active Zones</th>
            <th style="font-weight: bold; text-align: center;">Participation</th>
        </tr>';

    foreach (array_slice($monthlyTrends, 0, 12) as $trend) {
        $compliance = $totalZones > 0 ? ($trend['unique_zones'] / $totalZones) * 100 : 0;
        $html .= '<tr>
            <td>' . htmlspecialchars($trend['month_label']) . '</td>
            <td style="text-align: center;">' . number_format($trend['report_count']) . '</td>
            <td style="text-align: center;">' . number_format($trend['total_copies'] ?? 0) . '</td>
            <td style="text-align: center;">' . number_format($trend['unique_zones']) . '</td>
            <td style="text-align: center;">' . number_format($compliance, 1) . '%</td>
        </tr>';
    }
    $html .= '</table>';

    $pdf->writeHTML($html, true, false, false, false, '');
    $pdf->Ln(8);
}

// Active Filters Summary
$activeFilters = array_filter($filters);
if (!empty($activeFilters)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, '🔍 Applied Filters', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 10);
    $filterText = '';
    if (!empty($filterDateRange)) $filterText .= '• Date Range: ' . ucwords(str_replace('_', ' ', $filterDateRange)) . "\n";
    if (!empty($filterRegion)) $filterText .= '• Region: ' . htmlspecialchars($filterRegion) . "\n";
    if (!empty($filterYear)) $filterText .= '• Year: ' . htmlspecialchars($filterYear) . "\n";
    if (!empty($filterZone)) $filterText .= '• Zone: ' . htmlspecialchars($filterZone) . "\n";
    if (!empty($filterComplianceStatus)) $filterText .= '• Status: ' . ucwords(str_replace('_', ' ', $filterComplianceStatus)) . "\n";

    $pdf->MultiCell(0, 6, $filterText, 0, 'L', false);
    $pdf->Ln(5);
}

// Recommendations
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Recommendations', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$recommendations = [];

$complianceRate = $complianceData['compliance_rate'] ?? 0;
if ($complianceRate < 60) {
    $recommendations[] = '• URGENT: Low participation rate detected. Consider outreach to inactive zones.';
} elseif ($complianceRate < 80) {
    $recommendations[] = '• Consider strategies to increase zone participation and engagement.';
} else {
    $recommendations[] = '• Excellent participation! Focus on maintaining current momentum.';
}

if (!empty($submissionDistribution['monthly'])) {
    $totalMonths = count($submissionDistribution['monthly']);
    if ($totalMonths >= 2) {
        $current = $submissionDistribution['monthly'][count($submissionDistribution['monthly']) - 1]['submissions'];
        $previous = $submissionDistribution['monthly'][count($submissionDistribution['monthly']) - 2]['submissions'];

        if ($current > $previous * 1.1) {
            $recommendations[] = '• Activity is trending upward - maintain successful strategies!';
        } elseif ($current < $previous * 0.9) {
            $recommendations[] = '• Activity has declined - investigate potential causes.';
        } else {
            $recommendations[] = '• Activity levels are stable - good foundation for growth.';
        }
    }
}

$recommendations[] = '• Continue monitoring top-performing zones for best practices.';
$recommendations[] = '• Regular check-ins with zones showing reduced activity.';

foreach ($recommendations as $rec) {
    $pdf->Cell(0, 6, $rec, 0, 1, 'L');
}

// Clean any buffered output before PDF generation
ob_end_clean();

// Output the PDF
$filename = 'Activity_Overview_Report_' . date('Y-m-d_H-i-s') . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>
