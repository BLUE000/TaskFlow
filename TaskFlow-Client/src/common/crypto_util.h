#ifndef CRYPTO_UTIL_H
#define CRYPTO_UTIL_H

#include <QString>

/**
 * @brief TransCipher をラップし、Qtアプリケーション向けの暗号化・復号、Base64相互変換を行うユーティリティクラス
 */
class CryptoUtil {
public:
    /**
     * @brief 秘密鍵を設定して初期化する
     * @param secretKey 暗号化・復号に使用する秘密鍵
     */
    static void init(const QString& secretKey);

    /**
     * @brief テキストを暗号化してBase64文字列で返す
     * @param plainText 平文テキスト
     * @return QString 暗号化されたBase64文字列。失敗時は例外をスローします。
     */
    static QString encrypt(const QString& plainText);

    /**
     * @brief Base64暗号化文字列を復号して平文テキストで返す
     * @param cipherTextBase64 暗号化されたBase64文字列
     * @return QString 復号された平文テキスト。失敗時は例外をスローします。
     */
    static QString decrypt(const QString& cipherTextBase64);

private:
    static QString m_secretKey;
};

#endif // CRYPTO_UTIL_H
