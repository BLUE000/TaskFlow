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
     * @param array $allocations 日別作業時間配分データの配列
     * @return bool 処理結果（成功時 true、失敗時 false）
     */
    public function save(array $schedule, array $allocations = [], array $progress = [], array $milestoneTargets = []): bool {
        // 必須フィールドの検証
        if (empty($schedule['id']) || empty($schedule['title'])) {
            return false;
        }

        // ステータス自動設定
        if (($schedule['type'] ?? 'simple') === 'task') {
            $totalProgress = 0;
            if (!empty($progress)) {
                foreach ($progress as $date => $pct) {
                    $totalProgress += (int)$pct;
                }
            }
            if ($totalProgress == 0) {
                $schedule['status'] = 'todo';
            } elseif ($totalProgress >= 100) {
                $schedule['status'] = 'done';
            } else {
                $schedule['status'] = 'doing';
            }
        }

        // 暗号化対象フィールドの暗号化
        $encryptedTitle = CryptoUtil::encrypt($schedule['title']);
        $encryptedDesc = null;
        if (isset($schedule['description']) && $schedule['description'] !== '') {
            $encryptedDesc = CryptoUtil::encrypt($schedule['description']);
        }

        try {
            $this->db->beginTransaction();

            $sql = "INSERT INTO schedules (
                        id, title, description, type, start_at, end_at, 
                        status, priority, weight, time_slot_id, category, 
                        is_public, discord_webhook_id, recurrence_group_id, updated_at, is_deleted
                    ) VALUES (
                        :id, :title, :description, :type, :start_at, :end_at, 
                        :status, :priority, :weight, :time_slot_id, :category, 
                        :is_public, :discord_webhook_id, :recurrence_group_id, NOW(), :is_deleted
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
                        recurrence_group_id = VALUES(recurrence_group_id),
                        updated_at = NOW(),
                        is_deleted = VALUES(is_deleted)";

            $stmt = $this->db->prepare($sql);
            $res = $stmt->execute([
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
                ':recurrence_group_id' => !empty($schedule['recurrence_group_id']) ? $schedule['recurrence_group_id'] : null,
                ':is_deleted' => isset($schedule['is_deleted']) ? (int)$schedule['is_deleted'] : 0
            ]);

            if (!$res) {
                $this->db->rollBack();
                return false;
            }

            // 子レコード (schedule_allocations) の保存処理
            $delSql = "DELETE FROM schedule_allocations WHERE schedule_id = :schedule_id";
            $delStmt = $this->db->prepare($delSql);
            $delStmt->execute([':schedule_id' => $schedule['id']]);

            if (!empty($allocations)) {
                $insSql = "INSERT INTO schedule_allocations (
                                id, schedule_id, date, time_slot_id, allocated_hours, created_at, updated_at, is_deleted
                            ) VALUES (
                                :id, :schedule_id, :date, :time_slot_id, :allocated_hours, NOW(), NOW(), 0
                            )";
                $insStmt = $this->db->prepare($insSql);

                foreach ($allocations as $alloc) {
                    $allocId = !empty($alloc['id']) ? $alloc['id'] : $this->generate_uuidv4();
                    $insStmt->execute([
                        ':id' => $allocId,
                        ':schedule_id' => $schedule['id'],
                        ':date' => $alloc['date'],
                        ':time_slot_id' => $alloc['time_slot_id'],
                        ':allocated_hours' => (double)$alloc['allocated_hours']
                    ]);
                }
            }

            // 子レコード (schedule_progress) の保存処理
            $delProgSql = "DELETE FROM schedule_progress WHERE schedule_id = :schedule_id";
            $delProgStmt = $this->db->prepare($delProgSql);
            $delProgStmt->execute([':schedule_id' => $schedule['id']]);

            if (($schedule['type'] ?? 'simple') === 'task') {
                if (!empty($progress)) {
                    $insProgSql = "INSERT INTO schedule_progress (
                                        schedule_id, date, progress_percent, created_at, updated_at
                                    ) VALUES (
                                        :schedule_id, :date, :progress_percent, NOW(), NOW()
                                    )";
                    $insProgStmt = $this->db->prepare($insProgSql);

                    foreach ($progress as $date => $pct) {
                        $insProgStmt->execute([
                            ':schedule_id' => $schedule['id'],
                            ':date' => $date,
                            ':progress_percent' => (int)$pct
                        ]);
                    }
                }
            }

            // 子レコード (schedule_milestone_relations) の保存処理
            $delMilestoneSql = "DELETE FROM schedule_milestone_relations WHERE milestone_id = :milestone_id";
            $delMilestoneStmt = $this->db->prepare($delMilestoneSql);
            $delMilestoneStmt->execute([':milestone_id' => $schedule['id']]);

            if (($schedule['type'] ?? 'simple') === 'milestone') {
                if (!empty($milestoneTargets)) {
                    $insMilestoneSql = "INSERT INTO schedule_milestone_relations (
                                            milestone_id, target_schedule_id
                                        ) VALUES (
                                            :milestone_id, :target_schedule_id
                                        )";
                    $insMilestoneStmt = $this->db->prepare($insMilestoneSql);

                    foreach ($milestoneTargets as $targetId) {
                        if (!empty($targetId)) {
                            $insMilestoneStmt->execute([
                                ':milestone_id' => $schedule['id'],
                                ':target_schedule_id' => $targetId
                            ]);
                        }
                    }
                }
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Save schedule failed: " . $e->getMessage());
            return false;
        }
    }

    private function generate_uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

        // allocations と progress を一括マッピング
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // allocations マッピング
            $allocSql = "SELECT * FROM schedule_allocations WHERE schedule_id IN ($placeholders) AND is_deleted = 0";
            $allocStmt = $this->db->prepare($allocSql);
            $allocStmt->execute($ids);
            $allAllocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $allocMap = [];
            foreach ($allAllocations as $alloc) {
                $allocMap[$alloc['schedule_id']][] = $alloc;
            }
            
            // progress マッピング
            $progSql = "SELECT * FROM schedule_progress WHERE schedule_id IN ($placeholders)";
            $progStmt = $this->db->prepare($progSql);
            $progStmt->execute($ids);
            $allProgress = $progStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $progMap = [];
            foreach ($allProgress as $prog) {
                $progMap[$prog['schedule_id']][] = $prog;
            }

            // milestone targets マッピング
            $relSql = "SELECT * FROM schedule_milestone_relations WHERE milestone_id IN ($placeholders)";
            $relStmt = $this->db->prepare($relSql);
            $relStmt->execute($ids);
            $allRels = $relStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $relMap = [];
            foreach ($allRels as $rel) {
                $relMap[$rel['milestone_id']][] = $rel['target_schedule_id'];
            }
            
            // segments マッピング
            $segSql = "SELECT * FROM schedule_segments WHERE schedule_id IN ($placeholders) AND is_deleted = 0 ORDER BY segment_order ASC";
            $segStmt = $this->db->prepare($segSql);
            $segStmt->execute($ids);
            $allSegments = $segStmt->fetchAll(PDO::FETCH_ASSOC);
            $segMap = [];
            foreach ($allSegments as $seg) {
                $segMap[$seg['schedule_id']][] = $seg;
            }

            foreach ($results as &$row) {
                $row['allocations'] = $allocMap[$row['id']] ?? [];
                $row['progress'] = $progMap[$row['id']] ?? [];
                $row['milestone_targets'] = $relMap[$row['id']] ?? [];
                $row['segments'] = $segMap[$row['id']] ?? [];
            }
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

        // allocations と progress を一括マッピング
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // allocations マッピング
            $allocSql = "SELECT * FROM schedule_allocations WHERE schedule_id IN ($placeholders) AND is_deleted = 0";
            $allocStmt = $this->db->prepare($allocSql);
            $allocStmt->execute($ids);
            $allAllocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $allocMap = [];
            foreach ($allAllocations as $alloc) {
                $allocMap[$alloc['schedule_id']][] = $alloc;
            }
            
            // progress マッピング
            $progSql = "SELECT * FROM schedule_progress WHERE schedule_id IN ($placeholders)";
            $progStmt = $this->db->prepare($progSql);
            $progStmt->execute($ids);
            $allProgress = $progStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $progMap = [];
            foreach ($allProgress as $prog) {
                $progMap[$prog['schedule_id']][] = $prog;
            }

            // milestone targets マッピング
            $relSql = "SELECT * FROM schedule_milestone_relations WHERE milestone_id IN ($placeholders)";
            $relStmt = $this->db->prepare($relSql);
            $relStmt->execute($ids);
            $allRels = $relStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $relMap = [];
            foreach ($allRels as $rel) {
                $relMap[$rel['milestone_id']][] = $rel['target_schedule_id'];
            }
            
            // segments マッピング
            $segSql2 = "SELECT * FROM schedule_segments WHERE schedule_id IN ($placeholders) AND is_deleted = 0 ORDER BY segment_order ASC";
            $segStmt2 = $this->db->prepare($segSql2);
            $segStmt2->execute($ids);
            $allSegments2 = $segStmt2->fetchAll(PDO::FETCH_ASSOC);
            $segMap2 = [];
            foreach ($allSegments2 as $seg) {
                $segMap2[$seg['schedule_id']][] = $seg;
            }

            foreach ($results as &$row) {
                $row['allocations'] = $allocMap[$row['id']] ?? [];
                $row['progress'] = $progMap[$row['id']] ?? [];
                $row['milestone_targets'] = $relMap[$row['id']] ?? [];
                $row['segments'] = $segMap2[$row['id']] ?? [];
            }
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
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // allocations と progress を一括マッピング
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // allocations マッピング
            $allocSql = "SELECT * FROM schedule_allocations WHERE schedule_id IN ($placeholders) AND is_deleted = 0";
            $allocStmt = $this->db->prepare($allocSql);
            $allocStmt->execute($ids);
            $allAllocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $allocMap = [];
            foreach ($allAllocations as $alloc) {
                $allocMap[$alloc['schedule_id']][] = $alloc;
            }
            
            // progress マッピング
            $progSql = "SELECT * FROM schedule_progress WHERE schedule_id IN ($placeholders)";
            $progStmt = $this->db->prepare($progSql);
            $progStmt->execute($ids);
            $allProgress = $progStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $progMap = [];
            foreach ($allProgress as $prog) {
                $progMap[$prog['schedule_id']][] = $prog;
            }

            // milestone targets マッピング
            $relSql = "SELECT * FROM schedule_milestone_relations WHERE milestone_id IN ($placeholders)";
            $relStmt = $this->db->prepare($relSql);
            $relStmt->execute($ids);
            $allRels = $relStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $relMap = [];
            foreach ($allRels as $rel) {
                $relMap[$rel['milestone_id']][] = $rel['target_schedule_id'];
            }
            
            foreach ($results as &$row) {
                $row['allocations'] = $allocMap[$row['id']] ?? [];
                $row['progress'] = $progMap[$row['id']] ?? [];
                $row['milestone_targets'] = $relMap[$row['id']] ?? [];
            }
        }

        return $results;
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

    /**
     * 指定された recurrence_group_id に紐づくすべての予定を一括で論理削除する
     * 
     * @param string $recurrenceGroupId グループID
     * @return bool 処理結果
     */
    public function deleteSeries(string $recurrenceGroupId): bool {
        if (empty($recurrenceGroupId)) {
            return false;
        }

        $sql = "UPDATE schedules SET is_deleted = 1, updated_at = NOW() WHERE recurrence_group_id = :recurrence_group_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':recurrence_group_id' => $recurrenceGroupId]);
    }

    /**
     * 指定された recurrence_group_id に紐づくすべての予定の共通項目を一括更新する
     * （開始・終了日時は各コピー予定の固有の値を維持するため更新対象外）
     * 
     * @param string $recurrenceGroupId グループID
     * @param array $data 更新データ
     * @return bool 処理結果
     */
    public function updateSeries(string $recurrenceGroupId, array $data): bool {
        if (empty($recurrenceGroupId)) {
            return false;
        }

        // 暗号化対象フィールドの暗号化
        $encryptedTitle = CryptoUtil::encrypt($data['title']);
        $encryptedDesc = null;
        if (isset($data['description']) && $data['description'] !== '') {
            $encryptedDesc = CryptoUtil::encrypt($data['description']);
        }

        $sql = "UPDATE schedules SET 
                    title = :title,
                    description = :description,
                    category = :category,
                    priority = :priority,
                    weight = :weight,
                    time_slot_id = :time_slot_id,
                    is_public = :is_public,
                    discord_webhook_id = :discord_webhook_id,
                    updated_at = NOW()
                WHERE recurrence_group_id = :recurrence_group_id AND is_deleted = 0";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':title' => $encryptedTitle,
            ':description' => $encryptedDesc,
            ':category' => $data['category'] ?? 'general',
            ':priority' => isset($data['priority']) ? (int)$data['priority'] : 0,
            ':weight' => isset($data['weight']) ? (double)$data['weight'] : 0.0,
            ':time_slot_id' => !empty($data['time_slot_id']) ? $data['time_slot_id'] : null,
            ':is_public' => isset($data['is_public']) ? (int)$data['is_public'] : 0,
            ':discord_webhook_id' => !empty($data['discord_webhook_id']) ? $data['discord_webhook_id'] : null,
            ':recurrence_group_id' => $recurrenceGroupId
        ]);
    }

    /**
     * セグメントを保存する（既存セグメントを論理削除後、再挿入）
     * 全セグメント保存後、schedules.start_at/end_at を自動更新する
     *
     * @param string $scheduleId 対象予定のID
     * @param array $segments セグメントデータ配列 [{start_at, end_at}, ...]（segment_orderは自動付与）
     * @return bool 処理結果
     */
    public function saveSegments(string $scheduleId, array $segments): bool {
        try {
            $this->db->beginTransaction();

            // 既存セグメントを論理削除
            $delSql = "UPDATE schedule_segments SET is_deleted = 1, updated_at = NOW() WHERE schedule_id = :schedule_id AND is_deleted = 0";
            $delStmt = $this->db->prepare($delSql);
            $delStmt->execute([':schedule_id' => $scheduleId]);

            if (!empty($segments)) {
                $insSql = "INSERT INTO schedule_segments (id, schedule_id, segment_order, start_at, end_at, created_at, updated_at, is_deleted)
                           VALUES (:id, :schedule_id, :segment_order, :start_at, :end_at, NOW(), NOW(), 0)";
                $insStmt = $this->db->prepare($insSql);

                $order = 1;
                foreach ($segments as $seg) {
                    $insStmt->execute([
                        ':id'            => $this->generate_uuidv4(),
                        ':schedule_id'   => $scheduleId,
                        ':segment_order' => $order++,
                        ':start_at'      => $seg['start_at'],
                        ':end_at'        => $seg['end_at'],
                    ]);
                }

                // schedules.start_at / end_at を自動更新（最初/最後のセグメント）
                $firstStart = $segments[0]['start_at'];
                $lastEnd    = end($segments)['end_at'];
                $updSql = "UPDATE schedules SET start_at = :start_at, end_at = :end_at, updated_at = NOW() WHERE id = :id";
                $updStmt = $this->db->prepare($updSql);
                $updStmt->execute([
                    ':start_at' => $firstStart,
                    ':end_at'   => $lastEnd,
                    ':id'       => $scheduleId,
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("saveSegments failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 指定された予定IDのセグメントリストを取得する
     *
     * @param string $scheduleId 予定ID
     * @return array セグメントデータの配列（segment_order昇順）
     */
    public function findSegmentsByScheduleId(string $scheduleId): array {
        $sql = "SELECT * FROM schedule_segments WHERE schedule_id = :schedule_id AND is_deleted = 0 ORDER BY segment_order ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':schedule_id' => $scheduleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
