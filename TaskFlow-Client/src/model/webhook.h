#ifndef WEBHOOK_H
#define WEBHOOK_H

#include <QString>
#include <QDateTime>

struct Webhook {
    QString id;
    QString name;
    QString url;
    QString event_trigger; // 'add', 'update', 'all'
    bool is_active = true;
    bool is_deleted = false;
    QDateTime updated_at;
};

#endif // WEBHOOK_H
