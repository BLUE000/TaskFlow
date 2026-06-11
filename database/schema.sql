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
  `segment_id` VARCHAR(36) NULL DEFAULT NULL,
  `date` DATE NOT NULL,
  `time_slot_id` VARCHAR(36) NOT NULL,
  `allocated_hours` DOUBLE NOT NULL DEFAULT 0.0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_allocations_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_allocations_time_slot` FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
  -- fk_allocations_segment は schedule_segments テーブル作成後に ALTER TABLE で追加
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. schedule_progress テーブル (予定・タスクの進捗率)
CREATE TABLE IF NOT EXISTS `schedule_progress` (
  `schedule_id` VARCHAR(36) NOT NULL,
  `date` DATE NOT NULL,
  `progress_percent` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`schedule_id`, `date`),
  CONSTRAINT `fk_progress_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. schedule_milestone_relations テーブル (マイルストーンとタスクの関連付け用)
CREATE TABLE IF NOT EXISTS `schedule_milestone_relations` (
  `milestone_id` VARCHAR(36) NOT NULL,
  `target_schedule_id` VARCHAR(36) NOT NULL,
  PRIMARY KEY (`milestone_id`, `target_schedule_id`),
  CONSTRAINT `fk_milestone_rel_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_milestone_rel_target` FOREIGN KEY (`target_schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. schedule_segments テーブル (タスク分割セグメント)
CREATE TABLE IF NOT EXISTS `schedule_segments` (
  `id` VARCHAR(36) NOT NULL,
  `schedule_id` VARCHAR(36) NOT NULL,
  `segment_order` INT NOT NULL DEFAULT 1,
  `start_at` DATETIME NOT NULL,
  `end_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_segments_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schedule_allocations に segment_id FKを追加
ALTER TABLE `schedule_allocations`
  ADD CONSTRAINT `fk_allocations_segment` FOREIGN KEY (`segment_id`) REFERENCES `schedule_segments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- 8. schedule_predecessors テーブル (先行タスク依存関係)
CREATE TABLE IF NOT EXISTS schedule_predecessors (
  id             VARCHAR(36) NOT NULL,
  schedule_id    VARCHAR(36) NOT NULL COMMENT '後続タスクのschedule.id',
  predecessor_id VARCHAR(36) NOT NULL COMMENT '先行タスクのschedule.id',
  PRIMARY KEY (id),
  UNIQUE KEY uq_pred (schedule_id, predecessor_id),
  CONSTRAINT fk_pred_schedule    FOREIGN KEY (schedule_id)    REFERENCES schedules (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pred_predecessor FOREIGN KEY (predecessor_id) REFERENCES schedules (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;