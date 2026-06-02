#include "common/crypto_util.h"
#include <iostream>
#include <stdexcept>
#include <QString>
#include <QCoreApplication>

int successCount = 0;
int failCount = 0;

void runTest(const std::string& testId, const std::string& testName, bool (*testFunc)()) {
    std::cout << "[" << testId << "] " << testName << ": ";
    try {
        if (testFunc()) {
            std::cout << "\033[32mPASS\033[0m" << std::endl;
            successCount++;
        } else {
            std::cout << "\033[31mFAIL\033[0m (Returned false)" << std::endl;
            failCount++;
        }
    } catch (const std::exception& e) {
        std::cout << "\033[31mFAIL\033[0m (Exception: " << e.what() << ")" << std::endl;
        failCount++;
    } catch (...) {
        std::cout << "\033[31mFAIL\033[0m (Unknown Exception)" << std::endl;
        failCount++;
    }
}

// UT-CPP-01: 未初期化時の暗号化
// 注: 実装では安全のため空文字ではなく std::runtime_error を投げる設計としたため、例外が発生することを PASS とする。
bool testUT_CPP_01() {
    CryptoUtil::init(""); // 鍵を空にして未初期化状態にする
    try {
        CryptoUtil::encrypt("Test");
        return false; // 例外が発生しなければ失敗
    } catch (const std::runtime_error&) {
        return true; // 期待通り
    }
}

// UT-CPP-02: 空文字の暗号化
bool testUT_CPP_02() {
    CryptoUtil::init("test_key");
    QString result = CryptoUtil::encrypt("");
    return result == "";
}

// UT-CPP-03: 正常な暗号化・復号 (日本語テスト含む)
bool testUT_CPP_03() {
    CryptoUtil::init("test_key_12345");
    QString plainText = QString::fromUtf8("日本語テスト Hello Qt6");
    QString cipherText = CryptoUtil::encrypt(plainText);
    
    // 暗号文字列が空でないことを確認
    if (cipherText.isEmpty()) {
        return false;
    }
    
    QString decrypted = CryptoUtil::decrypt(cipherText);
    return decrypted == plainText;
}

// UT-CPP-04: 異なる秘密鍵での復号
bool testUT_CPP_04() {
    QString plainText = "Secret C++ Message";
    
    // key-A で暗号化
    CryptoUtil::init("key-A");
    QString cipherText = CryptoUtil::encrypt(plainText);
    
    // key-B で復号
    CryptoUtil::init("key-B");
    try {
        CryptoUtil::decrypt(cipherText);
        return false; // 例外が発生しなければ失敗
    } catch (const std::runtime_error&) {
        return true; // 期待通り
    }
}

// UT-CPP-05: 不正なBase64の復号
bool testUT_CPP_05() {
    CryptoUtil::init("test_key");
    try {
        CryptoUtil::decrypt("NotBase64!!!");
        return false; // 例外が発生しなければ失敗
    } catch (const std::runtime_error&) {
        return true; // 期待通り
    }
}

int main(int argc, char *argv[]) {
    // QCoreApplication を初期化して、Qt の基本的な機能 (QString の toUtf8 など) が動作するようにする
    QCoreApplication a(argc, argv);
    
    std::cout << "=== CryptoUtil Unit Tests (C++) ===" << std::endl;
    
    runTest("UT-CPP-01", "Encryption when uninitialized", testUT_CPP_01);
    runTest("UT-CPP-02", "Encryption of empty string", testUT_CPP_02);
    runTest("UT-CPP-03", "Normal Encryption and Decryption (with Japanese)", testUT_CPP_03);
    runTest("UT-CPP-04", "Decryption with different secret key", testUT_CPP_04);
    runTest("UT-CPP-05", "Decryption of invalid base64 string", testUT_CPP_05);
    
    std::cout << "\nTest Summary: " << successCount << " Succeeded, " << failCount << " Failed." << std::endl;
    
    return failCount == 0 ? 0 : 1;
}
