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
            $is_new = empty($_POST['id']);
            $id = $_POST['id'] ?: generate_uuidv4();

            // 既存の recurrence_group_id を取得
            $existing_group_id = null;
            if (!$is_new) {
                $checkSql = "SELECT recurrence_group_id FROM schedules WHERE id = :id";
                $checkStmt = $db->prepare($checkSql);
                $checkStmt->execute([':id' => $id]);
                $existing_group_id = $checkStmt->fetchColumn() ?: null;
            }

            $recurrence_mode = $_POST['recurrence_mode'] ?? 'single';
            $type = $_POST['type'] ?? 'simple';

            // 日時は memo タイプなら強制 null、マイルストーンなら終了日を開始日と同じにする
            $start_at = ($type === 'memo') ? null : ($_POST['start_at'] ?: null);
            $end_at = ($type === 'memo') ? null : (($type === 'milestone') ? $start_at : ($_POST['end_at'] ?: null));

            $schedule = [
                'id' => $id,
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'type' => $type,
                'start_at' => $start_at,
                'end_at' => $end_at,
                'status' => $_POST['status'] ?? 'todo',
                'priority' => (int)($_POST['priority'] ?? 0),
                'weight' => (double)($_POST['weight'] ?? 0.0),
                'time_slot_id' => $_POST['time_slot_id'] ?: null,
                'category' => $_POST['category'] ?? 'general',
                'is_public' => isset($_POST['is_public']) ? 1 : 0,
                'discord_webhook_id' => $_POST['discord_webhook_id'] ?: null,
                'recurrence_group_id' => $existing_group_id,
                'is_deleted' => 0
            ];

            // 日別作業時間配分データの取得
            $allocations = [];
            if ($schedule['type'] === 'task' && !empty($_POST['allocations']) && is_array($_POST['allocations'])) {
                foreach ($_POST['allocations'] as $date => $slots) {
                    foreach ($slots as $slotId => $hours) {
                        $hours = (double)$hours;
                        if ($hours > 0.0) {
                            $allocations[] = [
                                'id' => '', // リポジトリ側で生成
                                'date' => $date,
                                'time_slot_id' => $slotId,
                                'allocated_hours' => $hours
                            ];
                        }
                    }
                }
            }

            // 日別進捗率データの取得
            $progress = [];
            if ($schedule['type'] === 'task' && !empty($_POST['progress']) && is_array($_POST['progress'])) {
                foreach ($_POST['progress'] as $date => $pct) {
                    $pct = (int)$pct;
                    if ($pct >= 0 && $pct <= 100) {
                        $progress[$date] = $pct;
                    }
                }
            }

            // マイルストーン関連タスクの取得
            $milestone_targets = [];
            if ($schedule['type'] === 'milestone' && !empty($_POST['milestone_targets']) && is_array($_POST['milestone_targets'])) {
                foreach ($_POST['milestone_targets'] as $target_id) {
                    if (!empty($target_id)) {
                        $milestone_targets[] = $target_id;
                    }
                }
            }

            // 繰り返し設定 (新規登録時のみ有効)
            $recurrence = $_POST['recurrence'] ?? 'none';
            $save_success = false;

            if (!$is_new && $recurrence_mode === 'series' && !empty($existing_group_id)) {
                // シリーズ全体を更新
                $save_success = $scheduleRepo->updateSeries($existing_group_id, $schedule);
                // 現在の選択予定（固有の日付などを持つ）も更新
                $scheduleRepo->save($schedule, $allocations, $progress, $milestone_targets);
            } else {
                // 新規またはこの予定のみ
                if (!$is_new && $recurrence_mode === 'single') {
                    // シリーズから切り離す
                    $schedule['recurrence_group_id'] = null;
                }

                // 新規登録で繰り返しが設定されている場合
                if ($is_new && $recurrence !== 'none' && $type !== 'task' && $type !== 'memo' && !empty($schedule['start_at']) && !empty($schedule['end_at'])) {
                    $recurrence_group_id = generate_uuidv4();
                    $schedule['recurrence_group_id'] = $recurrence_group_id;

                    // オリジナルを保存
                    $save_success = $scheduleRepo->save($schedule, $allocations, $progress, $milestone_targets);

                    if ($save_success) {
                        // コピーを生成
                        try {
                            $start_dt = str_replace('T', ' ', $schedule['start_at']);
                            $end_dt = str_replace('T', ' ', $schedule['end_at']);
                            $startDateObj = new DateTime($start_dt);
                            $endDateObj = new DateTime($end_dt);
                            $interval = $startDateObj->diff($endDateObj);

                            $endOfMonth = new DateTime($startDateObj->format('Y-m-t 23:59:59'));
                            $currentDate = clone $startDateObj;
                            $currentDate->modify('+1 day');

                            while ($currentDate <= $endOfMonth) {
                                $dStr = $currentDate->format('Y-m-d');
                                $w = (int)$currentDate->format('w');
                                $orig_w = (int)$startDateObj->format('w');

                                $match = false;
                                if ($recurrence === 'daily') {
                                    $match = true;
                                } elseif ($recurrence === 'weekly') {
                                    if ($w === $orig_w) {
                                        $match = true;
                                    }
                                } elseif ($recurrence === 'weekdays') {
                                    if ($w >= 1 && $w <= 5) {
                                        $match = true;
                                    }
                                }

                                if ($match) {
                                    $copy_start = $dStr . ' ' . $startDateObj->format('H:i:s');
                                    $copy_start_obj = new DateTime($copy_start);
                                    $copy_end_obj = clone $copy_start_obj;
                                    $copy_end_obj->add($interval);
                                    $copy_end = $copy_end_obj->format('Y-m-d H:i:s');

                                    $copy_schedule = $schedule;
                                    $copy_schedule['id'] = generate_uuidv4();
                                    $copy_schedule['start_at'] = $copy_start;
                                    $copy_schedule['end_at'] = $copy_end;
                                    $copy_schedule['recurrence_group_id'] = $recurrence_group_id;

                                    $scheduleRepo->save($copy_schedule, [], [], []); // コピーには日別配分・進捗・マイルストーン関連を引き継がない
                                }
                                $currentDate->modify('+1 day');
                            }
                        } catch (\Exception $e) {
                            error_log("Failed to generate recurring copies: " . $e->getMessage());
                        }
                    }
                } else {
                    // 通常の単一保存
                    $save_success = $scheduleRepo->save($schedule, $allocations, $progress, $milestone_targets);
                }
            }

            if ($save_success) {
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
            $recurrence_mode = $_POST['recurrence_mode'] ?? 'single';

            if ($id) {
                if ($recurrence_mode === 'series') {
                    // シリーズ全体を削除
                    $checkSql = "SELECT recurrence_group_id FROM schedules WHERE id = :id";
                    $checkStmt = $db->prepare($checkSql);
                    $checkStmt->execute([':id' => $id]);
                    $group_id = $checkStmt->fetchColumn();

                    if (!empty($group_id)) {
                        if ($scheduleRepo->deleteSeries($group_id)) {
                            $message = "シリーズ予定全体を削除しました。";
                        } else {
                            $error = "シリーズ予定の削除に失敗しました。";
                        }
                    } else {
                        // グループIDがない場合は単一削除
                        $sql = "UPDATE schedules SET is_deleted = 1, updated_at = NOW() WHERE id = :id";
                        $stmt = $db->prepare($sql);
                        if ($stmt->execute([':id' => $id])) {
                            $message = "予定を削除しました。";
                        } else {
                            $error = "予定の削除に失敗しました。";
                        }
                    }
                } else {
                    // 単一削除
                    $sql = "UPDATE schedules SET is_deleted = 1, updated_at = NOW() WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute([':id' => $id])) {
                        $message = "予定を削除しました。";
                    } else {
                        $error = "予定の削除に失敗しました。";
                    }
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
        } elseif ($form_action === 'delete_timeslot') {
            // 時間枠削除
            $id = $_POST['id'] ?? '';
            if ($id) {
                if ($timeSlotRepo->delete($id)) {
                    $message = "時間枠を削除しました。";
                } else {
                    $error = "時間枠の削除に失敗しました。";
                }
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

            if (move_uploaded_file($tempPath, $destPath)) {
                $message = "祝日CSVをアップロードし、" . count($parsedHolidays) . " 件の祝日データを正常に反映しました。";
            } else {
                $error = "祝日CSVファイルの保存に失敗しました。";
            }
        } elseif ($form_action === 'change_password') {
            // パスワード変更
            $current_pw = $_POST['current_password'] ?? '';
            $new_pw = $_POST['new_password'] ?? '';
            $confirm_pw = $_POST['confirm_password'] ?? '';

            if (empty($current_pw) || empty($new_pw) || empty($confirm_pw)) {
                throw new InvalidArgumentException("すべてのフィールドを入力してください。");
            }

            if (!password_verify($current_pw, ADMIN_PASSWORD_HASH)) {
                throw new InvalidArgumentException("現在のパスワードが正しくありません。");
            }

            if ($new_pw !== $confirm_pw) {
                throw new InvalidArgumentException("新しいパスワードと確認用パスワードが一致しません。");
            }

            if (strlen($new_pw) < 6) {
                throw new InvalidArgumentException("新しいパスワードは6文字以上で設定してください。");
            }

            // config/config.php を読み込んで、ADMIN_PASSWORD_HASH 定数を安全に変更
            $configPath = __DIR__ . '/../config/config.php';
            $content = file_get_contents($configPath);
            if ($content === false) {
                throw new RuntimeException("設定ファイルの読み込みに失敗しました。");
            }

            $newHash = password_hash($new_pw, PASSWORD_BCRYPT);

            // 安全な固定文字列置換（定数定義の行を特定して書き換え）
            $oldLine = "define('ADMIN_PASSWORD_HASH', '" . ADMIN_PASSWORD_HASH . "');";
            $newLine = "define('ADMIN_PASSWORD_HASH', '" . $newHash . "');";
            $newContent = str_replace($oldLine, $newLine, $content);

            // 一時ファイルに保存後、rename を使ってアトミックに書き換え
            $tempFile = $configPath . '.tmp';
            if (file_put_contents($tempFile, $newContent) === false) {
                throw new RuntimeException("一時設定ファイルの保存に失敗しました。");
            }

            if (!rename($tempFile, $configPath)) {
                @unlink($tempFile);
                throw new RuntimeException("設定ファイルの更新に失敗しました。");
            }

            $message = "管理者パスワードを正常に変更しました。";
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

        /* アコーディオン機能用スタイル */
        .accordion-header {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            user-select: none;
            transition: var(--transition-smooth);
        }
        .accordion-header:hover {
            color: var(--accent-blue);
        }
        .accordion-icon {
            display: inline-block;
            transition: transform 0.2s ease;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        .accordion-header:hover .accordion-icon {
            color: var(--accent-blue);
        }
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
                    <a class="menu-item active" onclick="showSection(this, 'section-schedules')">予定・タスク管理</a>
                    <a class="menu-item" onclick="showSection(this, 'section-bulk')">一括公開設定</a>
                    <a class="menu-item" onclick="showSection(this, 'section-timeslots')">時間枠設定</a>
                    <a class="menu-item" onclick="showSection(this, 'section-webhooks')">Discord Webhook設定</a>
                    <a class="menu-item" onclick="showSection(this, 'section-holiday')">祝日データ更新</a>
                    <a class="menu-item" onclick="showSection(this, 'section-password')">パスワード変更</a>
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
                                        <option value="milestone">マイルストーン (milestone)</option>
                                        <option value="memo">やることメモ (memo)</option>
                                    </select>
                                    <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ 簡易予定：日時のみのシンプルな予定。期間予定：複数日にまたがる予定。個人タスク：時間枠に割り当て、重み（所要時間）を集計する進捗管理用の予定。やることメモ：日付のないTo-Doリスト項目。</p>
                                </div>
                                <div class="form-group">
                                    <label for="sched-category">カテゴリ/タグ</label>
                                    <input type="text" id="sched-category" name="category" placeholder="work, private, studyなど">
                                    <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ 予定の分類タグ（一般画面での絞り込みフィルターで使用）。半角英数字で入力してください。</p>
                                </div>
                            </div>

                            <div class="form-row" id="sched-date-fields">
                                <div class="form-group">
                                    <label for="sched-start">開始日時</label>
                                    <input type="datetime-local" id="sched-start" name="start_at" onchange="updateAllocationForm()">
                                </div>
                                <div class="form-group" id="sched-end-group">
                                    <label for="sched-end">終了日時</label>
                                    <input type="datetime-local" id="sched-end" name="end_at" onchange="updateAllocationForm()">
                                </div>
                            </div>

                            <!-- 繰り返し設定 (新規登録時のみ有効) -->
                            <div class="form-group" id="recurrence-field" style="margin-top: 1rem; display: none;">
                                <label for="sched-recurrence">繰り返し設定 (同月内月末までコピー)</label>
                                <select id="sched-recurrence" name="recurrence">
                                    <option value="none">なし (none)</option>
                                    <option value="daily">毎日 (daily)</option>
                                    <option value="weekly">毎週同じ曜日 (weekly)</option>
                                    <option value="weekdays">平日のみ (weekdays)</option>
                                </select>
                                <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ 新規予定登録時のみ有効（個人タスク・メモは対象外）。開始日の翌日から同月月末まで自動コピーを生成します。</p>
                            </div>

                            <!-- 繰り返し編集スコープ選択 -->
                            <div class="form-group" id="edit-scope-field" style="margin-top: 1rem; display: none;">
                                <label>編集の対象範囲</label>
                                <div style="display: flex; gap: 1.5rem; margin-top: 0.25rem;">
                                    <label class="toggle-container" style="font-weight: normal;">
                                        <input type="radio" name="recurrence_mode" id="scope-single" value="single" checked style="width: 16px; height: 16px;">
                                        この予定のみ変更
                                    </label>
                                    <label class="toggle-container" style="font-weight: normal;">
                                        <input type="radio" name="recurrence_mode" id="scope-series" value="series" style="width: 16px; height: 16px;">
                                        シリーズ全体を変更
                                    </label>
                                </div>
                                <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ 「シリーズ全体を変更」を選択すると、同じグループのすべての予定のタイトルや詳細などが一括で更新されます（各予定の日付は維持されます）。</p>
                            </div>

                            <!-- タスク専用フィールド -->
                            <div id="task-fields" style="display: none; border-top: 1px dashed var(--panel-border); padding-top: 1rem; margin-top: 1rem;">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="sched-status">進捗状況</label>
                                        <select id="sched-status" name="status" disabled style="opacity: 0.7; cursor: not-allowed;">
                                            <option value="todo">未着手 (todo)</option>
                                            <option value="doing">進行中 (doing)</option>
                                            <option value="done">完了 (done)</option>
                                        </select>
                                        <input type="hidden" name="status" id="sched-status-hidden" value="todo">
                                    </div>
                                    <div class="form-group">
                                        <label for="sched-weight">重み (所要時間・時間単位)</label>
                                        <input type="number" id="sched-weight" name="weight" step="0.5" min="0" value="0" onchange="updateAllocationForm()">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="sched-timeslot">割り当て時間枠</label>
                                        <select id="sched-timeslot" name="time_slot_id" onchange="updateAllocationForm()">
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

                            <!-- マイルストーン専用フィールド -->
                            <div id="milestone-fields" style="display: none; border-top: 1px dashed var(--panel-border); padding-top: 1rem; margin-top: 1rem;">
                                <div class="form-group">
                                    <label for="milestone-targets">関連付けるタスク (複数選択可、未選択時は独立行)</label>
                                    <select id="milestone-targets" name="milestone_targets[]" multiple style="width: 100%; height: 120px; padding: 0.5rem; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--panel-border); border-radius: 8px; color: var(--text-primary);">
                                        <?php foreach ($allSchedules as $s): ?>
                                            <?php if ($s['type'] === 'task' && !(int)$s['is_deleted']): ?>
                                                <option value="<?php echo h($s['id']); ?>"><?php echo h($s['title']); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ Windowsの場合「Ctrlキー」、Macの場合「Cmdキー」を押しながらクリックすることで複数選択・解除ができます。</p>
                                </div>
                            </div>

                            <!-- 日別作業時間配分フォーム -->
                            <div id="allocation-fields" style="display: none; border-top: 1px dashed var(--panel-border); padding-top: 1rem; margin-top: 1rem;">
                                <label style="margin-bottom: 0.5rem; display:block;">日別作業時間配分</label>
                                <div id="allocation-table-wrapper" style="overflow-x: auto;">
                                    <!-- JavaScript で動的にテーブルを生成 -->
                                </div>
                                <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.5rem; line-height: 1.4;">※ 期間タスクの場合、日付と時間枠ごとに作業時間を個別に割り当てます（単位：時間）。合計が全体の重みと一致しなくても保存可能です。</p>
                                <div style="margin-top: 0.5rem;">
                                    <button type="button" class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;" onclick="addActualDate()">+ 実績日（予定外の作業日）を追加</button>
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
                        <div class="accordion-header" style="font-weight:700; margin-bottom:1rem; font-size:1rem;" onclick="toggleSectionVisibility('active-schedules-container', 'active-schedules-toggle-icon')">
                            <span>登録済みの予定一覧 (削除済除く)</span>
                            <span id="active-schedules-toggle-icon" class="accordion-icon">▼</span>
                        </div>
                        <div id="active-schedules-container" style="overflow-x:auto;">
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
                                    $activeScheds = array_filter($allSchedules, function($s) { return !(int)$s['is_deleted'] && $s['type'] !== 'memo'; });
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
                                                    <?php if (!empty($s['recurrence_group_id'])): ?>
                                                    <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirmDeleteSeries(this);">
                                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="form_action" value="delete_schedule">
                                                        <input type="hidden" name="id" value="<?php echo h($s['id']); ?>">
                                                        <input type="hidden" name="recurrence_mode" value="single">
                                                        <button type="submit" class="btn btn-danger" style="padding:0.25rem 0.5rem; font-size:0.75rem;">削除</button>
                                                    </form>
                                                    <?php else: ?>
                                                    <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('本当にこの予定を削除しますか？');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="form_action" value="delete_schedule">
                                                        <input type="hidden" name="id" value="<?php echo h($s['id']); ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding:0.25rem 0.5rem; font-size:0.75rem;">削除</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- やることメモ一覧リスト -->
                        <div class="accordion-header" style="font-weight:700; margin-top: 2rem; margin-bottom:1rem; font-size:1rem;" onclick="toggleSectionVisibility('memo-schedules-container', 'memo-schedules-toggle-icon')">
                            <span>やることメモ一覧 (日付未定)</span>
                            <span id="memo-schedules-toggle-icon" class="accordion-icon">▼</span>
                        </div>
                        <div id="memo-schedules-container" style="overflow-x:auto; margin-bottom: 2rem;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>タイトル</th>
                                        <th>詳細</th>
                                        <th>カテゴリ</th>
                                        <th>公開状態</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $memoScheds = array_filter($allSchedules, function($s) { return !(int)$s['is_deleted'] && $s['type'] === 'memo'; });
                                    if (empty($memoScheds)): 
                                    ?>
                                        <tr>
                                            <td colspan="5" style="text-align:center; color:var(--text-secondary);">やることメモが登録されていません。</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($memoScheds as $s): ?>
                                            <tr>
                                                <td style="font-weight:600;"><?php echo h($s['title']); ?></td>
                                                <td><?php echo h($s['description']); ?></td>
                                                <td><?php echo h($s['category']); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $s['is_public'] ? 'badge-public' : 'badge-private'; ?>">
                                                        <?php echo $s['is_public'] ? '公開' : '非公開'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem; margin-right:0.25rem;" onclick='editSchedule(<?php echo json_encode($s, JSON_UNESCAPED_UNICODE); ?>)'>編集</button>
                                                    <button type="button" class="btn" style="padding:0.25rem 0.5rem; font-size:0.75rem; margin-right:0.25rem; background: linear-gradient(135deg, var(--accent-green), var(--accent-blue));" onclick='migrateMemoToSchedule(<?php echo json_encode($s, JSON_UNESCAPED_UNICODE); ?>)'>予定/タスクに移行</button>
                                                    <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('本当にこのメモを削除しますか？');">
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
                                <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ カレンダー上でタスクの所要時間を集計・管理する単位となる名前（例: 午前枠、午後枠）。</p>
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
                                <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ 1日のうち、この時間枠に割り当てられるタスクの最大合計時間（例: 午前枠なら3.0時間）。これを超えると負荷警告ハイライトが作動します。</p>
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
                                                <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('本当にこの時間枠を削除しますか？');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="form_action" value="delete_timeslot">
                                                    <input type="hidden" name="id" value="<?php echo h($ts['id']); ?>">
                                                    <button type="submit" class="btn btn-danger" style="padding:0.25rem 0.5rem; font-size:0.75rem;">削除</button>
                                                </form>
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
                                <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ Discordの対象テキストチャンネルの「設定」➔「連携サービス」➔「ウェブフックを作成」➔「ウェブフックURLをコピー」から取得したURLを入力してください。</p>
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

                    <!-- セクション 6: パスワード変更 -->
                    <section id="section-password" class="panel-section">
                        <div class="section-title">管理者パスワードの変更</div>
                        
                        <form method="POST" action="admin.php" style="background:rgba(0,0,0,0.15); padding:1.5rem; border-radius:12px; max-width: 500px; margin-bottom:2rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="form_action" value="change_password">
                            
                            <div class="form-group" style="margin-bottom:1.25rem;">
                                <label for="current-password">現在のパスワード *</label>
                                <input type="password" id="current-password" name="current_password" required>
                            </div>

                            <div class="form-group" style="margin-bottom:1.25rem;">
                                <label for="new-password">新しいパスワード * (6文字以上)</label>
                                <input type="password" id="new-password" name="new_password" required minlength="6">
                            </div>

                            <div class="form-group" style="margin-bottom:1.5rem;">
                                <label for="confirm-password">新しいパスワードの確認 *</label>
                                <input type="password" id="confirm-password" name="confirm_password" required minlength="6">
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                                <button type="button" class="btn btn-secondary" onclick="clearPasswordForm()">クリア</button>
                                <button type="submit" class="btn">パスワードを変更する</button>
                            </div>
                        </form>
                    </section>

                </main>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const timeSlots = <?php echo json_encode($allTimeSlots); ?>;
        let currentAllocations = {}; // { "YYYY-MM-DD": { "slot_id": hours } }
        let currentProgress = {}; // { "YYYY-MM-DD": percent }

        // セクション内のアコーディオン開閉
        function toggleSectionVisibility(containerId, iconId) {
            const container = document.getElementById(containerId);
            const icon = document.getElementById(iconId);
            if (!container || !icon) return;

            if (container.style.display === 'none') {
                container.style.display = 'block';
                icon.style.transform = 'rotate(0deg)';
            } else {
                container.style.display = 'none';
                icon.style.transform = 'rotate(-90deg)';
            }
        }

        // セクションの切り替え
        function showSection(element, sectionId) {
            document.querySelectorAll('.panel-section').forEach(sec => sec.classList.remove('active'));
            document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
            
            document.getElementById(sectionId).classList.add('active');
            
            // サイドバーのメニュー項目の active クラス切り替え
            element.classList.add('active');
        }

        // 予定フォーム項目の表示制御 (タスク選択時のみ専用項目を表示、新規かつタスク・メモ・マイルストーン外のみ繰り返し設定表示、メモ時は日付非表示)
        function toggleFormFields() {
            const id = document.getElementById('sched-id').value;
            const type = document.getElementById('sched-type').value;
            const taskFields = document.getElementById('task-fields');
            const milestoneFields = document.getElementById('milestone-fields');
            const recurrenceField = document.getElementById('recurrence-field');
            const dateFields = document.getElementById('sched-date-fields');
            const endGroup = document.getElementById('sched-end-group');
            
            if (type === 'task') {
                taskFields.style.display = 'block';
            } else {
                taskFields.style.display = 'none';
            }

            if (type === 'milestone') {
                if (milestoneFields) milestoneFields.style.display = 'block';
                if (endGroup) endGroup.style.display = 'none';
            } else {
                if (milestoneFields) milestoneFields.style.display = 'none';
                if (endGroup) endGroup.style.display = 'block';
            }
            
            // メモの場合は日付指定行を非表示にする
            if (type === 'memo') {
                dateFields.style.display = 'none';
            } else {
                dateFields.style.display = 'flex';
            }
            
            // 新規登録かつタスク・メモ・マイルストーン以外の場合のみ繰り返しプルダウンを表示
            if (!id && type !== 'task' && type !== 'memo' && type !== 'milestone') {
                recurrenceField.style.display = 'block';
            } else {
                recurrenceField.style.display = 'none';
            }
            
            updateAllocationForm();
        }

        // 一括選択チェックボックスのトグル
        function toggleSelectAll(master) {
            const checkboxes = document.querySelectorAll('.bulk-checkbox');
            checkboxes.forEach(cb => cb.checked = master.checked);
        }

        // 日付範囲の算出
        function getDateRange(startStr, endStr) {
            if (!startStr || !endStr) return [];
            const start = new Date(startStr.substring(0, 10));
            const end = new Date(endStr.substring(0, 10));
            const dates = [];
            let current = new Date(start);
            while (current <= end) {
                dates.push(current.toISOString().substring(0, 10));
                current.setDate(current.getDate() + 1);
            }
            return dates;
        }

        // 日付範囲（予定＋登録済・追加済実績のユニオン）の算出
        function getDateRangeUnion(startStr, endStr) {
            const datesSet = new Set();
            
            // 1. 予定された期間内の全日付
            if (startStr && endStr) {
                const start = new Date(startStr.substring(0, 10));
                const end = new Date(endStr.substring(0, 10));
                let current = new Date(start);
                while (current <= end) {
                    datesSet.add(current.toISOString().substring(0, 10));
                    current.setDate(current.getDate() + 1);
                }
            }
            
            // 2. 既存の進捗データの日付
            if (typeof currentProgress === 'object') {
                Object.keys(currentProgress).forEach(date => {
                    if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                        datesSet.add(date);
                    }
                });
            }
            
            // 3. 既存の時間配分データの日付
            if (typeof currentAllocations === 'object') {
                Object.keys(currentAllocations).forEach(date => {
                    if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                        datesSet.add(date);
                    }
                });
            }
            
            return Array.from(datesSet).sort();
        }

        // 実績日（予定外の作業日）の追加
        function addActualDate() {
            const dateStr = prompt("実績日（YYYY-MM-DD）を入力してください：");
            if (!dateStr) return;
            
            const regex = /^\d{4}-\d{2}-\d{2}$/;
            if (!regex.test(dateStr)) {
                alert("日付形式が正しくありません (YYYY-MM-DD)。");
                return;
            }
            
            const testDate = new Date(dateStr);
            if (isNaN(testDate.getTime())) {
                alert("有効な日付ではありません。");
                return;
            }
            
            if (currentProgress[dateStr] === undefined) {
                currentProgress[dateStr] = 0;
            }
            if (!currentAllocations[dateStr]) {
                currentAllocations[dateStr] = {};
            }
            
            updateAllocationForm();
        }

        // 日別作業時間配分フォームの動的生成
        function updateAllocationForm() {
            const type = document.getElementById('sched-type').value;
            const startAt = document.getElementById('sched-start').value;
            const endAt = document.getElementById('sched-end').value;
            
            const allocFields = document.getElementById('allocation-fields');
            const wrapper = document.getElementById('allocation-table-wrapper');
            
            if (type !== 'task' || !startAt || !endAt) {
                allocFields.style.display = 'none';
                wrapper.innerHTML = '';
                return;
            }

            const dates = getDateRangeUnion(startAt, endAt);
            if (dates.length === 0) {
                allocFields.style.display = 'none';
                wrapper.innerHTML = '';
                return;
            }

            allocFields.style.display = 'block';

            if (timeSlots.length === 0) {
                wrapper.innerHTML = '<div style="color: var(--text-secondary); font-size: 0.85rem;">※ 設定済みの時間枠がありません。先に時間枠を設定してください。</div>';
                return;
            }

            // テーブルヘッダーの構築
            let html = '<table style="margin-bottom:0; font-size:0.8rem;"><thead><tr><th>日付</th>';
            timeSlots.forEach(slot => {
                html += `<th>${escapeHtml(slot.name)} (${slot.start_time.substring(0,5)}-${slot.end_time.substring(0,5)})</th>`;
            });
            html += '<th style="width: 100px;">進捗率 (%)</th>';
            html += '</tr></thead><tbody>';

            // 各日ごとの入力行の構築
            dates.forEach(date => {
                html += `<tr><td style="font-weight:600; white-space:nowrap;">${date}</td>`;
                timeSlots.forEach(slot => {
                    let val = '';
                    if (currentAllocations && currentAllocations[date] && currentAllocations[date][slot.id] !== undefined) {
                        val = currentAllocations[date][slot.id];
                    }
                    html += `<td><input type="number" name="allocations[${date}][${slot.id}]" step="0.5" min="0" value="${val}" style="padding:0.25rem 0.5rem; width:80px; text-align:right;"></td>`;
                });
                let progVal = currentProgress[date] !== undefined ? currentProgress[date] : '';
                html += `<td><input type="number" name="progress[${date}]" min="0" max="100" class="progress-pct-input" data-date="${date}" value="${progVal}" onchange="onProgressChange(this)" oninput="onProgressChange(this)" style="padding:0.25rem 0.5rem; width:80px; text-align:right;"></td>`;
                html += '</tr>';
            });
            html += '</tbody></table>';
            wrapper.innerHTML = html;
            updateStatusFromProgress();
        }

        function onProgressChange(input) {
            const date = input.getAttribute('data-date');
            let val = parseInt(input.value, 10);
            if (isNaN(val)) {
                delete currentProgress[date];
            } else {
                if (val < 0) val = 0;
                if (val > 100) val = 100;
                input.value = val;
                currentProgress[date] = val;
            }
            updateStatusFromProgress();
        }

        function updateStatusFromProgress() {
            const inputs = document.querySelectorAll('.progress-pct-input');
            let total = 0;
            inputs.forEach(input => {
                total += parseInt(input.value || 0, 10);
            });
            const statusSelect = document.getElementById('sched-status');
            const statusHidden = document.getElementById('sched-status-hidden');
            
            let status = 'todo';
            if (total >= 100) {
                status = 'done';
            } else if (total > 0) {
                status = 'doing';
            }
            
            if (statusSelect) statusSelect.value = status;
            if (statusHidden) statusHidden.value = status;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // 繰り返し予定削除時の確認ダイアログ
        function confirmDeleteSeries(form) {
            const res = confirm("この予定は繰り返しグループに属しています。\n\n「OK」を押すとシリーズ（グループ）全体を削除します。\n「キャンセル」を押すとこの予定のみ削除します。");
            if (res) {
                form.recurrence_mode.value = "series";
            } else {
                form.recurrence_mode.value = "single";
                if (!confirm("本当にこの予定のみ削除しますか？")) {
                    return false;
                }
            }
            return true;
        }

        // やることメモを予定またはタスクへ移行するUIアクション
        function migrateMemoToSchedule(sched) {
            editSchedule(sched);
            document.getElementById('sched-type').value = 'simple';
            toggleFormFields();
            document.getElementById('sched-start').focus();
        }

        // 予定編集用のデータロード
        function editSchedule(sched) {
            document.getElementById('form-header').innerText = '予定の編集 (ID: ' + sched.id.substring(0, 8) + ')';
            document.getElementById('sched-id').value = sched.id;
            document.getElementById('sched-title').value = sched.title;
            document.getElementById('sched-desc').value = sched.description;
            document.getElementById('sched-type').value = sched.type;
            document.getElementById('sched-category').value = sched.category;
            
            // 日時フォーマット (YYYY-MM-DDTHH:MM) に合わせる (memoタイプは空にする)
            if (sched.type === 'memo') {
                document.getElementById('sched-start').value = '';
                document.getElementById('sched-end').value = '';
            } else {
                document.getElementById('sched-start').value = sched.start_at ? sched.start_at.substring(0, 16).replace(' ', 'T') : '';
                document.getElementById('sched-end').value = sched.end_at ? sched.end_at.substring(0, 16).replace(' ', 'T') : '';
            }
            
            document.getElementById('sched-public').checked = parseInt(sched.is_public) === 1;

            // allocations データのロード
            currentAllocations = {};
            if (sched.allocations && Array.isArray(sched.allocations)) {
                sched.allocations.forEach(alloc => {
                    const date = alloc.date;
                    const slotId = alloc.time_slot_id;
                    const hours = alloc.allocated_hours;
                    if (!currentAllocations[date]) {
                        currentAllocations[date] = {};
                    }
                    currentAllocations[date][slotId] = hours;
                });
            }

            // progress データのロード
            currentProgress = {};
            if (sched.progress && Array.isArray(sched.progress)) {
                sched.progress.forEach(p => {
                    currentProgress[p.date] = p.progress_percent;
                });
            }

            // milestone_targets データのロード
            const milestoneTargetsSelect = document.getElementById('milestone-targets');
            if (milestoneTargetsSelect) {
                Array.from(milestoneTargetsSelect.options).forEach(opt => opt.selected = false);
                if (sched.type === 'milestone' && Array.isArray(sched.milestone_targets)) {
                    sched.milestone_targets.forEach(targetId => {
                        const opt = Array.from(milestoneTargetsSelect.options).find(o => o.value === targetId);
                        if (opt) opt.selected = true;
                    });
                }
            }

            if (sched.type === 'task') {
                document.getElementById('sched-status').value = sched.status || 'todo';
                if (document.getElementById('sched-status-hidden')) {
                    document.getElementById('sched-status-hidden').value = sched.status || 'todo';
                }
                document.getElementById('sched-weight').value = sched.weight || 0;
                document.getElementById('sched-timeslot').value = sched.time_slot_id || '';
                document.getElementById('sched-webhook').value = sched.discord_webhook_id || '';
            } else {
                document.getElementById('sched-status').value = 'todo';
                if (document.getElementById('sched-status-hidden')) {
                    document.getElementById('sched-status-hidden').value = 'todo';
                }
                document.getElementById('sched-weight').value = 0;
                document.getElementById('sched-timeslot').value = '';
                document.getElementById('sched-webhook').value = '';
            }

            // 繰り返し編集スコープの表示制御
            const editScopeField = document.getElementById('edit-scope-field');
            if (sched.recurrence_group_id) {
                editScopeField.style.display = 'block';
                document.getElementById('scope-single').checked = true;
            } else {
                editScopeField.style.display = 'none';
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
            if (document.getElementById('sched-status-hidden')) {
                document.getElementById('sched-status-hidden').value = 'todo';
            }
            document.getElementById('sched-weight').value = 0;
            document.getElementById('sched-timeslot').value = '';
            document.getElementById('sched-webhook').value = '';
            document.getElementById('sched-public').checked = true;
            
            currentAllocations = {};
            currentProgress = {};

            // milestone_targets の初期化
            const milestoneTargetsSelect = document.getElementById('milestone-targets');
            if (milestoneTargetsSelect) {
                Array.from(milestoneTargetsSelect.options).forEach(opt => opt.selected = false);
            }

            document.getElementById('edit-scope-field').style.display = 'none';
            
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

        // Webhookクリア
        function clearWebhookForm() {
            document.getElementById('wh-id').value = '';
            document.getElementById('wh-name').value = '';
            document.getElementById('wh-url').value = '';
        }

        function clearPasswordForm() {
            document.getElementById('current-password').value = '';
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
        }
    </script>
</body>
</html>
