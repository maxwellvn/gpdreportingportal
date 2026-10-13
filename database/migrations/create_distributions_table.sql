-- Distribution capture tables
CREATE TABLE IF NOT EXISTS distributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- NULL = public (not-logged-in) submission
    distributor_name VARCHAR(255) NOT NULL,
    cell VARCHAR(255) NOT NULL DEFAULT '',
    church VARCHAR(255) NOT NULL DEFAULT '',
    group_name VARCHAR(255) NOT NULL DEFAULT '',
    zone VARCHAR(255) NOT NULL DEFAULT '',
    locations TEXT NULL,
    copies_distributed INT NOT NULL DEFAULT 0,
    format VARCHAR(255) NOT NULL DEFAULT '', -- comma-joined multi-select
    recipients INT NOT NULL DEFAULT 0,
    souls_saved INT NOT NULL DEFAULT 0,
    testimonies_file VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_distributions_user (user_id),
    CONSTRAINT fk_distributions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS distribution_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    distribution_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_distribution_images_dist (distribution_id),
    CONSTRAINT fk_distribution_images_dist FOREIGN KEY (distribution_id) REFERENCES distributions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- KingsChat users allowed to view the distribution dashboard
CREATE TABLE IF NOT EXISTS distribution_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kc_username VARCHAR(100) NOT NULL UNIQUE,
    kc_user_id VARCHAR(255) NOT NULL DEFAULT '',
    kc_name VARCHAR(255) NOT NULL DEFAULT '',
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    added_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
