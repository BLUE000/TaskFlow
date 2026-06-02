#ifndef LOCAL_STORAGE_H
#define LOCAL_STORAGE_H

#include <QList>
#include <QString>
#include <QDateTime>
#include "model/schedule.h"
#include "model/time_slot.h"
#include "model/webhook.h"

class LocalStorage {
public:
    LocalStorage() = default;
    ~LocalStorage() = default;

    // 予定 (schedules) 操作
    QList<Schedule> getAllSchedules(bool includeDeleted = false);
    Schedule getScheduleById(const QString& id);
    bool saveSchedule(const Schedule& sched);
    bool deleteSchedule(const QString& id);

    // 時間枠 (time_slots) 操作
    QList<TimeSlot> getAllTimeSlots(bool includeDeleted = false);
    TimeSlot getTimeSlotById(const QString& id);
    bool saveTimeSlot(const TimeSlot& slot);
    bool deleteTimeSlot(const QString& id);

    // Webhook (webhooks) 操作
    QList<Webhook> getAllWebhooks(bool includeDeleted = false);
    Webhook getWebhookById(const QString& id);
    bool saveWebhook(const Webhook& webhook);
    bool deleteWebhook(const QString& id);

    // 同期メタデータ
    QDateTime getLastSyncedAt();
    bool setLastSyncedAt(const QDateTime& dt);
    QString getMetadata(const QString& key, const QString& defaultValue = "");
    bool setMetadata(const QString& key, const QString& value);
};

#endif // LOCAL_STORAGE_H
