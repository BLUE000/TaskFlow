#include "local_storage.h"
#include "db_manager.h"
#include "common/crypto_util.h"
#include <QSqlQuery>
#include <QSqlError>
#include <QVariant>
#include <QDebug>

// --- 予定 (schedules) 操作 ---

QList<Schedule> LocalStorage::getAllSchedules(bool includeDeleted) {
    QList<Schedule> list;
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);

    QString sql = "SELECT id, title, description, type, start_at, end_at, status, priority, weight, time_slot_id, is_public, is_deleted, updated_at, recurrence_group_id FROM schedules";
    if (!includeDeleted) {
        sql += " WHERE is_deleted = 0";
    }

    if (!query.exec(sql)) {
        qCritical() << "Failed to query schedules:" << query.lastError().text();
        return list;
    }

    while (query.next()) {
        Schedule sched;
        sched.id = query.value(0).toString();
        
        // 復号処理
        QString encTitle = query.value(1).toString();
        QString encDesc = query.value(2).toString();
        try {
            sched.title = CryptoUtil::decrypt(encTitle);
            sched.description = encDesc.isEmpty() ? "" : CryptoUtil::decrypt(encDesc);
        } catch (const std::exception& e) {
            qWarning() << "Decryption failed for schedule" << sched.id << ":" << e.what();
            sched.title = "[Decryption Failed]";
            sched.description = "";
        }

        sched.type = query.value(3).toString();
        sched.start_at = QDateTime::fromString(query.value(4).toString(), "yyyy-MM-dd HH:mm:ss");
        sched.end_at = QDateTime::fromString(query.value(5).toString(), "yyyy-MM-dd HH:mm:ss");
        sched.weight = query.value(8).toDouble();
        sched.time_slot_id = query.value(9).toString();
        sched.is_public = query.value(10).toInt() != 0;
        sched.is_deleted = query.value(11).toInt() != 0;
        sched.updated_at = QDateTime::fromString(query.value(12).toString(), "yyyy-MM-dd HH:mm:ss");
        sched.recurrence_group_id = query.value(13).toString();

        list.append(sched);
    }
    return list;
}

Schedule LocalStorage::getScheduleById(const QString& id) {
    Schedule sched;
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("SELECT id, title, description, type, start_at, end_at, status, priority, weight, time_slot_id, is_public, is_deleted, updated_at, recurrence_group_id FROM schedules WHERE id = :id");
    query.bindValue(":id", id);

    if (!query.exec() || !query.next()) {
        return sched;
    }

    sched.id = query.value(0).toString();
    QString encTitle = query.value(1).toString();
    QString encDesc = query.value(2).toString();
    try {
        sched.title = CryptoUtil::decrypt(encTitle);
        sched.description = encDesc.isEmpty() ? "" : CryptoUtil::decrypt(encDesc);
    } catch (const std::exception& e) {
        qWarning() << "Decryption failed for schedule" << sched.id << ":" << e.what();
        sched.title = "[Decryption Failed]";
        sched.description = "";
    }

    sched.type = query.value(3).toString();
    sched.start_at = QDateTime::fromString(query.value(4).toString(), "yyyy-MM-dd HH:mm:ss");
    sched.end_at = QDateTime::fromString(query.value(5).toString(), "yyyy-MM-dd HH:mm:ss");
    sched.weight = query.value(8).toDouble();
    sched.time_slot_id = query.value(9).toString();
    sched.is_public = query.value(10).toInt() != 0;
    sched.is_deleted = query.value(11).toInt() != 0;
    sched.updated_at = QDateTime::fromString(query.value(12).toString(), "yyyy-MM-dd HH:mm:ss");
    sched.recurrence_group_id = query.value(13).toString();

    return sched;
}

