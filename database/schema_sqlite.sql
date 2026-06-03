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
  `date` TEXT NOT NULL,
  `time_slot_id` TEXT NOT NULL,
  `allocated_hours` REAL NOT NULL DEFAULT 0.0,
  `created_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `updated_at` TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  `is_deleted` INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE CASCADE
);

-- 初期データの登録 (最終同期時刻の初期化)
INSERT OR IGNORE INTO `sync_metadata` (`key`, `value`) VALUES ('last_synced_at', '1970-01-01 00:00:00');
