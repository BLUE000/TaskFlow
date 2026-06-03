<?php
/**
 * TaskFlow 環境設定ファイル
 */

// データベース接続設定 (さくらインターネット本番/XAMPPローカル切り替え)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'taskflow');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''); // XAMPPはデフォルト空文字

// TransCipher 設定
define('TRANSCIPHER_SECRET_KEY', getenv('TRANSCIPHER_SECRET_KEY') ?: 'test_secret_key_12345');
define('TRANSCIPHER_API_URL', getenv('TRANSCIPHER_API_URL') ?: 'https://streamers-tool.sakura.ne.jp/TransCipher/api.php');
define('TRANSCIPHER_API_TOKEN', getenv('TRANSCIPHER_API_TOKEN') ?: 'tc_f2d6ccf601dd459b515215fd');

// アプリケーション共通設定
define('APP_TIMEZONE', 'Asia/Tokyo');

// 管理者ログイン用パスワードハッシュ (パスワード: 'admin123')
define('ADMIN_PASSWORD_HASH', '$2y$10$weUhWtsLLBypHEoX9E26FuxNJChHqNTLZoWKpcw5ZNM1EzK5et5Fq');

// タイムゾーン設定
date_default_timezone_set(APP_TIMEZONE);

// DB接続用のPDOインスタンスを取得するヘルパー関数
function get_db_connection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, // SQLインジェクション防止のため静的プレースホルダーを使用
            ]);
        } catch (PDOException $e) {
            // セキュリティ設計(8.5項)に従い、生の例外メッセージ（パスやパスワード等）は画面に出さず
            // セキュリティログに出力し、ユーザーには抽象的なメッセージを返す
            error_log("Database Connection Error: " . $e->getMessage());
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'Database connection failed. Please contact the administrator.']);
            exit;
        }
    }
    return $pdo;
}

// カテゴリごとのカレンダー時間表示設定
// 設定値: 'none' (非表示), 'start' (開始時間のみ), 'both' (開始・終了両方)
define('CATEGORY_TIME_DISPLAY', [
    'work' => 'both',
    'private' => 'none',
    'study' => 'start',
    'meeting' => 'both',
    'default' => 'start' // 設定がないカテゴリやデフォルトの表示設定
]);
