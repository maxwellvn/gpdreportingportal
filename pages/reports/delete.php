<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/audit.php';

// Ensure user is logged in and is admin
Auth::requireLogin();
if (!Auth::isAdmin()) {
    header("Location: list.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_id'])) {
    // CSRF validation
    try {
        if (!isset($_POST['csrf_token'])) {
            throw new Exception('Missing CSRF token');
        }
        Security::verifyCSRFToken($_POST['csrf_token']);
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: list.php');
        exit;
    }
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Start transaction
        $conn->beginTransaction();
        
        // Get report details for file deletion
        $reportQuery = "SELECT testimonies_file, innovations_file FROM reports WHERE id = :report_id";
        $stmt = $conn->prepare($reportQuery);
        $stmt->bindValue(':report_id', $_POST['report_id'], PDO::PARAM_INT);
        $stmt->execute();
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get image paths
        $imagesQuery = "SELECT image_path FROM report_images WHERE report_id = :report_id";
        $stmt = $conn->prepare($imagesQuery);
        $stmt->bindValue(':report_id', $_POST['report_id'], PDO::PARAM_INT);
        $stmt->execute();
        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Delete physical files (correct directories)
        if ($report) {
            if (!empty($report['testimonies_file'])) {
                $path = realpath(__DIR__ . '/../../uploads/documents') . DIRECTORY_SEPARATOR . $report['testimonies_file'];
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
            }
            if (!empty($report['innovations_file'])) {
                $path = realpath(__DIR__ . '/../../uploads/documents') . DIRECTORY_SEPARATOR . $report['innovations_file'];
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
        
        // Delete image files
        foreach ($images as $image) {
            $path = realpath(__DIR__ . '/../../uploads/pictures');
            if ($path) {
                $full = $path . DIRECTORY_SEPARATOR . $image;
                if (file_exists($full)) {
                    @unlink($full);
                }
            }
        }
        
        // Delete report images from database
        $deleteImagesQuery = "DELETE FROM report_images WHERE report_id = :report_id";
        $stmt = $conn->prepare($deleteImagesQuery);
        $stmt->bindValue(':report_id', $_POST['report_id'], PDO::PARAM_INT);
        $stmt->execute();
        
        // Delete report
        $deleteReportQuery = "DELETE FROM reports WHERE id = :report_id";
        $stmt = $conn->prepare($deleteReportQuery);
        $stmt->bindValue(':report_id', $_POST['report_id'], PDO::PARAM_INT);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();

        Audit::log('delete_report', 'report', (int)$_POST['report_id'], 'Deleted report #' . (int)$_POST['report_id']);
        $_SESSION['success'] = "Report deleted successfully.";
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error deleting report: " . $e->getMessage();
    }
}

// Redirect back to list page
header("Location: list.php");
exit;
