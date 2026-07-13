#include "mainwindow.h"
#include "ui_mainwindow.h"
#include "sync/sync_engine.h"
#include "ui/conflict_dialog.h"
#include "common/crypto_util.h"
#include <QUuid>
#include <QMessageBox>
#include <QDateTime>
#include <QDebug>

MainWindow::MainWindow(QWidget *parent)
    : QMainWindow(parent), ui(new Ui::MainWindow) {
    ui->setupUi(this);

    m_syncEngine = new SyncEngine(this);

    initUi();

    // 接続設定のロードと暗号化キーの初期化
    loadApiSettings();
    QString secretKey = m_storage.getMetadata("secret_key", "test_secret_key_12345");
    CryptoUtil::init(secretKey);

    // データのロード
    loadTimeSlots();
    loadWebhooks();
    loadSchedules();
}

MainWindow::~MainWindow() {
    delete m_syncEngine;
    delete ui;
}

void MainWindow::initUi() {
    // ウィンドウタイトルの設定（CMakeで定義されたバージョンを使用）
#ifdef APP_VERSION
    setWindowTitle(QString("TaskFlow-Client v%1").arg(APP_VERSION));
#else
    setWindowTitle("TaskFlow-Client");
#endif

    // 予定タイプのコンボボックス初期化
    ui->cmbType->addItem("Auto Detect (自動判定)", "auto");
    ui->cmbType->addItem("Simple Schedule (簡易予定)", "simple");
    ui->cmbType->addItem("Period Schedule (期間予定)", "period");
    ui->cmbType->addItem("Task Schedule (個人タスク)", "task");
    ui->cmbType->addItem("Memo (やることメモ)", "memo");

    // 日時初期値設定 (現在時刻)
    ui->dtStart->setDateTime(QDateTime::currentDateTime());
    ui->dtEnd->setDateTime(QDateTime::currentDateTime().addSecs(3600));

    // テーブル列数の設定
    ui->tableSchedules->setColumnCount(7);
    QStringList headers;
    headers << "ID" << "Title" << "Type" << "Start At" << "End At" << "Weight" << "Public";
    ui->tableSchedules->setHorizontalHeaderLabels(headers);
    ui->tableSchedules->setColumnHidden(0, true); // IDは隠す
    ui->tableSchedules->horizontalHeader()->setStretchLastSection(true);

    // スロット接続
    connect(ui->btnAddSchedule, &QPushButton::clicked, this, &MainWindow::onAddScheduleClicked);
    connect(ui->btnSaveSchedule, &QPushButton::clicked, this, &MainWindow::onSaveScheduleClicked);
    connect(ui->btnDeleteSchedule, &QPushButton::clicked, this, &MainWindow::onDeleteScheduleClicked);
    connect(ui->btnClearScheduleForm, &QPushButton::clicked, this, &MainWindow::onClearScheduleFormClicked);
    connect(ui->tableSchedules, &QTableWidget::cellClicked, this, &MainWindow::onScheduleSelected);

    // リアルタイム負荷警告の変更トリガー
    connect(ui->dtStart, &QDateTimeEdit::dateTimeChanged, this, &MainWindow::onScheduleFormChanged);
    connect(ui->spinWeight, qOverload<double>(&QDoubleSpinBox::valueChanged), this, &MainWindow::onScheduleFormChanged);
    connect(ui->cmbTimeSlot, qOverload<int>(&QComboBox::currentIndexChanged), this, &MainWindow::onScheduleFormChanged);
    connect(ui->cmbType, qOverload<int>(&QComboBox::currentIndexChanged), this, &MainWindow::onTypeChanged);

    // 時間枠・Webhook・設定ボタンの接続
    connect(ui->btnAddTimeSlot, &QPushButton::clicked, this, &MainWindow::onAddTimeSlotClicked);
    connect(ui->btnDeleteTimeSlot, &QPushButton::clicked, this, &MainWindow::onDeleteTimeSlotClicked);
    connect(ui->listTimeSlots, &QListWidget::itemSelectionChanged, this, &MainWindow::onTimeSlotSelected);

    connect(ui->btnAddWebhook, &QPushButton::clicked, this, &MainWindow::onAddWebhookClicked);
    connect(ui->btnDeleteWebhook, &QPushButton::clicked, this, &MainWindow::onDeleteWebhookClicked);
    connect(ui->listWebhooks, &QListWidget::itemSelectionChanged, this, &MainWindow::onWebhookSelected);

    connect(ui->btnSaveSettings, &QPushButton::clicked, this, &MainWindow::onSaveSettingsClicked);
    connect(ui->btnSyncNow, &QPushButton::clicked, this, &MainWindow::onSyncNowClicked);
}

