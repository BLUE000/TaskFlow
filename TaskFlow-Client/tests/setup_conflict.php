<?php
/**
 * 競合テスト用 MySQL データセットアップスクリプト
 */
require_once __DIR__ . '/../../config/config.php';

// クラスオートローダー
spl_autoload_register(function ($class) {
    $base_dir = __DIR__ . '/../../src/';
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use Common\Security\CryptoUtil;

$db = get_db_connection();

// 既存の競合データをクリーンアップ
$db->exec("DELETE FROM schedules WHERE id = 'conflict-task-1'");

// 暗号化ユーティリティの初期化 (config.php の設定を再利用)
CryptoUtil::init(TRANSCIPHER_SECRET_KEY, TRANSCIPHER_API_URL, TRANSCIPHER_API_TOKEN);

// 暗号化タイトルの生成
$encTitle = CryptoUtil::encrypt("Remote Title");
$encDesc = CryptoUtil::encrypt("Remote Description");

$stmt = $db->prepare("INSERT INTO schedules (
    id, title, description, type, start_at, end_at, 
    status, priority, weight, category, is_public, is_deleted, created_at, updated_at
) VALUES (
    'conflict-task-1', :title, :desc, 'simple', '2026-06-03 10:00:00', '2026-06-03 11:00:00',
    'todo', 0, 1.0, 'general', 0, 0, '2026-06-03 09:00:00', '2026-06-03 09:10:00'
)");

$stmt->execute([
    ':title' => $encTitle,
    ':desc' => $encDesc
]);

echo "Conflict test data setup in MySQL completed.\n";
