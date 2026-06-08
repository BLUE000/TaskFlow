<?php
/**
 * TaskFlow 一般閲覧画面 (Calendar & Gantt Chart View)
 */

// セッションパラメータの保護設定 (セッション開始前に設定)
ini_set('session.cookie_httponly', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
session_start();

require_once __DIR__ . '/../config/config.php';

// クラスオートローダーの登録
spl_autoload_register(function ($class) {
    $base_dir = __DIR__ . '/../src/';
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use Common\Security\CryptoUtil;
use Holiday\HolidayParser;
use Repository\ScheduleRepository;
use Repository\TimeSlotRepository;

// TransCipher の初期化
CryptoUtil::init(TRANSCIPHER_SECRET_KEY, TRANSCIPHER_API_URL, TRANSCIPHER_API_TOKEN);

$db = get_db_connection();
$scheduleRepo = new ScheduleRepository($db);
$timeSlotRepo = new TimeSlotRepository($db);

// CSRFトークンの生成
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ログアウト処理
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    
    // GETパラメータを保持したままログアウト後のURLへリダイレクト
    $query = !empty($_GET) ? '?' . http_build_query(array_filter($_GET, function($k) { return $k !== 'action'; }, ARRAY_FILTER_USE_KEY)) : '';
    header("Location: index.php" . $query);
    exit;
}

// ログイン処理
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'index_login') {
    // CSRF検証
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header('HTTP/1.1 403 Forbidden');
        echo "CSRF token verification failed.";
        exit;
    }

    $password = $_POST['password'] ?? '';
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        // セッションIDの再生成
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        
        // GETパラメータ（categoriesなど）を維持してリダイレクト
        $query = !empty($_GET) ? '?' . http_build_query($_GET) : '';
        header("Location: index.php" . $query);
        exit;
    } else {
        $login_error = 'パスワードが正しくありません。';
    }
}

// 管理者セッションの判定
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// データの取得 (管理者の場合は全件、一般ユーザーは公開設定されたもののみ)
$rawSchedules = $isAdmin ? $scheduleRepo->findAll() : $scheduleRepo->findAllPublic();
// やることメモ (type = 'memo') は一般画面（カレンダー/週表示/ガントチャート）には表示しない
$schedules = array_values(array_filter($rawSchedules, function($s) {
    return $s['type'] !== 'memo';
}));
$timeSlots = $timeSlotRepo->findAllActive();

// 祝日データの取得
$holidays = [];
$holidayCsvPath = __DIR__ . '/../data/syukujitsu.csv';
if (file_exists($holidayCsvPath)) {
    try {
        $holidays = HolidayParser::parse($holidayCsvPath);
    } catch (\Exception $e) {
        error_log("Failed to parse holiday CSV: " . $e->getMessage());
    }
}

