<?php
/**
 * テスト用シードデータ登録スクリプト
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
use Repository\TimeSlotRepository;

// TransCipher 初期化
CryptoUtil::init(TRANSCIPHER_SECRET_KEY, TRANSCIPHER_API_URL, TRANSCIPHER_API_TOKEN);

$db = get_db_connection();
$scheduleRepo = new ScheduleRepository($db);
$timeSlotRepo = new TimeSlotRepository($db);

echo "=== Seeding Database with Test Data ===\n";

try {
    // 既存データの全クリーンアップ (検証をクリアにするため)
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE schedules;");
    $db->exec("TRUNCATE TABLE time_slots;");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "Cleared old database records.\n";

    // 1. 時間枠の登録
    $timeSlotsData = [
        [
            'id' => 'slot-am-111',
            'name' => '午前枠',
            'start_time' => '10:00:00',
            'end_time' => '13:00:00',
            'limit_hours' => 3.0,
            'is_deleted' => 0
        ],
        [
            'id' => 'slot-pm-222',
            'name' => '午後枠',
            'start_time' => '13:00:00',
            'end_time' => '22:00:00',
            'limit_hours' => 9.0,
            'is_deleted' => 0
        ]
    ];

    foreach ($timeSlotsData as $slot) {
        $res = $timeSlotRepo->save($slot);
        echo "Saved TimeSlot: {$slot['name']} -> " . ($res ? "OK" : "FAIL") . "\n";
    }

    // 2. 予定・タスクの登録
    $schedulesData = [
        // ST-LOAD-01 用: 午前枠に計4.0時間のタスク（制限3.0時間）
        [
            'id' => 'sched-task-a',
            'title' => '会議資料準備 (タスクA)',
            'description' => '午前枠に割り当てられるタスクAのメモです。',
            'type' => 'task',
            'start_at' => '2026-06-10 10:00:00',
            'end_at' => '2026-06-10 12:00:00',
            'status' => 'todo',
            'priority' => 1,
            'weight' => 2.0,
            'time_slot_id' => 'slot-am-111',
            'category' => 'work',
            'is_public' => 1,
            'discord_webhook_id' => null,
            'is_deleted' => 0
        ],
        [
            'id' => 'sched-task-b',
            'title' => '要件確認ミーティング (タスクB)',
            'description' => '午前枠に割り当てられるタスクBのメモです。',
            'type' => 'task',
            'start_at' => '2026-06-10 11:00:00',
            'end_at' => '2026-06-10 13:00:00',
            'status' => 'doing', // 進行中
            'priority' => 2,
            'weight' => 2.0,
            'time_slot_id' => 'slot-am-111',
            'category' => 'work',
            'is_public' => 1,
            'discord_webhook_id' => null,
            'is_deleted' => 0
        ],
        // ST-VIEW-01 用: 非公開タスクC (一般画面に表示されないこと)
        [
            'id' => 'sched-task-c',
            'title' => '秘密の個人用タスク (タスクC)',
            'description' => 'このタスクは非公開に設定されているため、ログインしていない第三者は見られません。',
            'type' => 'task',
            'start_at' => '2026-06-10 14:00:00',
            'end_at' => '2026-06-10 18:00:00',
            'status' => 'todo',
            'priority' => 0,
            'weight' => 4.0,
            'time_slot_id' => 'slot-pm-222',
            'category' => 'private',
            'is_public' => 0, // 非公開
            'discord_webhook_id' => null,
            'is_deleted' => 0
        ],
        // ST-LOAD-02 用: 午後枠に10.0時間の完了タスク（制限9.0時間だが、完了につき除外され警告は出ない）
        [
            'id' => 'sched-task-d',
            'title' => '大型システム開発 (タスクD)',
            'description' => 'すでに完了した重たいタスクです。負荷計算から除外されるべきです。',
            'type' => 'task',
            'start_at' => '2026-06-11 13:00:00',
            'end_at' => '2026-06-11 23:00:00',
            'status' => 'done', // 完了
            'priority' => 3,
            'weight' => 10.0,
            'time_slot_id' => 'slot-pm-222',
            'category' => 'work',
            'is_public' => 1,
            'discord_webhook_id' => null,
            'is_deleted' => 0
        ],
        // 期間予定E: 複数日にまたがる予定
        [
            'id' => 'sched-period-e',
            'title' => '地方出張・現地サポート (予定E)',
            'description' => '4日間の出張予定です。ガントチャートでバーが正しく伸びることを検証します。',
            'type' => 'period',
            'start_at' => '2026-06-12 09:00:00',
            'end_at' => '2026-06-15 18:00:00',
            'status' => 'todo',
            'priority' => 0,
            'weight' => 0.0,
            'time_slot_id' => null,
            'category' => 'work',
            'is_public' => 1,
            'discord_webhook_id' => null,
            'is_deleted' => 0
        ],
        // 簡易予定F: 日時のみ
        [
            'id' => 'sched-simple-f',
            'title' => '定期通院',
            'description' => '定期検診のための通院。',
            'type' => 'simple',
            'start_at' => '2026-06-20 15:00:00',
            'end_at' => null,
            'status' => 'todo',
            'priority' => 0,
            'weight' => 0.0,
            'time_slot_id' => null,
            'category' => 'private',
            'is_public' => 1,
            'discord_webhook_id' => null,
            'is_deleted' => 0
        ]
    ];

    foreach ($schedulesData as $sched) {
        $res = $scheduleRepo->save($sched);
        echo "Saved Schedule: {$sched['title']} -> " . ($res ? "OK" : "FAIL") . "\n";
    }

    echo "Seeding completed successfully.\n";

} catch (Exception $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
}
