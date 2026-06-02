<?php
require_once __DIR__ . '/../src/Common/Security/CryptoUtil.php';

use Common\Security\CryptoUtil;

// テスト用のダミーパラメータ
$apiUrl = "https://streamers-tool.sakura.ne.jp/TransCipher/api.php";
$apiToken = "tc_f2d6ccf601dd459b515215fd";
$secretKey = "test_secret_key_12345";

$successCount = 0;
$failCount = 0;

function runTest($testId, $testName, $callback) {
    global $successCount, $failCount;
    echo "[$testId] $testName: ";
    try {
        $result = $callback();
        if ($result === true) {
            echo "\033[32mPASS\033[0m\n";
            $successCount++;
        } else {
            echo "\033[31mFAIL\033[0m (Returned false)\n";
            $failCount++;
        }
    } catch (Exception $e) {
        echo "\033[31mFAIL\033[0m (Exception: " . $e->getMessage() . ")\n";
        $failCount++;
    }
}

// 初期化
CryptoUtil::init($secretKey, $apiUrl, $apiToken);

echo "=== CryptoUtil Unit Tests (PHP) ===\n";

// UT-PHP-01: 空文字の暗号化
runTest("UT-PHP-01", "Encryption of empty string", function() {
    $result = CryptoUtil::encrypt("");
    return $result === "";
});

// UT-PHP-02: 正常な暗号化・復号
runTest("UT-PHP-02", "Normal Encryption and Decryption", function() {
    $plainText = "Hello TaskFlow 日本語テスト";
    $cipherText = CryptoUtil::encrypt($plainText);
    
    // 暗号化されたものがBase64文字列になっていること（簡易検証）
    if ($cipherText === "" || !preg_match('/^[a-zA-Z0-9\/+]*={0,2}$/', $cipherText)) {
        echo "[Detail: Output is not valid base64 ($cipherText)] ";
        return false;
    }
    
    $decrypted = CryptoUtil::decrypt($cipherText);
    return $decrypted === $plainText;
});

// UT-PHP-03: 空文字の復号
runTest("UT-PHP-03", "Decryption of empty string", function() {
    $result = CryptoUtil::decrypt("");
    return $result === "";
});

// UT-PHP-04: 不正なBase64の復号
runTest("UT-PHP-04", "Decryption of invalid base64 string", function() {
    try {
        CryptoUtil::decrypt("InvalidBase64#$@");
        return false; // 例外が発生しなければ失敗
    } catch (InvalidArgumentException $e) {
        return true; // 期待通り
    }
});

// UT-PHP-05: 異なる秘密鍵での復号
runTest("UT-PHP-05", "Decryption with different secret key", function() use ($apiUrl, $apiToken) {
    $plainText = "Secret Message";
    
    // key-A で暗号化
    CryptoUtil::init("key-A", $apiUrl, $apiToken);
    $cipherText = CryptoUtil::encrypt($plainText);
    
    // key-B で復号
    CryptoUtil::init("key-B", $apiUrl, $apiToken);
    try {
        CryptoUtil::decrypt($cipherText);
        return false; // 例外が発生しなければ失敗
    } catch (RuntimeException $e) {
        return true; // 期待通り
    } finally {
        // キーを元に戻す
        CryptoUtil::init("test_secret_key_12345", $apiUrl, $apiToken);
    }
});

// UT-PHP-06: 接続エラー時の挙動
runTest("UT-PHP-06", "Behavior on connection error", function() use ($apiToken, $secretKey) {
    // 存在しないAPIエンドポイントを設定
    CryptoUtil::init($secretKey, "https://invalid-host-name-xyz.com/api.php", $apiToken);
    try {
        CryptoUtil::encrypt("Test");
        return false; // 例外が発生しなければ失敗
    } catch (RuntimeException $e) {
        return true; // 期待通り
    } finally {
        // API URLを元に戻す
        CryptoUtil::init($secretKey, "https://streamers-tool.sakura.ne.jp/TransCipher/api.php", $apiToken);
    }
});

echo "\nTest Summary: $successCount Succeeded, $failCount Failed.\n";
exit($failCount === 0 ? 0 : 1);
