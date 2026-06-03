<?php
/**
 * admin.php 繰り返し予定機能の動作検証スクリプト (HTTP経由の結合テスト)
 */

echo "=== Verifying admin.php Recurring Schedules implementation ===\n";

$targetUrl = "http://localhost:8888/admin.php";
$cookieFile = __DIR__ . '/../data/cookie.txt';

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

require_once __DIR__ . '/../config/config.php';
try {
    $db = get_db_connection();
} catch (Exception $e) {
    echo "MySQL connection failed. Ensure MySQL is running.\n";
    exit(1);
}

$success = true;

function sendGet($url, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function sendPost($url, $fields, $cookieFile, &$httpCode = 0) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $response;
}

// 1. ログイン処理
$html = sendGet($targetUrl, $cookieFile);
$csrfToken = '';
if (preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $matches)) {
    $csrfToken = $matches[1];
}

if (empty($csrfToken)) {
    echo "[ERROR] Failed to extract CSRF token.\n";
    exit(1);
}

$postData = [
    'form_action' => 'login',
    'csrf_token' => $csrfToken,
    'password' => 'admin123'
];
$html = sendPost($targetUrl, $postData, $cookieFile);

if (strpos($html, "予定・タスク管理") !== false) {
    echo "[ST-REC-01] PASS: Logged in successfully.\n";
} else {
    echo "[ST-REC-01] \033[31mFAIL\033[0m: Login failed.\n";
    exit(1);
}

// CSRFトークンを再取得（ログイン後の画面から）
$csrfToken = '';
if (preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $matches)) {
    $csrfToken = $matches[1];
}

// 2. 繰り返し予定（毎週同じ曜日、同月月末まで）の登録テスト
// 2026年6月4日(木) から開始する予定を登録。同月の木曜日は 6/4, 6/11, 6/18, 6/25 (計4回)
$scheduleData = [
    'form_action' => 'save_schedule',
    'csrf_token' => $csrfToken,
    'id' => '', // 新規登録のため空
    'title' => '毎週定例ミーティング',
    'description' => 'プロジェクトの進捗確認',
    'type' => 'period',
    'start_at' => '2026-06-04T10:00',
    'end_at' => '2026-06-04T11:00',
    'category' => 'meeting',
    'recurrence' => 'weekly',
    'is_public' => '1'
];

$html = sendPost($targetUrl, $scheduleData, $cookieFile);

// MySQL で生成された件数と group_id を検証
// タイトルは暗号化されているため、category が 'meeting' のデータを取得
$stmt = $db->query("SELECT id, recurrence_group_id, start_at FROM schedules WHERE category = 'meeting' AND is_deleted = 0 ORDER BY start_at ASC");
$rows = $stmt->fetchAll();

if (count($rows) === 4) {
    echo "[ST-REC-02-A] PASS: Generated correct number of weekly recurring copies (4 schedules).\n";
    
    $groupIds = array_unique(array_column($rows, 'recurrence_group_id'));
    if (count($groupIds) === 1 && !empty($groupIds[0])) {
        echo "[ST-REC-02-B] PASS: All copies have the same non-empty recurrence_group_id.\n";
        $recurrenceGroupId = $groupIds[0];
    } else {
        echo "[ST-REC-02-B] \033[31mFAIL\033[0m: recurrence_group_id is missing or not identical. Got: " . json_encode($groupIds) . "\n";
        $success = false;
    }
} else {
    echo "[ST-REC-02-A] \033[31mFAIL\033[0m: Expected 4 schedules, got " . count($rows) . ".\n";
    $success = false;
}

if (!$success) {
    // クリーンアップ
    $db->exec("DELETE FROM schedules WHERE category = 'meeting'");
    exit(1);
}

// 3. シリーズ全体の編集テスト (共通項目の更新)
$targetScheduleId = $rows[1]['id']; // 2回目の 6/11 の予定を対象にする
$editData = [
    'form_action' => 'save_schedule',
    'csrf_token' => $csrfToken,
    'id' => $targetScheduleId,
    'title' => '【時間変更】毎週定例ミーティング', // タイトル変更
    'description' => '新しいアジェンダで実施',
    'type' => 'period',
    'start_at' => '2026-06-11T13:00', // 開始時刻変更 (13:00)
    'end_at' => '2026-06-11T14:00',
    'category' => 'meeting-updated', // カテゴリ変更
    'recurrence_mode' => 'series', // シリーズ全体の編集を指定
    'is_public' => '1'
];

