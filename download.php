<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Ensure user is logged in
Auth::requireLogin();

// Get parameters
$type = isset($_GET['type']) ? $_GET['type'] : '';
$file = isset($_GET['file']) ? $_GET['file'] : '';
$token = isset($_GET['token']) ? $_GET['token'] : '';

// Validate token
$expectedToken = hash('sha256', AUTH_SECRET . $file);
if ($token !== $expectedToken) {
    http_response_code(403);
    exit('Access denied');
}

// Validate file type
if (!in_array($type, ['image', 'document'])) {
    http_response_code(400);
    exit('Invalid file type');
}

// Set base path based on file type
$basePath = $type === 'image' ? 'uploads/images/' : 'uploads/documents/';
$filePath = $basePath . basename($file); // Use basename for security

// Validate file exists
if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File not found');
}

// Get file information
$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($filePath);

// Set headers for download
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output file
readfile($filePath);
exit;
