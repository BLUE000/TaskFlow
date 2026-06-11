<?php
/**
 * TaskFlow 管理者ダッシュボード (admin.php) - Controller
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
use Holiday\HolidayParser;

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
if (!function_exists('generate_uuidv4')) {
    function generate_uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
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

            // セグメントデータの取得
            $segments = [];
            if (in_array($type, ['task', 'period']) && !empty($_POST['segments']) && is_array($_POST['segments'])) {
                foreach ($_POST['segments'] as $seg) {
                    $seg_start = trim($seg['start_at'] ?? '');
                    $seg_end   = trim($seg['end_at']   ?? '');
                    if ($seg_start !== '' && $seg_end !== '') {
                        $segments[] = [
                            'start_at' => str_replace('T', ' ', $seg_start),
                            'end_at'   => str_replace('T', ' ', $seg_end),
                        ];
                    }
                }
            }

            if ($save_success) {
                $message = "予定を保存しました。";

                // セグメントの保存（セグメントが送信された場合のみ）
                if (!empty($segments)) {
                    $scheduleRepo->saveSegments($id, $segments);
                }

                // 先行タスクの保存
                $predecessorIds = [];
                if (!empty($_POST['predecessors']) && is_array($_POST['predecessors'])) {
                    $predecessorIds = array_filter($_POST['predecessors'], function($v) { return !empty($v); });
                }
                $scheduleRepo->savePredecessors($id, array_values($predecessorIds));

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
$allPredecessorRelations = $is_logged_in ? $scheduleRepo->findAllPredecessorRelations() : [];

// XSSエスケープ
if (!function_exists('h')) {
    function h(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

// ビューテンプレートの呼び出し
if (!$is_logged_in) {
    include __DIR__ . '/../templates/admin_login.php';
} else {
    include __DIR__ . '/../templates/admin_dashboard.php';
}
