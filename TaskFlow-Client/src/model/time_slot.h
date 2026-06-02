#ifndef TIME_SLOT_H
#define TIME_SLOT_H

#include <QString>
#include <QTime>
#include <QDateTime>

struct TimeSlot {
    QString id;
    QString name;
    QTime start_time;
    QTime end_time;
    double limit_hours = 0.0;
    bool is_deleted = false;
    QDateTime updated_at;
};

#endif // TIME_SLOT_H
