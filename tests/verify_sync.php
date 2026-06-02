<?php
/**
 * sync.php 同期API結合・検証スクリプト
 */

echo "=== Verifying public/sync.php data synchronization API ===\n";

$targetUrl = "http://localhost:8080/sync.php";
$apiToken = "tc_f2d6ccf601dd459b515215fd";

// 接続確認のため、MySQLが起動していない場合は事前にエラーとする
require_once __DIR__ . '/../config/config.php';
try {
    get_db_connection();
} catch (Exception $e) {
    echo "MySQL connection failed. Ensure MySQL is running before running this test.\n";
    exit(1);
}

$success = true;

function sendJsonPost($url, $data, &$httpCode = 0) {
    $ch = curl_init($url);
    $jsonData = json_encode($data);
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
    return $response;
}

// 1. トークンなし、または不正トークンのテスト (401 Unauthorized になるべき)
$httpCode = 0;
$res = sendJsonPost($targetUrl, ['action' => 'pull', 'token' => 'invalid_token'], $httpCode);
if ($httpCode === 401) {
    echo "[ST-SYNC-01] PASS: Invalid token returns 401 Unauthorized.\n";
} else {
    echo "[ST-SYNC-01] \033[31mFAIL\033[0m: Invalid token returned HTTP Code $httpCode (Expected 401).\n";
    $success = false;
}

// 2. 正常な PULL 同期のテスト (200 OK 且つ schedules などのキーを持つJSON)
$httpCode = 0;
$res = sendJsonPost($targetUrl, [
    'action' => 'pull',
    'token' => $apiToken,
    'last_synced_at' => '2026-06-01 00:00:00'
], $httpCode);

if ($httpCode === 200) {
    $data = json_decode($res, true);
    if ($data && isset($data['status']) && $data['status'] === 'success') {
        echo "[ST-SYNC-02-A] PASS: Valid pull request returned success status.\n";
        
        if (isset($data['data']['schedules']) && isset($data['data']['time_slots'])) {
            echo "[ST-SYNC-02-B] PASS: JSON response contains schedule and timeslot keys.\n";
        } else {
            echo "[ST-SYNC-02-B] \033[31mFAIL\033[0m: Pull response missing database tables keys.\n";
            $success = false;
        }
    } else {
        echo "[ST-SYNC-02-A] \033[31mFAIL\033[0m: Pull returned invalid JSON response: " . $res . "\n";
        $success = false;
    }
} else {
    echo "[ST-SYNC-02-A] \033[31mFAIL\033[0m: Pull returned HTTP Code $httpCode (Expected 200).\n";
    $success = false;
}

// 3. PUSH 同期のテスト (SQLite ➔ MySQL への書き込み)
$newSlotId = 'slot-push-test-999';
$pushPayload = [
    'action' => 'push',
    'token' => $apiToken,
    'data' => [
        'schedules' => [],
        'time_slots' => [
            [
                'id' => $newSlotId,
                'name' => '同期テスト枠',
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'limit_hours' => 1.0,
                'is_deleted' => 0
            ]
        ],
        'webhooks' => []
    ]
];

$httpCode = 0;
$res = sendJsonPost($targetUrl, $pushPayload, $httpCode);
if ($httpCode === 200) {
    $data = json_decode($res, true);
    if ($data && isset($data['status']) && $data['status'] === 'success') {
        echo "[ST-SYNC-03-A] PASS: Valid push request returned success.\n";
        
        // MySQL データベースに実際に書き込まれたか確認
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT * FROM time_slots WHERE id = :id");
        $stmt->execute([':id' => $newSlotId]);
        $row = $stmt->fetch();
        if ($row && $row['name'] === '同期テスト枠') {
            echo "[ST-SYNC-03-B] PASS: Pushed timeslot successfully written to MySQL database.\n";
            
            // 検証データの削除 (クリーンアップ)
            $db->exec("DELETE FROM time_slots WHERE id = '$newSlotId'");
        } else {
            echo "[ST-SYNC-03-B] \033[31mFAIL\033[0m: Pushed timeslot not found in MySQL.\n";
            $success = false;
        }
    } else {
        echo "[ST-SYNC-03-A] \033[31mFAIL\033[0m: Push returned invalid JSON: " . $res . "\n";
        $success = false;
    }
} else {
    echo "[ST-SYNC-03-A] \033[31mFAIL\033[0m: Push returned HTTP Code $httpCode (Expected 200).\n";
    $success = false;
}

if ($success) {
    echo "\nVerification successful! Data synchronization API functions work correctly.\n";
    exit(0);
} else {
    echo "\nVerification failed.\n";
    exit(1);
}
