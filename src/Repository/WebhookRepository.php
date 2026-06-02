<?php
namespace Repository;

use PDO;
use Common\Security\CryptoUtil;
use RuntimeException;

/**
 * WebhookRepository - webhooks テーブルに対するデータアクセスを担当するリポジトリクラス
 */
class WebhookRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Webhook設定データを保存（挿入または重複キー時に更新）する
     * 
     * @param array $webhook 保存対象の連想配列データ
     * @return bool 処理結果
     */
    public function save(array $webhook): bool {
        if (empty($webhook['id']) || empty($webhook['name']) || empty($webhook['url'])) {
            return false;
        }

        // Webhook URL は TransCipher で暗号化
        $encryptedUrl = CryptoUtil::encrypt($webhook['url']);

        $sql = "INSERT INTO webhooks (
                    id, name, url, created_at, updated_at, is_deleted
                ) VALUES (
                    :id, :name, :url, NOW(), NOW(), :is_deleted
                ) ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    url = VALUES(url),
                    updated_at = NOW(),
                    is_deleted = VALUES(is_deleted)";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':id' => $webhook['id'],
            ':name' => $webhook['name'],
            ':url' => $encryptedUrl,
            ':is_deleted' => isset($webhook['is_deleted']) ? (int)$webhook['is_deleted'] : 0
        ]);
    }

    /**
     * 有効な（削除されていない）Webhook設定をすべて取得し、復号して返却する
     * 
     * @return array Webhook設定データの配列
     */
    public function findAllActive(): array {
        $sql = "SELECT * FROM webhooks WHERE is_deleted = 0 ORDER BY name ASC";
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $row) {
            try {
                $row['url'] = CryptoUtil::decrypt($row['url']);
            } catch (RuntimeException $e) {
                error_log("Decryption failed for webhook ID {$row['id']}: " . $e->getMessage());
                $row['url'] = "[Decryption Error]";
            }
            $results[] = $row;
        }

        return $results;
    }

    /**
     * 指定日時以降に更新されたレコードを暗号化された生の状態で全取得する (同期API用)
     * 
     * @param string $lastSyncedAt 最終同期日時
     * @return array Webhookデータの配列
     */
    public function findUpdatedSince(string $lastSyncedAt): array {
        $sql = "SELECT * FROM webhooks WHERE updated_at > :last_synced_at";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':last_synced_at' => $lastSyncedAt]);
        return $stmt->fetchAll();
    }
}
