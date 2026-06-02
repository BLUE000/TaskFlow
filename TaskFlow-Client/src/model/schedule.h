#ifndef SCHEDULE_H
#define SCHEDULE_H

#include <QString>
#include <QDateTime>

struct Schedule {
    QString id;
    QString title;
    QString description;
    QDateTime start_at;
    QDateTime end_at;
    QString type; // 'simple', 'period', 'task'
    double weight = 0.0;
    QString time_slot_id;
    bool is_public = false;
    bool is_deleted = false;
    QDateTime updated_at;
};

#endif // SCHEDULE_H
