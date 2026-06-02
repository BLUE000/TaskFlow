<?php
namespace Common\Security;

require_once __DIR__ . '/TransCipher/CipherEngine.php';
require_once __DIR__ . '/TransCipher/RecipeManager.php';

use TransCipher\CipherEngine;
use TransCipher\RecipeManager;
use RuntimeException;
use InvalidArgumentException;

/**
 * CryptoUtil - TransCipher をラップし、データベース保存用 Base64 暗号文字列の変換を行うユーティリティクラス
 */
class CryptoUtil {
    private static ?string $secretKey = null;

    /**
     * 秘密鍵とAPI接続用の初期設定を行う
     * 
     * @param string $secretKey 暗号化に使用する秘密鍵
     * @param string $apiUrl TransCipher APIサーバーのURL
     * @param string $apiToken API接続用認証トークン
     */
    public static function init(string $secretKey, string $apiUrl, string $apiToken): void {
        self::$secretKey = $secretKey;
        CipherEngine::configure($apiUrl, $apiToken);
    }

    /**
     * テキストを暗号化し、Base64文字列として返却する
     * 
     * @param string $plainText 平文テキスト
     * @return string 暗号化されたBase64文字列
     * @throws RuntimeException 暗号化に失敗した場合
     */
    public static function encrypt(string $plainText): string {
        if ($plainText === '') {
            return '';
        }

        if (self::$secretKey === null) {
            throw new RuntimeException("CryptoUtil has not been initialized. Call init() first.");
        }

        // CipherEngine::encrypt はバイナリの暗号化結果を返却する
        $cipherBinary = CipherEngine::encrypt(
            $plainText,
            self::$secretKey,
            RecipeManager::MODE_MANDATORY,
            "2.0"
        );

        if ($cipherBinary === null) {
            throw new RuntimeException("Encryption failed in TransCipher Engine.");
        }

        return base64_encode($cipherBinary);
    }

    /**
     * Base64暗号化文字列を復号し、平文テキストを返却する
     * 
     * @param string $cipherTextBase64 暗号化されたBase64文字列
     * @return string 復号された平文テキスト
     * @throws InvalidArgumentException 無効なBase64文字列が指定された場合
     * @throws RuntimeException 復号に失敗した場合
     */
    public static function decrypt(string $cipherTextBase64): string {
        if ($cipherTextBase64 === '') {
            return '';
        }

        if (self::$secretKey === null) {
            throw new RuntimeException("CryptoUtil has not been initialized. Call init() first.");
        }

        $cipherBinary = base64_decode($cipherTextBase64, true);
        if ($cipherBinary === false) {
            throw new InvalidArgumentException("Invalid base64 string provided for decryption.");
        }

        // CipherEngine::decrypt はバイナリデータを復号して平文を返却する
        $plainText = CipherEngine::decrypt($cipherBinary, self::$secretKey);

        if ($plainText === null) {
            throw new RuntimeException("Decryption failed in TransCipher Engine.");
        }

        return $plainText;
    }
}
