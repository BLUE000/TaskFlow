<?php
/**
 * admin.php 認証・セキュリティ検証スクリプト (HTTP経由)
 */

echo "=== Verifying public/admin.php security & authentication ===\n";

$targetUrl = "http://localhost:8080/admin.php";
$cookieFile = __DIR__ . '/../data/cookie.txt';

// クッキーファイルをクリア
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

$success = true;

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

function sendGet($url, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
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
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $response;
}

// 1. 未ログイン状態でのアクセス検証
$html = sendGet($targetUrl, $cookieFile);

assertContains("TaskFlow login", $html, "ST-AUTH-01-A", "Login box is displayed for unauthenticated access.");
assertContains("csrf_token", $html, "ST-AUTH-01-B", "CSRF token input is embedded in login form.");
assertNotContains("予定・タスク管理", $html, "ST-AUTH-01-C", "Admin panel content is hidden.");

// CSRFトークンをHTMLから抽出する
$csrfToken = '';
if (preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $matches)) {
    $csrfToken = $matches[1];
}

if (empty($csrfToken)) {
    echo "[ERROR] Failed to extract CSRF token from login form.\n";
    $success = false;
} else {
    // 2. 誤ったパスワードでのログイン試行検証
    $postData = [
        'form_action' => 'login',
        'csrf_token' => $csrfToken,
        'password' => 'wrong_password'
    ];
    $html = sendPost($targetUrl, $postData, $cookieFile);
    assertContains("パスワードが正しくありません。", $html, "ST-AUTH-02-A", "Error message displayed on incorrect password.");
    assertContains("TaskFlow login", $html, "ST-AUTH-02-B", "Login box is still displayed.");

    // 3. CSRF トークンなしでの POST 試行検証 (403 Forbidden になるべき)
    $postDataNoCsrf = [
        'form_action' => 'login',
        'password' => 'admin123'
    ];
    $httpCode = 0;
    $html = sendPost($targetUrl, $postDataNoCsrf, $cookieFile, $httpCode);
    if ($httpCode === 403) {
        echo "[ST-AUTH-03-A] PASS: POST request without CSRF token returns 403 Forbidden.\n";
    } else {
        echo "[ST-AUTH-03-A] \033[31mFAIL\033[0m: POST request without CSRF token returned HTTP Code $httpCode (Expected 403).\n";
        $success = false;
    }
}

// クッキーファイルの削除
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

if ($success) {
    echo "\nVerification successful! Admin security functions work as expected.\n";
    exit(0);
} else {
    echo "\nVerification failed.\n";
    exit(1);
}
