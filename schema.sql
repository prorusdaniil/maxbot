-- Структура БД бота. Файл можно применять повторно.
-- bot_sessions и stats создаются автоматически при первом запросе к webhook.php;
-- этот файл нужен, если у пользователя БД нет права CREATE.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `books` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_sessions` (
  `user_id` bigint(20) NOT NULL,
  `data` longtext NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `local_celebrities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subcategory` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `years` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `known_for` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fact` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `paragraphs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `book_id` int(11) NOT NULL,
  `paragraph_id` int(11) NOT NULL,
  `last_paragraph_id` int(11) DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `questions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `has_video` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `book_id` (`book_id`),
  CONSTRAINT `paragraphs_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `paragraph_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `book_id` int(11) NOT NULL,
  `paragraph_id` int(11) NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `main_points` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_paragraph` (`book_id`,`paragraph_id`),
  CONSTRAINT `paragraph_details_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stats` (
  `created_at` datetime NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `book_id` int(11) DEFAULT NULL,
  `paragraph_id` int(11) DEFAULT NULL,
  `feature` varchar(32) NOT NULL,
  `input_tokens` int(11) NOT NULL DEFAULT 0,
  `cached_tokens` int(11) NOT NULL DEFAULT 0,
  `output_tokens` int(11) NOT NULL DEFAULT 0,
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
