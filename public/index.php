<?php
/**
 * TaskFlow 一般閲覧画面 (Calendar & Gantt Chart View) - Controller
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
    $selfPath = $_SERVER['PHP_SELF']; // 例: /TaskFlow/public/index.php
    header("Location: " . $selfPath . $query);
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
        $selfPath = $_SERVER['PHP_SELF']; // 例: /TaskFlow/public/index.php
        header("Location: " . $selfPath . $query);
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
if (!function_exists('h')) {
    function h(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

// ビューテンプレートの呼び出し
include __DIR__ . '/../templates/index_view.php';
