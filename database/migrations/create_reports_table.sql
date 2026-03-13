-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables if they exist
DROP TABLE IF EXISTS report_images;
DROP TABLE IF EXISTS reports;

-- Create reports table
CREATE TABLE reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    total_copies INT NOT NULL DEFAULT 0,
    champions_league_groups INT NOT NULL DEFAULT 0,
    groups_1m INT NOT NULL DEFAULT 0,
    groups_500k INT NOT NULL DEFAULT 0,
    groups_250k INT NOT NULL DEFAULT 0,
    groups_100k INT NOT NULL DEFAULT 0,
    monthly_copies INT NOT NULL DEFAULT 0,
    wonder_alerts INT NOT NULL DEFAULT 0,
    kids_alerts INT NOT NULL DEFAULT 0,
    language_missions INT NOT NULL DEFAULT 0,
    penetrating_truth INT NOT NULL DEFAULT 0,
    penetrating_languages INT NOT NULL DEFAULT 0,
    youth_aglow INT NOT NULL DEFAULT 0,
    minister_campaign INT NOT NULL DEFAULT 0,
    say_yes_kids INT NOT NULL DEFAULT 0,
    no_one_left INT NOT NULL DEFAULT 0,
    teevolution INT NOT NULL DEFAULT 0,
    subscriptions INT NOT NULL DEFAULT 0,
    prayer_programs INT NOT NULL DEFAULT 0,
    partner_programs INT NOT NULL DEFAULT 0,
    total_distribution INT NOT NULL DEFAULT 0,
    souls_won INT NOT NULL DEFAULT 0,
    rhapsody_outreaches INT NOT NULL DEFAULT 0,
    rhapsody_cells INT NOT NULL DEFAULT 0,
    new_churches INT NOT NULL DEFAULT 0,
    new_partners INT NOT NULL DEFAULT 0,
    lingual_cells INT NOT NULL DEFAULT 0,
    language_churches INT NOT NULL DEFAULT 0,
    languages_sponsored INT NOT NULL DEFAULT 0,
    distribution_centers INT NOT NULL DEFAULT 0,
    external_ministers INT NOT NULL DEFAULT 0,
    testimonies_file VARCHAR(255),
    innovations_file VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create report_images table
CREATE TABLE report_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
