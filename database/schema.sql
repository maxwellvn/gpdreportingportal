-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u410505784_gpd_reports`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','admin-manager','rzm') DEFAULT 'rzm',
  `active` tinyint(1) DEFAULT 1,
  `region` varchar(100) NOT NULL,
  `zone` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE IF NOT EXISTS `reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `report_month` date DEFAULT NULL,
  `total_copies` int(11) NOT NULL DEFAULT 0 COMMENT 'Total copies overall',
  `champions_league_groups` int(11) NOT NULL DEFAULT 0,
  `groups_1m` int(11) NOT NULL DEFAULT 0,
  `groups_500k` int(11) NOT NULL DEFAULT 0,
  `groups_250k` int(11) NOT NULL DEFAULT 0,
  `groups_100k` int(11) NOT NULL DEFAULT 0,
  `monthly_copies` int(11) NOT NULL DEFAULT 0 COMMENT 'Total copies for this month',
  `wonder_alerts` int(11) NOT NULL DEFAULT 0,
  `kids_alerts` int(11) NOT NULL DEFAULT 0,
  `language_missions` int(11) NOT NULL DEFAULT 0,
  `penetrating_truth` int(11) NOT NULL DEFAULT 0,
  `penetrating_languages` int(11) NOT NULL DEFAULT 0,
  `youth_aglow` int(11) NOT NULL DEFAULT 0,
  `minister_campaign` int(11) NOT NULL DEFAULT 0,
  `say_yes_kids` int(11) NOT NULL DEFAULT 0,
  `no_one_left` int(11) NOT NULL DEFAULT 0,
  `teevolution` int(11) NOT NULL DEFAULT 0,
  `subscriptions` int(11) NOT NULL DEFAULT 0,
  `prayer_programs` int(11) NOT NULL DEFAULT 0,
  `partner_programs` int(11) NOT NULL DEFAULT 0,
  `total_distribution` int(11) NOT NULL DEFAULT 0 COMMENT 'Cumulative total copies compared to distribution',
  `distribution_digital` int(11) NOT NULL DEFAULT 0,
  `distribution_physical` int(11) NOT NULL DEFAULT 0,
  `souls_won` int(11) NOT NULL DEFAULT 0,
  `rhapsody_outreaches` int(11) NOT NULL DEFAULT 0,
  `rhapsody_cells` int(11) NOT NULL DEFAULT 0,
  `new_churches` int(11) NOT NULL DEFAULT 0,
  `new_partners` int(11) NOT NULL DEFAULT 0,
  `lingual_cells` int(11) NOT NULL DEFAULT 0,
  `language_churches` int(11) NOT NULL DEFAULT 0,
  `languages_sponsored` int(11) NOT NULL DEFAULT 0,
  `distribution_centers` int(11) NOT NULL DEFAULT 0,
  `external_ministers` int(11) NOT NULL DEFAULT 0,
  `crusades` int(11) NOT NULL DEFAULT 0,
  `testimonies_received` int(11) NOT NULL DEFAULT 0,
  `testimonies_file` varchar(255) DEFAULT NULL,
  `innovations_file` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  UNIQUE KEY `uniq_reports_user_month` (`user_id`,`report_month`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_images`
--

CREATE TABLE IF NOT EXISTS `report_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `report_id` (`report_id`),
  CONSTRAINT `report_images_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
