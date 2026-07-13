<?php
/**
 * 予定取得API (public/schedules.php) の検証テストスクリプト
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

echo "=== Verifying public/schedules.php API ===\n";

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

// テスト用データ定義
$category = 'api-test-category';
$otherCategory = 'api-other-category';
$baseDate = '2026-06-08';

$testSchedules = [
    // 1. 公開タスク（期間内）
    'task-public-in' => [
        'id' => 'api-task-pub-in-111',
        'title' => 'APIテストタスク1',
        'type' => 'task',
        'start_at' => '2026-06-08 09:00:00',
        'end_at' => '2026-06-10 18:00:00',
        'category' => $category,
        'is_public' => 1,
        'is_deleted' => 0
    ],
    // 2. 非公開タスク（期間内）
    'task-private-in' => [
        'id' => 'api-task-priv-in-222',
        'title' => 'API非公開タスク',
        'type' => 'task',
        'start_at' => '2026-06-08 09:00:00',
        'end_at' => '2026-06-10 18:00:00',
        'category' => $category,
        'is_public' => 0,
        'is_deleted' => 0
    ],
    // 3. 公開簡易予定（期間内）
    'simple-public-in' => [
        'id' => 'api-simple-pub-in-333',
        'title' => 'APIテスト簡易予定',
        'type' => 'simple',
        'start_at' => '2026-06-09 10:00:00',
        'end_at' => '2026-06-09 12:00:00',
        'category' => $category,
        'is_public' => 1,
        'is_deleted' => 0
    ],
    // 4. 公開マイルストーン（期間内）
    'milestone-public-in' => [
        'id' => 'api-milestone-pub-in-444',
        'title' => 'APIテストマイルストーン',
        'type' => 'milestone',
        'start_at' => '2026-06-10 12:00:00',
        'end_at' => '2026-06-10 12:00:00',
        'category' => $category,
        'is_public' => 1,
        'is_deleted' => 0
    ],
    // 5. 公開タスク（期間外・前）
    'task-public-before' => [
        'id' => 'api-task-pub-bef-555',
        'title' => 'APIテストタスク期間前',
        'type' => 'task',
        'start_at' => '2026-06-05 09:00:00',
        'end_at' => '2026-06-07 18:00:00',
        'category' => $category,
        'is_public' => 1,
        'is_deleted' => 0
    ],
    // 6. 公開タスク（期間外・後）
    'task-public-after' => [
        'id' => 'api-task-pub-aft-666',
        'title' => 'APIテストタスク期間後',
        'type' => 'task',
        'start_at' => '2026-06-16 09:00:00',
        'end_at' => '2026-06-18 18:00:00',
        'category' => $category,
        'is_public' => 1,
        'is_deleted' => 0
    ],
    // 7. 削除済み公開タスク（期間内）
    'task-public-deleted' => [
        'id' => 'api-task-pub-del-777',
        'title' => 'APIテストタスク削除済',
        'type' => 'task',
        'start_at' => '2026-06-08 09:00:00',
        'end_at' => '2026-06-10 18:00:00',
        'category' => $category,
        'is_public' => 1,
        'is_deleted' => 1
    ],
    // 8. 別カテゴリ公開タスク（期間内）
    'task-public-other-cat' => [
        'id' => 'api-task-pub-ocat-888',
        'title' => 'APIテストタスク別カテゴリ',
        'type' => 'task',
        'start_at' => '2026-06-08 09:00:00',
        'end_at' => '2026-06-10 18:00:00',
        'category' => $otherCategory,
        'is_public' => 1,
        'is_deleted' => 0
    ],
    // 9. 公開やることメモ（期間内扱い想定日）
    'memo-public' => [
        'id' => 'api-memo-pub-999',
        'title' => 'APIテストやることメモ',
        'type' => 'memo',
        'start_at' => null,
        'end_at' => null,
        'category' => $category,
        'is_public' => 1,
        'is_deleted' => 0
    ]
];

try {
    // 1. クリーニング
    $ids = array_column($testSchedules, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM schedule_progress WHERE schedule_id IN ($placeholders)")->execute($ids);
    $db->prepare("DELETE FROM schedules WHERE id IN ($placeholders)")->execute($ids);
    
    // 2. テストデータ投入
    foreach ($testSchedules as $key => $s) {
        $scheduleRepo->save($s);
    }
    
    // タスク1に進捗データを追加 (合計 60%)
    $progressData = [
        '2026-06-08' => 20,
        '2026-06-09' => 40
    ];
    $scheduleRepo->save($testSchedules['task-public-in'], [], $progressData);
    
    // 3. APIリクエスト擬装と結果検証用ヘルパー
    function runApi($method, $params) {
        $queryString = http_build_query($params);
        $scriptPath = realpath(__DIR__ . '/../public/schedules.php');
        $cmd = "set \"REQUEST_METHOD={$method}\"&& set \"QUERY_STRING={$queryString}\"&& set \"REDIRECT_STATUS=1\"&& set \"SCRIPT_FILENAME={$scriptPath}\"&& d:\\xampp\\php\\php-cgi.exe 2>&1";
        echo "DEBUG CMD: " . $cmd . "\n";
        $output = shell_exec($cmd);
        
        $parts = explode("\r\n\r\n", $output, 2);
        if (count($parts) < 2) {
            $parts = explode("\n\n", $output, 2);
        }
        
        $headerPart = $parts[0] ?? '';
        $bodyPart = $parts[1] ?? '';
        
        $http_code = 200;
        if (preg_match('/Status:\s*(\d+)/i', $headerPart, $matches)) {
            $http_code = (int)$matches[1];
        }
        
        $start = strpos($bodyPart, '{');
        $end = strrpos($bodyPart, '}');
        if ($start !== false && $end !== false) {
            $json = substr($bodyPart, $start, $end - $start + 1);
        } else {
            $json = '';
        }
        
        return [
            'json' => $json,
            'data' => json_decode($json, true),
            'http_code' => $http_code
        ];
    }
    
    // テスト実行
    
    // ST-API-01: 正常データ取得 (タスク) & タイトル暗号化維持 & 進捗率確認
    http_response_code(200); // 状態リセット
    $res = runApi('GET', ['category' => $category, 'start_date' => $baseDate, 'days' => 7]);
    echo "DEBUG JSON: " . $res['json'] . "\n";
    echo "DEBUG DATA: " . print_r($res['data'], true) . "\n";
    
    assertTest($res['http_code'] === 200, "ST-API-01: API returns HTTP 200 on success.");
    assertTest(isset($res['data']['status']) && $res['data']['status'] === 'success', "ST-API-01: Status is 'success'.");
    assertTest(isset($res['data']['data']) && is_array($res['data']['data']), "ST-API-01: Response data is an array.");
    
    $returnedSchedules = $res['data']['data'] ?? [];
    $taskPubIn = null;
    $simplePubIn = null;
    $milestonePubIn = null;
    
    foreach ($returnedSchedules as $s) {
        if ($s['id'] === $testSchedules['task-public-in']['id']) {
            $taskPubIn = $s;
        }
        if ($s['id'] === $testSchedules['simple-public-in']['id']) {
            $simplePubIn = $s;
        }
        if ($s['id'] === $testSchedules['milestone-public-in']['id']) {
            $milestonePubIn = $s;
        }
    }
    
    // タスクの検証
    assertTest($taskPubIn !== null, "ST-API-01: Public task in range is returned.");
    if ($taskPubIn) {
        $expectedEncTitle = CryptoUtil::encrypt($testSchedules['task-public-in']['title']);
        // 復号値ではなく、Base64の暗号化状態のままであること
        assertTest($taskPubIn['title'] !== $testSchedules['task-public-in']['title'], "ST-API-01: Title is not plain text.");
        assertTest($taskPubIn['title'] !== '', "ST-API-01: Title is not empty.");
        // クライアントで復号して平文と一致することを確認
        assertTest(CryptoUtil::decrypt($taskPubIn['title']) === $testSchedules['task-public-in']['title'], "ST-API-01: Encrypted title can be decrypted to correct plain text.");
        
        assertTest($taskPubIn['start_at'] === $testSchedules['task-public-in']['start_at'], "ST-API-01: start_at matches.");
        assertTest($taskPubIn['end_at'] === $testSchedules['task-public-in']['end_at'], "ST-API-01: end_at matches.");
        assertTest($taskPubIn['progress_rate'] === 60, "ST-API-01: progress_rate matches (60%). Actual: {$taskPubIn['progress_rate']}");
    }
    
    // ST-API-02: 予定の検証 (簡易/期間予定)
    assertTest($simplePubIn !== null, "ST-API-02: Public simple schedule in range is returned.");
    if ($simplePubIn) {
        assertTest(CryptoUtil::decrypt($simplePubIn['title']) === $testSchedules['simple-public-in']['title'], "ST-API-02: Encrypted title is correct.");
        assertTest($simplePubIn['start_at'] === $testSchedules['simple-public-in']['start_at'], "ST-API-02: start_at matches.");
        assertTest($simplePubIn['end_at'] === $testSchedules['simple-public-in']['end_at'], "ST-API-02: end_at matches.");
        assertTest(!array_key_exists('progress_rate', $simplePubIn), "ST-API-02: progress_rate field is absent.");
    }
    
    // ST-API-03: マイルストーンの検証
    assertTest($milestonePubIn !== null, "ST-API-03: Public milestone in range is returned.");
    if ($milestonePubIn) {
        assertTest(CryptoUtil::decrypt($milestonePubIn['title']) === $testSchedules['milestone-public-in']['title'], "ST-API-03: Encrypted title is correct.");
        assertTest($milestonePubIn['date'] === '2026-06-10', "ST-API-03: Date is returned in YYYY-MM-DD format. Actual: {$milestonePubIn['date']}");
        assertTest(!array_key_exists('start_at', $milestonePubIn), "ST-API-03: start_at field is absent.");
        assertTest(!array_key_exists('end_at', $milestonePubIn), "ST-API-03: end_at field is absent.");
        assertTest(!array_key_exists('progress_rate', $milestonePubIn), "ST-API-03: progress_rate field is absent.");
    }
    
    // ST-API-04 / ST-API-05 / ST-API-06 / ST-API-07 / ST-API-08: 除外対象の検証
    $returnedIds = array_column($returnedSchedules, 'id');
    
    assertTest(!in_array($testSchedules['task-public-before']['id'], $returnedIds), "ST-API-04: Public task before range is excluded.");
    assertTest(!in_array($testSchedules['task-public-after']['id'], $returnedIds), "ST-API-04: Public task after range is excluded.");
    assertTest(!in_array($testSchedules['task-private-in']['id'], $returnedIds), "ST-API-05: Private task in range is excluded.");
    assertTest(!in_array($testSchedules['task-public-deleted']['id'], $returnedIds), "ST-API-06: Deleted public task is excluded.");
    assertTest(!in_array($testSchedules['memo-public']['id'], $returnedIds), "ST-API-07: Memo is excluded.");
    assertTest(!in_array($testSchedules['task-public-other-cat']['id'], $returnedIds), "ST-API-08: Other category public task is excluded.");
    
    // ST-API-09: パラメータ不足エラーの検証
    http_response_code(200); // 状態リセット
    $resErr1 = runApi('GET', ['start_date' => $baseDate, 'days' => 7]); // categoryなし
    assertTest($resErr1['http_code'] === 400, "ST-API-09: API returns HTTP 400 when 'category' is missing.");
    assertTest(isset($resErr1['data']['error']), "ST-API-09: JSON contains 'error' message.");
    
    http_response_code(200);
    $resErr2 = runApi('GET', ['category' => $category, 'days' => 7]); // start_dateなし
    assertTest($resErr2['http_code'] === 400, "ST-API-09: API returns HTTP 400 when 'start_date' is missing.");
    
    http_response_code(200);
    $resErr3 = runApi('GET', ['category' => $category, 'start_date' => $baseDate]); // daysなし
    assertTest($resErr3['http_code'] === 400, "ST-API-09: API returns HTTP 400 when 'days' is missing.");
    
    // ST-API-10: 無効なパラメータ値のエラー
    http_response_code(200);
    $resErrVal1 = runApi('GET', ['category' => $category, 'start_date' => 'invalid-date-format', 'days' => 7]);
    assertTest($resErrVal1['http_code'] === 400, "ST-API-10: API returns HTTP 400 when 'start_date' format is invalid.");
    
    http_response_code(200);
    $resErrVal2 = runApi('GET', ['category' => $category, 'start_date' => $baseDate, 'days' => 0]);
    assertTest($resErrVal2['http_code'] === 400, "ST-API-10: API returns HTTP 400 when 'days' is 0.");
    
    http_response_code(200);
    $resErrVal3 = runApi('GET', ['category' => $category, 'start_date' => $baseDate, 'days' => -5]);
    assertTest($resErrVal3['http_code'] === 400, "ST-API-10: API returns HTTP 400 when 'days' is negative.");

    // ST-API-11: GET以外のメソッドエラーの検証
    http_response_code(200);
    $resPost = runApi('POST', ['category' => $category, 'start_date' => $baseDate, 'days' => 7]);
    assertTest($resPost['http_code'] === 405, "ST-API-11: API returns HTTP 405 when method is POST.");
    assertTest(isset($resPost['data']['error']), "ST-API-11: Response contains error message.");
    
} catch (Exception $e) {
    echo "Exception occurred during testing: " . $e->getMessage() . "\n";
    $success = false;
} finally {
    // 後片付け
    echo "Cleaning up test data...\n";
    try {
        $ids = array_column($testSchedules, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM schedule_progress WHERE schedule_id IN ($placeholders)")->execute($ids);
        $db->prepare("DELETE FROM schedules WHERE id IN ($placeholders)")->execute($ids);
        echo "Cleanup completed.\n";
    } catch (Exception $e) {
        echo "Cleanup failed: " . $e->getMessage() . "\n";
    }
}

if ($success) {
    echo "\n=== ALL API TESTS PASSED ===\n";
    exit(0);
} else {
    echo "\n=== API TESTS FAILED ===\n";
    exit(1);
}
