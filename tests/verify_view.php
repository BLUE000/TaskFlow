<?php
/**
 * View 画面出力検証スクリプト (結合・システムテストの代用)
 */

echo "=== Verifying public/index.php rendered output ===\n";

// 接続確認のため、MySQLが起動していない場合は事前にエラーとする
require_once __DIR__ . '/../config/config.php';
try {
    get_db_connection();
} catch (Exception $e) {
    echo "MySQL connection failed. Ensure MySQL is running before running this test.\n";
    exit(1);
}

// 出力のバッファリング開始
ob_start();
// GET パラメータやサーバー変数を偽装 (2026年6月のカレンダーを表示させるため)
$_GET['y'] = '2026';
$_GET['m'] = '6';

// index.php のロード
require __DIR__ . '/../public/index.php';

// 出力文字列を取得してバッファリング終了
$html = ob_get_clean();

$success = true;

// 検証用ヘルパー
function assertContains($target, $content, $testId, $message) {
    global $success;
    if (strpos($content, $target) !== false) {
        echo "[$testId] PASS: $message\n";
    } else {
        echo "[$testId] \033[31mFAIL\033[0m: $message (Could not find expected content: '$target')\n";
        $success = false;
    }
}

function assertNotContains($target, $content, $testId, $message) {
    global $success;
    if (strpos($content, $target) === false) {
        echo "[$testId] PASS: $message\n";
    } else {
        echo "[$testId] \033[31mFAIL\033[0m: $message (Found unexpected content: '$target')\n";
        $success = false;
    }
}

// ST-VIEW-01: 公開予定が表示され、非公開予定が表示されないこと
assertContains("会議資料準備 (タスクA)", $html, "ST-VIEW-01-A", "Public task A is included in the output.");
assertContains("地方出張・現地サポート (予定E)", $html, "ST-VIEW-01-B", "Public period event E is included in the output.");
assertNotContains("秘密の個人用タスク (タスクC)", $html, "ST-VIEW-01-C", "Private task C is NOT included in the output.");

// ST-LOAD-01: 負荷警告表示
// JSONデータ内に "sched-task-a" および "sched-task-b" が正しく出力されていること
assertContains("sched-task-a", $html, "ST-LOAD-01-A", "Task A JSON data is printed.");
assertContains("sched-task-b", $html, "ST-LOAD-01-B", "Task B JSON data is printed.");

// セキュリティチェック: 秘密鍵がHTML出力に含まれていないこと (情報漏洩対策)
assertNotContains("test_secret_key_12345", $html, "ST-SEC-04-A", "Security check: Secret cryptographic key is NOT printed in the public HTML.");

if ($success) {
    echo "\nVerification successful! All views and data integration logic are correct.\n";
    exit(0);
} else {
    echo "\nVerification failed. Please check output details.\n";
    exit(1);
}
