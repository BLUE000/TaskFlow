#include "crypto_util.h"
#include "cipher_engine.h"
#include <QByteArray>
#include <stdexcept>

// 静的メンバ変数の実体定義
QString CryptoUtil::m_secretKey = "";

void CryptoUtil::init(const QString& secretKey) {
    m_secretKey = secretKey;
}

QString CryptoUtil::encrypt(const QString& plainText) {
    if (plainText.isEmpty()) {
        return "";
    }

    if (m_secretKey.isEmpty()) {
        throw std::runtime_error("CryptoUtil has not been initialized. Call init() first.");
    }

    QByteArray plainData = plainText.toUtf8();
    CipherResult result = CipherEngine::encrypt(plainData, m_secretKey, AesMode::Mandatory);

    if (!result.isSuccess()) {
        throw std::runtime_error(result.message().toStdString());
    }

    return QString::fromLatin1(result.data().toBase64());
}

QString CryptoUtil::decrypt(const QString& cipherTextBase64) {
    if (cipherTextBase64.isEmpty()) {
        return "";
    }

    if (m_secretKey.isEmpty()) {
        throw std::runtime_error("CryptoUtil has not been initialized. Call init() first.");
    }

    // Qt6 の QByteArray::fromBase64Encoding を使用して、Base64のデコード検証を行う
    QByteArray::FromBase64Result decResult = QByteArray::fromBase64Encoding(cipherTextBase64.toUtf8());
    if (!decResult) {
        throw std::runtime_error("Invalid base64 string provided for decryption.");
    }

    QByteArray cipherBinary = *decResult;
    CipherResult result = CipherEngine::decrypt(cipherBinary, m_secretKey);

    if (!result.isSuccess()) {
        throw std::runtime_error(result.message().toStdString());
    }

    return QString::fromUtf8(result.data());
}
