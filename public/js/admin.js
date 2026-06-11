const timeSlots = window.TaskFlowData.timeSlots;
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

    // セグメントフィールドは task / period タイプのみ表示
    const segmentFields = document.getElementById('segment-fields');
    if (segmentFields) {
        segmentFields.style.display = (type === 'task' || type === 'period') ? 'block' : 'none';
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

    // 先行タスクのロード
    const predecessorSelect = document.getElementById('sched-predecessors');
    if (predecessorSelect) {
        Array.from(predecessorSelect.options).forEach(opt => {
            opt.selected = sched.predecessors && sched.predecessors.includes(opt.value);
            // 自分自身を先行タスクに設定できないように非表示（または無効化）にする
            if (opt.value === sched.id) {
                opt.style.display = 'none';
                opt.disabled = true;
                opt.selected = false;
            } else {
                opt.style.display = 'block';
                opt.disabled = false;
            }
        });
    }

    // セグメントのロード
    loadSegments(sched.segments || []);

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

    // セグメントのクリア
    loadSegments([]);

    // 先行タスクのクリア
    const predecessorSelect = document.getElementById('sched-predecessors');
    if (predecessorSelect) {
        Array.from(predecessorSelect.options).forEach(opt => {
            opt.selected = false;
            opt.style.display = 'block';
            opt.disabled = false;
        });
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

// ─── セグメント管理 ────────────────────────────────────────────────

/**
 * セグメント一覧を segment-list に描画する
 * @param {Array} segments [{id, start_at, end_at, segment_order}, ...]
 */
function loadSegments(segments) {
    const list = document.getElementById('segment-list');
    if (!list) return;
    list.innerHTML = '';
    if (segments && segments.length > 0) {
        segments.forEach(seg => {
            addSegmentRow(
                seg.start_at ? seg.start_at.substring(0, 16).replace(' ', 'T') : '',
                seg.end_at   ? seg.end_at.substring(0, 16).replace(' ', 'T')   : ''
            );
        });
    }
}

/**
 * セグメント入力行を1行追加する
 * @param {string} startVal  開始日時の初期値 (YYYY-MM-DDTHH:mm)
 * @param {string} endVal    終了日時の初期値
 */
function addSegmentRow(startVal = '', endVal = '') {
    const list = document.getElementById('segment-list');
    if (!list) return;

    const index = list.children.length;
    const row = document.createElement('div');
    row.style.cssText = 'display:flex; gap:0.75rem; align-items:center; margin-bottom:0.5rem; flex-wrap:wrap;';
    row.innerHTML = `
        <span style="font-size:0.8rem; color:var(--text-secondary); min-width:3rem;">第${index + 1}期</span>
        <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
            <label style="font-size:0.8rem; font-weight:normal; margin:0;">開始</label>
            <input type="datetime-local" name="segments[${index}][start_at]" value="${escapeHtml(startVal)}"
                   style="padding:0.3rem 0.5rem; font-size:0.85rem; background:rgba(15,23,42,0.6); border:1px solid var(--panel-border); border-radius:6px; color:var(--text-primary);">
            <label style="font-size:0.8rem; font-weight:normal; margin:0;">終了</label>
            <input type="datetime-local" name="segments[${index}][end_at]" value="${escapeHtml(endVal)}"
                   style="padding:0.3rem 0.5rem; font-size:0.85rem; background:rgba(15,23,42,0.6); border:1px solid var(--panel-border); border-radius:6px; color:var(--text-primary);">
        </div>
        <button type="button" onclick="removeSegmentRow(this)" style="padding:0.2rem 0.6rem; font-size:0.8rem; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#fca5a5; border-radius:6px; cursor:pointer;">✕ 削除</button>
    `;
    list.appendChild(row);
    // name の index を振り直す
    renumberSegments();
}

/** セグメント行を削除して index を振り直す */
function removeSegmentRow(btn) {
    btn.closest('div[style]').remove();
    renumberSegments();
}

/** セグメントの表示番号と name[index] を振り直す */
function renumberSegments() {
    const list = document.getElementById('segment-list');
    if (!list) return;
    Array.from(list.children).forEach((row, i) => {
        const label = row.querySelector('span');
        if (label) label.textContent = `第${i + 1}期`;
        const inputs = row.querySelectorAll('input[name]');
        inputs.forEach(inp => {
            inp.name = inp.name.replace(/segments\[\d+\]/, `segments[${i}]`);
        });
    });
}
