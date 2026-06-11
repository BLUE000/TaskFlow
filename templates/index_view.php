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
    <link rel="stylesheet" href="css/index.css">
</head>
<body>
    <div class="app-container">
        <header>
            <div class="logo-area">
                <h1>TaskFlow</h1>
                <p>ハイブリッド スケジュール ＆ 負荷分析ダッシュボード</p>
            </div>
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
                    <input type="password" id="admin-password" name="password" required placeholder="管理者パスワードを入力" style="width:100%; padding:0.75rem 1rem; background:rgba(15, 23, 42, 0.6); border:1px solid var(--panel-border); border-radius:8px; color:var(--text-primary);">
                </div>
                <button type="submit" class="btn" style="width:100%; margin-top: 1rem; font-weight:600;">ログイン</button>
            </form>
        </div>
    </div>

    <!-- PHPからJavaScriptへのデータブリッジアタッチ & スクリプト読込 -->
    <script>
        window.TaskFlowData = {
            rawSchedules: <?php echo json_encode($schedules, JSON_UNESCAPED_UNICODE); ?>,
            timeSlots: <?php echo json_encode($timeSlots, JSON_UNESCAPED_UNICODE); ?>,
            holidays: <?php echo json_encode($holidays, JSON_UNESCAPED_UNICODE); ?>,
            categoryTimeDisplay: <?php echo json_encode(CATEGORY_TIME_DISPLAY, JSON_UNESCAPED_UNICODE); ?>,
            isAdmin: <?php echo $isAdmin ? 'true' : 'false'; ?>
        };
    </script>
    <script src="js/index.js" defer></script>
</body>
</html>
