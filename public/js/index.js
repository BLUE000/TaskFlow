const rawSchedules = window.TaskFlowData.rawSchedules;
const timeSlots = window.TaskFlowData.timeSlots;
const holidays = window.TaskFlowData.holidays;
const categoryTimeDisplay = window.TaskFlowData.categoryTimeDisplay;
const isAdmin = window.TaskFlowData.isAdmin;

let currentDate = new Date(); // 今日を初期値とする
let currentView = 'month'; // 'month', 'week', 'gantt'

// 選択されたカテゴリのセット (空の場合はすべて表示)
const selectedCategories = new Set();

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
            if (!(sDate <= lastDateStr && eDate >= firstDateStr)) {
                return false;
            }

            // 単一タスクに関連付けられたマイルストーンは独立した行を描画しない
            if (sched.type === 'milestone' && sched.milestone_targets && sched.milestone_targets.length === 1) {
                return false;
            }

            return true;
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

    // 座標記録用オブジェクト
    const ganttCoords = {};

    // 各予定行の描画
    targetEvents.forEach((event, index) => {
        // 座標計算と記録
        const startAtStr = event.start_at.substring(0, 10);
        const endAtStr = event.end_at ? event.end_at.substring(0, 10) : startAtStr;
        const startDateObj = parseLocalDate(startAtStr);
        const endDateObj = parseLocalDate(endAtStr);
        const startOffset = Math.max(0, Math.round((startDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));
        const endOffset = Math.min(timelineDays.length - 1, Math.round((endDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));
        const rowY = index * 48 + 24;

        let startX = startOffset * 40;
        let endX = (endOffset + 1) * 40;

        if (event.type === 'milestone') {
            startX = startOffset * 40 + 20;
            endX = startOffset * 40 + 20;
        } else {
            if (startAtStr === endAtStr) {
                startX = startOffset * 40 + 20;
                endX = startOffset * 40 + 20;
            } else {
                startX = startOffset * 40 + 8;
                endX = (endOffset + 1) * 40 - 8;
            }

            const segments = event.segments && Array.isArray(event.segments) && event.segments.length > 0 ? event.segments : null;
            if (segments) {
                const firstSeg = segments[0];
                const lastSeg = segments[segments.length - 1];
                const firstSegStartStr = firstSeg.start_at ? firstSeg.start_at.substring(0, 10) : null;
                if (firstSegStartStr) {
                    const firstSegStartObj = parseLocalDate(firstSegStartStr);
                    const firstSegStartOff = Math.max(0, Math.round((firstSegStartObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));
                    const lastSegEndStr = lastSeg.end_at ? lastSeg.end_at.substring(0, 10) : firstSegStartStr;
                    const lastSegEndObj = parseLocalDate(lastSegEndStr);
                    const lastSegEndOff = Math.min(timelineDays.length - 1, Math.round((lastSegEndObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));

                    if (firstSegStartStr === lastSegEndStr) {
                        startX = firstSegStartOff * 40 + 20;
                        endX = firstSegStartOff * 40 + 20;
                    } else {
                        startX = firstSegStartOff * 40 + 8;
                        endX = (lastSegEndOff + 1) * 40 - 8;
                    }
                }
            }
        }
        ganttCoords[event.id] = { startX, endX, y: rowY };

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
        const durationDays = endOffset - startOffset + 1;

        if (durationDays > 0) {
            const displayTitle = isPrivate ? `🔒 [非公開] ${event.title}` : event.title;

            if (event.type === 'milestone') {
                // 独立行マイルストーン（0個または2個以上の関連タスク）
                const star = document.createElement('div');
                star.className = 'gantt-milestone-star';
                star.innerText = '★';
                star.style.left = `${startOffset * 40 + 12}px`;
                star.style.top = `calc(50% - 8px)`;

                // ツールチップ文言の組み立て
                let tooltipMsg = displayTitle;
                if (event.milestone_targets && event.milestone_targets.length >= 2) {
                    const targetNames = [];
                    event.milestone_targets.forEach(tid => {
                        const tSched = getFilteredSchedules().find(s => s.id === tid);
                        if (tSched) {
                            targetNames.push(tSched.title);
                        }
                    });
                    if (targetNames.length > 0) {
                        tooltipMsg += ` (関連タスク: ${targetNames.join(', ')})`;
                    }
                }

                star.addEventListener('mouseenter', (e) => {
                    showTooltip(e, escapeHtml(tooltipMsg));
                });
                star.addEventListener('mouseleave', hideTooltip);
                star.addEventListener('click', (e) => {
                    e.stopPropagation();
                    showEventDetail(event, startDateObj);
                });

                rowBody.appendChild(star);
            } else {
                // 通常の予定/タスク
                const bar = document.createElement('div');
                bar.className = `gantt-bar ${event.type}`;
                
                if (isPrivate) {
                    bar.classList.add('private-schedule');
                }

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

                    // Draw task-level inline milestones
                    const targetId = event.id;
                    const inlineMilestones = getFilteredSchedules().filter(sched => 
                        sched.type === 'milestone' && 
                        sched.milestone_targets && 
                        sched.milestone_targets.length === 1 && 
                        sched.milestone_targets[0] === targetId
                    );

                    inlineMilestones.forEach(m => {
                        if (m.start_at) {
                            const mDateStr = m.start_at.substring(0, 10);
                            const mDateObj = parseLocalDate(mDateStr);
                            const mOffset = Math.round((mDateObj - timelineDays[0]) / (1000 * 60 * 60 * 24));
                            if (mOffset >= 0 && mOffset < timelineDays.length) {
                                const mStar = document.createElement('div');
                                mStar.className = 'gantt-milestone-star';
                                mStar.innerText = '★';
                                mStar.style.left = `${mOffset * 40 + 12}px`;
                                mStar.style.top = `calc(50% - 8px)`;
                                
                                const mTitle = parseInt(m.is_public) === 0 ? `🔒 [非公開] ${m.title}` : m.title;
                                
                                mStar.addEventListener('mouseenter', (e) => {
                                    showTooltip(e, escapeHtml(mTitle));
                                });
                                mStar.addEventListener('mouseleave', hideTooltip);
                                mStar.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    showEventDetail(m, mDateObj);
                                });
                                rowBody.appendChild(mStar);
                            }
                        }
                    });
                }

                // ─── セグメント対応バー描画 ───────────────────────────────────
                const segments = event.segments && Array.isArray(event.segments) && event.segments.length > 0
                    ? event.segments
                    : null;

                if (segments) {
                    // セグメントがある場合：同一行に複数バーを描画
                    segments.forEach((seg, segIdx) => {
                        const segStartStr = seg.start_at ? seg.start_at.substring(0, 10) : null;
                        const segEndStr   = seg.end_at   ? seg.end_at.substring(0, 10)   : segStartStr;
                        if (!segStartStr) return;

                        const segStartObj  = parseLocalDate(segStartStr);
                        const segEndObj    = parseLocalDate(segEndStr);
                        const segStartOff  = Math.max(0, Math.round((segStartObj - timelineDays[0]) / (1000 * 60 * 60 * 24)));
                        const segEndOff    = Math.min(timelineDays.length - 1, Math.round((segEndObj   - timelineDays[0]) / (1000 * 60 * 60 * 24)));
                        const segDuration  = segEndOff - segStartOff + 1;
                        if (segDuration <= 0) return;

                        const segBar = document.createElement('div');
                        segBar.className = bar.className; // 同じクラス（進捗状態含む）を引き継ぐ

                        if (segStartStr === segEndStr) {
                            segBar.style.left   = `${segStartOff * 40 + 12}px`;
                            segBar.style.width  = '16px';
                            segBar.style.height = '16px';
                            segBar.style.borderRadius = '50%';
                            segBar.style.padding      = '0';
                            segBar.style.justifyContent = 'center';
                            segBar.innerText = '';
                        } else {
                            segBar.style.left  = `${segStartOff * 40 + 8}px`;
                            segBar.style.width = `${segDuration * 40 - 16}px`;
                            // タイトルは最初のセグメントにのみ表示
                            segBar.innerText = segIdx === 0 ? displayTitle : '';
                        }

                        const segTooltip = `${escapeHtml(displayTitle)}: 第${segIdx + 1}期 (${segStartStr} 〜 ${segEndStr})`;
                        segBar.addEventListener('mouseenter', (e) => showTooltip(e, segTooltip));
                        segBar.addEventListener('mouseleave', hideTooltip);
                        segBar.addEventListener('click', () => showEventDetail(event, segStartObj));
                        rowBody.appendChild(segBar);
                    });
                } else {
                    // セグメントなし：従来の単一バー描画
                    if (startAtStr === endAtStr) {
                        // 1日未満（当日のみ）の場合は●（ドット）として配置
                        bar.style.left = `${startOffset * 40 + 12}px`;
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
            }
        }

        rightPanel.appendChild(rowBody);
    });

    // 先行タスク（依存関係）矢印の描画
    if (targetEvents.length > 0) {
        const totalHeight = targetEvents.length * 48;
        const svgNS = "http://www.w3.org/2000/svg";
        const arrowSvg = document.createElementNS(svgNS, "svg");
        arrowSvg.style.position = 'absolute';
        arrowSvg.style.top = '48px';
        arrowSvg.style.left = '0';
        arrowSvg.style.width = `${daysInMonth * 40}px`;
        arrowSvg.style.height = `${totalHeight}px`;
        arrowSvg.style.pointerEvents = 'none';
        arrowSvg.style.zIndex = '4';

        const defs = document.createElementNS(svgNS, "defs");
        const marker = document.createElementNS(svgNS, "marker");
        marker.setAttribute("id", "gantt-arrow");
        marker.setAttribute("viewBox", "0 0 10 10");
        marker.setAttribute("refX", "6");
        marker.setAttribute("refY", "5");
        marker.setAttribute("markerWidth", "6");
        marker.setAttribute("markerHeight", "6");
        marker.setAttribute("orient", "auto-start-reverse");

        const path = document.createElementNS(svgNS, "path");
        path.setAttribute("d", "M 0 2 L 8 5 L 0 8 z");
        path.setAttribute("fill", "rgba(56, 189, 248, 0.75)");

        marker.appendChild(path);
        defs.appendChild(marker);
        arrowSvg.appendChild(defs);

        targetEvents.forEach(event => {
            const successorId = event.id;
            const predecessors = event.predecessors || [];
            
            predecessors.forEach(predId => {
                if (ganttCoords[predId] && ganttCoords[successorId]) {
                    const predCoord = ganttCoords[predId];
                    const succCoord = ganttCoords[successorId];

                    const x1 = predCoord.endX;
                    const y1 = predCoord.y;
                    const x2 = succCoord.startX;
                    const y2 = succCoord.y;

                    const link = document.createElementNS(svgNS, "path");
                    
                    let d = "";
                    if (x2 >= x1 + 10) {
                        const xMid = x1 + (x2 - x1) / 2;
                        d = `M ${x1} ${y1} L ${xMid} ${y1} L ${xMid} ${y2} L ${x2} ${y2}`;
                    } else {
                        const xMid1 = x1 + 12;
                        const yMid = y1 + (y2 - y1) / 2;
                        const xMid2 = x2 - 12;
                        d = `M ${x1} ${y1} L ${xMid1} ${y1} L ${xMid1} ${yMid} L ${xMid2} ${yMid} L ${xMid2} ${y2} L ${x2} ${y2}`;
                    }

                    link.setAttribute("d", d);
                    link.setAttribute("stroke", "rgba(56, 189, 248, 0.45)");
                    link.setAttribute("stroke-width", "1.5");
                    link.setAttribute("fill", "none");
                    link.setAttribute("marker-end", "url(#gantt-arrow)");
                    arrowSvg.appendChild(link);
                }
            });
        });

        rightPanel.appendChild(arrowSvg);
    }

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

                const isPast = endDateObj < today;
                const isFuture = startDateObj > today;

                // 頂点計算を行わない（x = x_today のまままっすぐ通過）ケース:
                // 1. 過去のタスクで、完了している（P_total >= 100）
                // 2. 未来のタスクで、未着手である（P_total === 0）
                const skipCalculation = (isPast && P_total >= 100) || (isFuture && P_total === 0);
                if (skipCalculation) {
                    // x = x_today のまま（稲妻線をその行でまっすぐ通過）
                    points.push(`${x},${y}`);
                    return; // 進捗ドットも描かない
                }

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
