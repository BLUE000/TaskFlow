#ifndef SCHEDULER_H
#define SCHEDULER_H

#include <QString>
#include <QDate>
#include <QMap>
#include <QList>

class Scheduler {
public:
    Scheduler() = default;
    ~Scheduler() = default;

    bool checkLoadOverflow(const QDate& date, const QString& timeSlotId, double& currentLoad, double& limitHours);
    QMap<QString, double> calculateLoadsForDate(const QDate& date);
    QList<QString> getOverflowedTimeSlotIds(const QDate& date);
};

#endif // SCHEDULER_H