// --- カスタムイベント受信 ---
void MainWindow::customEvent(QEvent *event) {
    if (event->type() == static_cast<QEvent::Type>(SyncProgressType)) {
        auto *progressEvent = static_cast<SyncProgressEvent*>(event);
        ui->progressBar->setValue(progressEvent->percentage());
    } 
    else if (event->type() == static_cast<QEvent::Type>(SyncConflictType)) {
        auto *conflictEvent = static_cast<SyncConflictEvent*>(event);
        showConflictDialog(conflictEvent->localSchedule(), conflictEvent->remoteSchedule());
    } 
    else if (event->type() == static_cast<QEvent::Type>(SyncFinishedType)) {
        auto *finishedEvent = static_cast<SyncFinishedEvent*>(event);
        ui->btnSyncNow->setEnabled(true);
        ui->progressBar->setValue(100);
        
        if (finishedEvent->success()) {
            ui->lblSyncStatus->setText("Sync finished successfully.");
            QMessageBox::information(this, "Sync Completed", "Synchronization completed successfully.");
            // データをリロード
            loadTimeSlots();
            loadWebhooks();
            loadSchedules();
        } else {
            ui->lblSyncStatus->setText(QString("Sync Failed: %1").arg(finishedEvent->message()));
            QMessageBox::warning(this, "Sync Failed", QString("Sync failed:\n%1").arg(finishedEvent->message()));
        }
    }
    QMainWindow::customEvent(event);
}

void MainWindow::showConflictDialog(const Schedule& local, const Schedule& remote) {
    // 競合解決ダイアログを起動
    ConflictDialog dialog(local, remote, this);
    if (dialog.exec() == QDialog::Accepted) {
        Schedule resolved = dialog.getResolvedSchedule();
        m_syncEngine->resolveConflict(resolved);
    } else {
        // ダイアログがキャンセルされた場合は、安全のためにリモート優先で自動解決を促す
        m_syncEngine->resolveConflict(remote);
    }
}

// --- データのロードと表示 ---

void MainWindow::loadSchedules() {
    ui->tableSchedules->setRowCount(0);
    m_scheduleListCache = m_storage.getAllSchedules(false);

    for (int i = 0; i < m_scheduleListCache.size(); ++i) {
        const Schedule& s = m_scheduleListCache[i];
        ui->tableSchedules->insertRow(i);

        QTableWidgetItem *item0 = new QTableWidgetItem(s.id);
        QTableWidgetItem *item1 = new QTableWidgetItem(s.title);
        QTableWidgetItem *item2 = new QTableWidgetItem(s.type);
        QTableWidgetItem *item3 = new QTableWidgetItem(s.start_at.isValid() ? s.start_at.toString("yyyy-MM-dd HH:mm") : "-");
        QTableWidgetItem *item4 = new QTableWidgetItem(s.end_at.isValid() ? s.end_at.toString("yyyy-MM-dd HH:mm") : "-");
        QTableWidgetItem *item5 = new QTableWidgetItem(QString::number(s.weight));
        QTableWidgetItem *item6 = new QTableWidgetItem(s.is_public ? "Yes" : "No");

        ui->tableSchedules->setItem(i, 0, item0);
        ui->tableSchedules->setItem(i, 1, item1);
        ui->tableSchedules->setItem(i, 2, item2);
        ui->tableSchedules->setItem(i, 3, item3);
        ui->tableSchedules->setItem(i, 4, item4);
        ui->tableSchedules->setItem(i, 5, item5);
        ui->tableSchedules->setItem(i, 6, item6);
    }
    onClearScheduleFormClicked();
}

void MainWindow::loadTimeSlots() {
    ui->listTimeSlots->clear();
    ui->cmbTimeSlot->clear();
    ui->cmbTimeSlot->addItem("(None)", "");

    m_timeSlotListCache = m_storage.getAllTimeSlots(false);
    for (const TimeSlot& t : m_timeSlotListCache) {
        QString text = QString("%1 (%2 - %3, Max: %4h)")
            .arg(t.name, t.start_time.toString("HH:mm"), t.end_time.toString("HH:mm"), QString::number(t.limit_hours));
        QListWidgetItem *item = new QListWidgetItem(text);
        item->setData(Qt::UserRole, t.id);
        ui->listTimeSlots->addItem(item);

        ui->cmbTimeSlot->addItem(t.name, t.id);
    }
}

