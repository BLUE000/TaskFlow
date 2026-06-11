<?php
/**
 * 先行タスク（依存関係）機能の保存・取得・リポジトリマッピング検証テスト（SQLiteインメモリ版・直接SQL）
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

echo "=== Verifying predecessor saving, retrieving, and relations (SQLite in-memory) ===\n";

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
    // SQLite インメモリ接続を作成
    $db = new PDO("sqlite::memory:");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // テスト用のテーブルを作成
    $db->exec("CREATE TABLE schedules (
      id            VARCHAR(36)  NOT NULL,
      title         VARCHAR(255) NOT NULL,
      description   TEXT,
      type          VARCHAR(20)  NOT NULL DEFAULT 'simple',
      start_at      DATETIME,
      end_at        DATETIME,
      status        VARCHAR(20)  DEFAULT 'todo',
      priority      INTEGER      DEFAULT 0,
      weight        DOUBLE       DEFAULT 0.0,
      time_slot_id  VARCHAR(36),
      category      VARCHAR(50)  DEFAULT 'general',
      is_public     INTEGER      DEFAULT 0,
      discord_webhook_id VARCHAR(36),
      recurrence_group_id VARCHAR(36),
      created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
      updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
      is_deleted    INTEGER      DEFAULT 0,
      PRIMARY KEY (id)
    )");

    $db->exec("CREATE TABLE schedule_segments (
      id            VARCHAR(36)  NOT NULL,
      schedule_id   VARCHAR(36)  NOT NULL,
      segment_order INTEGER      NOT NULL,
      start_at      DATETIME     NOT NULL,
      end_at        DATETIME     NOT NULL,
      created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
      updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
      is_deleted    INTEGER      DEFAULT 0,
      PRIMARY KEY (id)
    )");

    $db->exec("CREATE TABLE schedule_predecessors (
      id            VARCHAR(36)  NOT NULL,
      schedule_id   VARCHAR(36)  NOT NULL,
      predecessor_id VARCHAR(36) NOT NULL,
      PRIMARY KEY (id),
      UNIQUE (schedule_id, predecessor_id)
    )");

    $db->exec("CREATE TABLE schedule_milestone_relations (
      milestone_id VARCHAR(36) NOT NULL,
      target_schedule_id VARCHAR(36) NOT NULL,
      PRIMARY KEY (milestone_id, target_schedule_id)
    )");

    $db->exec("CREATE TABLE schedule_allocations (
      id VARCHAR(36) NOT NULL,
      schedule_id VARCHAR(36) NOT NULL,
      date DATE NOT NULL,
      time_slot_id VARCHAR(36) NOT NULL,
      allocated_hours DOUBLE NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      is_deleted INTEGER DEFAULT 0,
      PRIMARY KEY (id)
    )");

    $db->exec("CREATE TABLE schedule_progress (
      schedule_id VARCHAR(36) NOT NULL,
      date DATE NOT NULL,
      progress_percent INTEGER NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (schedule_id, date)
    )");

    $scheduleRepo = new ScheduleRepository($db);

    $taskAId = 'test-pred-task-a-999';
    $taskBId = 'test-pred-task-b-999';

    // テストタスクを直接インサート (MySQL固有の ON DUPLICATE KEY UPDATE 構文エラーを避けるため)
    $encryptedTitleA = CryptoUtil::encrypt('先行タスクA');
    $encryptedTitleB = CryptoUtil::encrypt('後続タスクB');

    $db->prepare("INSERT INTO schedules (id, title, type, start_at, end_at, status, is_public, is_deleted)
                  VALUES (?, ?, 'task', '2026-06-08 09:00:00', '2026-06-08 18:00:00', 'todo', 1, 0)")
       ->execute([$taskAId, $encryptedTitleA]);

    $db->prepare("INSERT INTO schedules (id, title, type, start_at, end_at, status, is_public, is_deleted)
                  VALUES (?, ?, 'task', '2026-06-09 09:00:00', '2026-06-09 18:00:00', 'todo', 1, 0)")
       ->execute([$taskBId, $encryptedTitleB]);

    // 1. 先行タスクを保存する
    $saveRes = $scheduleRepo->savePredecessors($taskBId, [$taskAId]);
    assertTest($saveRes === true, "Saved predecessor Task A for Task B");

    // 2. 先行タスクの取得
    $preds = $scheduleRepo->findPredecessorsByScheduleId($taskBId);
    assertTest(count($preds) === 1, "Task B has exactly 1 predecessor");
    if (count($preds) === 1) {
        assertTest($preds[0]['predecessor_id'] === $taskAId, "The predecessor is Task A");
        assertTest($preds[0]['title'] === '先行タスクA', "The predecessor title matches (and decryption works)");
    }

    // 3. 後続タスクの取得
    $succs = $scheduleRepo->findSuccessorsByScheduleId($taskAId);
    assertTest(count($succs) === 1, "Task A has exactly 1 successor");
    if (count($succs) === 1) {
        assertTest($succs[0]['schedule_id'] === $taskBId, "The successor is Task B");
        assertTest($succs[0]['title'] === '後続タスクB', "The successor title matches");
    }

    // 4. findAll() における predecessors マッピングの検証
    $all = $scheduleRepo->findAll();
    $foundB = null;
    foreach ($all as $s) {
        if ($s['id'] === $taskBId) {
            $foundB = $s;
            break;
        }
    }
    assertTest($foundB !== null, "Task B found in findAll output");
    if ($foundB) {
        assertTest(isset($foundB['predecessors']), "predecessors key exists in Task B");
        assertTest(in_array($taskAId, $foundB['predecessors']), "Task B predecessors contains Task A ID");
    }

    // 5. 更新（削除）の検証
    $saveRes2 = $scheduleRepo->savePredecessors($taskBId, []);
    assertTest($saveRes2 === true, "Removed predecessors for Task B");
    $preds2 = $scheduleRepo->findPredecessorsByScheduleId($taskBId);
    assertTest(count($preds2) === 0, "Task B now has 0 predecessors");

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
