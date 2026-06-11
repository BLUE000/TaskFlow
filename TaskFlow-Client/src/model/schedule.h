#ifndef SCHEDULE_H
#define SCHEDULE_H

#include <QString>
#include <QDateTime>
#include <QList>

struct Segment {
    QString id;
    QString schedule_id;
    int segment_order = 1;
    QDateTime start_at;
    QDateTime end_at;
    QDateTime created_at;
    QDateTime updated_at;
    bool is_deleted = false;
};

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
    QString recurrence_group_id;
    QList<Segment> segments;
    QList<QString> predecessors; // list of predecessor schedule IDs
};

#endif // SCHEDULE_H