bool LocalStorage::saveSchedule(const Schedule& sched) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);

    // 暗号化処理
    QString encTitle;
    QString encDesc;
    try {
        encTitle = CryptoUtil::encrypt(sched.title);
        encDesc = sched.description.isEmpty() ? "" : CryptoUtil::encrypt(sched.description);
    } catch (const std::exception& e) {
        qCritical() << "Encryption failed for schedule" << sched.id << ":" << e.what();
        return false;
    }

    query.prepare(
        "INSERT INTO schedules (id, title, description, type, start_at, end_at, status, priority, weight, time_slot_id, is_public, is_deleted, updated_at, recurrence_group_id) "
        "VALUES (:id, :title, :description, :type, :start_at, :end_at, :status, :priority, :weight, :time_slot_id, :is_public, :is_deleted, :updated_at, :recurrence_group_id) "
        "ON CONFLICT(id) DO UPDATE SET "
        "  title = excluded.title, "
        "  description = excluded.description, "
        "  type = excluded.type, "
        "  start_at = excluded.start_at, "
        "  end_at = excluded.end_at, "
        "  status = excluded.status, "
        "  priority = excluded.priority, "
        "  weight = excluded.weight, "
        "  time_slot_id = excluded.time_slot_id, "
        "  is_public = excluded.is_public, "
        "  is_deleted = excluded.is_deleted, "
        "  updated_at = excluded.updated_at, "
        "  recurrence_group_id = excluded.recurrence_group_id"
    );

    query.bindValue(":id", sched.id);
    query.bindValue(":title", encTitle);
    query.bindValue(":description", encDesc.isEmpty() ? QVariant(QMetaType(QMetaType::QString)) : encDesc);
    query.bindValue(":type", sched.type);
    query.bindValue(":start_at", sched.start_at.isValid() ? sched.start_at.toString("yyyy-MM-dd HH:mm:ss") : QVariant(QMetaType(QMetaType::QString)));
    query.bindValue(":end_at", sched.end_at.isValid() ? sched.end_at.toString("yyyy-MM-dd HH:mm:ss") : QVariant(QMetaType(QMetaType::QString)));
    query.bindValue(":status", "todo");
    query.bindValue(":priority", 0);
    query.bindValue(":weight", sched.weight);
    query.bindValue(":time_slot_id", sched.time_slot_id.isEmpty() ? QVariant(QMetaType(QMetaType::QString)) : sched.time_slot_id);
    query.bindValue(":is_public", sched.is_public ? 1 : 0);
    query.bindValue(":is_deleted", sched.is_deleted ? 1 : 0);
    query.bindValue(":updated_at", sched.updated_at.isValid() ? sched.updated_at.toString("yyyy-MM-dd HH:mm:ss") : QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss"));
    query.bindValue(":recurrence_group_id", sched.recurrence_group_id.isEmpty() ? QVariant(QMetaType(QMetaType::QString)) : sched.recurrence_group_id);

    if (!query.exec()) {
        qCritical() << "Failed to save schedule:" << query.lastError().text();
        return false;
    }
    return true;
}

bool LocalStorage::deleteSchedule(const QString& id) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("UPDATE schedules SET is_deleted = 1, updated_at = :updated_at WHERE id = :id");
    query.bindValue(":id", id);
    query.bindValue(":updated_at", QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss"));

    if (!query.exec()) {
        qCritical() << "Failed to logical delete schedule:" << query.lastError().text();
        return false;
    }
    return true;
}

// --- 時間枠 (time_slots) 操作 ---

QList<TimeSlot> LocalStorage::getAllTimeSlots(bool includeDeleted) {
    QList<TimeSlot> list;
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);

    QString sql = "SELECT id, name, start_time, end_time, limit_hours, is_deleted, updated_at FROM time_slots";
    if (!includeDeleted) {
        sql += " WHERE is_deleted = 0";
    }

    if (!query.exec(sql)) {
        qCritical() << "Failed to query time_slots:" << query.lastError().text();
        return list;
    }

    while (query.next()) {
        TimeSlot slot;
        slot.id = query.value(0).toString();
        slot.name = query.value(1).toString();
        slot.start_time = QTime::fromString(query.value(2).toString(), "HH:mm:ss");
        slot.end_time = QTime::fromString(query.value(3).toString(), "HH:mm:ss");
        slot.limit_hours = query.value(4).toDouble();
        slot.is_deleted = query.value(5).toInt() != 0;
        slot.updated_at = QDateTime::fromString(query.value(6).toString(), "yyyy-MM-dd HH:mm:ss");
        list.append(slot);
    }
    return list;
}

