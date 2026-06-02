#ifndef SYNC_CLIENT_H
#define SYNC_CLIENT_H

#include <QString>
#include <QJsonObject>
#include <QJsonArray>
#include <QDateTime>
#include "model/schedule.h"
#include "model/time_slot.h"
#include "model/webhook.h"

class SyncClient {
public:
    SyncClient() = default;
    ~SyncClient() = default;

    bool pull(const QDateTime& lastSyncedAt, QDateTime& serverTime,
              QList<Schedule>& schedules, QList<TimeSlot>& timeSlots, QList<Webhook>& webhooks,
              QString& errorMsg);

    bool push(const QList<Schedule>& schedules, const QList<TimeSlot>& timeSlots, const QList<Webhook>& webhooks,
              QString& errorMsg);
};

#endif // SYNC_CLIENT_H
