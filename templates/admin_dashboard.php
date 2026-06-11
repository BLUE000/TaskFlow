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
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="container">
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

                        <!-- タスク分割セグメント管理フォーム -->
                        <div id="segment-fields" style="display: none; border-top: 1px dashed var(--panel-border); padding-top: 1rem; margin-top: 1rem;">
                            <label style="margin-bottom: 0.5rem; display:block; font-weight: 600;">期間分割（セグメント）</label>
                            <p style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.75rem; line-height: 1.4;">※ 1つのタスクを複数の非連続な期間に分割できます。ガントチャートで同一行に複数のバーとして表示されます。セグメントを設定すると「開始日時・終了日時」は最初/最後のセグメントで自動更新されます。</p>
                            <div id="segment-list">
                                <!-- JavaScript で動的に行を追加 -->
                            </div>
                            <button type="button" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.9rem; margin-top: 0.5rem;" onclick="addSegmentRow()">＋ 期間を追加</button>
                        </div>

                        <!-- 先行タスク（依存関係）フィールド -->
                        <div id="predecessor-fields" style="border-top: 1px dashed var(--panel-border); padding-top: 1rem; margin-top: 1rem;">
                            <label for="sched-predecessors" style="margin-bottom: 0.5rem; display:block; font-weight: 600;">先行タスク（依存関係）</label>
                            <p style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.75rem; line-height: 1.4;">※ このタスクが開始する前に完了していなければならない先行タスク（複数選択可）を選択します。</p>
                            <select id="sched-predecessors" name="predecessors[]" multiple style="width: 100%; height: 120px; padding: 0.5rem; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--panel-border); border-radius: 8px; color: var(--text-primary);">
                                <?php foreach ($allSchedules as $s): ?>
                                    <?php if (!(int)$s['is_deleted']): ?>
                                        <option value="<?php echo h($s['id']); ?>"><?php echo h($s['title']); ?> (<?php echo h($s['type']); ?>)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; line-height: 1.4;">※ Windowsの場合「Ctrlキー」、Macの場合「Cmdキー」を押しながらクリックすることで複数選択・解除ができます。</p>
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
                    <div id="active-schedules-container">
                        <?php 
                        $activeScheds = array_filter($allSchedules, function($s) { return !(int)$s['is_deleted'] && $s['type'] !== 'memo'; });
                        
                        // カテゴリと状態でグループ化
                        $groupedActiveScheds = [];
                        foreach ($activeScheds as $s) {
                            $cat = !empty($s['category']) ? trim($s['category']) : '未分類';
                            
                            $statusGroup = 'schedules';
                            if ($s['type'] === 'task') {
                                $statusGroup = $s['status'] ?? 'todo';
                            }
                            
                            if (!isset($groupedActiveScheds[$cat])) {
                                $groupedActiveScheds[$cat] = [
                                    'doing' => [],
                                    'todo' => [],
                                    'done' => [],
                                    'schedules' => []
                                ];
                            }
                            $groupedActiveScheds[$cat][$statusGroup][] = $s;
                        }

                        // カテゴリをソート（「未分類」は最後にする）
                        uksort($groupedActiveScheds, function($a, $b) {
                            if ($a === '未分類') return 1;
                            if ($b === '未分類') return -1;
                            return strcasecmp($a, $b);
                        });
                        ?>

                        <?php if (empty($groupedActiveScheds)): ?>
                            <table style="margin-bottom:0;">
                                <tbody>
                                    <tr>
                                        <td colspan="6" style="text-align:center; color:var(--text-secondary);">予定が登録されていません。</td>
                                    </tr>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <?php 
                            $catIndex = 0;
                            foreach ($groupedActiveScheds as $catName => $statusGroups):
                                $catId = 'cat-container-' . $catIndex;
                                $catIconId = 'cat-icon-' . $catIndex;
                                $totalCount = count($statusGroups['doing']) + count($statusGroups['todo']) + count($statusGroups['done']) + count($statusGroups['schedules']);
                                $catIndex++;
                            ?>
                                <!-- カテゴリごとのアコーディオン -->
                                <div class="category-section" style="margin-bottom: 1.5rem; background: rgba(30, 41, 59, 0.2); border: 1px solid var(--panel-border); border-radius: 12px; overflow: hidden;">
                                    <div class="accordion-header" style="font-weight:700; font-size:0.9rem; padding: 0.85rem 1.25rem; display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.02); cursor: pointer;" onclick="toggleSectionVisibility('<?php echo $catId; ?>', '<?php echo $catIconId; ?>')">
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <span style="color: var(--accent-blue);">📁</span>
                                            <span>カテゴリ: <?php echo h($catName); ?></span>
                                            <span style="font-size: 0.75rem; background: rgba(255,255,255,0.08); padding: 0.15rem 0.4rem; border-radius: 999px; color: var(--text-secondary);"><?php echo $totalCount; ?> 件</span>
                                        </div>
                                        <span id="<?php echo $catIconId; ?>" class="accordion-icon" style="transition: var(--transition-smooth); font-size: 0.8rem;">▼</span>
                                    </div>
                                    
                                    <div id="<?php echo $catId; ?>" style="padding: 1rem; border-top: 1px solid var(--panel-border);">
                                        <?php
                                        $statusGroupNames = [
                                            'doing' => 'タスク - 進行中 (Doing)',
                                            'todo' => 'タスク - 未着手 (To-Do)',
                                            'done' => 'タスク - 完了 (Done)',
                                            'schedules' => '一般予定 (Simple / Period / Milestone)'
                                        ];
                                        $displayOrder = ['doing', 'todo', 'done', 'schedules'];
                                        
                                        foreach ($displayOrder as $groupKey):
                                            if (empty($statusGroups[$groupKey])) continue;
                                            $schedList = $statusGroups[$groupKey];
                                        ?>
                                            <div class="status-group-container" style="margin-bottom: 1.25rem;">
                                                <h5 style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; color: <?php
                                                    echo $groupKey === 'doing' ? 'var(--accent-orange)' : 
                                                        ($groupKey === 'todo' ? 'var(--text-secondary)' : 
                                                        ($groupKey === 'done' ? 'var(--accent-green)' : 'var(--accent-blue)'));
                                                ?>;">
                                                    <span style="font-size: 0.5rem;">●</span>
                                                    <span><?php echo h($statusGroupNames[$groupKey]); ?></span>
                                                    <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-secondary);">(<?php echo count($schedList); ?>件)</span>
                                                </h5>
                                                
                                                <div style="overflow-x:auto;">
                                                    <table style="margin-bottom: 0.5rem;">
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
                                                            <?php foreach ($schedList as $s): ?>
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
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
                    <div class="section-title">作業時間枠 of 定義</div>
                    
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
    </div>

    <!-- JavaScript 動的データアタッチ & 外部スクリプトの読込 -->
    <script>
        window.TaskFlowData = {
            timeSlots: <?php echo json_encode($allTimeSlots, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="js/admin.js" defer></script>
</body>
</html>
