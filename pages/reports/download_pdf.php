<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
if ($user['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Check if report ID is provided
if (!isset($_GET['id'])) {
    header('Location: list.php');
    exit;
}

// Get report data
$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT r.*, u.username, u.zone, u.region 
        FROM reports r 
        LEFT JOIN users u ON r.user_id = u.id 
        WHERE r.id = ?
    ");
    $stmt->execute([$_GET['id']]);
    $report = $stmt->fetch();

    if (!$report) {
        header('Location: list.php');
        exit;
    }

    // Custom PDF class with header and footer
    class MYPDF extends TCPDF {
        public function Header() {
            // Logo
            $image_file = '../../assets/images/logo.png';
            if (file_exists($image_file)) {
                $this->Image($image_file, 15, 10, 30);
            }
            
            // Set font
            $this->SetFont('helvetica', 'B', 20);
            // Title
            $this->SetTextColor(44, 62, 80); // Dark blue color
            $this->Cell(0, 30, 'GPD Report Details', 0, true, 'C');
            
            // Line separator
            $this->SetLineStyle(array('width' => 0.5, 'color' => array(52, 73, 94)));
            $this->Line(15, 45, 195, 45);
        }

        public function Footer() {
            // Position at 15 mm from bottom
            $this->SetY(-15);
            // Set font
            $this->SetFont('helvetica', 'I', 8);
            // Text color
            $this->SetTextColor(128);
            // Page number
            $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C');
        }

        // Colored table
        public function ColoredTable($header, $data) {
            // Colors, line width and bold font
            $this->SetFillColor(52, 152, 219); // Blue header
            $this->SetTextColor(255);
            $this->SetDrawColor(44, 62, 80);
            $this->SetLineWidth(0.3);
            $this->SetFont('', 'B');
            
            // Header
            $w = array(70, 50, 65);
            for($i = 0; $i < count($header); $i++)
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            $this->Ln();
            
            // Color and font restoration
            $this->SetFillColor(224, 235, 255);
            $this->SetTextColor(44, 62, 80);
            $this->SetFont('');
            
            // Data
            $fill = false;
            foreach($data as $row) {
                $this->Cell($w[0], 6, $row[0], 'LR', 0, 'L', $fill);
                $this->Cell($w[1], 6, $row[1], 'LR', 0, 'R', $fill);
                $this->Cell($w[2], 6, $row[2], 'LR', 0, 'R', $fill);
                $this->Ln();
                $fill = !$fill;
            }
            $this->Cell(array_sum($w), 0, '', 'T');
        }
    }

    // Create new PDF document
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('GPD Reports System');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Report Details - ' . $report['id']);

    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

    // Set margins
    $pdf->SetMargins(15, 50, 15);
    $pdf->SetHeaderMargin(20);
    $pdf->SetFooterMargin(10);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

    // Add a page
    $pdf->AddPage();

    // Report Meta Information
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(41, 128, 185); // Blue
    $pdf->Cell(0, 10, 'Report Information', 0, 1, 'L');
    $pdf->SetDrawColor(189, 195, 199);
    $pdf->Line($pdf->GetX(), $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);

    // Meta Information Table
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(44, 62, 80);
    
    // Create info box with light blue background
    $pdf->SetFillColor(236, 240, 241);
    $pdf->Rect(15, $pdf->GetY(), 180, 40, 'F');
    $pdf->SetXY(20, $pdf->GetY() + 5);
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 7, 'Report ID:', 0, 0);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(50, 7, $report['id'], 0, 0);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 7, 'Submitted By:', 0, 0);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 7, $report['username'], 0, 1);
    
    $pdf->SetX(20);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 7, 'Zone:', 0, 0);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(50, 7, $report['zone'], 0, 0);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 7, 'Region:', 0, 0);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 7, $report['region'], 0, 1);
    
    $pdf->SetX(20);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 7, 'Date:', 0, 0);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 7, date('F j, Y', strtotime($report['created_at'])), 0, 1);
    
    $pdf->Ln(15);

    // Copies Information
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(41, 128, 185);
    $pdf->Cell(0, 10, 'Distribution Statistics', 0, 1, 'L');
    $pdf->Line($pdf->GetX(), $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);

    // Create statistics table
    $header = array('Metric', 'Value', 'Percentage');
    $data = array(
        array('Total Copies', number_format($report['total_copies']), '100%'),
        array('Total Distribution', number_format($report['total_distribution']), 
            round(($report['total_distribution'] / $report['total_copies']) * 100, 1) . '%')
    );
    $pdf->ColoredTable($header, $data);
    
    $pdf->Ln(15);

    // Impact Metrics
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(41, 128, 185);
    $pdf->Cell(0, 10, 'Impact Metrics', 0, 1, 'L');
    $pdf->Line($pdf->GetX(), $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);

    // Create 2x2 grid for impact metrics
    $pdf->SetFillColor(236, 240, 241);
    $pdf->SetTextColor(44, 62, 80);
    $metrics = array(
        array('Souls Won', $report['souls_won']),
        array('New Churches', $report['new_churches']),
        array('New Partners', $report['new_partners']),
        array('External Ministers', $report['external_ministers'])
    );

    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $width = 85;
    $height = 25;
    $spacing = 10;

    foreach($metrics as $i => $metric) {
        $currentX = $x + ($i % 2) * ($width + $spacing);
        $currentY = $y + floor($i / 2) * ($height + $spacing);
        
        $pdf->SetXY($currentX, $currentY);
        $pdf->Rect($currentX, $currentY, $width, $height, 'F');
        
        $pdf->SetXY($currentX + 5, $currentY + 5);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell($width - 10, 7, $metric[0], 0, 1);
        
        $pdf->SetXY($currentX + 5, $currentY + 12);
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell($width - 10, 7, number_format($metric[1]), 0, 1);
    }

    // Output the PDF
    $pdf->Output('Report_' . $report['id'] . '.pdf', 'D');

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header('Location: list.php');
    exit;
}
