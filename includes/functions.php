<?php
/**
 * Helper functions for the GPD Reports application
 */

// Format number with commas, handling null values and empty strings
function formatNumber($number, $decimals = 0) {
    if ($number === null || $number === '' || !is_numeric($number)) {
        $number = 0;
    }
    return number_format((float)$number, $decimals);
}

// Safe number format that handles null values and empty strings
function safeNumberFormat($number, $decimals = 0) {
    if ($number === null || $number === '' || !is_numeric($number)) {
        $number = 0;
    }
    return number_format((float)$number, $decimals);
}

// Format date to readable format
function formatDate($date, $format = 'M j, Y') {
    return date($format, strtotime($date));
}

// Get user's role
function getUserRole() {
    return isset($_SESSION['user_data']['role']) ? $_SESSION['user_data']['role'] : '';
}

// Check if user is admin
function isAdmin() {
    return getUserRole() === 'admin';
}

// Sanitize output
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Generate random string
function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length));
}

// Handle file upload
function handleFileUpload($file, $targetDir, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Invalid file parameters'];
    }

    // Check file errors
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['success' => false, 'message' => 'No file uploaded'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['success' => false, 'message' => 'File size exceeds limit'];
        default:
            return ['success' => false, 'message' => 'Unknown error occurred'];
    }

    $maximumFileSize = defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : 10 * 1024 * 1024;
    if ($file['size'] > $maximumFileSize) {
        return ['success' => false, 'message' => 'File size exceeds 10MB limit'];
    }

    // Check file type
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);

    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }

    // Additional MIME type validation for images and pdf/doc
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedMimeMap = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']
    ];
    if (isset($allowedMimeMap[$extension]) && !in_array($mimeType, $allowedMimeMap[$extension], true)) {
        return ['success' => false, 'message' => 'Invalid file content type'];
    }

    // Generate unique filename
    $newFilename = generateRandomString() . '.' . $extension;
    $targetPath = $targetDir . '/' . $newFilename;

    // Create directory if it doesn't exist
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'message' => 'Failed to move uploaded file'];
    }

    return [
        'success' => true,
        'filename' => $newFilename,
        'path' => $targetPath
    ];
}

// Rearray files from multiple file upload
function reArrayFiles($files) {
    $file_array = array();
    $file_count = count($files['name']);
    $file_keys = array_keys($files);

    for ($i = 0; $i < $file_count; $i++) {
        foreach ($file_keys as $key) {
            $file_array[$i][$key] = $files[$key][$i];
        }
    }

    return $file_array;
}

// Upload file and return filename
function uploadFile($file, $targetDir, $allowedTypes = null) {
    // Create directory if it doesn't exist
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Handle the file upload
    if ($allowedTypes === null) {
        $result = handleFileUpload($file, $targetDir);
    } else {
        $result = handleFileUpload($file, $targetDir, $allowedTypes);
    }
    
    if (!$result['success']) {
        throw new Exception($result['message']);
    }
    
    return $result['filename'];
}

/**
 * Allow the next calendar month only during the final UTC day, covering users
 * whose local timezone has already crossed into a new month.
 */
function getLatestAllowedReportMonth(?DateTimeInterface $now = null) {
    $currentTime = $now
        ? DateTimeImmutable::createFromInterface($now)
        : new DateTimeImmutable('now');

    return $currentTime->modify('+1 day')->format('Y-m');
}

// Delete file
function deleteFile($filepath) {
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

// Get file extension
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

// Check if file is an image
function isImage($filename) {
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif'];
    return in_array(getFileExtension($filename), $imageTypes);
}

// Get file icon based on extension
function getFileIcon($filename) {
    $extension = getFileExtension($filename);
    $icons = [
        'doc' => 'fa-file-word',
        'docx' => 'fa-file-word',
        'pdf' => 'fa-file-pdf',
        'jpg' => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png' => 'fa-file-image',
        'gif' => 'fa-file-image'
    ];
    
    return isset($icons[$extension]) ? $icons[$extension] : 'fa-file';
}

// Create upload directories if they don't exist
function createUploadDirectories() {
    $directories = [
        __DIR__ . '/../uploads',
        __DIR__ . '/../uploads/documents',
        __DIR__ . '/../uploads/pictures'
    ];

    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}

// Initialize upload directories
createUploadDirectories();
