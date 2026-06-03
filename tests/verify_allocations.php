<?php
/**
 * schedule_allocations 保存・取得・同期機能の検証テスト
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

echo "=== Verifying schedule_allocations CRUD & API Sync ===\n";

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
    // 1. レポジトリ保存・取得テスト
    $scheduleId = 'sched-alloc-test-123';
    
    // クリーニング
    $db->exec("DELETE FROM schedule_allocations WHERE schedule_id = '$scheduleId'");
    $db->exec("DELETE FROM schedules WHERE id = '$scheduleId'");

    $schedule = [
        'id' => $scheduleId,
        'title' => 'アロケーション検証タスク',
        'description' => '日別作業時間配分のテスト予定です。',
        'type' => 'task',
        'start_at' => '2026-06-10 09:00:00',
        'end_at' => '2026-06-12 18:00:00',
        'status' => 'doing',
        'weight' => 6.0,
        'time_slot_id' => 'slot-am-111',
        'category' => 'work',
        'is_public' => 1,
        'is_deleted' => 0
    ];

    $allocations = [
        [
            'id' => 'alloc-test-aaa',
            'date' => '2026-06-10',
            'time_slot_id' => 'slot-am-111',
            'allocated_hours' => 2.0
        ],
        [
            'id' => 'alloc-test-bbb',
            'date' => '2026-06-11',
            'time_slot_id' => 'slot-am-111',
            'allocated_hours' => 3.0
        ],
        [
            'id' => 'alloc-test-ccc',
            'date' => '2026-06-12',
            'time_slot_id' => 'slot-am-111',
            'allocated_hours' => 1.0
        ]
    ];

    // 保存
    $saveRes = $scheduleRepo->save($schedule, $allocations);
    assertTest($saveRes === true, "ScheduleRepository::save() returned true with allocations.");

    // 取得
    $allScheds = $scheduleRepo->findAll();
    $savedSched = null;
    foreach ($allScheds as $s) {
        if ($s['id'] === $scheduleId) {
            $savedSched = $s;
            break;
        }
    }

    assertTest($savedSched !== null, "Found saved schedule in findAll().");
    assertTest(isset($savedSched['allocations']) && count($savedSched['allocations']) === 3, "Loaded schedule contains 3 allocations.");
    
    if ($savedSched && !empty($savedSched['allocations'])) {
        $totalHours = array_sum(array_column($savedSched['allocations'], 'allocated_hours'));
        assertTest($totalHours === 6.0, "Sum of allocated hours is 6.0 (matched expectations).");
        
        $dates = array_column($savedSched['allocations'], 'date');
        assertTest(in_array('2026-06-10', $dates) && in_array('2026-06-11', $dates) && in_array('2026-06-12', $dates), "Allocations correspond to correct dates.");
    }

    // 2. 同期 API プッシュ＆プル検証
    $targetUrl = "http://localhost:8888/sync.php";
    $apiToken = "tc_f2d6ccf601dd459b515215fd";

    $syncScheduleId = 'sched-sync-test-456';
    $db->exec("DELETE FROM schedule_allocations WHERE schedule_id = '$syncScheduleId'");
    $db->exec("DELETE FROM schedules WHERE id = '$syncScheduleId'");

    // 暗号化済みの文字列として送信するため、タイトルをダミー暗号化
    $encTitle = CryptoUtil::encrypt('同期検証タスク');
    $encDesc = CryptoUtil::encrypt('同期API経由でアロケーションが同期されるかテスト。');

    $pushPayload = [
        'action' => 'push',
        'token' => $apiToken,
        'data' => [
            'schedules' => [
                [
                    'id' => $syncScheduleId,
                    'title' => $encTitle,
                    'description' => $encDesc,
                    'type' => 'task',
                    'start_at' => '2026-06-15 09:00:00',
                    'end_at' => '2026-06-16 18:00:00',
                    'status' => 'todo',
                    'priority' => 1,
                    'weight' => 5.0,
                    'time_slot_id' => 'slot-pm-222',
                    'category' => 'work',
                    'is_public' => 1,
                    'discord_webhook_id' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'is_deleted' => 0,
                    'allocations' => [
                        [
                            'id' => 'alloc-sync-111',
                            'date' => '2026-06-15',
                            'time_slot_id' => 'slot-pm-222',
                            'allocated_hours' => 2.0
                        ],
                        [
                            'id' => 'alloc-sync-222',
                            'date' => '2026-06-16',
                            'time_slot_id' => 'slot-pm-222',
                            'allocated_hours' => 3.0
                        ]
                    ]
                ]
            ],
            'time_slots' => [],
            'webhooks' => []
        ]
    ];

    // PUSH API の実行
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

    assertTest($httpCode === 200, "Push request to sync.php returned 200 OK.");
    
    // DB確認
    $pushedAllocs = $db->query("SELECT * FROM schedule_allocations WHERE schedule_id = '$syncScheduleId'")->fetchAll(PDO::FETCH_ASSOC);
    assertTest(count($pushedAllocs) === 2, "MySQL DB contains 2 schedule_allocations after push.");

    // PULL API の実行
    $pullPayload = [
        'action' => 'pull',
        'token' => $apiToken,
        'last_synced_at' => date('Y-m-d H:i:s', time() - 3600) // 1時間前
    ];

    $ch = curl_init($targetUrl);
    $jsonData = json_encode($pullPayload);
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

    assertTest($httpCode === 200, "Pull request to sync.php returned 200 OK.");
    $pullData = json_decode($response, true);
    
    $syncSchedFromPull = null;
    if ($pullData && isset($pullData['data']['schedules'])) {
        foreach ($pullData['data']['schedules'] as $s) {
            if ($s['id'] === $syncScheduleId) {
                $syncSchedFromPull = $s;
                break;
            }
        }
    }

    assertTest($syncSchedFromPull !== null, "Pushed schedule was successfully retrieved in pull API.");
    assertTest(isset($syncSchedFromPull['allocations']) && count($syncSchedFromPull['allocations']) === 2, "Pulled schedule contains nested allocations array with 2 elements.");

    // クリーニング
    $db->exec("DELETE FROM schedule_allocations WHERE schedule_id = '$scheduleId'");
    $db->exec("DELETE FROM schedules WHERE id = '$scheduleId'");
    $db->exec("DELETE FROM schedule_allocations WHERE schedule_id = '$syncScheduleId'");
    $db->exec("DELETE FROM schedules WHERE id = '$syncScheduleId'");

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    $success = false;
}

if ($success) {
    echo "\nAll allocation tests PASSED!\n";
    exit(0);
} else {
    echo "\nAllocation tests FAILED.\n";
    exit(1);
}