// XSS対策用のエスケープ関数
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskFlow | スケジュール＆タスクボード</title>
    <meta name="description" content="予定の俯瞰と時間枠ごとの作業負荷を視覚的に管理できるタスク・カレンダーシステム。">
    <!-- Google Fonts: Outfit & Noto Sans JP -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient-start: #0f172a;
            --bg-gradient-end: #020617;
            --panel-bg: rgba(30, 41, 59, 0.45);
            --panel-border: rgba(255, 255, 255, 0.08);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent-blue: #38bdf8;
            --accent-purple: #a855f7;
            --accent-green: #10b981;
            --accent-orange: #f97316;
            --alert-red: #ef4444;
            --alert-glow: rgba(239, 68, 68, 0.3);
            --shadow-premium: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', 'Noto Sans JP', sans-serif;
            background: linear-gradient(135deg, var(--bg-gradient-start), var(--bg-gradient-end));
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            line-height: 1.6;
        }

        /* アプリケーションレイアウト */
        .app-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* プレミアムヘッダー */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--panel-border);
        }

        .logo-area h1 {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(to right, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.05em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo-area p {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.2rem;
        }

        /* モード切替タブ */
        .view-controls {
            display: flex;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--panel-border);
            padding: 0.35rem;
            border-radius: 12px;
            gap: 0.25rem;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            padding: 0.6rem 1.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition-smooth);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.03);
        }

        .tab-btn.active {
            color: var(--text-primary);
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.2), rgba(168, 85, 247, 0.2));
            box-shadow: 0 0 15px -3px rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        /* メインビューエリア */
        .view-panel {
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--panel-border);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: var(--shadow-premium);
            transition: var(--transition-smooth);
            margin-bottom: 2rem;
        }

        /* 操作バー (年月移動など) */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .nav-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--panel-border);
            color: var(--text-primary);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--accent-blue);
        }

        .current-period {
            font-size: 1.4rem;
            font-weight: 700;
            min-width: 180px;
            text-align: center;
        }

        /* カレンダーグリッド */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 8px;
        }

        .day-header {
            text-align: center;
            padding: 0.8rem 0;
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            border-bottom: 2px solid var(--panel-border);
        }

        .day-header.sunday { color: var(--alert-red); }
        .day-header.saturday { color: var(--accent-blue); }

        .calendar-cell {
            min-height: 120px;
            background: rgba(15, 23, 42, 0.35);
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            padding: 0.5rem;
            transition: var(--transition-smooth);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .calendar-cell:hover {
            background: rgba(255, 255, 255, 0.02);
            border-color: rgba(56, 189, 248, 0.3);
            transform: translateY(-2px);
        }

        .calendar-cell.other-month {
            opacity: 0.3;
        }

        .cell-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .day-number {
            font-weight: 700;
            font-size: 0.95rem;
        }

        .holiday-label {
            font-size: 0.7rem;
            color: var(--alert-red);
            font-weight: 500;
            max-width: 70%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .calendar-cell.sunday .day-number,
        .calendar-cell.holiday .day-number {
            color: var(--alert-red);
        }

        .calendar-cell.saturday .day-number {
            color: var(--accent-blue);
        }

        /* 予定アイテム */
        .event-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-grow: 1;
            overflow-y: auto;
            max-height: 90px;
            min-width: 0;
        }

        .event-item {
            font-size: 0.72rem;
            padding: 0.25rem 0.4rem;
            border-radius: 4px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            transition: var(--transition-smooth);
            min-width: 0;
            max-width: 100%;
        }

        .event-item:hover {
            filter: brightness(1.2);
            transform: scale(1.02);
        }

        .event-simple {
            background: rgba(56, 189, 248, 0.15);
            border-left: 3px solid var(--accent-blue);
            color: #bae6fd;
        }

        .event-period {
            background: rgba(168, 85, 247, 0.15);
            border-left: 3px solid var(--accent-purple);
            color: #f5d0fe;
        }

        .event-task {
            background: rgba(16, 185, 129, 0.15);
            border-left: 3px solid var(--accent-green);
            color: #d1fae5;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .event-task.doing {
            border-left-color: var(--accent-orange);
            background: rgba(249, 115, 22, 0.15);
            color: #ffedd5;
        }

        .task-weight-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 4px;
        }

        /* 時間枠負荷警告表示 */
        .load-indicator-container {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            margin-top: auto;
            padding-top: 4px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .load-indicator {
            font-size: 0.65rem;
            padding: 1px 4px;
            border-radius: 3px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            font-weight: 600;
        }

        .load-indicator.warning {
            background: var(--alert-red);
            color: #fff;
            animation: pulse 2s infinite;
            box-shadow: 0 0 8px var(--alert-glow);
        }

        /* ツールチップ */
        .tooltip {
            position: absolute;
            background: #1e293b;
            border: 1px solid var(--panel-border);
            padding: 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            z-index: 100;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            pointer-events: none;
            display: none;
            max-width: 250px;
        }

        /* ガントチャート */
        .gantt-container {
            overflow-x: auto;
            width: 100%;
        }

        .gantt-grid {
            display: grid;
            grid-template-columns: 200px 1fr;
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.25);
        }

        .gantt-left-panel {
            border-right: 1px solid var(--panel-border);
        }

        .gantt-row-header {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-bottom: 1px solid var(--panel-border);
            height: 48px;
            display: flex;
            align-items: center;
        }

        .gantt-row-header.title-col {
            font-weight: 700;
            color: var(--text-secondary);
            background: rgba(0,0,0,0.1);
        }

        .gantt-right-panel {
            overflow-x: auto;
            position: relative;
        }

        .gantt-timeline-header {
            display: flex;
            height: 48px;
            border-bottom: 1px solid var(--panel-border);
            background: rgba(0,0,0,0.1);
        }

        .gantt-header-cell {
            flex-shrink: 0;
            width: 40px;
            text-align: center;
            border-right: 1px solid rgba(255, 255, 255, 0.03);
            display: flex;
            flex-direction: column;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .gantt-header-cell.sunday,
        .gantt-header-cell.holiday {
            color: var(--alert-red);
            background: rgba(239, 68, 68, 0.05);
        }

        .gantt-header-cell.saturday {
            color: var(--accent-blue);
            background: rgba(56, 189, 248, 0.05);
        }

        .gantt-row-body {
            display: flex;
            height: 48px;
            border-bottom: 1px solid var(--panel-border);
            position: relative;
            align-items: center;
        }

        .gantt-bar {
            position: absolute;
            height: 24px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            padding: 0 0.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            color: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .gantt-bar.simple {
            background: linear-gradient(90deg, var(--accent-blue), #0284c7);
        }
        .gantt-bar.period {
            background: linear-gradient(90deg, var(--accent-purple), #7e22ce);
        }
        .gantt-bar.task {
            background: linear-gradient(90deg, var(--accent-green), #047857);
        }
        .gantt-bar.task.todo {
            background: rgba(148, 163, 184, 0.25) !important;
            border: 1px solid rgba(148, 163, 184, 0.5) !important;
            color: #94a3b8 !important;
        }
        .gantt-bar.task.done {
            background: linear-gradient(90deg, #0f766e, #115e59) !important;
            border: 1px solid #14b8a6 !important;
            color: #ccfbf1 !important;
        }
        .gantt-bar.task.doing.delayed {
            background: linear-gradient(90deg, var(--alert-red), #b91c1c) !important;
            border: 1px solid #ef4444 !important;
        }
        .gantt-bar.task.doing.ahead {
            background: linear-gradient(90deg, var(--accent-blue), #0369a1) !important;
            border: 1px solid #38bdf8 !important;
        }
        .gantt-bar.task.doing.onschedule {
            background: linear-gradient(90deg, var(--accent-green), #047857) !important;
            border: 1px solid #10b981;
        }

        /* 予定外の実績前倒し・超過期間の表示スタイル */
        .gantt-bar.actual-extension {
            background: transparent !important;
            border-style: dashed !important;
            border-width: 1.5px !important;
            box-shadow: none !important;
            color: transparent !important;
            cursor: pointer;
        }
        .gantt-bar.actual-extension.early {
            border-color: var(--accent-blue) !important;
        }
        .gantt-bar.actual-extension.late {
            border-color: var(--alert-red) !important;
        }


        /* アニメーション */
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 8px var(--alert-glow); }
            50% { transform: scale(1.05); box-shadow: 0 0 15px rgba(239, 68, 68, 0.6); }
            100% { transform: scale(1); box-shadow: 0 0 8px var(--alert-glow); }
        }

        /* スマホ用レスポンシブ調整 */
        @media (max-width: 768px) {
            .app-container { padding: 1rem; }
            header { flex-direction: column; gap: 1rem; align-items: flex-start; }
            .calendar-grid { grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
            .calendar-cell { min-height: 80px; padding: 0.25rem; }
            .event-item { font-size: 0.6rem; padding: 1px 2px; }
            .event-list { max-height: 50px; }
            .cell-header { margin-bottom: 0.2rem; }
            .holiday-label { display: none; }
            .gantt-grid { grid-template-columns: 120px 1fr; }
        }

        /* フィルターパネルのスタイリング */
        .filter-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid var(--panel-border);
            padding: 1rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .filter-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-secondary);
        }

        .category-filter-list {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .category-chip {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--panel-border);
            color: var(--text-secondary);
            padding: 0.35rem 0.85rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 20px;
            cursor: pointer;
            transition: var(--transition-smooth);
            user-select: none;
        }

        .category-chip:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.1);
        }

        .category-chip.active {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.25), rgba(168, 85, 247, 0.25));
            border-color: var(--accent-blue);
            color: var(--text-primary);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.15);
        }

        .admin-only-controls {
            border-left: 1px solid var(--panel-border);
            padding-left: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .badge-admin {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            background: rgba(168, 85, 247, 0.15);
            color: #d8b4fe;
            border: 1px solid rgba(168, 85, 247, 0.3);
            letter-spacing: 0.05em;
        }

        /* トグルスイッチスタイル */
        .toggle-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .toggle-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* 非公開予定のスタイル表示 */
        .event-item.private-schedule {
            background: rgba(241, 245, 249, 0.03) !important;
            border-left: 3px dashed #64748b !important;
            color: #94a3b8 !important;
            opacity: 0.65;
        }
        
        .gantt-bar.private-schedule {
            background: linear-gradient(90deg, #334155, #1e293b) !important;
            border: 1px dashed #475569 !important;
            color: #94a3b8 !important;
            opacity: 0.65;
        }

        @media (max-width: 768px) {
            .admin-only-controls {
                border-left: none;
                padding-left: 0;
                width: 100%;
            }
        }

        /* ログインモーダル */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(2, 6, 17, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition-smooth);
        }

        .modal-content {
            background: rgba(30, 41, 59, 0.75);
            border: 1px solid var(--panel-border);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2.5rem;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            position: relative;
            text-align: center;
            animation: modalFadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-close-btn {
            position: absolute;
            top: 1rem;
            right: 1.25rem;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-size: 1.8rem;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .modal-close-btn:hover {
            color: var(--text-primary);
        }

        .modal-content h2 {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            background: linear-gradient(to right, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header>
            <div></div> <!-- レイアウト維持用の空ブロック -->
            <div style="display:flex; align-items:center; gap:1rem; flex-wrap: wrap;">
                <?php if ($isAdmin): ?>
                    <a href="admin.php" class="tab-btn" style="color:var(--accent-purple); border:1px solid rgba(168,85,247,0.3); text-decoration:none; display:flex; align-items:center; gap:0.25rem;">
                        🔒 管理コンソールへ
                    </a>
                    <a href="index.php?action=logout<?php echo !empty($_GET) && !isset($_GET['action']) ? '&' . h(http_build_query($_GET)) : ''; ?>" class="tab-btn" style="color:var(--text-secondary); border:1px solid var(--panel-border); text-decoration:none;">
                        ログアウト
                    </a>
                <?php else: ?>
                    <button class="tab-btn" onclick="showLoginModal()" style="color:var(--accent-blue); border:1px solid rgba(56,189,248,0.3);">
                        管理者ログイン
                    </button>
                <?php endif; ?>
                <div class="view-controls">
                    <button class="tab-btn active" id="tab-month" onclick="switchView('month')">
                        月間カレンダー
                    </button>
                    <button class="tab-btn" id="tab-week" onclick="switchView('week')">
                        週間スケジュール
                    </button>
                    <button class="tab-btn" id="tab-gantt" onclick="switchView('gantt')">
                        ガントチャート
                    </button>
                </div>
            </div>
        </header>

        <!-- メインパネル -->
        <main class="view-panel">
            
            <!-- フィルターコントロール -->
            <div class="filter-panel">
                <div class="filter-group">
                    <span class="filter-label" onclick="toggleCategoryFilterVisibility()" style="cursor: pointer; display: flex; align-items: center; gap: 0.25rem; user-select: none;">
                        カテゴリ絞り込み: <span id="filter-toggle-icon" style="display: inline-block; transition: transform 0.2s; transform: rotate(0deg); font-size: 0.75rem;">▼</span>
                    </span>
                    <div id="category-filters-container" style="display: block;">
                        <div id="category-filters" class="category-filter-list">
                            <!-- JSで動的にカテゴリトグルボタンを生成 -->
                        </div>
                    </div>
                </div>
                <?php if ($isAdmin): ?>
                    <div class="filter-group admin-only-controls">
                        <div class="toggle-container">
                            <input type="checkbox" id="toggle-private-schedules" class="toggle-input" checked onchange="onFilterChange()">
                            <label for="toggle-private-schedules" style="cursor:pointer; font-size: 0.85rem; font-weight:600; color: var(--accent-purple);">非公開の予定を表示する</label>
                        </div>
                        <span class="badge-admin">ADMIN MODE</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="toolbar">
                <div class="nav-controls">
                    <button class="nav-btn" onclick="prevPeriod()">&lt;</button>
                    <div class="current-period" id="period-title">2026年 6月</div>
                    <button class="nav-btn" onclick="nextPeriod()">&gt;</button>
                </div>
                <div>
                    <!-- 凡例やステータスを表示 -->
                </div>
            </div>

            <!-- カレンダーコンテナ -->
            <div id="calendar-view-container">
                <div class="calendar-grid" id="calendar-grid-body">
                    <!-- JavaScriptで動的生成されます -->
                </div>
            </div>

            <!-- ガントチャートコンテナ (初期非表示) -->
            <div id="gantt-view-container" style="display: none;">
                <div class="gantt-container" id="gantt-chart-body">
                    <!-- JavaScriptで動的生成されます -->
                </div>
            </div>
        </main>
        
        <!-- コピーライトフッター -->
        <footer style="text-align: center; margin-top: 3rem; padding: 2rem 0; border-top: 1px solid var(--panel-border); color: var(--text-secondary); font-size: 0.8rem;">
            <p style="margin-bottom: 0.5rem;">&copy; 2026 TaskFlow Project. All rights reserved.</p>
            <p style="opacity: 0.7;">Powered by PHP 8.1, MySQL &amp; SQLite 3. Securely protected by TransCipher. Desktop client built with Qt 6.</p>
        </footer>
    </div>

    <!-- ホバー詳細情報表示用のツールチップ -->
    <div class="tooltip" id="cell-tooltip"></div>

    <script>
        // PHPから渡されたJSONデータをJavaScriptオブジェクトとして展開
        const rawSchedules = <?php echo json_encode($schedules, JSON_UNESCAPED_UNICODE); ?>;
        const timeSlots = <?php echo json_encode($timeSlots, JSON_UNESCAPED_UNICODE); ?>;
        const holidays = <?php echo json_encode($holidays, JSON_UNESCAPED_UNICODE); ?>;
        const categoryTimeDisplay = <?php echo json_encode(CATEGORY_TIME_DISPLAY, JSON_UNESCAPED_UNICODE); ?>;

        let currentDate = new Date(2026, 5, 1); // 2026年6月を初期値とする (月は0始まりなので 5=6月)
        let currentView = 'month'; // 'month', 'week', 'gantt'

        // 選択されたカテゴリのセット (空の場合はすべて表示)
        const selectedCategories = new Set();
        // 管理者権限フラグ
        const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;

        // ページロード時の初期化
        window.addEventListener('DOMContentLoaded', () => {
            parseUrlParams();
            initCategoryFilters();
            renderView();
        });

        // ログインモーダル表示
        function showLoginModal() {
            const modal = document.getElementById('login-modal');
            if (modal) {
                modal.style.display = 'flex';
                document.getElementById('admin-password').focus();
            }
        }

        // ログインモーダル非表示
        function closeLoginModal() {
            const modal = document.getElementById('login-modal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        // URLパラメータの解析とselectedCategoriesへの登録
        function parseUrlParams() {
            const params = new URLSearchParams(window.location.search);
            const categoriesParam = params.get('categories');
            if (categoriesParam) {
                categoriesParam.split(',').forEach(cat => {
                    const trimmed = cat.trim();
                    if (trimmed) {
                        selectedCategories.add(trimmed);
                    }
                });
            }
        }

        // URLパラメータの更新 (history.replaceState)
        function updateUrlParams() {
            const url = new URL(window.location);
            if (selectedCategories.size > 0) {
                url.searchParams.set('categories', Array.from(selectedCategories).join(','));
            } else {
                url.searchParams.delete('categories');
            }
            window.history.replaceState({}, '', url);

            // ログインフォームのアクションURLパラメータも追従させ、リダイレクト後のフィルタ復元を可能にする
            const loginForm = document.querySelector('#login-modal form');
            if (loginForm) {
                const actionUrl = new URL('index.php', window.location.origin);
                url.searchParams.forEach((val, key) => {
                    if (key !== 'action') {
                        actionUrl.searchParams.set(key, val);
                    }
                });
                loginForm.action = actionUrl.pathname + actionUrl.search;
            }
        }

        // カテゴリフィルターUIの動的生成
        function initCategoryFilters() {
            const filterContainer = document.getElementById('category-filters');
            if (!filterContainer) return;

            // rawSchedules から存在するカテゴリ（空文字除く）を取得
            const categories = new Set();
            rawSchedules.forEach(s => {
                if (s.category && s.category.trim() !== '') {
                    categories.add(s.category.trim());
                }
            });

            filterContainer.innerHTML = '';

            if (categories.size === 0) {
                filterContainer.innerHTML = '<span style="font-size:0.75rem; color:var(--text-secondary); font-style:italic;">(設定済みのカテゴリはありません)</span>';
                return;
            }

            categories.forEach(cat => {
                const chip = document.createElement('div');
                chip.className = 'category-chip';
                // 初期ロード時に選択済みの場合はactiveクラスを付与
                if (selectedCategories.has(cat)) {
                    chip.classList.add('active');
                }
                chip.innerText = cat;
                chip.dataset.category = cat;
                chip.addEventListener('click', () => toggleCategoryFilter(chip, cat));
                filterContainer.appendChild(chip);
            });
        }

        // カテゴリフィルターの切り替え
        function toggleCategoryFilter(chip, category) {
            if (selectedCategories.has(category)) {
                selectedCategories.delete(category);
                chip.classList.remove('active');
            } else {
                selectedCategories.add(category);
                chip.classList.add('active');
            }
            updateUrlParams();
            renderView();
        }

        let filterVisible = true;
        function toggleCategoryFilterVisibility() {
            const container = document.getElementById('category-filters-container');
            const icon = document.getElementById('filter-toggle-icon');
            if (!container || !icon) return;

            if (filterVisible) {
                container.style.display = 'none';
                icon.style.transform = 'rotate(-90deg)';
                filterVisible = false;
            } else {
                container.style.display = 'block';
                icon.style.transform = 'rotate(0deg)';
                filterVisible = true;
            }
        }

        // フィルター状態が変わったときの再描画（非公開トグル用）
        function onFilterChange() {
            renderView();
        }

        // フィルターが適用された予定データを取得する
        function getFilteredSchedules() {
            const showPrivateCheckbox = document.getElementById('toggle-private-schedules');
            const showPrivate = showPrivateCheckbox ? showPrivateCheckbox.checked : false;

            return rawSchedules.filter(sched => {
                // 1. 論理削除された予定は除外
                if (parseInt(sched.is_deleted)) return false;

                // 2. 非公開予定のフィルタリング (管理者が非公開トグルをOFFにしている場合)
                if (parseInt(sched.is_public) === 0) {
                    if (!isAdmin || !showPrivate) {
                        return false;
                    }
                }

                // 3. カテゴリでの絞り込み
                if (selectedCategories.size > 0) {
                    const schedCat = (sched.category || '').trim();
                    if (!selectedCategories.has(schedCat)) {
                        return false;
                    }
                }

                return true;
            });
        }

        // 期間を戻す
        function prevPeriod() {
            if (currentView === 'month' || currentView === 'gantt') {
                currentDate.setMonth(currentDate.getMonth() - 1);
            } else if (currentView === 'week') {
                currentDate.setDate(currentDate.getDate() - 7);
            }
            renderView();
        }

        // 期間を進める
        function nextPeriod() {
            if (currentView === 'month' || currentView === 'gantt') {
                currentDate.setMonth(currentDate.getMonth() + 1);
            } else if (currentView === 'week') {
                currentDate.setDate(currentDate.getDate() + 7);
            }
            renderView();
        }

        // 表示の切り替え
        function switchView(view) {
            currentView = view;
            
            // タブのスタイリング切り替え
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            if (view === 'month') document.getElementById('tab-month').classList.add('active');
            if (view === 'week') document.getElementById('tab-week').classList.add('active');
            if (view === 'gantt') document.getElementById('tab-gantt').classList.add('active');

            // 表示コンテナの切り替え
            const calContainer = document.getElementById('calendar-view-container');
            const ganttContainer = document.getElementById('gantt-view-container');
            
            if (view === 'gantt') {
                calContainer.style.display = 'none';
                ganttContainer.style.display = 'block';
            } else {
                calContainer.style.display = 'block';
                ganttContainer.style.display = 'none';
            }

            renderView();
        }

        // 現在のビューに応じて描画を実行
        function renderView() {
            const periodTitle = document.getElementById('period-title');
            
            if (currentView === 'month') {
                const year = currentDate.getFullYear();
                const month = currentDate.getMonth() + 1;
                periodTitle.innerText = `${year}年 ${month}月`;
                renderMonthCalendar();
            } else if (currentView === 'week') {
                const startOfWeek = getStartOfWeek(new Date(currentDate));
                const endOfWeek = new Date(startOfWeek);
                endOfWeek.setDate(endOfWeek.getDate() + 6);
                
                const startStr = `${startOfWeek.getFullYear()}/${startOfWeek.getMonth() + 1}/${startOfWeek.getDate()}`;
                const endStr = `${endOfWeek.getFullYear() === startOfWeek.getFullYear() ? '' : endOfWeek.getFullYear() + '/'}${endOfWeek.getMonth() + 1}/${endOfWeek.getDate()}`;
                periodTitle.innerText = `${startStr} 〜 ${endStr}`;
                renderWeekCalendar(startOfWeek);
            } else if (currentView === 'gantt') {
                const year = currentDate.getFullYear();
                const month = currentDate.getMonth() + 1;
                periodTitle.innerText = `${year}年 ${month}月 (ガント)`;
                renderGanttChart();
            }
        }

        // 週の開始日（日曜日始まり）を取得する
        function getStartOfWeek(date) {
            const day = date.getDay();
            const diff = date.getDate() - day; // その週の日曜日へ
            return new Date(date.setDate(diff));
        }

        // 負荷計算 (指定日の各時間枠ごとの未完了タスク負荷)
        // 判定基準: 未完了タスク(type='task', status != 'done')の重み合計
        function calculateDailyLoad(dateStr) {
            const loadMap = {};
            
            // 時間枠マップの初期化
            timeSlots.forEach(slot => {
                loadMap[slot.id] = {
                    name: slot.name,
                    limit: parseFloat(slot.limit_hours),
                    current: 0.0,
                    tasks: []
                };
            });

            // 予定データの集計 (絞り込み済みの予定を使用)
            getFilteredSchedules().forEach(sched => {
                if (sched.type === 'task' && sched.status !== 'done') {
                    const allocs = sched.allocations || [];
                    if (allocs.length > 0) {
                        // 子テーブルのデータ（allocations）から集計
                        allocs.forEach(alloc => {
                            if (alloc.date === dateStr) {
                                const slotId = alloc.time_slot_id;
                                if (loadMap[slotId]) {
                                    const hrs = parseFloat(alloc.allocated_hours) || 0.0;
                                    loadMap[slotId].current += hrs;
                                    loadMap[slotId].tasks.push({
                                        title: sched.title,
                                        weight: hrs,
                                        status: sched.status
                                    });
                                }
                            }
                        });
                    } else {
                        // 後方互換：単発タスク（allocationsなし）は開始日に全負荷を割り当て
                        if (sched.start_at && sched.start_at.substring(0, 10) === dateStr) {
                            const slotId = sched.time_slot_id;
                            if (slotId && loadMap[slotId]) {
                                const w = parseFloat(sched.weight) || 0.0;
                                loadMap[slotId].current += w;
                                loadMap[slotId].tasks.push({
                                    title: sched.title,
                                    weight: w,
                                    status: sched.status
                                });
                            }
                        }
                    }
                }
            });

            return loadMap;
        }

        // 月間カレンダーの描画
        function renderMonthCalendar() {
            const container = document.getElementById('calendar-grid-body');
            container.className = "calendar-grid";
            container.innerHTML = '';

            // 曜日ヘッダー
            const dayNames = ['日', '月', '火', '水', '木', '金', '土'];
            dayNames.forEach((name, idx) => {
                const header = document.createElement('div');
                header.className = 'day-header';
                if (idx === 0) header.classList.add('sunday');
                if (idx === 6) header.classList.add('saturday');
                header.innerText = name;
                container.appendChild(header);
            });

            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();

            // 月の初日と末日
            const firstDayOfMonth = new Date(year, month, 1);
            const lastDayOfMonth = new Date(year, month + 1, 0);

            // カレンダー開始日（日曜日に合わせるために前月分を含める）
            let startDay = firstDayOfMonth.getDay();
            let prevDaysOffset = startDay; // 日曜日(0)なら0日分、月曜日(1)なら1日分

            const calendarStart = new Date(firstDayOfMonth);
            calendarStart.setDate(calendarStart.getDate() - prevDaysOffset);

            // 6週間分 (42日分) 描画
            for (let i = 0; i < 42; i++) {
                const cellDate = new Date(calendarStart);
                cellDate.setDate(calendarStart.getDate() + i);

                const dateStr = formatDate(cellDate);
                const cell = document.createElement('div');
                cell.className = 'calendar-cell';
                
                // 土日判定
                const dayOfWeek = cellDate.getDay();
                if (dayOfWeek === 0) cell.classList.add('sunday');
                if (dayOfWeek === 6) cell.classList.add('saturday');

                // 当月外判定
                if (cellDate.getMonth() !== month) {
                    cell.classList.add('other-month');
                }

                // 祝日判定
                let holidayName = '';
                if (holidays[dateStr]) {
                    cell.classList.add('holiday');
                    holidayName = holidays[dateStr];
                }

                // セルヘッダー
                const cellHeader = document.createElement('div');
                cellHeader.className = 'cell-header';
                
                const dayNum = document.createElement('span');
                dayNum.className = 'day-number';
                dayNum.innerText = cellDate.getDate();
                cellHeader.appendChild(dayNum);

                if (holidayName) {
                    const holidaySpan = document.createElement('span');
                    holidaySpan.className = 'holiday-label';
                    holidaySpan.innerText = holidayName;
                    cellHeader.appendChild(holidaySpan);
                }
                cell.appendChild(cellHeader);

                // 予定リスト
                const listContainer = document.createElement('div');
                listContainer.className = 'event-list';
                
                // その日の予定をフィルタリングして追加
                const dailyEvents = getFilteredSchedules().filter(sched => {
                    if (!sched.start_at) return false;
                    const sDate = sched.start_at.substring(0, 10);
                    
                    if (sched.type === 'period' && sched.end_at) {
                        const eDate = sched.end_at.substring(0, 10);
                        return dateStr >= sDate && dateStr <= eDate;
                    }
                    
                    if (sched.type === 'task') {
                        const allocs = sched.allocations || [];
                        if (allocs.length > 0) {
                            // allocations が存在する日付セル全てに描画される
                            return allocs.some(a => a.date === dateStr);
                        } else if (sched.end_at) {
                            // 期間が設定されたタスク（allocationsなし）も開始〜終了の全日付枠に連日表示
                            const eDate = sched.end_at.substring(0, 10);
                            return dateStr >= sDate && dateStr <= eDate;
                        }
                        // 開始日のみ
                        return sDate === dateStr;
                    }
                    
                    // simple予定
                    return sDate === dateStr;
                });

                dailyEvents.forEach(event => {
                    const evItem = document.createElement('div');
                    evItem.className = `event-item event-${event.type}`;
                    
                    const isPrivate = parseInt(event.is_public) === 0;
                    if (isPrivate) {
                        evItem.classList.add('private-schedule');
                    }

                    let prefix = "";
                    if (isPrivate) {
                        prefix = `🔒 `;
                    }

                    if (event.type === 'task') {
                        evItem.classList.add(event.status); // doing, todo
                        prefix += `<span class="task-weight-dot" style="background:${event.status === 'doing' ? 'var(--accent-orange)' : 'var(--accent-green)'}"></span>`;
                    }
                    
                    const timeLabel = getEventTimeLabel(event);
                    const displayTitle = isPrivate ? `[非公開] ${event.title}` : event.title;
                    evItem.innerHTML = `${prefix}<span class="event-time" style="font-weight:600; margin-right:4px;">${escapeHtml(timeLabel)}</span>${escapeHtml(displayTitle)}`;
                    evItem.addEventListener('click', (e) => {
                        e.stopPropagation();
                        showEventDetail(event, cellDate);
                    });
                    listContainer.appendChild(evItem);
                });
                cell.appendChild(listContainer);

                // 負荷インジケータの計算と描画
                const dailyLoad = calculateDailyLoad(dateStr);
                const loadIndicatorContainer = document.createElement('div');
                loadIndicatorContainer.className = 'load-indicator-container';
                
                let hasAlert = false;
                let alertDetails = [];

                Object.keys(dailyLoad).forEach(slotId => {
                    const load = dailyLoad[slotId];
                    if (load.tasks.length > 0) {
                        const ind = document.createElement('span');
                        ind.className = 'load-indicator';
                        
                        // limit_hours を超えている場合は警告色にする
                        if (load.current > load.limit) {
                            ind.classList.add('warning');
                            hasAlert = true;
                            alertDetails.push(`${load.name}: ${load.current}h / 制限${load.limit}h`);
                        } else {
                            ind.innerText = `${load.name.substring(0,2)}:${load.current}h`;
                            loadIndicatorContainer.appendChild(ind);
                        }
                    }
                });

                // 警告が発生している時間枠がある場合は、目立たせて先頭に配置
                if (hasAlert) {
                    const alertInd = document.createElement('span');
                    alertInd.className = 'load-indicator warning';
                    alertInd.innerText = `⚠ 負荷警告`;
                    loadIndicatorContainer.prepend(alertInd);

                    // セル全体にホバー時のツールチップイベントを追加
                    cell.addEventListener('mouseenter', (e) => {
                        showTooltip(e, `<strong>時間枠の許容負荷超過</strong><br>${alertDetails.join('<br>')}`);
                    });
                    cell.addEventListener('mouseleave', hideTooltip);
                }

                cell.appendChild(loadIndicatorContainer);
                container.appendChild(cell);
            }
        }

        // 週間スケジュールの描画
        function renderWeekCalendar(startOfWeek) {
            const container = document.getElementById('calendar-grid-body');
            container.className = "calendar-grid";
            container.innerHTML = '';

            // 曜日ヘッダー
            const dayNames = ['日', '月', '火', '水', '木', '金', '土'];
            dayNames.forEach((name, idx) => {
                const header = document.createElement('div');
                header.className = 'day-header';
                if (idx === 0) header.classList.add('sunday');
                if (idx === 6) header.classList.add('saturday');
                
                const d = new Date(startOfWeek);
                d.setDate(startOfWeek.getDate() + idx);
                header.innerHTML = `${name}<br><span style="font-size:0.75rem;font-weight:normal">${d.getMonth()+1}/${d.getDate()}</span>`;
                container.appendChild(header);
            });

            // 1週間分 (7セル) 描画
            for (let i = 0; i < 7; i++) {
                const cellDate = new Date(startOfWeek);
                cellDate.setDate(startOfWeek.getDate() + i);

                const dateStr = formatDate(cellDate);
                const cell = document.createElement('div');
                cell.className = 'calendar-cell';
                cell.style.minHeight = '350px'; // 週表示は縦幅を広げる

                const dayOfWeek = cellDate.getDay();
                if (dayOfWeek === 0) cell.classList.add('sunday');
                if (dayOfWeek === 6) cell.classList.add('saturday');

                let holidayName = '';
                if (holidays[dateStr]) {
                    cell.classList.add('holiday');
                    holidayName = holidays[dateStr];
                }

                const cellHeader = document.createElement('div');
                cellHeader.className = 'cell-header';
                
                const dayNum = document.createElement('span');
                dayNum.className = 'day-number';
                dayNum.innerText = cellDate.getDate();
                cellHeader.appendChild(dayNum);

                if (holidayName) {
                    const holidaySpan = document.createElement('span');
                    holidaySpan.className = 'holiday-label';
                    holidaySpan.innerText = holidayName;
                    cellHeader.appendChild(holidaySpan);
                }
                cell.appendChild(cellHeader);

                // 予定リスト (詳細表示できるように縦に並べる)
                const listContainer = document.createElement('div');
                listContainer.className = 'event-list';
                listContainer.style.maxHeight = '240px';
                
                const dailyEvents = getFilteredSchedules().filter(sched => {
                    if (!sched.start_at) return false;
                    const sDate = sched.start_at.substring(0, 10);
                    
                    if (sched.type === 'period' && sched.end_at) {
                        const eDate = sched.end_at.substring(0, 10);
                        return dateStr >= sDate && dateStr <= eDate;
                    }
                    
                    if (sched.type === 'task') {
                        const allocs = sched.allocations || [];
                        if (allocs.length > 0) {
                            // allocations が存在する日付セル全てに描画される
                            return allocs.some(a => a.date === dateStr);
                        } else if (sched.end_at) {
                            // 期間が設定されたタスク（allocationsなし）も開始〜終了の全日付枠に連日表示
                            const eDate = sched.end_at.substring(0, 10);
                            return dateStr >= sDate && dateStr <= eDate;
                        }
                        // 開始日のみ
                        return sDate === dateStr;
                    }
                    
                    // simple予定
                    return sDate === dateStr;
                });

                dailyEvents.forEach(event => {
                    const evItem = document.createElement('div');
                    evItem.className = `event-item event-${event.type}`;
                    
                    const isPrivate = parseInt(event.is_public) === 0;
                    if (isPrivate) {
                        evItem.classList.add('private-schedule');
                    }

                    let prefix = "";
                    if (isPrivate) {
                        prefix = `🔒 `;
                    }

                    let suffix = "";
                    if (event.type === 'task') {
                        evItem.classList.add(event.status);
                        prefix += `<span class="task-weight-dot" style="background:${event.status === 'doing' ? 'var(--accent-orange)' : 'var(--accent-green)'}"></span>`;
                        suffix = ` <span style="opacity:0.6;font-size:0.65rem">(${event.weight}h)</span>`;
                    }
                    
                    const timeLabel = getEventTimeLabel(event);
                    const displayTitle = isPrivate ? `[非公開] ${event.title}` : event.title;
                    evItem.innerHTML = `${prefix}<span class="event-time" style="font-weight:600; margin-right:4px;">${escapeHtml(timeLabel)}</span>${escapeHtml(displayTitle)}${suffix}`;
                    evItem.addEventListener('click', (e) => {
                        e.stopPropagation();
                        showEventDetail(event, cellDate);
                    });
                    listContainer.appendChild(evItem);
                });
                cell.appendChild(listContainer);

                // 負荷詳細を一覧で表示
                const dailyLoad = calculateDailyLoad(dateStr);
                const loadContainer = document.createElement('div');
                loadContainer.className = 'load-indicator-container';
                loadContainer.style.flexDirection = 'column';
                loadContainer.style.gap = '4px';

                Object.keys(dailyLoad).forEach(slotId => {
                    const load = dailyLoad[slotId];
                    if (load.tasks.length > 0) {
                        const isOver = load.current > load.limit;
                        const ind = document.createElement('div');
                        ind.className = 'load-indicator';
                        ind.style.width = '100%';
                        ind.style.display = 'flex';
                        ind.style.justifyContent = 'space-between';
                        
                        if (isOver) {
                            ind.classList.add('warning');
                            ind.innerHTML = `<span>⚠ ${load.name}</span><span>${load.current} / ${load.limit}h</span>`;
                        } else {
                            ind.innerHTML = `<span>${load.name}</span><span>${load.current} / ${load.limit}h</span>`;
                        }
                        loadContainer.appendChild(ind);
                    }
                });
                cell.appendChild(loadContainer);
                container.appendChild(cell);
            }
        }

        // ガントチャートの描画
        function renderGanttChart() {
            const container = document.getElementById('gantt-chart-body');
            container.innerHTML = '';

            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();

            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const isCurrentMonth = today.getFullYear() === year && today.getMonth() === month;

            // 対象月の全日付を生成
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const timelineDays = [];
            for (let d = 1; d <= daysInMonth; d++) {
                timelineDays.push(new Date(year, month, d));
            }

            // ガントグリッド全体のコンテナ
            const ganttGrid = document.createElement('div');
            ganttGrid.className = 'gantt-grid';

            // 左パネル: 予定タイトル一覧
            const leftPanel = document.createElement('div');
            leftPanel.className = 'gantt-left-panel';

            const leftHeader = document.createElement('div');
            leftHeader.className = 'gantt-row-header title-col';
            leftHeader.innerText = '予定・タスク名';
            leftPanel.appendChild(leftHeader);

            // 右パネル: タイムライン
            const rightPanel = document.createElement('div');
            rightPanel.className = 'gantt-right-panel';

            // タイムラインヘッダー
            const timelineHeader = document.createElement('div');
            timelineHeader.className = 'gantt-timeline-header';
            
            timelineDays.forEach(date => {
                const hCell = document.createElement('div');
                hCell.className = 'gantt-header-cell';
                
                const dateStr = formatDate(date);
                const dayOfWeek = date.getDay();
                if (dayOfWeek === 0) hCell.classList.add('sunday');
                if (dayOfWeek === 6) hCell.classList.add('saturday');
                if (holidays[dateStr]) hCell.classList.add('holiday');
                
                hCell.innerHTML = `<span>${date.getDate()}</span><span style="font-size:0.55rem;opacity:0.6">${['日','月','火','水','木','金','土'][dayOfWeek]}</span>`;
                timelineHeader.appendChild(hCell);
            });
            rightPanel.appendChild(timelineHeader);

            // 描画対象の予定データを抽出
            // 当月にかぶる予定 (period または task/simple)
            const firstDateStr = formatDate(timelineDays[0]);
            const lastDateStr = formatDate(timelineDays[timelineDays.length - 1]);

            const targetEvents = getFilteredSchedules().filter(sched => {
                if (sched.start_at) {
                    const sDate = sched.start_at.substring(0, 10);
                    const eDate = sched.end_at ? sched.end_at.substring(0, 10) : sDate;
                    
                    // 期間が当月の範囲にかぶっているか
                    return sDate <= lastDateStr && eDate >= firstDateStr;
                }
                return false;
            });

            const parseLocalDate = (dateStr) => {
                const parts = dateStr.split('-');
                return new Date(parts[0], parts[1] - 1, parts[2]);
            };

            if (targetEvents.length === 0) {
                // 予定がない場合の空表示
                const emptyRowLeft = document.createElement('div');
                emptyRowLeft.className = 'gantt-row-header';
                emptyRowLeft.style.color = 'var(--text-secondary)';
                emptyRowLeft.innerText = '表示する予定がありません';
                leftPanel.appendChild(emptyRowLeft);

                const emptyRowRight = document.createElement('div');
                emptyRowRight.className = 'gantt-row-body';
                rightPanel.appendChild(emptyRowRight);
            }

            // 各予定行の描画
            targetEvents.forEach(event => {
                // 左パネル追加
                const rowHeader = document.createElement('div');
                rowHeader.className = 'gantt-row-header';
                
                const isPrivate = parseInt(event.is_public) === 0;
                if (isPrivate) {
                    rowHeader.classList.add('private-schedule');
                    rowHeader.innerText = `🔒 [非公開] ${event.title}`;
                } else {
                    rowHeader.innerText = event.title;
                }
                leftPanel.appendChild(rowHeader);

                // 右パネルの行追加
                const rowBody = document.createElement('div');
                rowBody.className = 'gantt-row-body';

                // 背景に日付のグリッド線を描画
                timelineDays.forEach(date => {
                    const bgGrid = document.createElement('div');
                    bgGrid.style.width = '40px';
                    bgGrid.style.height = '100%';
                    bgGrid.style.borderRight = '1px solid rgba(255, 255, 255, 0.02)';
                    bgGrid.style.flexShrink = '0';
                    
                    const dayOfWeek = date.getDay();
                    if (dayOfWeek === 0 || dayOfWeek === 6 || holidays[formatDate(date)]) {
                        bgGrid.style.background = 'rgba(255, 255, 255, 0.01)';
                    }
                    rowBody.appendChild(bgGrid);
                });

                // 予定のバーの配置計算
                const startAtStr = event.start_at.substring(0, 10);
                const endAtStr = event.end_at ? event.end_at.substring(0, 10) : startAtStr;

                const startDateObj = parseLocalDate(startAtStr);
                const endDateObj = parseLocalDate(endAtStr);

                // 当月の開始日と終了日からのインデックス（オフセット）算出
                const startOffset = Math.max(0, Math.round((startDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));
                const endOffset = Math.min(timelineDays.length - 1, Math.round((endDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));

                const durationDays = endOffset - startOffset + 1;

                if (durationDays > 0) {
                    const bar = document.createElement('div');
                    bar.className = `gantt-bar ${event.type}`;
                    
                    if (isPrivate) {
                        bar.classList.add('private-schedule');
                    }

                    const displayTitle = isPrivate ? `🔒 [非公開] ${event.title}` : event.title;

                    if (event.type === 'task') {
                        // Calculate actual progress
                        let P_total = 0;
                        if (event.progress && Array.isArray(event.progress)) {
                            event.progress.forEach(p => {
                                P_total += parseInt(p.progress_percent || 0, 10);
                            });
                        }
                        P_total = Math.min(100, Math.max(0, P_total));

                        if (P_total === 0) {
                            bar.classList.add('todo');
                        } else if (P_total >= 100) {
                            bar.classList.add('done');
                        } else {
                            const P_planned = calculatePlannedProgress(startDateObj, endDateObj, today);
                            bar.classList.add('doing');
                            if (P_total < P_planned) {
                                bar.classList.add('delayed');
                            } else if (P_total > P_planned) {
                                bar.classList.add('ahead');
                            } else {
                                bar.classList.add('onschedule');
                            }
                        }

                        // Determine earliest and latest dates with positive progress or allocation
                        let actualStartDateObj = null;
                        let actualEndDateObj = null;

                        if (event.progress && Array.isArray(event.progress)) {
                            event.progress.forEach(p => {
                                const pct = parseInt(p.progress_percent || 0, 10);
                                if (pct > 0) {
                                    const d = parseLocalDate(p.date);
                                    if (!actualStartDateObj || d < actualStartDateObj) actualStartDateObj = d;
                                    if (!actualEndDateObj || d > actualEndDateObj) actualEndDateObj = d;
                                }
                            });
                        }

                        if (event.allocations && Array.isArray(event.allocations)) {
                            event.allocations.forEach(alloc => {
                                const hours = parseFloat(alloc.allocated_hours || 0);
                                if (hours > 0) {
                                    const d = parseLocalDate(alloc.date);
                                    if (!actualStartDateObj || d < actualStartDateObj) actualStartDateObj = d;
                                    if (!actualEndDateObj || d > actualEndDateObj) actualEndDateObj = d;
                                }
                            });
                        }

                        // If we have an early start actual date before planned start date
                        if (actualStartDateObj && actualStartDateObj < startDateObj) {
                            const earlyStartOffset = Math.max(0, Math.round((actualStartDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));
                            const earlyEndOffset = Math.round((startDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)) - 1;
                            const earlyDurationDays = earlyEndOffset - earlyStartOffset + 1;

                            if (earlyDurationDays > 0) {
                                const earlyBar = document.createElement('div');
                                earlyBar.className = 'gantt-bar actual-extension early';
                                earlyBar.style.left = `${earlyStartOffset * 40 + 8}px`;
                                earlyBar.style.width = `${earlyDurationDays * 40}px`;
                                
                                const earlyStartStr = formatDate(actualStartDateObj);
                                const dayBeforePlannedStart = new Date(startDateObj.getTime() - (1000 * 60 * 60 * 24));
                                const earlyEndStr = formatDate(dayBeforePlannedStart);
                                const tooltipMsg = `${escapeHtml(displayTitle)}: 予定外の早期着手期間 (${earlyStartStr} 〜 ${earlyEndStr})`;

                                earlyBar.addEventListener('mouseenter', (e) => {
                                    showTooltip(e, tooltipMsg);
                                });
                                earlyBar.addEventListener('mouseleave', hideTooltip);
                                earlyBar.addEventListener('click', () => {
                                    showEventDetail(event, startDateObj);
                                });
                                rowBody.appendChild(earlyBar);
                            }
                        }

                        // If we have a late finish actual date after planned end date
                        if (actualEndDateObj && actualEndDateObj > endDateObj) {
                            const lateStartOffset = Math.round((endDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)) + 1;
                            const lateEndOffset = Math.min(timelineDays.length - 1, Math.round((actualEndDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));
                            const lateDurationDays = lateEndOffset - lateStartOffset + 1;

                            if (lateDurationDays > 0) {
                                const lateBar = document.createElement('div');
                                lateBar.className = 'gantt-bar actual-extension late';
                                lateBar.style.left = `${(endOffset + 1) * 40 - 8}px`;
                                lateBar.style.width = `${lateDurationDays * 40}px`;

                                const dayAfterPlannedEnd = new Date(endDateObj.getTime() + (1000 * 60 * 60 * 24));
                                const lateStartStr = formatDate(dayAfterPlannedEnd);
                                const lateEndStr = formatDate(actualEndDateObj);
                                const tooltipMsg = `${escapeHtml(displayTitle)}: 予定外の超過期間 (${lateStartStr} 〜 ${lateEndStr})`;

                                lateBar.addEventListener('mouseenter', (e) => {
                                    showTooltip(e, tooltipMsg);
                                });
                                lateBar.addEventListener('mouseleave', hideTooltip);
                                lateBar.addEventListener('click', () => {
                                    showEventDetail(event, startDateObj);
                                });
                                rowBody.appendChild(lateBar);
                            }
                        }
                    }

                    if (startAtStr === endAtStr) {
                        // 1日未満（当日のみ）の場合は●（ドット）として配置
                        bar.style.left = `${startOffset * 40 + 12}px`; // 40pxセルの真ん中に配置 (40-16)/2 = 12px
                        bar.style.width = '16px';
                        bar.style.height = '16px';
                        bar.style.borderRadius = '50%';
                        bar.style.padding = '0';
                        bar.style.justifyContent = 'center';
                        bar.innerText = '';
                    } else {
                        // 1日以上の場合は通常のバーとして配置
                        bar.style.left = `${startOffset * 40 + 8}px`;
                        bar.style.width = `${durationDays * 40 - 16}px`;
                        bar.innerText = displayTitle;
                    }

                    // ホバー時にツールチップでタイトルを表示
                    bar.addEventListener('mouseenter', (e) => {
                        showTooltip(e, escapeHtml(displayTitle));
                    });
                    bar.addEventListener('mouseleave', hideTooltip);

                    bar.addEventListener('click', () => {
                        showEventDetail(event, startDateObj);
                    });

                    rowBody.appendChild(bar);
                }

                rightPanel.appendChild(rowBody);
            });

            // 稲妻線と本日線のSVG描画 (今年今月のみ)
            if (isCurrentMonth && targetEvents.length > 0) {
                const totalHeight = targetEvents.length * 48;
                const x_today = (today.getDate() - 1) * 40;

                const svgNS = "http://www.w3.org/2000/svg";
                const svg = document.createElementNS(svgNS, "svg");
                svg.style.position = 'absolute';
                svg.style.top = '48px';
                svg.style.left = '0';
                svg.style.width = `${daysInMonth * 40}px`;
                svg.style.height = `${totalHeight}px`;
                svg.style.pointerEvents = 'none';
                svg.style.zIndex = '5';

                // 1. 本日線（縦点線）
                const todayLine = document.createElementNS(svgNS, "line");
                todayLine.setAttribute("x1", x_today);
                todayLine.setAttribute("y1", 0);
                todayLine.setAttribute("x2", x_today);
                todayLine.setAttribute("y2", totalHeight);
                todayLine.setAttribute("stroke", "var(--alert-red)");
                todayLine.setAttribute("stroke-width", "1.5");
                todayLine.setAttribute("stroke-dasharray", "4,4");
                svg.appendChild(todayLine);

                // 2. 稲妻線折れ線
                const points = [];
                points.push(`${x_today},0`);

                targetEvents.forEach((event, index) => {
                    const y = index * 48 + 24;
                    let x = x_today;

                    if (event.type === 'task') {
                        let P_total = 0;
                        if (event.progress && Array.isArray(event.progress)) {
                            event.progress.forEach(p => {
                                P_total += parseInt(p.progress_percent || 0, 10);
                            });
                        }
                        P_total = Math.min(100, Math.max(0, P_total));

                        const startAtStr = event.start_at.substring(0, 10);
                        const endAtStr = event.end_at ? event.end_at.substring(0, 10) : startAtStr;
                        const startDateObj = parseLocalDate(startAtStr);
                        const endDateObj = parseLocalDate(endAtStr);

                        const actualTotalDays = Math.round((endDateObj - startDateObj) / (1000 * 60 * 60 * 24)) + 1;
                        const progressDays = (P_total / 100) * actualTotalDays;
                        const progressDate = new Date(startDateObj.getTime() + progressDays * (1000 * 60 * 60 * 24));

                        const diffTime = progressDate - timelineDays[0];
                        const diffDays = diffTime / (1000 * 60 * 60 * 24);
                        x = Math.max(0, Math.min(daysInMonth * 40, diffDays * 40));

                        // 進捗ドット
                        const circle = document.createElementNS(svgNS, "circle");
                        circle.setAttribute("cx", x);
                        circle.setAttribute("cy", y);
                        circle.setAttribute("r", "4");
                        circle.setAttribute("fill", "var(--alert-red)");
                        svg.appendChild(circle);
                    }

                    points.push(`${x},${y}`);
                });

                points.push(`${x_today},${totalHeight}`);

                const polyline = document.createElementNS(svgNS, "polyline");
                polyline.setAttribute("points", points.join(" "));
                polyline.setAttribute("stroke", "var(--alert-red)");
                polyline.setAttribute("stroke-width", "2");
                polyline.setAttribute("fill", "none");
                svg.appendChild(polyline);

                rightPanel.appendChild(svg);
            }

            ganttGrid.appendChild(leftPanel);
            ganttGrid.appendChild(rightPanel);
            container.appendChild(ganttGrid);
        }

        // 計画進捗率の算出関数
        function calculatePlannedProgress(startDateObj, endDateObj, todayDateObj) {
            const start = new Date(startDateObj.getFullYear(), startDateObj.getMonth(), startDateObj.getDate());
            const end = new Date(endDateObj.getFullYear(), endDateObj.getMonth(), endDateObj.getDate());
            const today = new Date(todayDateObj.getFullYear(), todayDateObj.getMonth(), todayDateObj.getDate());

            const totalDays = Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
            
            if (today < start) {
                return 0;
            } else if (today > end) {
                return 100;
            } else {
                const elapsedDays = Math.round((today - start) / (1000 * 60 * 60 * 24));
                return (elapsedDays / totalDays) * 100;
            }
        }

        // ツールチップ表示
        function showTooltip(e, content) {
            const tooltip = document.getElementById('cell-tooltip');
            tooltip.innerHTML = content;
            tooltip.style.display = 'block';
            
            // マウス位置に合わせて座標を調整
            const x = e.clientX + 15;
            const y = e.clientY + 15;
            tooltip.style.left = `${x}px`;
            tooltip.style.top = `${y}px`;
        }

        // ツールチップ非表示
        function hideTooltip() {
            document.getElementById('cell-tooltip').style.display = 'none';
        }

        // 予定クリック時の詳細ダイアログ表示 (詳細版)
        function showEventDetail(event, date) {
            let details = `【予定詳細】\n\nタイトル: ${event.title}\n種類: ${event.type}\n日付: ${event.start_at}`;
            if (event.end_at) {
                details += ` 〜 ${event.end_at}`;
            }
            details += `\nステータス: ${event.status || 'なし'}\n重み: ${event.weight || 0}h\nカテゴリ: ${event.category || '一般'}`;
            
            if (event.type === 'task' && event.allocations && event.allocations.length > 0) {
                details += `\n\n【日別作業時間配分】`;
                event.allocations.forEach(a => {
                    const slot = timeSlots.find(s => s.id === a.time_slot_id);
                    const slotName = slot ? slot.name : '時間枠不明';
                    details += `\n・${a.date} [${slotName}]: ${a.allocated_hours}h`;
                });
            }
            
            details += `\n\n詳細メモ: ${event.description || '(なし)'}`;
            alert(details);
        }

        // 日付オブジェクトを YYYY-MM-DD 形式にフォーマットする
        function formatDate(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        // HTMLエスケープ処理
        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // カテゴリと予定データに基づいて表示時間を整形するヘルパー
        function getEventTimeLabel(event) {
            if (!event.start_at) return '';
            
            const cat = (event.category || '').trim().toLowerCase();
            const config = categoryTimeDisplay[cat] || categoryTimeDisplay['default'] || 'start';
            
            if (config === 'none') return '';
            
            const startTime = event.start_at.substring(11, 16);
            if (config === 'start') {
                return startTime + ' ';
            }
            
            if (config === 'both') {
                const endTime = event.end_at ? event.end_at.substring(11, 16) : '';
                if (endTime) {
                    return `${startTime}-${endTime} `;
                }
                return startTime + ' ';
            }
            
            return '';
        }
    </script>

    <!-- 管理者ログインモーダル -->
    <div id="login-modal" class="modal-overlay" style="display: <?php echo $login_error ? 'flex' : 'none'; ?>;">
        <div class="modal-content">
            <button class="modal-close-btn" onclick="closeLoginModal()">&times;</button>
            <h2>管理者ログイン</h2>
            <?php if ($login_error): ?>
                <div class="alert alert-error" style="margin-bottom: 1rem; padding: 0.5rem 1rem; font-size: 0.8rem; text-align: left;"><?php echo h($login_error); ?></div>
            <?php endif; ?>
            <form method="POST" action="index.php<?php echo !empty($_GET) ? '?' . h(http_build_query(array_filter($_GET, function($k) { return $k !== 'action'; }, ARRAY_FILTER_USE_KEY))) : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="form_action" value="index_login">
                <div class="form-group" style="text-align: left; display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem;">
                    <label for="admin-password" style="font-size: 0.85rem; font-weight:600; color:var(--text-secondary);">パスワード</label>
                    <input type="password" id="admin-password" name="password" required placeholder="管理者パスワードを入力" autofocus style="width:100%; padding:0.75rem 1rem; background:rgba(15, 23, 42, 0.6); border:1px solid var(--panel-border); border-radius:8px; color:var(--text-primary);">
                </div>
                <button type="submit" class="btn" style="width:100%; margin-top: 1rem; font-weight:600;">ログイン</button>
            </form>
        </div>
    </div>
</body>
</html>