void MainWindow::loadWebhooks() {
    ui->listWebhooks->clear();
    m_webhookListCache = m_storage.getAllWebhooks(false);
    for (const Webhook& w : m_webhookListCache) {
        QString text = QString("%1 (%2)").arg(w.name, w.url);
        QListWidgetItem *item = new QListWidgetItem(text);
        item->setData(Qt::UserRole, w.id);
        ui->listWebhooks->addItem(item);
    }
}

void MainWindow::loadApiSettings() {
    ui->txtApiUrl->setText(m_storage.getMetadata("api_url", "http://localhost:8000/sync.php"));
    ui->txtApiToken->setText(m_storage.getMetadata("api_token", "tc_f2d6ccf601dd459b515215fd"));
    ui->txtSecretKey->setText(m_storage.getMetadata("secret_key", "test_secret_key_12345"));
}

// --- 予定の追加/編集フォームイベント ---

void MainWindow::onAddScheduleClicked() {
    QString title = ui->txtTitle->text().trimmed();
    if (title.isEmpty()) {
        QMessageBox::warning(this, "Validation Error", "Title cannot be empty.");
        return;
    }

    Schedule s;
    s.id = QUuid::createUuid().toString(QUuid::WithoutBraces);
    s.title = title;
    s.description = ui->txtDescription->toPlainText().trimmed();
    s.weight = ui->spinWeight->value();
    s.time_slot_id = ui->cmbTimeSlot->currentData().toString();
    s.is_public = ui->chkIsPublic->isChecked();
    s.is_deleted = false;
    s.updated_at = QDateTime::currentDateTime();

    // 期間の取得
    QDateTime start = ui->dtStart->dateTime();
    QDateTime end = ui->dtEnd->dateTime();
    
    // 自動タイプ判定
    QString selectedType = ui->cmbType->currentData().toString();
    if (selectedType == "auto") {
        if (s.weight > 0.0) {
            s.type = "task";
            s.start_at = start;
            s.end_at = end;
        } else if (start != end) {
            s.type = "period";
            s.start_at = start;
            s.end_at = end;
        } else {
            s.type = "simple";
            s.start_at = QDateTime(); // 無効な日付
            s.end_at = QDateTime();
        }
    } else {
        s.type = selectedType;
        if (s.type == "simple" || s.type == "memo") {
            s.start_at = QDateTime();
            s.end_at = QDateTime();
        } else {
            s.start_at = start;
            s.end_at = end;
        }
    }

    if (m_storage.saveSchedule(s)) {
        loadSchedules();
    }
}

void MainWindow::onSaveScheduleClicked() {
    int row = ui->tableSchedules->currentRow();
    if (row < 0 || row >= m_scheduleListCache.size()) {
        QMessageBox::warning(this, "Selection Error", "Please select a schedule to save changes.");
        return;
    }

    QString id = ui->tableSchedules->item(row, 0)->text();
    QString title = ui->txtTitle->text().trimmed();
    if (title.isEmpty()) {
        QMessageBox::warning(this, "Validation Error", "Title cannot be empty.");
        return;
    }

    Schedule s = m_storage.getScheduleById(id);
    s.title = title;
    s.description = ui->txtDescription->toPlainText().trimmed();
    s.weight = ui->spinWeight->value();
    s.time_slot_id = ui->cmbTimeSlot->currentData().toString();
    s.is_public = ui->chkIsPublic->isChecked();
    s.updated_at = QDateTime::currentDateTime();

    QDateTime start = ui->dtStart->dateTime();
    QDateTime end = ui->dtEnd->dateTime();

    // タイプ判定
    QString selectedType = ui->cmbType->currentData().toString();
    if (selectedType == "auto") {
        if (s.weight > 0.0) {
            s.type = "task";
            s.start_at = start;
            s.end_at = end;
        } else if (start != end) {
            s.type = "period";
            s.start_at = start;
            s.end_at = end;
        } else {
            s.type = "simple";
            s.start_at = QDateTime();
            s.end_at = QDateTime();
        }
    } else {
        s.type = selectedType;
        if (s.type == "simple" || s.type == "memo") {
            s.start_at = QDateTime();
            s.end_at = QDateTime();
        } else {
            s.start_at = start;
            s.end_at = end;
        }
    }

    if (m_storage.saveSchedule(s)) {
        loadSchedules();
    }
}

