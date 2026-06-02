<?php
/**
 * TaskFlow 管理者ダッシュボード (admin.php)
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
use Repository\ScheduleRepository;
use Repository\TimeSlotRepository;
use Repository\WebhookRepository;

// TransCipher の初期化
CryptoUtil::init(TRANSCIPHER_SECRET_KEY, TRANSCIPHER_API_URL, TRANSCIPHER_API_TOKEN);

$db = get_db_connection();
$scheduleRepo = new ScheduleRepository($db);
$timeSlotRepo = new TimeSlotRepository($db);
$webhookRepo = new WebhookRepository($db);

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
    header("Location: admin.php");
    exit;
}

// ログイン処理
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'login') {
    // CSRF検証
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header('HTTP/1.1 403 Forbidden');
        echo "CSRF token verification failed.";
        exit;
    }

    $password = $_POST['password'] ?? '';
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        // セッション固定化攻撃防止のため、ログイン成功時にセッションIDを再生成
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $login_error = 'パスワードが正しくありません。';
    }
}

// ログインチェック
$is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// UUIDv4 生成関数 (Pure PHP)
function generate_uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// POST アクションハンドリング (ログイン時のみ)
$message = '';
$error = '';

if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    // CSRF検証
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header('HTTP/1.1 403 Forbidden');
        echo "CSRF token verification failed.";
        exit;
    }

    $form_action = $_POST['form_action'];

    try {
        if ($form_action === 'save_schedule') {
            // 新規または編集保存
            $id = $_POST['id'] ?: generate_uuidv4();
            $schedule = [
                'id' => $id,
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'type' => $_POST['type'] ?? 'simple',
                'start_at' => $_POST['start_at'] ?: null,
                'end_at' => $_POST['end_at'] ?: null,
                'status' => $_POST['status'] ?? 'todo',
                'priority' => (int)($_POST['priority'] ?? 0),
                'weight' => (double)($_POST['weight'] ?? 0.0),
                'time_slot_id' => $_POST['time_slot_id'] ?: null,
                'category' => $_POST['category'] ?? 'general',
                'is_public' => isset($_POST['is_public']) ? 1 : 0,
                'discord_webhook_id' => $_POST['discord_webhook_id'] ?: null,
                'is_deleted' => 0
            ];

            if ($scheduleRepo->save($schedule)) {
                $message = "予定を保存しました。";
                
                // Discord通知の送信処理
                if (!empty($schedule['discord_webhook_id'])) {
                    $whSql = "SELECT * FROM webhooks WHERE id = :id AND is_deleted = 0";
                    $whStmt = $db->prepare($whSql);
                    $whStmt->execute([':id' => $schedule['discord_webhook_id']]);
                    $webhook = $whStmt->fetch();
                    if ($webhook) {
                        try {
                            $webhookUrl = CryptoUtil::decrypt($webhook['url']);
                            \Notification\DiscordNotifier::notify($schedule, $webhookUrl);
                        } catch (\Exception $e) {
                            error_log("Failed to send Discord notification: " . $e->getMessage());
                        }
                    }
                }
            } else {
                $error = "予定の保存に失敗しました。";
            }
        } elseif ($form_action === 'delete_schedule') {
            // 論理削除
            $id = $_POST['id'] ?? '';
            if ($id) {
                // schedules レコードを取得して、is_deleted = 1 にして保存
                $sql = "UPDATE schedules SET is_deleted = 1, updated_at = NOW() WHERE id = :id";
                $stmt = $db->prepare($sql);
                if ($stmt->execute([':id' => $id])) {
                    $message = "予定を削除しました。";
                } else {
                    $error = "予定の削除に失敗しました。";
                }
            }
        } elseif ($form_action === 'bulk_public') {
            // 一括公開設定
            $ids = $_POST['schedule_ids'] ?? [];
            $status = (int)($_POST['status'] ?? 0);
            if (!empty($ids)) {
                if ($scheduleRepo->updatePublicStatus($ids, $status)) {
                    $message = "選択した予定の公開設定を一括更新しました。";
                } else {
                    $error = "一括更新に失敗しました。";
                }
            }
        } elseif ($form_action === 'save_timeslot') {
            // 時間枠保存
            $id = $_POST['id'] ?: generate_uuidv4();
            $slot = [
                'id' => $id,
                'name' => $_POST['name'] ?? '',
                'start_time' => $_POST['start_time'] ?? '',
                'end_time' => $_POST['end_time'] ?? '',
                'limit_hours' => (double)($_POST['limit_hours'] ?? 0.0),
                'is_deleted' => 0
            ];
            if ($timeSlotRepo->save($slot)) {
                $message = "時間枠を保存しました。";
            } else {
                $error = "時間枠の保存に失敗しました。";
            }
        } elseif ($form_action === 'save_webhook') {
            // Webhook保存
            $id = $_POST['id'] ?: generate_uuidv4();
            $webhook = [
                'id' => $id,
                'name' => $_POST['name'] ?? '',
                'url' => $_POST['url'] ?? '',
                'is_deleted' => 0
            ];
            if ($webhookRepo->save($webhook)) {
                $message = "Webhook設定を保存しました。";
            } else {
                $error = "Webhook設定の保存に失敗しました。";
            }
        } elseif ($form_action === 'save_holiday') {
            // 祝日CSVのアップロード保存
            if (!isset($_FILES['holiday_csv']) || $_FILES['holiday_csv']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException("ファイルのアップロードに失敗しました。");
            }
            
            $tempPath = $_FILES['holiday_csv']['tmp_name'];
            
            // 安全な拡張子チェック
            $pathInfo = pathinfo($_FILES['holiday_csv']['name']);
            if (strtolower($pathInfo['extension'] ?? '') !== 'csv') {
                throw new InvalidArgumentException("アップロードできるのはCSVファイルのみです。");
            }

            // HolidayParser でパースできるかテスト (パース失敗時は例外が飛ぶ)
            $parsedHolidays = HolidayParser::parse($tempPath);
            
            // パースに成功したら、固定の一時パス (data/syukujitsu.csv) に上書き保存
            $destPath = __DIR__ . '/../data/syukujitsu.csv';
            
            // $_FILES['holiday_csv']['tmp_name'] は require/include 等で直接ロードしないため安全
            if (move_uploaded_file($tempPath, $destPath)) {
                $message = "祝日CSVをアップロードし、" . count($parsedHolidays) . " 件の祝日データを正常に反映しました。";
            } else {
                $error = "祝日CSVファイルの保存に失敗しました。";
            }
        }
    } catch (\Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// データ取得 (管理者用・すべて)
$allSchedules = $is_logged_in ? $scheduleRepo->findAll() : [];
$allTimeSlots = $is_logged_in ? $timeSlotRepo->findAllActive() : [];
$allWebhooks = $is_logged_in ? $webhookRepo->findAllActive() : [];

// XSSエスケープ
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskFlow | 管理コンソール</title>
    <!-- Google Fonts -->
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
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* ログイン画面のスタイリング */
        .login-box {
            max-width: 400px;
            margin: 8rem auto;
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--panel-border);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }

        .login-box h2 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            text-align: center;
            background: linear-gradient(to right, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .form-group {
            margin-bottom: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        input[type="text"],
        input[type="password"],
        input[type="number"],
        input[type="datetime-local"],
        input[type="time"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.9rem;
            transition: var(--transition-smooth);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.2);
        }

        .btn {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition-smooth);
            text-align: center;
        }

        .btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }

        .btn.btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--panel-border);
            color: var(--text-primary);
        }

        .btn.btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn.btn-danger {
            background: var(--alert-red);
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #a7f3d0;
        }

        /* ダッシュボードレイアウト */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--panel-border);
        }

        .logo h1 {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(to right, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .admin-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .menu-item {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .menu-item:hover, .menu-item.active {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.05);
        }

        .menu-item.active {
            border-left: 4px solid var(--accent-blue);
        }

        .main-panel {
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--panel-border);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .panel-section {
            display: none;
        }

        .panel-section.active {
            display: block;
        }

        .section-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 0.5rem;
        }

        /* 一覧テーブル */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }

        th, td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        th {
            font-weight: 700;
            color: var(--text-secondary);
            background: rgba(0,0,0,0.1);
        }

        tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        /* フォームの行レイアウト */
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        /* トグルスイッチ */
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

        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-public { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-private { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }
        .badge-deleted { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$is_logged_in): ?>
            <!-- ログイン画面 -->
            <div class="login-box">
                <h2>TaskFlow login</h2>
                <?php if ($login_error): ?>
                    <div class="alert alert-error"><?php echo h($login_error); ?></div>
                <?php endif; ?>
                <form method="POST" action="admin.php">
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="form_action" value="login">
                    <div class="form-group">
                        <label for="password">管理者パスワード</label>
                        <input type="password" id="password" name="password" required autofocus>
                    </div>
                    <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">認証する</button>
                </form>
            </div>
        <?php else: ?>
            <!-- 管理者ダッシュボード -->
            <header>
                <div class="logo">
                    <h1>TaskFlow <span style="font-size:1rem; font-weight:normal; color:var(--text-secondary);">管理コンソール</span></h1>
                </div>
                <div>
                    <a href="index.php" target="_blank" class="btn btn-secondary" style="margin-right: 0.5rem; text-decoration:none;">一般画面を開く</a>
                    <a href="admin.php?action=logout" class="btn btn-secondary">ログアウト</a>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo h($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo h($error); ?></div>
            <?php endif; ?>

            <div class="admin-layout">
                <!-- 左メニュー -->
                <nav class="sidebar">
                    <a class="menu-item active" onclick="showSection('section-schedules')">予定・タスク管理</a>
                    <a class="menu-item" onclick="showSection('section-bulk')">一括公開設定</a>
                    <a class="menu-item" onclick="showSection('section-timeslots')">時間枠設定</a>
                    <a class="menu-item" onclick="showSection('section-webhooks')">Discord Webhook設定</a>
                    <a class="menu-item" onclick="showSection('section-holiday')">祝日データ更新</a>
                </nav>

                <!-- 右メインパネル -->
                <main class="main-panel">
                    
                    <!-- セクション 1: 予定・タスク管理 -->
                    <section id="section-schedules" class="panel-section active">
                        <div class="section-title">予定・タスク管理</div>
                        
                        <!-- 登録・編集フォーム -->
                        <form method="POST" action="admin.php" style="margin-bottom: 2.5rem; background:rgba(0,0,0,0.15); padding:1.5rem; border-radius:12px;">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="form_action" value="save_schedule">
                            <input type="hidden" name="id" id="sched-id" value="">

                            <div style="font-weight:700; margin-bottom:1rem; font-size:1rem;" id="form-header">新規予定の登録</div>
                            
                            <div class="form-group">
                                <label for="sched-title">タイトル *</label>
                                <input type="text" id="sched-title" name="title" required>
                            </div>

                            <div class="form-group">
                                <label for="sched-desc">詳細説明・メモ</label>
                                <textarea id="sched-desc" name="description" rows="3"></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="sched-type">予定の種類</label>
                                    <select id="sched-type" name="type" onchange="toggleFormFields()">
                                        <option value="simple">簡易予定 (simple)</option>
                                        <option value="period">期間予定 (period)</option>
                                        <option value="task">個人タスク (task)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="sched-category">カテゴリ/タグ</label>
                                    <input type="text" id="sched-category" name="category" placeholder="work, private, studyなど">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="sched-start">開始日時</label>
                                    <input type="datetime-local" id="sched-start" name="start_at">
                                </div>
                                <div class="form-group">
                                    <label for="sched-end">終了日時</label>
                                    <input type="datetime-local" id="sched-end" name="end_at">
                                </div>
                            </div>

                            <!-- タスク専用フィールド -->
                            <div id="task-fields" style="display: none; border-top: 1px dashed var(--panel-border); padding-top: 1rem; margin-top: 1rem;">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="sched-status">進捗状況</label>
                                        <select id="sched-status" name="status">
                                            <option value="todo">未着手 (todo)</option>
                                            <option value="doing">進行中 (doing)</option>
                                            <option value="done">完了 (done)</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="sched-weight">重み (所要時間・時間単位)</label>
                                        <input type="number" id="sched-weight" name="weight" step="0.5" min="0" value="0">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="sched-timeslot">割り当て時間枠</label>
                                        <select id="sched-timeslot" name="time_slot_id">
                                            <option value="">(未選択)</option>
                                            <?php foreach ($allTimeSlots as $slot): ?>
                                                <option value="<?php echo h($slot['id']); ?>"><?php echo h($slot['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="sched-webhook">通知先 Discord Webhook</label>
                                        <select id="sched-webhook" name="discord_webhook_id">
                                            <option value="">(通知なし)</option>
                                            <?php foreach ($allWebhooks as $wh): ?>
                                                <option value="<?php echo h($wh['id']); ?>"><?php echo h($wh['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem;">
                                <div class="toggle-container">
                                    <input type="checkbox" id="sched-public" name="is_public" class="toggle-input" checked>
                                    <label for="sched-public" style="cursor:pointer;">第三者へ公開する</label>
                                </div>
                                <div style="display:flex; gap:0.5rem;">
                                    <button type="button" class="btn btn-secondary" onclick="clearSchedForm()">クリア</button>
                                    <button type="submit" class="btn">保存する</button>
                                </div>
                            </div>
                        </form>

                        <!-- 一覧リスト -->
                        <div style="font-weight:700; margin-bottom:1rem; font-size:1rem;">登録済みの予定一覧 (削除済除く)</div>
                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>タイトル</th>
                                        <th>種類</th>
                                        <th>期間</th>
                                        <th>負荷(重み)</th>
                                        <th>公開状態</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $activeScheds = array_filter($allSchedules, function($s) { return !parseInt($s['is_deleted']); });
                                    if (empty($activeScheds)): 
                                    ?>
                                        <tr>
                                            <td colspan="6" style="text-align:center; color:var(--text-secondary);">予定が登録されていません。</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($activeScheds as $s): ?>
                                            <tr>
                                                <td style="font-weight:600;"><?php echo h($s['title']); ?></td>
                                                <td><?php echo h($s['type']); ?></td>
                                                <td>
                                                    <?php 
                                                    if ($s['start_at']) {
                                                        echo substr($s['start_at'], 0, 16);
                                                        if ($s['end_at']) {
                                                            echo ' 〜 ' . substr($s['end_at'], 5, 11);
                                                        }
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo $s['type'] === 'task' ? h($s['weight']) . 'h' : '-'; ?></td>
                                                <td>
                                                    <span class="badge <?php echo $s['is_public'] ? 'badge-public' : 'badge-private'; ?>">
                                                        <?php echo $s['is_public'] ? '公開' : '非公開'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem; margin-right:0.25rem;" onclick='editSchedule(<?php echo json_encode($s, JSON_UNESCAPED_UNICODE); ?>)'>編集</button>
                                                    <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('本当にこの予定を削除しますか？');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="form_action" value="delete_schedule">
                                                        <input type="hidden" name="id" value="<?php echo h($s['id']); ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding:0.25rem 0.5rem; font-size:0.75rem;">削除</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- セクション 2: 一括公開設定 -->
                    <section id="section-bulk" class="panel-section">
                        <div class="section-title">一括公開設定</div>
                        <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:1.5rem;">
                            複数の予定を選択し、公開（一般画面へ表示する）または非公開へ一括で変更できます。
                        </p>
                        <form method="POST" action="admin.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="form_action" value="bulk_public">
                            
                            <div style="display:flex; gap:0.5rem; margin-bottom:1.5rem;">
                                <button type="submit" name="status" value="1" class="btn">選択した予定を公開する</button>
                                <button type="submit" name="status" value="0" class="btn btn-secondary">選択した予定を非公開にする</button>
                            </div>

                            <div style="overflow-x:auto;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width: 40px; text-align:center;"><input type="checkbox" onclick="toggleSelectAll(this)"></th>
                                            <th>タイトル</th>
                                            <th>種類</th>
                                            <th>現在状態</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($activeScheds)): ?>
                                            <tr>
                                                <td colspan="4" style="text-align:center; color:var(--text-secondary);">予定がありません。</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($activeScheds as $s): ?>
                                                <tr>
                                                    <td style="text-align:center;"><input type="checkbox" name="schedule_ids[]" value="<?php echo h($s['id']); ?>" class="bulk-checkbox"></td>
                                                    <td><?php echo h($s['title']); ?></td>
                                                    <td><?php echo h($s['type']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $s['is_public'] ? 'badge-public' : 'badge-private'; ?>">
                                                            <?php echo $s['is_public'] ? '公開' : '非公開'; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </section>

                    <!-- セクション 3: 時間枠設定 -->
                    <section id="section-timeslots" class="panel-section">
                        <div class="section-title">作業時間枠の定義</div>
                        
                        <!-- 時間枠登録フォーム -->
                        <form method="POST" action="admin.php" style="margin-bottom: 2rem; background:rgba(0,0,0,0.15); padding:1.5rem; border-radius:12px;">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="form_action" value="save_timeslot">
                            <input type="hidden" name="id" id="slot-id" value="">

                            <div class="form-group">
                                <label for="slot-name">時間枠の名前 *</label>
                                <input type="text" id="slot-name" name="name" required placeholder="例: 午前枠, 午後枠">
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="slot-start">開始時刻 *</label>
                                    <input type="time" id="slot-start" name="start_time" required>
                                </div>
                                <div class="form-group">
                                    <label for="slot-end">終了時刻 *</label>
                                    <input type="time" id="slot-end" name="end_time" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="slot-limit">許容作業時間 (limit_hours) *</label>
                                <input type="number" id="slot-limit" name="limit_hours" step="0.5" min="0" value="0.0">
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                                <button type="button" class="btn btn-secondary" onclick="clearSlotForm()">クリア</button>
                                <button type="submit" class="btn">保存する</button>
                            </div>
                        </form>

                        <div style="font-weight:700; margin-bottom:1rem; font-size:1rem;">設定済みの時間枠</div>
                        <table>
                            <thead>
                                <tr>
                                    <th>名前</th>
                                    <th>時間帯</th>
                                    <th>許容負荷 (時間)</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allTimeSlots)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; color:var(--text-secondary);">時間枠が設定されていません。</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allTimeSlots as $ts): ?>
                                        <tr>
                                            <td style="font-weight:600;"><?php echo h($ts['name']); ?></td>
                                            <td><?php echo h($ts['start_time']) . ' 〜 ' . h($ts['end_time']); ?></td>
                                            <td><?php echo h($ts['limit_hours']); ?> 時間</td>
                                            <td>
                                                <button type="button" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem;" onclick='editTimeSlot(<?php echo json_encode($ts, JSON_UNESCAPED_UNICODE); ?>)'>編集</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </section>

                    <!-- セクション 4: Webhook設定 -->
                    <section id="section-webhooks" class="panel-section">
                        <div class="section-title">Discord Webhook設定</div>
                        
                        <!-- Webhook登録フォーム -->
                        <form method="POST" action="admin.php" style="margin-bottom: 2rem; background:rgba(0,0,0,0.15); padding:1.5rem; border-radius:12px;">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="form_action" value="save_webhook">
                            <input type="hidden" name="id" id="wh-id" value="">

                            <div class="form-group">
                                <label for="wh-name">設定名 *</label>
                                <input type="text" id="wh-name" name="name" required placeholder="例: 仕事用, プライベート用">
                            </div>

                            <div class="form-group">
                                <label for="wh-url">Discord Webhook URL *</label>
                                <input type="text" id="wh-url" name="url" required placeholder="https://discord.com/api/webhooks/...">
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                                <button type="button" class="btn btn-secondary" onclick="clearWebhookForm()">クリア</button>
                                <button type="submit" class="btn">保存する</button>
                            </div>
                        </form>

                        <div style="font-weight:700; margin-bottom:1rem; font-size:1rem;">設定済みのWebhook一覧</div>
                        <table>
                            <thead>
                                <tr>
                                    <th>名前</th>
                                    <th>Webhook URL (末尾のみ表示)</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allWebhooks)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; color:var(--text-secondary);">Webhookが設定されていません。</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allWebhooks as $wh): ?>
                                        <tr>
                                            <td style="font-weight:600;"><?php echo h($wh['name']); ?></td>
                                            <td style="font-family:monospace; font-size:0.75rem;">
                                                <?php 
                                                $url = $wh['url'];
                                                if (strlen($url) > 30) {
                                                    echo '...' . h(substr($url, -25));
                                                } else {
                                                    echo h($url);
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem;" onclick='editWebhook(<?php echo json_encode($wh, JSON_UNESCAPED_UNICODE); ?>)'>編集</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </section>

                    <!-- セクション 5: 祝日データ更新 -->
                    <section id="section-holiday" class="panel-section">
                        <div class="section-title">祝日データの更新</div>
                        
                        <!-- 手順説明書 -->
                        <div style="background:rgba(255,255,255,0.03); border:1px solid var(--panel-border); border-radius:12px; padding:1.5rem; margin-bottom:2rem; font-size:0.9rem;">
                            <div style="font-weight:700; margin-bottom:0.75rem; color:var(--accent-blue);">日本の祝日CSVデータの取得手順</div>
                            <ol style="margin-left:1.5rem; display:flex; flex-direction:column; gap:0.5rem; color:var(--text-secondary); line-height:1.6;">
                                <li>内閣府の「国民の祝日について」の公式ウェブサイトへアクセスします。<br>
                                    <a href="https://www8.cao.go.jp/chosei/shukujitsu/gaiyou.html" target="_blank" style="color:var(--accent-blue); text-decoration:underline;">内閣府 国民の祝日についてホームページ</a>
                                </li>
                                <li>ページ中段付近にある「公表されている『国民の祝日』のデータ（CSVファイル）」リンク（例: <code>syukujitsu.csv</code>）を右クリックして「名前を付けて保存」します。</li>
                                <li>保存したCSVファイルをそのまま、以下のアップロードフォームから送信してください。</li>
                            </ol>
                        </div>

                        <!-- アップロードフォーム -->
                        <form method="POST" action="admin.php" enctype="multipart/form-data" style="background:rgba(0,0,0,0.15); padding:1.5rem; border-radius:12px; margin-bottom:2rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="form_action" value="save_holiday">
                            
                            <div class="form-group" style="margin-bottom:1.5rem;">
                                <label for="holiday-csv">内閣府祝日CSVファイルを選択 (syukujitsu.csv) *</label>
                                <input type="file" id="holiday-csv" name="holiday_csv" accept=".csv" required style="background:rgba(15, 23, 42, 0.6); border:1px solid var(--panel-border); padding:0.5rem; border-radius:8px; width:100%; color:var(--text-primary);">
                            </div>

                            <button type="submit" class="btn">祝日データを反映する</button>
                        </form>
                    </section>

                </main>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // セクションの切り替え
        function showSection(sectionId) {
            document.querySelectorAll('.panel-section').forEach(sec => sec.classList.remove('active'));
            document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
            
            document.getElementById(sectionId).classList.add('active');
            
            // サイドバーのメニュー項目の active クラス切り替え
            event.target.classList.add('active');
        }

        // 予定フォーム項目の表示制御 (タスク選択時のみ専用項目を表示)
        function toggleFormFields() {
            const type = document.getElementById('sched-type').value;
            const taskFields = document.getElementById('task-fields');
            if (type === 'task') {
                taskFields.style.display = 'block';
            } else {
                taskFields.style.display = 'none';
            }
        }

        // 一括選択チェックボックスのトグル
        function toggleSelectAll(master) {
            const checkboxes = document.querySelectorAll('.bulk-checkbox');
            checkboxes.forEach(cb => cb.checked = master.checked);
        }

        // 予定編集用のデータロード
        function editSchedule(sched) {
            document.getElementById('form-header').innerText = '予定の編集 (ID: ' + sched.id.substring(0, 8) + ')';
            document.getElementById('sched-id').value = sched.id;
            document.getElementById('sched-title').value = sched.title;
            document.getElementById('sched-desc').value = sched.description;
            document.getElementById('sched-type').value = sched.type;
            document.getElementById('sched-category').value = sched.category;
            
            // 日時フォーマット (YYYY-MM-DDTHH:MM) に合わせる
            document.getElementById('sched-start').value = sched.start_at ? sched.start_at.substring(0, 16).replace(' ', 'T') : '';
            document.getElementById('sched-end').value = sched.end_at ? sched.end_at.substring(0, 16).replace(' ', 'T') : '';
            
            document.getElementById('sched-public').checked = parseInt(sched.is_public) === 1;

            if (sched.type === 'task') {
                document.getElementById('sched-status').value = sched.status || 'todo';
                document.getElementById('sched-weight').value = sched.weight || 0;
                document.getElementById('sched-timeslot').value = sched.time_slot_id || '';
                document.getElementById('sched-webhook').value = sched.discord_webhook_id || '';
            } else {
                document.getElementById('sched-status').value = 'todo';
                document.getElementById('sched-weight').value = 0;
                document.getElementById('sched-timeslot').value = '';
                document.getElementById('sched-webhook').value = '';
            }

            toggleFormFields();
            
            // フォームへスクロール
            document.getElementById('form-header').scrollIntoView({ behavior: 'smooth' });
        }

        function clearSchedForm() {
            document.getElementById('form-header').innerText = '新規予定の登録';
            document.getElementById('sched-id').value = '';
            document.getElementById('sched-title').value = '';
            document.getElementById('sched-desc').value = '';
            document.getElementById('sched-type').value = 'simple';
            document.getElementById('sched-category').value = '';
            document.getElementById('sched-start').value = '';
            document.getElementById('sched-end').value = '';
            document.getElementById('sched-status').value = 'todo';
            document.getElementById('sched-weight').value = 0;
            document.getElementById('sched-timeslot').value = '';
            document.getElementById('sched-webhook').value = '';
            document.getElementById('sched-public').checked = true;
            toggleFormFields();
        }

        // 時間枠編集用のロード
        function editTimeSlot(slot) {
            document.getElementById('slot-id').value = slot.id;
            document.getElementById('slot-name').value = slot.name;
            document.getElementById('slot-start').value = slot.start_time;
            document.getElementById('slot-end').value = slot.end_time;
            document.getElementById('slot-limit').value = slot.limit_hours;
        }

        function clearSlotForm() {
            document.getElementById('slot-id').value = '';
            document.getElementById('slot-name').value = '';
            document.getElementById('slot-start').value = '';
            document.getElementById('slot-end').value = '';
            document.getElementById('slot-limit').value = '0.0';
        }

        // Webhook編集用のロード
        function editWebhook(wh) {
            document.getElementById('wh-id').value = wh.id;
            document.getElementById('wh-name').value = wh.name;
            document.getElementById('wh-url').value = wh.url;
        }

        function clearWebhookForm() {
            document.getElementById('wh-id').value = '';
            document.getElementById('wh-name').value = '';
            document.getElementById('wh-url').value = '';
        }
    </script>
</body>
</html>
