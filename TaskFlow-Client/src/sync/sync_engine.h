#ifndef SYNC_ENGINE_H
#define SYNC_ENGINE_H

#include <QObject>
#include <QDateTime>
#include <QEvent>
#include <QMutex>
#include <QWaitCondition>
#include "model/schedule.h"
#include "model/time_slot.h"
#include "model/webhook.h"

// カスタムイベントのタイプ定義
enum TaskFlowEventType {
    SyncProgressType = QEvent::User + 1,
    SyncConflictType,
    SyncFinishedType
};

// カスタムイベントクラス群
class SyncProgressEvent : public QEvent {
public:
    SyncProgressEvent(int percentage)
        : QEvent(static_cast<QEvent::Type>(SyncProgressType)), m_percentage(percentage) {}
    int percentage() const { return m_percentage; }
private:
    int m_percentage;
};

class SyncConflictEvent : public QEvent {
public:
    SyncConflictEvent(const Schedule& localSched, const Schedule& remoteSched)
        : QEvent(static_cast<QEvent::Type>(SyncConflictType)), m_localSched(localSched), m_remoteSched(remoteSched) {}
    Schedule localSchedule() const { return m_localSched; }
    Schedule remoteSchedule() const { return m_remoteSched; }
private:
    Schedule m_localSched;
    Schedule m_remoteSched;
};

class SyncFinishedEvent : public QEvent {
public:
    SyncFinishedEvent(bool success, const QString& message)
        : QEvent(static_cast<QEvent::Type>(SyncFinishedType)), m_success(success), m_message(message) {}
    bool success() const { return m_success; }
    QString message() const { return m_message; }
private:
    bool m_success;
    QString m_message;
};

class SyncEngine {
public:
    explicit SyncEngine(QObject *receiver = nullptr);
    ~SyncEngine() = default;

    void setReceiver(QObject *receiver);

    // 同期処理を非同期で開始する
    void startSync();

    // UIで競合が解決されたら呼び出される
    void resolveConflict(const Schedule& resolvedSched);

private:
    void runSync();

    QObject *m_receiver;
    QMutex m_mutex;
    QWaitCondition m_waitCondition;
    Schedule m_resolvedSchedule;
    bool m_hasResolution;
    bool m_isSyncing;
};

#endif // SYNC_ENGINE_H