void MainWindow::onDeleteScheduleClicked() {
    int row = ui->tableSchedules->currentRow();
    if (row < 0 || row >= m_scheduleListCache.size()) {
        QMessageBox::warning(this, "Selection Error", "Please select a schedule to delete.");
        return;
    }

    QString id = ui->tableSchedules->item(row, 0)->text();
    if (QMessageBox::question(this, "Confirm Delete", "Are you sure you want to delete this schedule?", QMessageBox::Yes | QMessageBox::No) == QMessageBox::Yes) {
        if (m_storage.deleteSchedule(id)) {
            loadSchedules();
        }
    }
}

void MainWindow::onClearScheduleFormClicked() {
    m_isUpdatingForm = true;
    ui->txtTitle->clear();
    ui->txtDescription->clear();
    ui->cmbType->setCurrentIndex(0);
    ui->dtStart->setDateTime(QDateTime::currentDateTime());
    ui->dtEnd->setDateTime(QDateTime::currentDateTime().addSecs(3600));
    ui->spinWeight->setValue(0.0);
    ui->cmbTimeSlot->setCurrentIndex(0);
    ui->chkIsPublic->setChecked(false);
    ui->lblLoadWarning->clear();
    ui->tableSchedules->clearSelection();
    m_isUpdatingForm = false;
}

void MainWindow::onScheduleSelected(int row, int /*column*/) {
    if (row < 0 || row >= m_scheduleListCache.size()) return;

    m_isUpdatingForm = true;
    const Schedule& s = m_scheduleListCache[row];

    ui->txtTitle->setText(s.title);
    ui->txtDescription->setText(s.description);
    
    // コボボックスのインデックス設定
    int idx = ui->cmbType->findData(s.type);
    ui->cmbType->setCurrentIndex(idx >= 0 ? idx : 0);

    if (s.start_at.isValid()) ui->dtStart->setDateTime(s.start_at);
    if (s.end_at.isValid()) ui->dtEnd->setDateTime(s.end_at);
    ui->spinWeight->setValue(s.weight);

    idx = ui->cmbTimeSlot->findData(s.time_slot_id);
    ui->cmbTimeSlot->setCurrentIndex(idx >= 0 ? idx : 0);

    ui->chkIsPublic->setChecked(s.is_public);
    m_isUpdatingForm = false;

    checkLoadAndWarn();
}

void MainWindow::onScheduleFormChanged() {
    if (m_isUpdatingForm) return;
    checkLoadAndWarn();
}

void MainWindow::onTypeChanged(int /*index*/) {
    QString type = ui->cmbType->currentData().toString();
    bool isMemo = (type == "memo");
    ui->dtStart->setEnabled(!isMemo);
    ui->dtEnd->setEnabled(!isMemo);
}

// リアルタイム負荷警告の計算
void MainWindow::checkLoadAndWarn() {
    QDate date = ui->dtStart->date();
    QString timeSlotId = ui->cmbTimeSlot->currentData().toString();
    double currentLoad = 0.0;
    double limitHours = 0.0;

    // 現在編集中の weight も含めて計算するため、
    // まず日付の既存負荷（キャッシュから）を合計し、選択中の予定の weight 分を引いたのち、
    // 入力中の weight を足して判定します。
    double editedWeight = ui->spinWeight->value();
    
    // 現在選択中の予定のIDを取得
    QString selectedId = "";
    int row = ui->tableSchedules->currentRow();
    if (row >= 0 && row < m_scheduleListCache.size()) {
        selectedId = m_scheduleListCache[row].id;
    }

    // 日付と時間枠で合算
    double totalWeight = 0.0;
    for (const Schedule& s : m_scheduleListCache) {
        if (s.is_deleted || s.type != "task" || s.time_slot_id != timeSlotId) continue;
        if (s.start_at.isValid() && s.start_at.date() == date) {
            // 現在編集中の行の元の負荷は除外
            if (s.id != selectedId) {
                totalWeight += s.weight;
            }
        }
    }
    // 入力中の負荷を足す
    totalWeight += editedWeight;

    // 制限値を取得
    LocalStorage storage;
    TimeSlot slot = storage.getTimeSlotById(timeSlotId);
    if (!slot.id.isEmpty() && !slot.is_deleted) {
        limitHours = slot.limit_hours;
        if (totalWeight > limitHours) {
            ui->lblLoadWarning->setStyleSheet("color: red; font-weight: bold;");
            ui->lblLoadWarning->setText(QString("Warning: Overloaded! Limit: %1h, Current: %2h")
                .arg(QString::number(limitHours), QString::number(totalWeight)));
        } else {
            ui->lblLoadWarning->setStyleSheet("color: green;");
            ui->lblLoadWarning->setText(QString("Load Status: OK (Limit: %1h, Current: %2h)")
                .arg(QString::number(limitHours), QString::number(totalWeight)));
        }
    } else {
        ui->lblLoadWarning->clear();
    }
}

