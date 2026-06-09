-- 1. webhooks テーブル (Discord Webhook 設定)
CREATE TABLE IF NOT EXISTS `webhooks` (
  `id` TEXT NOT NULL PRIMARY KEY,
  `name` TEXT NOT NULL,
  `url` TEXT NOT NULL,
  `created_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `updated_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `is_deleted` INTEGER NOT NULL DEFAULT 0
);

-- 2. time_slots テーブル (作業時間枠の定義)
CREATE TABLE IF NOT EXISTS `time_slots` (
  `id` TEXT NOT NULL PRIMARY KEY,
  `name` TEXT NOT NULL,
  `start_time` TEXT NOT NULL,
  `end_time` TEXT NOT NULL,
  `limit_hours` REAL NOT NULL DEFAULT 0.0,
  `created_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `updated_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `is_deleted` INTEGER NOT NULL DEFAULT 0
);

-- 3. schedules テーブル (予定・タスク情報)
CREATE TABLE IF NOT EXISTS `schedules` (
  `id` TEXT NOT NULL PRIMARY KEY,
  `title` TEXT NOT NULL,
  `description` TEXT DEFAULT NULL,
  `type` TEXT NOT NULL DEFAULT 'simple',
  `start_at` TEXT DEFAULT NULL,
  `end_at` TEXT DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT 'todo',
  `priority` INTEGER NOT NULL DEFAULT 0,
  `weight` REAL NOT NULL DEFAULT 0.0,
  `time_slot_id` TEXT DEFAULT NULL,
  `category` TEXT NOT NULL DEFAULT 'general',
  `is_public` INTEGER NOT NULL DEFAULT 0,
  `discord_webhook_id` TEXT DEFAULT NULL,
  `recurrence_group_id` TEXT DEFAULT NULL,
  `created_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `updated_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `is_deleted` INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`discord_webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE SET NULL
);

-- 4. sync_metadata テーブル (同期メタデータ)
CREATE TABLE IF NOT EXISTS `sync_metadata` (
  `key` TEXT NOT NULL PRIMARY KEY,
  `value` TEXT NOT NULL
);

-- 5. schedule_allocations テーブル (予定・タスクの日別作業時間配分)
CREATE TABLE IF NOT EXISTS `schedule_allocations` (
  `id` TEXT NOT NULL PRIMARY KEY,
  `schedule_id` TEXT NOT NULL,
  `segment_id` TEXT DEFAULT NULL,
  `date` TEXT NOT NULL,
  `time_slot_id` TEXT NOT NULL,
  `allocated_hours` REAL NOT NULL DEFAULT 0.0,
  `created_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `updated_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `is_deleted` INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`segment_id`) REFERENCES `schedule_segments` (`id`) ON DELETE SET NULL
);

-- 6. schedule_progress テーブル (予定・タスクの進捗率)
CREATE TABLE IF NOT EXISTS `schedule_progress` (
  `schedule_id` TEXT NOT NULL,
  `date` TEXT NOT NULL,
  `progress_percent` INTEGER NOT NULL DEFAULT 0,
  `created_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `updated_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  PRIMARY KEY (`schedule_id`, `date`),
  FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE
);

-- 7. schedule_milestone_relations テーブル (マイルストーンとタスクの関連付け用)
CREATE TABLE IF NOT EXISTS `schedule_milestone_relations` (
  `milestone_id` TEXT NOT NULL,
  `target_schedule_id` TEXT NOT NULL,
  PRIMARY KEY (`milestone_id`, `target_schedule_id`),
  FOREIGN KEY (`milestone_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (`target_schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 8. schedule_segments テーブル (タスク分割セグメント)
CREATE TABLE IF NOT EXISTS `schedule_segments` (
  `id` TEXT NOT NULL PRIMARY KEY,
  `schedule_id` TEXT NOT NULL,
  `segment_order` INTEGER NOT NULL DEFAULT 1,
  `start_at` TEXT NOT NULL,
  `end_at` TEXT NOT NULL,
  `created_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `updated_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `is_deleted` INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE
);

-- 初期データの登録 (最終同期時刻の初期化)
INSERT OR IGNORE INTO `sync_metadata` (`key`, `value`) VALUES ('last_synced_at', '1970-01-01 00:00:00');
