<?php
require_once '../config/config.php';
require_once '../includes/auth.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check if user is admin
if (!isset($_SESSION['user_data']['role']) || $_SESSION['user_data']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Get region parameter
$region = isset($_GET['region']) ? $_GET['region'] : '';

if (empty($region)) {
    echo json_encode([]);
    exit;
}

$zones = $REGIONS_ZONES[$region] ?? [];

// Set JSON header
header('Content-Type: application/json');
echo json_encode($zones);
?>
