#include "scheduler.h"
#include "database/local_storage.h"
#include <QDateTime>
#include <QDebug>

bool Scheduler::checkLoadOverflow(const QDate& date, const QString& timeSlotId, double& currentLoad, double& limitHours) {
    if (timeSlotId.isEmpty()) {
        currentLoad = 0.0;
        limitHours = 0.0;
        return false;
    }

    LocalStorage storage;
    TimeSlot slot = storage.getTimeSlotById(timeSlotId);
    if (slot.id.isEmpty() || slot.is_deleted) {
        currentLoad = 0.0;
        limitHours = 0.0;
        return false;
    }

    limitHours = slot.limit_hours;

    QMap<QString, double> loads = calculateLoadsForDate(date);
    currentLoad = loads.value(timeSlotId, 0.0);

    return currentLoad > limitHours;
}

QMap<QString, double> Scheduler::calculateLoadsForDate(const QDate& date) {
    QMap<QString, double> loads;
    LocalStorage storage;
    QList<Schedule> schedules = storage.getAllSchedules(false);

    for (const Schedule& s : schedules) {
        if (s.is_deleted || s.type != "task" || s.time_slot_id.isEmpty()) {
            continue;
        }

        if (s.start_at.isValid() && s.start_at.date() == date) {
            loads[s.time_slot_id] += s.weight;
        }
    }

    return loads;
}

QList<QString> Scheduler::getOverflowedTimeSlotIds(const QDate& date) {
    QList<QString> overflowedIds;
    LocalStorage storage;
    QList<TimeSlot> timeSlots = storage.getAllTimeSlots(false);
    QMap<QString, double> loads = calculateLoadsForDate(date);

    for (const TimeSlot& slot : timeSlots) {
        double currentLoad = loads.value(slot.id, 0.0);
        if (currentLoad > slot.limit_hours) {
            overflowedIds.append(slot.id);
        }
    }

    return overflowedIds;
}