$html = sendPost($targetUrl, $editData, $cookieFile);

// シリーズ全体が更新されたか検証
$stmt = $db->prepare("SELECT id, start_at, category FROM schedules WHERE recurrence_group_id = :group_id AND is_deleted = 0 ORDER BY start_at ASC");
$stmt->execute([':group_id' => $recurrenceGroupId]);
$updatedRows = $stmt->fetchAll();

$allUpdated = true;
$datesMaintained = true;

// 期待される開始日の一覧
$expectedDates = ['2026-06-04', '2026-06-11', '2026-06-18', '2026-06-25'];

foreach ($updatedRows as $idx => $r) {
    if ($r['category'] !== 'meeting-updated') {
        $allUpdated = false;
    }
    
    // 日付が維持されていることを確認（2026-06-04 等）
    $actualDate = substr($r['start_at'], 0, 10);
    if ($actualDate !== $expectedDates[$idx]) {
        $datesMaintained = false;
    }
}

if ($allUpdated) {
    echo "[ST-REC-03-A] PASS: Series edit updated all schedules in the group.\n";
} else {
    echo "[ST-REC-03-A] \033[31mFAIL\033[0m: Some schedules in the group were not updated.\n";
    $success = false;
}

if ($datesMaintained) {
    echo "[ST-REC-03-B] PASS: Copy dates were correctly maintained (not overwritten by the modified date).\n";
} else {
    echo "[ST-REC-03-B] \033[31mFAIL\033[0m: Copy dates were overwritten.\n";
    $success = false;
}

// 4. 「この予定のみ」編集のテスト (シリーズからの切り離し)
$decoupleScheduleId = $updatedRows[2]['id']; // 3回目の 6/18 の予定を対象にする
$decoupleData = [
    'form_action' => 'save_schedule',
    'csrf_token' => $csrfToken,
    'id' => $decoupleScheduleId,
    'title' => '個別ミーティング',
    'description' => '個別の打ち合わせ',
    'type' => 'period',
    'start_at' => '2026-06-18T15:00',
    'end_at' => '2026-06-18T16:00',
    'category' => 'meeting-single',
    'recurrence_mode' => 'single', // この予定のみ編集を指定
    'is_public' => '1'
];

$html = sendPost($targetUrl, $decoupleData, $cookieFile);

// recurrence_group_id が NULL になっていることを確認
$stmt = $db->prepare("SELECT recurrence_group_id, category FROM schedules WHERE id = :id");
$stmt->execute([':id' => $decoupleScheduleId]);
$decoupledRow = $stmt->fetch();

if ($decoupledRow && is_null($decoupledRow['recurrence_group_id'])) {
    echo "[ST-REC-04] PASS: 'Single' edit decoupled the schedule from the series (recurrence_group_id is now NULL).\n";
} else {
    echo "[ST-REC-04] \033[31mFAIL\033[0m: Schedule was not decoupled. recurrence_group_id: " . json_encode($decoupledRow['recurrence_group_id']) . "\n";
    $success = false;
}

// 5. シリーズ全体の削除テスト
$deleteData = [
    'form_action' => 'delete_schedule',
    'csrf_token' => $csrfToken,
    'id' => $targetScheduleId,
    'recurrence_mode' => 'series' // シリーズ全体の削除を指定
];

$html = sendPost($targetUrl, $deleteData, $cookieFile);

// シリーズの予定（decoupleされたものを除く）が論理削除されたか検証
$stmt = $db->prepare("SELECT COUNT(*) FROM schedules WHERE recurrence_group_id = :group_id AND is_deleted = 0");
$stmt->execute([':group_id' => $recurrenceGroupId]);
$activeCount = $stmt->fetchColumn();

if ($activeCount == 0) {
    echo "[ST-REC-05] PASS: Series deletion logically deleted all remaining schedules in the series.\n";
} else {
    echo "[ST-REC-05] \033[31mFAIL\033[0m: Series deletion did not delete all schedules in the series (Remaining: $activeCount).\n";
    $success = false;
}

// クリーンアップ
$db->exec("DELETE FROM schedules WHERE category LIKE 'meeting%'");

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

if ($success) {
    echo "\nAll recurrence tests passed successfully!\n";
    exit(0);
} else {
    echo "\nSome recurrence tests failed.\n";
    exit(1);
}
