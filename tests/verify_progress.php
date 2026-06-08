<?php
/**
 * schedule_progress 保存・取得・自動ステータス判定・同期機能の検証テスト
 */

require_once __DIR__ . '/../config/config.php';

// オートローダー
spl_autoload_register(function ($class) {
    $base_dir = __DIR__ . '/../src/';
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use Common\Security\CryptoUtil;
use Repository\ScheduleRepository;

CryptoUtil::init(TRANSCIPHER_SECRET_KEY, TRANSCIPHER_API_URL, TRANSCIPHER_API_TOKEN);

$db = get_db_connection();
$scheduleRepo = new ScheduleRepository($db);

echo "=== Verifying schedule_progress & Auto Status ===\n";

$success = true;

function assertTest($condition, $message) {
    global $success;
    if ($condition) {
        echo "PASS: $message\n";
    } else {
        echo "\033[31mFAIL\033[0m: $message\n";
        $success = false;
    }
}

try {
    $scheduleId = 'sched-progress-test-999';

    // クリーニング
    $db->exec("DELETE FROM schedule_progress WHERE schedule_id = '$scheduleId'");
    $db->exec("DELETE FROM schedule_allocations WHERE schedule_id = '$scheduleId'");
    $db->exec("DELETE FROM schedules WHERE id = '$scheduleId'");

    $schedule = [
        'id' => $scheduleId,
        'title' => '進捗テストタスク',
        'description' => '進捗とステータス自動連動のテスト予定です。',
        'type' => 'task',
        'start_at' => '2026-06-08 09:00:00',
        'end_at' => '2026-06-10 18:00:00',
        'status' => 'todo', // 最初は todo だが、進捗率合計で自動上書きされるはず
        'weight' => 3.0,
        'time_slot_id' => null,
        'category' => 'work',
        'is_public' => 1,
        'is_deleted' => 0
    ];

    // 1. 進捗率 0% のテスト -> todo
    $progress0 = [
        '2026-06-08' => 0,
        '2026-06-09' => 0,
        '2026-06-10' => 0
    ];
    $saveRes = $scheduleRepo->save($schedule, [], $progress0);
    assertTest($saveRes === true, "ScheduleRepository::save() with 0% progress.");
    
    $saved = $scheduleRepo->findAll();
    $target = null;
    foreach ($saved as $s) {
        if ($s['id'] === $scheduleId) {
            $target = $s;
            break;
        }
    }
    assertTest($target !== null, "Found saved schedule.");
    assertTest($target['status'] === 'todo', "Status is 'todo' when progress sum is 0%. Actual: {$target['status']}");
    assertTest(count($target['progress']) === 3, "Contains 3 progress entries.");

    // 2. 進捗率 50% のテスト -> doing
    $progress50 = [
        '2026-06-08' => 30,
        '2026-06-09' => 20
    ];
    $scheduleRepo->save($schedule, [], $progress50);
    $saved = $scheduleRepo->findAll();
    foreach ($saved as $s) {
        if ($s['id'] === $scheduleId) {
            $target = $s;
            break;
        }
    }
    assertTest($target['status'] === 'doing', "Status is 'doing' when progress sum is 50%. Actual: {$target['status']}");

    // 3. 進捗率 100% のテスト -> done
    $progress100 = [
        '2026-06-08' => 30,
        '2026-06-09' => 50,
        '2026-06-10' => 20
    ];
    $scheduleRepo->save($schedule, [], $progress100);
    $saved = $scheduleRepo->findAll();
    foreach ($saved as $s) {
        if ($s['id'] === $scheduleId) {
            $target = $s;
            break;
        }
    }
    assertTest($target['status'] === 'done', "Status is 'done' when progress sum is 100%. Actual: {$target['status']}");

    // 3.5. 計画期間外（予定日前・予定日後）の進捗保存テスト
    $progressOutBounds = [
        '2026-06-07' => 10,  // 早期着手 (予定は 06-08 から)
        '2026-06-08' => 40,
        '2026-06-09' => 40,
        '2026-06-11' => 10   // 期間超過 (予定は 06-10 まで)
    ];
    $scheduleRepo->save($schedule, [], $progressOutBounds);
    $saved = $scheduleRepo->findAll();
    foreach ($saved as $s) {
        if ($s['id'] === $scheduleId) {
            $target = $s;
            break;
        }
    }
    assertTest($target['status'] === 'done', "Status is 'done' when out-of-bounds progress sum is 100%. Actual: {$target['status']}");
    assertTest(count($target['progress']) === 4, "Progress contains 4 entries (including out-of-bounds). Actual: " . count($target['progress']));
    
    $dates = array_column($target['progress'], 'date');
    sort($dates);
    assertTest($dates[0] === '2026-06-07' && $dates[3] === '2026-06-11', "Earliest progress date is 2026-06-07 and latest is 2026-06-11. Actual: " . json_encode($dates));

    // 4. 同期 API プッシュ時のステータス自動補正テスト
    // 進捗率 50% の状態にする
    $scheduleRepo->save($schedule, [], $progress50);
    
    // C++ クライアントが status='todo' としてプッシュしてきた場合でも、
    // サーバー上の進捗合計（50%）を元に 'doing' に補正されるか検証
    $targetUrl = "http://localhost:8000/sync.php";
    $apiToken = "tc_f2d6ccf601dd459b515215fd";
    
    $encTitle = CryptoUtil::encrypt('進捗テストタスク');
    $encDesc = CryptoUtil::encrypt('同期時の自動補正テスト');

    $pushPayload = [
        'action' => 'push',
        'token' => $apiToken,
        'data' => [
            'schedules' => [
                [
                    'id' => $scheduleId,
                    'title' => $encTitle,
                    'description' => $encDesc,
                    'type' => 'task',
                    'start_at' => '2026-06-08 09:00:00',
                    'end_at' => '2026-06-10 18:00:00',
                    'status' => 'todo', // クライアントは todo を送信
                    'priority' => 1,
                    'weight' => 3.0,
                    'time_slot_id' => null,
                    'category' => 'work',
                    'is_public' => 1,
                    'discord_webhook_id' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'is_deleted' => 0,
                    'allocations' => []
                ]
            ],
            'time_slots' => [],
            'webhooks' => []
        ]
    ];

    $ch = curl_init($targetUrl);
    $jsonData = json_encode($pushPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    assertTest($httpCode === 200, "Push request returned 200 OK.");
    
    // DB 上でステータスが 'doing' に補正されているか確認
    $pushedStatus = $db->query("SELECT status FROM schedules WHERE id = '$scheduleId'")->fetchColumn();
    assertTest($pushedStatus === 'doing', "Recalculated status on push is 'doing'. Actual: $pushedStatus");

    // クリーニング
    $db->exec("DELETE FROM schedule_progress WHERE schedule_id = '$scheduleId'");
    $db->exec("DELETE FROM schedule_allocations WHERE schedule_id = '$scheduleId'");
    $db->exec("DELETE FROM schedules WHERE id = '$scheduleId'");

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    $success = false;
}

if ($success) {
    echo "\nAll progress tests PASSED!\n";
    exit(0);
} else {
    echo "\nProgress tests FAILED.\n";
    exit(1);
}
