<?php
/**
 * TaskFlow 公開予定取得 API (schedules.php)
 * 
 * 指定されたカテゴリ、開始日、日数に基づいて、公開されている予定データを取得し、JSON 形式で返却します。
 * 本 API は参照専用（GET）であり、予定のタイトルは暗号化（難読化）された状態のまま返却します。
 */

// 出力はすべて JSON とする
@header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

// クラスオートローダーの登録
spl_autoload_register(function ($class) {
    $base_dir = __DIR__ . '/../src/';
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// リクエストメソッドは GET のみを許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    @header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

// パラメータ取得
$category = isset($_GET['category']) ? $_GET['category'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$daysParam = isset($_GET['days']) ? $_GET['days'] : '';

// バリデーション
if ($category === '') {
    @header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => "Missing or empty parameter: 'category' is required."]);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || strtotime($start_date) === false) {
    @header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => "Invalid or missing parameter: 'start_date' must be in YYYY-MM-DD format."]);
    exit;
}

$days = filter_var($daysParam, FILTER_VALIDATE_INT);
if ($days === false || $days < 1) {
    @header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => "Invalid or missing parameter: 'days' must be an integer >= 1."]);
    exit;
}

try {
    $db = get_db_connection();
    
    // 期間の算出
    $range_start = $start_date . ' 00:00:00';
    $end_date_str = date('Y-m-d', strtotime("$start_date + " . ($days - 1) . " days"));
    $range_end = $end_date_str . ' 23:59:59';
    
    // 予定データと進捗率を LEFT JOIN + GROUP BY で一括取得（N+1問題を回避し高速化）
    $sql = "SELECT s.id, s.type, s.title, s.start_at, s.end_at, 
                   COALESCE(prog.progress_rate, 0) AS progress_rate
            FROM schedules s
            LEFT JOIN (
                SELECT schedule_id, SUM(progress_percent) AS progress_rate
                FROM schedule_progress
                GROUP BY schedule_id
            ) prog ON s.id = prog.schedule_id
            WHERE s.is_public = 1 
              AND s.is_deleted = 0 
              AND s.category = :category
              AND s.type != 'memo'
              AND s.start_at IS NOT NULL
              AND (
                  (s.end_at IS NOT NULL AND s.start_at <= :range_end1 AND s.end_at >= :range_start1) OR
                  (s.end_at IS NULL AND s.start_at >= :range_start2 AND s.start_at <= :range_end2)
              )
            ORDER BY s.start_at ASC, s.id ASC";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':category' => $category,
        ':range_start1' => $range_start,
        ':range_end1' => $range_end,
        ':range_start2' => $range_start,
        ':range_end2' => $range_end
    ]);
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedData = [];
    foreach ($rows as $row) {
        $type = $row['type'];
        
        switch ($type) {
            case 'task':
                $formattedData[] = [
                    'id' => $row['id'],
                    'type' => 'task',
                    'title' => $row['title'], // 暗号化されたBase64文字列のまま返却
                    'start_at' => $row['start_at'],
                    'end_at' => $row['end_at'],
                    'progress_rate' => min(100, (int)$row['progress_rate']) // 100%上限に丸める
                ];
                break;
                
            case 'simple':
            case 'period':
                $formattedData[] = [
                    'id' => $row['id'],
                    'type' => $type,
                    'title' => $row['title'], // 暗号化されたBase64文字列のまま返却
                    'start_at' => $row['start_at'],
                    'end_at' => $row['end_at']
                ];
                break;
                
            case 'milestone':
                $formattedData[] = [
                    'id' => $row['id'],
                    'type' => 'milestone',
                    'title' => $row['title'], // 暗号化されたBase64文字列のまま返却
                    'date' => date('Y-m-d', strtotime($row['start_at'])) // 日付部分のみ返却
                ];
                break;
                
            default:
                // 未定義または除外タイプ (memo等) の場合は返却しない
                break;
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $formattedData
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // セキュリティ設計(8.5項)に従い、生のエラー情報はログへ出力し、クライアントには抽象的なエラーメッセージのみ返却する
    error_log("Schedules API Database Error: " . $e->getMessage());
    @header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'An internal server error occurred. Please contact the administrator.']);
}
