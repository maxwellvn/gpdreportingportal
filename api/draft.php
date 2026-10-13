<?php
/**
 * Server-side draft API — save / load / delete a report draft per user.
 * Endpoints (JSON):
 *   POST  action=save   body: draft_data (JSON string), report_month (YYYY-MM)
 *   GET   action=load
 *   POST  action=delete
 */
require_once '../config/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

$db   = new Database();
$conn = $db->getConnection();

// Ensure table exists
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS `report_drafts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `report_month` varchar(7) DEFAULT NULL,
        `draft_data` mediumtext NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_draft_user` (`user_id`),
        CONSTRAINT `drafts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Table init failed']);
    exit;
}

try {
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Accept JSON body or form data
        $body = file_get_contents('php://input');
        $payload = $body ? json_decode($body, true) : null;
        $draftData   = ($payload['draft_data'] ?? null) ?: ($_POST['draft_data'] ?? '');
        $reportMonth = ($payload['report_month'] ?? null) ?: ($_POST['report_month'] ?? null);

        if (empty($draftData)) {
            echo json_encode(['ok' => false, 'error' => 'No draft data provided']);
            exit;
        }

        // Validate JSON
        json_decode($draftData);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON in draft_data']);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO report_drafts (user_id, report_month, draft_data)
            VALUES (:uid, :month, :data)
            ON DUPLICATE KEY UPDATE report_month = VALUES(report_month),
                                    draft_data   = VALUES(draft_data),
                                    updated_at   = current_timestamp()
        ");
        $stmt->execute([
            ':uid'   => $userId,
            ':month' => $reportMonth,
            ':data'  => $draftData,
        ]);

        echo json_encode(['ok' => true, 'saved_at' => date('c')]);

    } elseif ($action === 'load' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("SELECT draft_data, report_month, updated_at FROM report_drafts WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode([
                'ok'           => true,
                'draft_data'   => $row['draft_data'],
                'report_month' => $row['report_month'],
                'updated_at'   => $row['updated_at'],
            ]);
        } else {
            echo json_encode(['ok' => true, 'draft_data' => null]);
        }

    } elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $conn->prepare("DELETE FROM report_drafts WHERE user_id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['ok' => true]);

    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action or wrong method']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
