<?php
/**
 * マイルストーンの保存・取得・関連タスク紐付け機能の検証テスト
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

echo "=== Verifying milestone saving, target loading, and relations ===\n";

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
    $taskAId = 'test-task-a-999';
    $taskBId = 'test-task-b-999';
    $ms0Id = 'test-milestone-0-999';
    $ms1Id = 'test-milestone-1-999';
    $ms2Id = 'test-milestone-2-999';

    // クリーニング
    $db->exec("DELETE FROM schedule_milestone_relations WHERE milestone_id IN ('$ms0Id', '$ms1Id', '$ms2Id')");
    $db->exec("DELETE FROM schedule_milestone_relations WHERE target_schedule_id IN ('$taskAId', '$taskBId')");
    $db->exec("DELETE FROM schedules WHERE id IN ('$taskAId', '$taskBId', '$ms0Id', '$ms1Id', '$ms2Id')");

    // テスト用の親タスクの作成
    $taskA = [
        'id' => $taskAId,
        'title' => 'テストタスクA',
        'type' => 'task',
        'start_at' => '2026-06-08 09:00:00',
        'end_at' => '2026-06-08 18:00:00',
        'status' => 'todo',
        'is_public' => 1,
        'is_deleted' => 0
    ];
    $taskB = [
        'id' => $taskBId,
        'title' => 'テストタスクB',
        'type' => 'task',
        'start_at' => '2026-06-09 09:00:00',
        'end_at' => '2026-06-09 18:00:00',
        'status' => 'todo',
        'is_public' => 1,
        'is_deleted' => 0
    ];

    $scheduleRepo->save($taskA);
    $scheduleRepo->save($taskB);

    // 1. 関連タスクなし (0 targets) のマイルストーン
    $ms0 = [
        'id' => $ms0Id,
        'title' => '独立マイルストーン (0関連)',
        'type' => 'milestone',
        'start_at' => '2026-06-08 12:00:00',
        'end_at' => '2026-06-08 12:00:00',
        'is_public' => 1,
        'is_deleted' => 0
    ];
    $saveRes0 = $scheduleRepo->save($ms0, [], [], []);
    assertTest($saveRes0 === true, "Saved milestone with 0 targets");

    // 2. 単一関連タスク (1 target) のマイルストーン
    $ms1 = [
        'id' => $ms1Id,
        'title' => 'タスクA関連マイルストーン (1関連)',
        'type' => 'milestone',
        'start_at' => '2026-06-08 12:00:00',
        'end_at' => '2026-06-08 12:00:00',
        'is_public' => 1,
        'is_deleted' => 0
    ];
    $saveRes1 = $scheduleRepo->save($ms1, [], [], [$taskAId]);
    assertTest($saveRes1 === true, "Saved milestone with 1 target");

    // 3. 複数関連タスク (2 targets) のマイルストーン
    $ms2 = [
        'id' => $ms2Id,
        'title' => 'タスクA&B関連マイルストーン (2関連)',
        'type' => 'milestone',
        'start_at' => '2026-06-09 12:00:00',
        'end_at' => '2026-06-09 12:00:00',
        'is_public' => 1,
        'is_deleted' => 0
    ];
    $saveRes2 = $scheduleRepo->save($ms2, [], [], [$taskAId, $taskBId]);
    assertTest($saveRes2 === true, "Saved milestone with 2 targets");

    // 4. データ取得と関係性の検証
    $allSchedules = $scheduleRepo->findAll();
    $foundMs0 = null;
    $foundMs1 = null;
    $foundMs2 = null;

    foreach ($allSchedules as $s) {
        if ($s['id'] === $ms0Id) $foundMs0 = $s;
        if ($s['id'] === $ms1Id) $foundMs1 = $s;
        if ($s['id'] === $ms2Id) $foundMs2 = $s;
    }

    assertTest($foundMs0 !== null, "Milestone 0 found in repository output");
    assertTest($foundMs1 !== null, "Milestone 1 found in repository output");
    assertTest($foundMs2 !== null, "Milestone 2 found in repository output");

    if ($foundMs0) {
        assertTest(empty($foundMs0['milestone_targets']), "Milestone 0 targets are empty. Actual count: " . count($foundMs0['milestone_targets']));
    }
    if ($foundMs1) {
        assertTest(count($foundMs1['milestone_targets']) === 1, "Milestone 1 targets count is 1");
        assertTest(in_array($taskAId, $foundMs1['milestone_targets']), "Milestone 1 targets contains Task A ID");
    }
    if ($foundMs2) {
        assertTest(count($foundMs2['milestone_targets']) === 2, "Milestone 2 targets count is 2");
        assertTest(in_array($taskAId, $foundMs2['milestone_targets']) && in_array($taskBId, $foundMs2['milestone_targets']), "Milestone 2 targets contains Task A & B IDs");
    }

    // 5. 関連付け変更（更新）の検証
    // M2の関連タスクをBのみに変更する
    $saveResUpdate = $scheduleRepo->save($ms2, [], [], [$taskBId]);
    assertTest($saveResUpdate === true, "Updated milestone M2 with only target B");
    
    $allSchedulesUpdated = $scheduleRepo->findAll();
    $foundMs2Updated = null;
    foreach ($allSchedulesUpdated as $s) {
        if ($s['id'] === $ms2Id) {
            $foundMs2Updated = $s;
            break;
        }
    }
    if ($foundMs2Updated) {
        assertTest(count($foundMs2Updated['milestone_targets']) === 1, "Milestone 2 updated targets count is 1");
        assertTest($foundMs2Updated['milestone_targets'][0] === $taskBId, "Milestone 2 updated target is Task B ID");
    }

    // クリーニング
    $db->exec("DELETE FROM schedule_milestone_relations WHERE milestone_id IN ('$ms0Id', '$ms1Id', '$ms2Id')");
    $db->exec("DELETE FROM schedules WHERE id IN ('$taskAId', '$taskBId', '$ms0Id', '$ms1Id', '$ms2Id')");
    echo "Cleanup complete.\n";

} catch (\Exception $e) {
    echo "\033[31mException caught\033[0m: " . $e->getMessage() . "\n";
    $success = false;
}

if ($success) {
    echo "\n=== ALL TESTS PASSED! ===\n";
    exit(0);
} else {
    echo "\n=== SOME TESTS FAILED! ===\n";
    exit(1);
}