TimeSlot LocalStorage::getTimeSlotById(const QString& id) {
    TimeSlot slot;
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("SELECT id, name, start_time, end_time, limit_hours, is_deleted, updated_at FROM time_slots WHERE id = :id");
    query.bindValue(":id", id);

    if (!query.exec() || !query.next()) {
        return slot;
    }

    slot.id = query.value(0).toString();
    slot.name = query.value(1).toString();
    slot.start_time = QTime::fromString(query.value(2).toString(), "HH:mm:ss");
    slot.end_time = QTime::fromString(query.value(3).toString(), "HH:mm:ss");
    slot.limit_hours = query.value(4).toDouble();
    slot.is_deleted = query.value(5).toInt() != 0;
    slot.updated_at = QDateTime::fromString(query.value(6).toString(), "yyyy-MM-dd HH:mm:ss");
    return slot;
}

bool LocalStorage::saveTimeSlot(const TimeSlot& slot) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare(
        "INSERT INTO time_slots (id, name, start_time, end_time, limit_hours, is_deleted, updated_at) "
        "VALUES (:id, :name, :start_time, :end_time, :limit_hours, :is_deleted, :updated_at) "
        "ON CONFLICT(id) DO UPDATE SET "
        "  name = excluded.name, "
        "  start_time = excluded.start_time, "
        "  end_time = excluded.end_time, "
        "  limit_hours = excluded.limit_hours, "
        "  is_deleted = excluded.is_deleted, "
        "  updated_at = excluded.updated_at"
    );

    query.bindValue(":id", slot.id);
    query.bindValue(":name", slot.name);
    query.bindValue(":start_time", slot.start_time.toString("HH:mm:ss"));
    query.bindValue(":end_time", slot.end_time.toString("HH:mm:ss"));
    query.bindValue(":limit_hours", slot.limit_hours);
    query.bindValue(":is_deleted", slot.is_deleted ? 1 : 0);
    query.bindValue(":updated_at", slot.updated_at.isValid() ? slot.updated_at.toString("yyyy-MM-dd HH:mm:ss") : QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss"));

    if (!query.exec()) {
        qCritical() << "Failed to save time_slot:" << query.lastError().text();
        return false;
    }
    return true;
}

bool LocalStorage::deleteTimeSlot(const QString& id) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("UPDATE time_slots SET is_deleted = 1, updated_at = :updated_at WHERE id = :id");
    query.bindValue(":id", id);
    query.bindValue(":updated_at", QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss"));

    if (!query.exec()) {
        qCritical() << "Failed to logical delete time_slot:" << query.lastError().text();
        return false;
    }
    return true;
}

// --- Webhook (webhooks) 操作 ---

QList<Webhook> LocalStorage::getAllWebhooks(bool includeDeleted) {
    QList<Webhook> list;
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);

    QString sql = "SELECT id, name, url, is_deleted, updated_at FROM webhooks";
    if (!includeDeleted) {
        sql += " WHERE is_deleted = 0";
    }

    if (!query.exec(sql)) {
        qCritical() << "Failed to query webhooks:" << query.lastError().text();
        return list;
    }

    while (query.next()) {
        Webhook wh;
        wh.id = query.value(0).toString();
        wh.name = query.value(1).toString();
        
        // 復号
        QString encUrl = query.value(2).toString();
        try {
            wh.url = CryptoUtil::decrypt(encUrl);
        } catch (const std::exception& e) {
            qWarning() << "Decryption failed for webhook" << wh.id << ":" << e.what();
            wh.url = "[Decryption Failed]";
        }

        wh.is_active = true; // SQLite schema には is_active はないので一律 true または deleted で判断
        wh.is_deleted = query.value(3).toInt() != 0;
        wh.updated_at = QDateTime::fromString(query.value(4).toString(), "yyyy-MM-dd HH:mm:ss");
        list.append(wh);
    }
    return list;
}

