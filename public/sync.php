<?php
/**
 * TaskFlow C++ クライアント同期用 Web API (sync.php)
 * 
 * C++ アプリケーションから HTTPS JSON 通信を介して呼び出され、
 * 双方向同期（データのプル・プッシュ）の受け皿となります。
 */

// 出力はすべて JSON とする
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

// クラスオートローダーの登録
spl_autoload_register(function ($class) {
    $base_dir = __DIR__ . '/../src/';
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use Repository\ScheduleRepository;
use Repository\TimeSlotRepository;
use Repository\WebhookRepository;

// リクエストメソッドは POST のみを許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// JSON リクエストボディの取得
$requestBody = file_get_contents('php://input');
$requestData = json_decode($requestBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

// API トークン認証の検証 (NULL/空トークン汚染防止)
$clientToken = $requestData['token'] ?? '';
if (empty($clientToken) || empty(TRANSCIPHER_API_TOKEN) || $clientToken !== TRANSCIPHER_API_TOKEN) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized. Invalid API token.']);
    exit;
}

$action = $requestData['action'] ?? '';
$db = get_db_connection();

$scheduleRepo = new ScheduleRepository($db);
$timeSlotRepo = new TimeSlotRepository($db);
$webhookRepo = new WebhookRepository($db);

try {
    if ($action === 'pull') {
        // --- プル（MySQL ➔ C++ / SQLite） ---
        // クライアントの最終同期日時を取得
        $lastSyncedAt = $requestData['last_synced_at'] ?? '1970-01-01 00:00:00';

        // 最終同期日時以降に更新されたレコードを暗号化された状態のまま取得
        $schedules = $scheduleRepo->findUpdatedSince($lastSyncedAt);
        $timeSlots = $timeSlotRepo->findUpdatedSince($lastSyncedAt);
        $webhooks = $webhookRepo->findUpdatedSince($lastSyncedAt);

        // 現在のサーバー時刻を取得（次回の同期基準日時とするためC++へ返却する）
        $serverTime = $db->query("SELECT NOW()")->fetchColumn();

        echo json_encode([
            'status' => 'success',
            'server_time' => $serverTime,
            'data' => [
                'schedules' => $schedules,
                'time_slots' => $timeSlots,
                'webhooks' => $webhooks
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } elseif ($action === 'push') {
        // --- プッシュ（C++ / SQLite ➔ MySQL） ---
        $pushData = $requestData['data'] ?? [];
        $pushedSchedules = $pushData['schedules'] ?? [];
        $pushedTimeSlots = $pushData['time_slots'] ?? [];
        $pushedWebhooks = $pushData['webhooks'] ?? [];

        $db->beginTransaction();

        // 1. 予定データの書き込み
        // C++側からは暗号化済みのタイトル・詳細が送られてくるため、
        // PHP側の CryptoUtil での再暗号化を行わず、直接 SQL INSERT を行う。
        $schedSql = "INSERT INTO schedules (
                        id, title, description, type, start_at, end_at, 
                        status, priority, weight, time_slot_id, category, 
                        is_public, discord_webhook_id, created_at, updated_at, is_deleted
                    ) VALUES (
                        :id, :title, :description, :type, :start_at, :end_at, 
                        :status, :priority, :weight, :time_slot_id, :category, 
                        :is_public, :discord_webhook_id, :created_at, :updated_at, :is_deleted
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
                        updated_at = VALUES(updated_at),
                        is_deleted = VALUES(is_deleted)";
        
        $schedStmt = $db->prepare($schedSql);
        foreach ($pushedSchedules as $s) {
            $schedStmt->execute([
                ':id' => $s['id'],
                ':title' => $s['title'], // 暗号化済みの文字列
                ':description' => !empty($s['description']) ? $s['description'] : null, // 暗号化済みの文字列
                ':type' => $s['type'] ?? 'simple',
                ':start_at' => !empty($s['start_at']) ? $s['start_at'] : null,
                ':end_at' => !empty($s['end_at']) ? $s['end_at'] : null,
                ':status' => $s['status'] ?? 'todo',
                ':priority' => (int)($s['priority'] ?? 0),
                ':weight' => (double)($s['weight'] ?? 0.0),
                ':time_slot_id' => !empty($s['time_slot_id']) ? $s['time_slot_id'] : null,
                ':category' => $s['category'] ?? 'general',
                ':is_public' => (int)($s['is_public'] ?? 0),
                ':discord_webhook_id' => !empty($s['discord_webhook_id']) ? $s['discord_webhook_id'] : null,
                ':created_at' => $s['created_at'] ?? date('Y-m-d H:i:s'),
                ':updated_at' => $s['updated_at'] ?? date('Y-m-d H:i:s'),
                ':is_deleted' => (int)($s['is_deleted'] ?? 0)
            ]);
        }

        // 2. 時間枠の書き込み
        $slotSql = "INSERT INTO time_slots (
                        id, name, start_time, end_time, limit_hours, 
                        created_at, updated_at, is_deleted
                    ) VALUES (
                        :id, :name, :start_time, :end_time, :limit_hours, 
                        :created_at, :updated_at, :is_deleted
                    ) ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        start_time = VALUES(start_time),
                        end_time = VALUES(end_time),
                        limit_hours = VALUES(limit_hours),
                        updated_at = VALUES(updated_at),
                        is_deleted = VALUES(is_deleted)";
        
        $slotStmt = $db->prepare($slotSql);
        foreach ($pushedTimeSlots as $t) {
            $slotStmt->execute([
                ':id' => $t['id'],
                ':name' => $t['name'],
                ':start_time' => $t['start_time'],
                ':end_time' => $t['end_time'],
                ':limit_hours' => (double)($t['limit_hours'] ?? 0.0),
                ':created_at' => $t['created_at'] ?? date('Y-m-d H:i:s'),
                ':updated_at' => $t['updated_at'] ?? date('Y-m-d H:i:s'),
                ':is_deleted' => (int)($t['is_deleted'] ?? 0)
            ]);
        }

        // 3. Webhook設定の書き込み
        $whSql = "INSERT INTO webhooks (
                    id, name, url, created_at, updated_at, is_deleted
                ) VALUES (
                    :id, :name, :url, :created_at, :updated_at, :is_deleted
                ) ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    url = VALUES(url),
                    updated_at = VALUES(updated_at),
                    is_deleted = VALUES(is_deleted)";
        
        $whStmt = $db->prepare($whSql);
        foreach ($pushedWebhooks as $w) {
            $whStmt->execute([
                ':id' => $w['id'],
                ':name' => $w['name'],
                ':url' => $w['url'], // 暗号化済みのURL
                ':created_at' => $w['created_at'] ?? date('Y-m-d H:i:s'),
                ':updated_at' => $w['updated_at'] ?? date('Y-m-d H:i:s'),
                ':is_deleted' => (int)($w['is_deleted'] ?? 0)
            ]);
        }

        $db->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Data pushed and merged successfully.'
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } else {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Unknown action. Use pull or push.']);
        exit;
    }

} catch (\Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // セキュリティログに出力し、ユーザーには詳細を伏せてエラーを返す
    error_log("Synchronization API Error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Internal Server Error. Please contact administrator.']);
    exit;
}
