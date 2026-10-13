<?php
require_once __DIR__ . '/env.php';

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    private $conn;
    private static $safeMigrationsChecked = false;

    public function __construct() {
        loadEnvFile(__DIR__ . '/../.env');

        // Credentials come only from the environment (.env). Production must set them explicitly.
        $isProduction = defined('ENVIRONMENT') && ENVIRONMENT === 'production';
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->port = getenv('DB_PORT') ?: '3306';
        $this->db_name = getenv('DB_NAME') ?: ($isProduction ? '' : 'gpdreports');
        $this->username = getenv('DB_USER') ?: ($isProduction ? '' : 'root');
        $this->password = getenv('DB_PASS') ?: '';

        if ($isProduction && ($this->db_name === '' || $this->username === '')) {
            error_log('Database configuration missing: set DB_NAME, DB_USER and DB_PASS in .env');
            throw new Exception('Database is not configured');
        }
    }

    public function getConnection() {
        $this->conn = null;

        try {
            // Skip socket test in production as it might not be needed and could cause issues
            if (ENVIRONMENT !== 'production') {
                $socket = @fsockopen($this->host, (int)$this->port, $errno, $errstr, 5);
                if (!$socket) {
                    throw new Exception("Cannot connect to database host: $errstr ($errno)");
                }
                fclose($socket);
            }

            // Now try database connection
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_PERSISTENT => false
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);

            // Test the connection with a simple query
            $this->conn->query("SELECT 1");

            // Run safe, idempotent migrations once per request without destructive operations.
            if (!self::$safeMigrationsChecked) {
                $this->runAutomaticSafeMigrations();
                self::$safeMigrationsChecked = true;
            }

            return $this->conn;
        } catch(PDOException $e) {
            // Avoid logging sensitive connection details
            error_log("Database Connection Error at " . date('Y-m-d H:i:s'));
            error_log("Error: " . $e->getMessage());
            error_log("Code: " . $e->getCode());
            // Check for specific error codes
            $errorCode = $e->getCode();
            switch($errorCode) {
                case 1044:
                    throw new Exception("Access denied for user to the database");
                case 1045:
                    throw new Exception("Invalid username or password");
                case 1049:
                    throw new Exception("Unknown database");
                case 2002:
                    throw new Exception("Could not connect to database server");
                default:
                    throw new Exception("Database connection failed: " . $e->getMessage());
            }
        } catch(Exception $e) {
            error_log("General Error: " . $e->getMessage());
            throw $e;
        }
    }

    private function runAutomaticSafeMigrations() {
        try {
            $migrationsDir = __DIR__ . '/../database/migrations';
            if (!is_dir($migrationsDir)) {
                return;
            }

            // Track applied migrations to keep process idempotent.
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS schema_migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration_name VARCHAR(255) NOT NULL UNIQUE,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $files = glob($migrationsDir . '/*.sql');
            if (!$files) {
                return;
            }
            sort($files, SORT_STRING);

            $applied = $this->conn->query("SELECT migration_name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $appliedLookup = array_flip($applied);

            foreach ($files as $filePath) {
                $fileName = basename($filePath);

                if (isset($appliedLookup[$fileName])) {
                    continue;
                }

                // Skip known destructive/setup script from auto-run.
                if ($fileName === 'create_reports_table.sql') {
                    continue;
                }

                $sql = trim((string)file_get_contents($filePath));
                if ($sql === '') {
                    continue;
                }

                // Safety gate: never auto-run destructive migrations.
                if (!$this->isSafeMigrationSql($sql)) {
                    error_log("Skipping unsafe migration: " . $fileName);
                    continue;
                }

                // Precheck: unique user-month index should not run if duplicates exist.
                if ($fileName === 'add_unique_user_report_month.sql') {
                    $dupRow = $this->conn->query("
                        SELECT COUNT(*) AS duplicate_groups
                        FROM (
                            SELECT user_id, report_month
                            FROM reports
                            GROUP BY user_id, report_month
                            HAVING COUNT(*) > 1
                        ) d
                    ")->fetch();
                    $duplicateGroups = (int)($dupRow['duplicate_groups'] ?? 0);
                    if ($duplicateGroups > 0) {
                        error_log("Skipping add_unique_user_report_month.sql due to duplicate existing data groups: " . $duplicateGroups);
                        continue;
                    }
                }

                // The precheck report migration is informational; skip in auto mode.
                if ($fileName === 'check_duplicate_user_month_reports.sql') {
                    continue;
                }

                try {
                    $this->conn->beginTransaction();
                    $this->conn->exec($sql);
                    $trackStmt = $this->conn->prepare("INSERT INTO schema_migrations (migration_name) VALUES (:migration_name)");
                    $trackStmt->execute([':migration_name' => $fileName]);
                    $this->conn->commit();
                } catch (PDOException $e) {
                    if ($this->conn->inTransaction()) {
                        $this->conn->rollBack();
                    }

                    // If migration was effectively already applied, mark and continue.
                    $message = strtolower($e->getMessage());
                    if (strpos($message, 'duplicate column') !== false ||
                        strpos($message, 'duplicate key') !== false ||
                        strpos($message, 'already exists') !== false) {
                        $trackStmt = $this->conn->prepare("INSERT IGNORE INTO schema_migrations (migration_name) VALUES (:migration_name)");
                        $trackStmt->execute([':migration_name' => $fileName]);
                        continue;
                    }

                    error_log("Migration failed for " . $fileName . ": " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("Auto migration runner failed: " . $e->getMessage());
        }
    }

    private function isSafeMigrationSql($sql) {
        $normalized = strtoupper($sql);
        $forbiddenPatterns = [
            'DROP TABLE',
            'TRUNCATE TABLE',
            'DELETE FROM',
            'DROP DATABASE'
        ];

        foreach ($forbiddenPatterns as $pattern) {
            if (strpos($normalized, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }
}
