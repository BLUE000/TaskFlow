<?php
/**
 * admin.php アコーディオン機能のHTML/JS構造検証スクリプト
 */

// セッションのモック作成
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['csrf_token'] = 'test_csrf_token_12345';

// サーバー変数のモック
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "=== Verifying public/admin.php Accordion UI Integration ===\n";

require_once __DIR__ . '/../config/config.php';
try {
    get_db_connection();
} catch (Exception $e) {
    echo "MySQL connection failed. Ensure MySQL is running before running this test.\n";
    exit(1);
}

// 出力のバッファリング開始
ob_start();
// admin.php のロード
require __DIR__ . '/../public/admin.php';
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

// 1. JS関数の存在検証 (外部JSファイルを検証)
$jsContent = file_exists(__DIR__ . '/../public/js/admin.js') ? file_get_contents(__DIR__ . '/../public/js/admin.js') : '';
assertContains("function toggleSectionVisibility(containerId, iconId)", $jsContent, "ST-ACCORDION-01", "toggleSectionVisibility JavaScript function is defined.");

// 2. CSSクラスの存在検証 (外部CSSファイルを検証)
$cssContent = file_exists(__DIR__ . '/../public/css/admin.css') ? file_get_contents(__DIR__ . '/../public/css/admin.css') : '';
assertContains(".accordion-header {", $cssContent, "ST-ACCORDION-02-A", "accordion-header CSS class is defined.");
assertContains(".accordion-icon {", $cssContent, "ST-ACCORDION-02-B", "accordion-icon CSS class is defined.");

// 3. 予定一覧のアコーディオン要素検証
assertContains("active-schedules-container", $html, "ST-ACCORDION-03-A", "Container active-schedules-container ID is present.");
assertContains("active-schedules-toggle-icon", $html, "ST-ACCORDION-03-B", "Toggle icon active-schedules-toggle-icon ID is present.");
assertContains("toggleSectionVisibility('active-schedules-container'", $html, "ST-ACCORDION-03-C", "Onclick trigger for active schedules container is present.");

// 4. やることメモ一覧のアコーディオン要素検証
assertContains("memo-schedules-container", $html, "ST-ACCORDION-04-A", "Container memo-schedules-container ID is present.");
assertContains("memo-schedules-toggle-icon", $html, "ST-ACCORDION-04-B", "Toggle icon memo-schedules-toggle-icon ID is present.");
assertContains("toggleSectionVisibility('memo-schedules-container'", $html, "ST-ACCORDION-04-C", "Onclick trigger for memo schedules container is present.");

if ($success) {
    echo "\nVerification successful! All accordion HTML/JS structures are present and correct.\n";
    exit(0);
} else {
    echo "\nVerification failed.\n";
    exit(1);
}
