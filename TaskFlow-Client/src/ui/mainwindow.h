#ifndef MAINWINDOW_H
#define MAINWINDOW_H

#include <QMainWindow>
#include <QList>
#include <QEvent>
#include "model/schedule.h"
#include "model/time_slot.h"
#include "model/webhook.h"
#include "database/local_storage.h"
#include "scheduler/scheduler.h"

QT_BEGIN_NAMESPACE
namespace Ui { class MainWindow; }
QT_END_NAMESPACE

class SyncEngine;

class MainWindow : public QMainWindow {
    Q_OBJECT
public:
    explicit MainWindow(QWidget *parent = nullptr);
    ~MainWindow();

protected:
    // 下位モジュール (SyncEngine等) からポストされたカスタムイベントを受信して処理する
    void customEvent(QEvent *event) override;

private slots:
    // 予定(Schedule)操作
    void onAddScheduleClicked();
    void onSaveScheduleClicked();
    void onDeleteScheduleClicked();
    void onClearScheduleFormClicked();
    void onScheduleSelected(int row, int column);
    void onScheduleFormChanged();

    // 時間枠(TimeSlot)操作
    void onAddTimeSlotClicked();
    void onDeleteTimeSlotClicked();
    void onTimeSlotSelected();

    // Webhook操作
    void onAddWebhookClicked();
    void onDeleteWebhookClicked();
    void onWebhookSelected();

    // 設定・同期操作
    void onSaveSettingsClicked();
    void onSyncNowClicked();

private:
    void initUi();
    void loadSchedules();
    void loadTimeSlots();
    void loadWebhooks();
    void loadApiSettings();
    void checkLoadAndWarn();
    void showConflictDialog(const Schedule& local, const Schedule& remote);

    Ui::MainWindow *ui;
    SyncEngine *m_syncEngine;
    LocalStorage m_storage;
    Scheduler m_scheduler;

    QList<Schedule> m_scheduleListCache;
    QList<TimeSlot> m_timeSlotListCache;
    QList<Webhook> m_webhookListCache;
    bool m_isUpdatingForm = false;
};

#endif // MAINWINDOW_H
