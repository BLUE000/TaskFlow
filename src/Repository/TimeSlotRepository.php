<?php
namespace Repository;

use PDO;

/**
 * TimeSlotRepository - time_slots テーブルに対するデータアクセスを担当するリポジトリクラス
 */
class TimeSlotRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * 時間枠データを保存（挿入または重複キー時に更新）する
     * 
     * @param array $timeSlot 保存対象の連想配列データ
     * @return bool 処理結果
     */
    public function save(array $timeSlot): bool {
        if (empty($timeSlot['id']) || empty($timeSlot['name'])) {
            return false;
        }

        $sql = "INSERT INTO time_slots (
                    id, name, start_time, end_time, limit_hours, 
                    created_at, updated_at, is_deleted
                ) VALUES (
                    :id, :name, :start_time, :end_time, :limit_hours, 
                    NOW(), NOW(), :is_deleted
                ) ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    start_time = VALUES(start_time),
                    end_time = VALUES(end_time),
                    limit_hours = VALUES(limit_hours),
                    updated_at = NOW(),
                    is_deleted = VALUES(is_deleted)";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':id' => $timeSlot['id'],
            ':name' => $timeSlot['name'],
            ':start_time' => $timeSlot['start_time'],
            ':end_time' => $timeSlot['end_time'],
            ':limit_hours' => isset($timeSlot['limit_hours']) ? (double)$timeSlot['limit_hours'] : 0.0,
            ':is_deleted' => isset($timeSlot['is_deleted']) ? (int)$timeSlot['is_deleted'] : 0
        ]);
    }

    /**
     * 有効な（削除されていない）時間枠を時間順にすべて取得する
     * 
     * @return array 時間枠データの配列
     */
    public function findAllActive(): array {
        $sql = "SELECT * FROM time_slots WHERE is_deleted = 0 ORDER BY start_time ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * 指定日時以降に更新されたレコードを全取得する (同期API用)
     * 
     * @param string $lastSyncedAt 最終同期日時
     * @return array 時間枠データの配列
     */
    public function findUpdatedSince(string $lastSyncedAt): array {
        $sql = "SELECT * FROM time_slots WHERE updated_at > :last_synced_at";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':last_synced_at' => $lastSyncedAt]);
        return $stmt->fetchAll();
    }
}