Webhook LocalStorage::getWebhookById(const QString& id) {
    Webhook wh;
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("SELECT id, name, url, is_deleted, updated_at FROM webhooks WHERE id = :id");
    query.bindValue(":id", id);

    if (!query.exec() || !query.next()) {
        return wh;
    }

    wh.id = query.value(0).toString();
    wh.name = query.value(1).toString();
    QString encUrl = query.value(2).toString();
    try {
        wh.url = CryptoUtil::decrypt(encUrl);
    } catch (const std::exception& e) {
        qWarning() << "Decryption failed for webhook" << wh.id << ":" << e.what();
        wh.url = "[Decryption Failed]";
    }
    wh.is_active = true;
    wh.is_deleted = query.value(3).toInt() != 0;
    wh.updated_at = QDateTime::fromString(query.value(4).toString(), "yyyy-MM-dd HH:mm:ss");
    return wh;
}

bool LocalStorage::saveWebhook(const Webhook& webhook) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);

    // 暗号化
    QString encUrl;
    try {
        encUrl = CryptoUtil::encrypt(webhook.url);
    } catch (const std::exception& e) {
        qCritical() << "Encryption failed for webhook" << webhook.id << ":" << e.what();
        return false;
    }

    query.prepare(
        "INSERT INTO webhooks (id, name, url, is_deleted, updated_at) "
        "VALUES (:id, :name, :url, :is_deleted, :updated_at) "
        "ON CONFLICT(id) DO UPDATE SET "
        "  name = excluded.name, "
        "  url = excluded.url, "
        "  is_deleted = excluded.is_deleted, "
        "  updated_at = excluded.updated_at"
    );

    query.bindValue(":id", webhook.id);
    query.bindValue(":name", webhook.name);
    query.bindValue(":url", encUrl);
    query.bindValue(":is_deleted", webhook.is_deleted ? 1 : 0);
    query.bindValue(":updated_at", webhook.updated_at.isValid() ? webhook.updated_at.toString("yyyy-MM-dd HH:mm:ss") : QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss"));

    if (!query.exec()) {
        qCritical() << "Failed to save webhook:" << query.lastError().text();
        return false;
    }
    return true;
}

bool LocalStorage::deleteWebhook(const QString& id) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("UPDATE webhooks SET is_deleted = 1, updated_at = :updated_at WHERE id = :id");
    query.bindValue(":id", id);
    query.bindValue(":updated_at", QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss"));

    if (!query.exec()) {
        qCritical() << "Failed to logical delete webhook:" << query.lastError().text();
        return false;
    }
    return true;
}

// --- 同期メタデータ ---

QDateTime LocalStorage::getLastSyncedAt() {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    if (query.exec("SELECT value FROM sync_metadata WHERE key = 'last_synced_at'") && query.next()) {
        return QDateTime::fromString(query.value(0).toString(), "yyyy-MM-dd HH:mm:ss");
    }
    return QDateTime::fromSecsSinceEpoch(0);
}

bool LocalStorage::setLastSyncedAt(const QDateTime& dt) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("INSERT OR REPLACE INTO sync_metadata (key, value) VALUES ('last_synced_at', :val)");
    query.bindValue(":val", dt.toString("yyyy-MM-dd HH:mm:ss"));
    if (!query.exec()) {
        qCritical() << "Failed to update last_synced_at:" << query.lastError().text();
        return false;
    }
    return true;
}

QString LocalStorage::getMetadata(const QString& key, const QString& defaultValue) {
    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("SELECT value FROM sync_metadata WHERE key = :key");
    query.bindValue(":key", key);
    if (query.exec() && query.next()) {
        return query.value(0).toString();
    }
    return defaultValue;
}

bool LocalStorage::setMetadata(const QString& key, const QString& value) {
    if (key.contains(QChar('\0')) || value.contains(QChar('\0'))) {
        qWarning() << "Metadata key or value contains null bytes.";
        return false;
    }

    QSqlDatabase db = DBManager::instance().database();
    QSqlQuery query(db);
    query.prepare("INSERT OR REPLACE INTO sync_metadata (key, value) VALUES (:key, :val)");
    query.bindValue(":key", key);
    query.bindValue(":val", value);
    if (!query.exec()) {
        qCritical() << "Failed to set metadata for" << key << ":" << query.lastError().text();
        return false;
    }
    return true;
}