// --- 時間枠 (Time Slot) 操作 ---

void MainWindow::onAddTimeSlotClicked() {
    QString name = ui->txtSlotName->text().trimmed();
    if (name.isEmpty()) return;

    TimeSlot t;
    t.id = QUuid::createUuid().toString(QUuid::WithoutBraces);
    t.name = name;
    t.start_time = ui->timeSlotStart->time();
    t.end_time = ui->timeSlotEnd->time();
    t.limit_hours = ui->spinSlotLimit->value();
    t.is_deleted = false;
    t.updated_at = QDateTime::currentDateTime();

    if (m_storage.saveTimeSlot(t)) {
        loadTimeSlots();
        ui->txtSlotName->clear();
    }
}

void MainWindow::onDeleteTimeSlotClicked() {
    QListWidgetItem *item = ui->listTimeSlots->currentItem();
    if (!item) return;

    QString id = item->data(Qt::UserRole).toString();
    if (m_storage.deleteTimeSlot(id)) {
        loadTimeSlots();
    }
}

void MainWindow::onTimeSlotSelected() {
    QListWidgetItem *item = ui->listTimeSlots->currentItem();
    if (!item) return;

    QString id = item->data(Qt::UserRole).toString();
    TimeSlot t = m_storage.getTimeSlotById(id);
    ui->txtSlotName->setText(t.name);
    ui->timeSlotStart->setTime(t.start_time);
    ui->timeSlotEnd->setTime(t.end_time);
    ui->spinSlotLimit->setValue(t.limit_hours);
}

// --- Webhook 操作 ---

void MainWindow::onAddWebhookClicked() {
    QString name = ui->txtWebhookName->text().trimmed();
    QString url = ui->txtWebhookUrl->text().trimmed();
    if (name.isEmpty() || url.isEmpty()) return;

    Webhook w;
    w.id = QUuid::createUuid().toString(QUuid::WithoutBraces);
    w.name = name;
    w.url = url;
    w.event_trigger = "all";
    w.is_deleted = false;
    w.updated_at = QDateTime::currentDateTime();

    if (m_storage.saveWebhook(w)) {
        loadWebhooks();
        ui->txtWebhookName->clear();
        ui->txtWebhookUrl->clear();
    }
}

void MainWindow::onDeleteWebhookClicked() {
    QListWidgetItem *item = ui->listWebhooks->currentItem();
    if (!item) return;

    QString id = item->data(Qt::UserRole).toString();
    if (m_storage.deleteWebhook(id)) {
        loadWebhooks();
    }
}

void MainWindow::onWebhookSelected() {
    QListWidgetItem *item = ui->listWebhooks->currentItem();
    if (!item) return;

    QString id = item->data(Qt::UserRole).toString();
    Webhook w = m_storage.getWebhookById(id);
    ui->txtWebhookName->setText(w.name);
    ui->txtWebhookUrl->setText(w.url);
}

// --- 設定・同期操作 ---

void MainWindow::onSaveSettingsClicked() {
    QString url = ui->txtApiUrl->text().trimmed();
    QString token = ui->txtApiToken->text().trimmed();
    QString secret = ui->txtSecretKey->text().trimmed();

    m_storage.setMetadata("api_url", url);
    m_storage.setMetadata("api_token", token);
    m_storage.setMetadata("secret_key", secret);

    // 暗号キーの再初期設定
    CryptoUtil::init(secret);

    QMessageBox::information(this, "Settings Saved", "API Settings have been successfully saved.");
}

void MainWindow::onSyncNowClicked() {
    ui->btnSyncNow->setEnabled(false);
    ui->lblSyncStatus->setText("Synchronizing with MySQL server...");
    ui->progressBar->setValue(0);

    // 同期をキック
    m_syncEngine->startSync();
}
