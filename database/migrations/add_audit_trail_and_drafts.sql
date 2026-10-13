-- Audit trail table for tracking edits, approvals, and account changes
CREATE TABLE IF NOT EXISTS `audit_trail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL COMMENT 'User who performed the action',
  `actor_name` varchar(120) DEFAULT NULL,
  `action` varchar(60) NOT NULL COMMENT 'e.g. approve_user, edit_report, delete_report',
  `target_type` varchar(40) DEFAULT NULL COMMENT 'user | report',
  `target_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_actor` (`actor_id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Report drafts table for save-draft feature
CREATE TABLE IF NOT EXISTS `report_drafts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `report_month` varchar(7) DEFAULT NULL COMMENT 'YYYY-MM',
  `draft_data` mediumtext NOT NULL COMMENT 'JSON-encoded form values',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_draft_user` (`user_id`),
  CONSTRAINT `drafts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Reminders table
CREATE TABLE IF NOT EXISTS `reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `target_user_id` int(11) DEFAULT NULL COMMENT 'NULL = broadcast to all pending users',
  `message` text NOT NULL,
  `reminder_type` enum('personal','broadcast') NOT NULL DEFAULT 'personal',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sender` (`sender_id`),
  KEY `idx_target` (`target_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
