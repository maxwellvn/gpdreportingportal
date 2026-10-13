<?php
/**
 * Audit trail helper — log admin/user actions to the audit_trail table.
 * Creates the table automatically on first use if it does not exist.
 */
class Audit {
    private static function getConn(): PDO {
        $db = new Database();
        return $db->getConnection();
    }

    /**
     * Log an action.
     *
     * @param string $action       Short action key, e.g. 'approve_user'
     * @param string $targetType   'user' | 'report' | ''
     * @param int|null $targetId   ID of the affected record
     * @param string $description  Human-readable description
     */
    public static function log(
        string $action,
        string $targetType = '',
        ?int $targetId = null,
        string $description = ''
    ): void {
        try {
            $conn = self::getConn();
            self::ensureTable($conn);

            $actorId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $actorName = $_SESSION['user_data']['name'] ?? $_SESSION['user_data']['username'] ?? null;
            $ip        = $_SERVER['REMOTE_ADDR'] ?? null;

            $stmt = $conn->prepare("
                INSERT INTO audit_trail (actor_id, actor_name, action, target_type, target_id, description, ip_address)
                VALUES (:actor_id, :actor_name, :action, :target_type, :target_id, :description, :ip)
            ");
            $stmt->execute([
                ':actor_id'    => $actorId,
                ':actor_name'  => $actorName,
                ':action'      => $action,
                ':target_type' => $targetType,
                ':target_id'   => $targetId,
                ':description' => $description,
                ':ip'          => $ip,
            ]);
        } catch (Exception $e) {
            error_log('[Audit] Failed to log action: ' . $e->getMessage());
        }
    }

    /**
     * Fetch recent audit entries with optional filters.
     */
    public static function getEntries(
        int $limit = 50,
        string $action = '',
        string $targetType = '',
        ?int $targetId = null,
        ?int $actorId = null
    ): array {
        try {
            $conn = self::getConn();
            self::ensureTable($conn);

            $where  = [];
            $params = [];

            if ($action !== '') {
                $where[] = 'action = :action';
                $params[':action'] = $action;
            }
            if ($targetType !== '') {
                $where[] = 'target_type = :target_type';
                $params[':target_type'] = $targetType;
            }
            if ($targetId !== null) {
                $where[] = 'target_id = :target_id';
                $params[':target_id'] = $targetId;
            }
            if ($actorId !== null) {
                $where[] = 'actor_id = :actor_id';
                $params[':actor_id'] = $actorId;
            }

            $sql = 'SELECT * FROM audit_trail';
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY created_at DESC LIMIT :lim';
            $params[':lim'] = $limit;

            $stmt = $conn->prepare($sql);
            foreach ($params as $k => $v) {
                $type = ($k === ':lim' || $k === ':target_id' || $k === ':actor_id')
                    ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($k, $v, $type);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('[Audit] getEntries failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function ensureTable(PDO $conn): void {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `audit_trail` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `actor_id` int(11) DEFAULT NULL,
              `actor_name` varchar(120) DEFAULT NULL,
              `action` varchar(60) NOT NULL,
              `target_type` varchar(40) DEFAULT NULL,
              `target_id` int(11) DEFAULT NULL,
              `description` text DEFAULT NULL,
              `ip_address` varchar(45) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_actor` (`actor_id`),
              KEY `idx_target` (`target_type`, `target_id`),
              KEY `idx_action` (`action`),
              KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}
