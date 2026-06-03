CREATE DATABASE IF NOT EXISTS `taskflow` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `taskflow`;

-- 1. webhooks テーブル (Discord Webhook 設定)
CREATE TABLE IF NOT EXISTS `webhooks` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `url` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. time_slots テーブル (作業時間枠の定義)
CREATE TABLE IF NOT EXISTS `time_slots` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `limit_hours` DOUBLE NOT NULL DEFAULT 0.0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. schedules テーブル (予定・タスク情報)
CREATE TABLE IF NOT EXISTS `schedules` (
  `id` VARCHAR(36) NOT NULL,
  `title` TEXT NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'simple',
  `start_at` DATETIME NULL DEFAULT NULL,
  `end_at` DATETIME NULL DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'todo',
  `priority` INT NOT NULL DEFAULT 0,
  `weight` DOUBLE NOT NULL DEFAULT 0.0,
  `time_slot_id` VARCHAR(36) NULL DEFAULT NULL,
  `category` VARCHAR(50) NOT NULL DEFAULT 'general',
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `discord_webhook_id` VARCHAR(36) NULL DEFAULT NULL,
  `recurrence_group_id` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_schedules_time_slot` FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_schedules_webhook` FOREIGN KEY (`discord_webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. schedule_allocations テーブル (予定・タスクの日別作業時間配分)
CREATE TABLE IF NOT EXISTS `schedule_allocations` (
  `id` VARCHAR(36) NOT NULL,
  `schedule_id` VARCHAR(36) NOT NULL,
  `date` DATE NOT NULL,
  `time_slot_id` VARCHAR(36) NOT NULL,
  `allocated_hours` DOUBLE NOT NULL DEFAULT 0.0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_allocations_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_allocations_time_slot` FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
