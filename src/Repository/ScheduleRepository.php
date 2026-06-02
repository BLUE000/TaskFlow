<?php
namespace Repository;

use PDO;
use Common\Security\CryptoUtil;
use RuntimeException;

/**
 * ScheduleRepository - schedules テーブルに対するデータアクセスを担当するリポジトリクラス
 */
class ScheduleRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * 予定データを保存（挿入または重複キー時に更新）する
     * 
     * @param array $schedule 保存対象の連想配列データ
     * @return bool 処理結果（成功時 true、失敗時 false）
     */
    public function save(array $schedule): bool {
        // 必須フィールドの検証
        if (empty($schedule['id']) || empty($schedule['title'])) {
            return false;
        }

        // 暗号化対象フィールドの暗号化
        $encryptedTitle = CryptoUtil::encrypt($schedule['title']);
        $encryptedDesc = null;
        if (isset($schedule['description']) && $schedule['description'] !== '') {
            $encryptedDesc = CryptoUtil::encrypt($schedule['description']);
        }

        $sql = "INSERT INTO schedules (
                    id, title, description, type, start_at, end_at, 
                    status, priority, weight, time_slot_id, category, 
                    is_public, discord_webhook_id, updated_at, is_deleted
                ) VALUES (
                    :id, :title, :description, :type, :start_at, :end_at, 
                    :status, :priority, :weight, :time_slot_id, :category, 
                    :is_public, :discord_webhook_id, NOW(), :is_deleted
                ) ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    type = VALUES(type),
                    start_at = VALUES(start_at),
                    end_at = VALUES(end_at),
                    status = VALUES(status),
                    priority = VALUES(priority),
                    weight = VALUES(weight),
                    time_slot_id = VALUES(time_slot_id),
                    category = VALUES(category),
                    is_public = VALUES(is_public),
                    discord_webhook_id = VALUES(discord_webhook_id),
                    updated_at = NOW(),
                    is_deleted = VALUES(is_deleted)";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':id' => $schedule['id'],
            ':title' => $encryptedTitle,
            ':description' => $encryptedDesc,
            ':type' => $schedule['type'] ?? 'simple',
            ':start_at' => !empty($schedule['start_at']) ? $schedule['start_at'] : null,
            ':end_at' => !empty($schedule['end_at']) ? $schedule['end_at'] : null,
            ':status' => $schedule['status'] ?? 'todo',
            ':priority' => isset($schedule['priority']) ? (int)$schedule['priority'] : 0,
            ':weight' => isset($schedule['weight']) ? (double)$schedule['weight'] : 0.0,
            ':time_slot_id' => !empty($schedule['time_slot_id']) ? $schedule['time_slot_id'] : null,
            ':category' => $schedule['category'] ?? 'general',
            ':is_public' => isset($schedule['is_public']) ? (int)$schedule['is_public'] : 0,
            ':discord_webhook_id' => !empty($schedule['discord_webhook_id']) ? $schedule['discord_webhook_id'] : null,
            ':is_deleted' => isset($schedule['is_deleted']) ? (int)$schedule['is_deleted'] : 0
        ]);
    }

    /**
     * 公開されている未削除の予定を日付順で全取得し、復号して返却する
     * 
     * @return array 復号された予定データの配列
     */
    public function findAllPublic(): array {
        $sql = "SELECT * FROM schedules 
                WHERE is_public = 1 AND is_deleted = 0 
                ORDER BY start_at ASC, created_at ASC";

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->decryptRow($row);
        }

        return $results;
    }

    /**
     * すべての予定を全取得し、復号して返却する (管理者用)
     * 
     * @return array 復号された予定データの配列
     */
    public function findAll(): array {
        $sql = "SELECT * FROM schedules ORDER BY start_at ASC, created_at ASC";

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->decryptRow($row);
        }

        return $results;
    }

    /**
     * 指定日時以降に更新されたレコードを暗号化された生の状態で全取得する (同期API用)
     * 同期APIではデータの送受信段階で復号を行わず、各クライアント端末で復号するため、生のまま取得します。
     * 
     * @param string $lastSyncedAt 最終同期日時
     * @return array 暗号化された状態のままの予定データ配列
     */
    public function findUpdatedSince(string $lastSyncedAt): array {
        $sql = "SELECT * FROM schedules WHERE updated_at > :last_synced_at";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':last_synced_at' => $lastSyncedAt]);
        return $stmt->fetchAll();
    }

    /**
     * レコードの暗号化フィールド（title, description）を復号した連想配列に変換する内部ヘルパー
     * 
     * @param array $row DBから取得した1行データ
     * @return array 復号済みのデータ配列
     */
    private function decryptRow(array $row): array {
        try {
            $row['title'] = CryptoUtil::decrypt($row['title']);
            if (!empty($row['description'])) {
                $row['description'] = CryptoUtil::decrypt($row['description']);
            } else {
                $row['description'] = '';
            }
        } catch (RuntimeException $e) {
            // 暗号化エラーやキーの不一致があった場合は、表示をフォールバックする
            error_log("Decryption failed for schedule ID {$row['id']}: " . $e->getMessage());
            $row['title'] = "[Decryption Error]";
            $row['description'] = "[Could not decrypt description]";
        }
        return $row;
    }

    /**
     * 複数の予定の公開/非公開ステータスを一括更新する
     * 
     * @param array $ids 変更対象の予定ID配列
     * @param int $isPublic 公開フラグ (1: 公開, 0: 非公開)
     * @return bool 処理結果
     */
    public function updatePublicStatus(array $ids, int $isPublic): bool {
        if (empty($ids)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE schedules SET is_public = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        
        $params = array_merge([$isPublic], $ids);
        return $stmt->execute($params);
    }
}
