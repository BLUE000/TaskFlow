#include "sync_client.h"
#include "network/network_manager.h"
#include "database/local_storage.h"
#include "common/crypto_util.h"
#include <QJsonDocument>
#include <QJsonObject>
#include <QJsonArray>
#include <QDebug>

bool SyncClient::pull(const QDateTime& lastSyncedAt, QDateTime& serverTime,
                      QList<Schedule>& schedules, QList<TimeSlot>& timeSlots, QList<Webhook>& webhooks,
                      QString& errorMsg) {
    LocalStorage storage;
    QString apiUrl = storage.getMetadata("api_url", "").trimmed();
    QString apiToken = storage.getMetadata("api_token", "").trimmed();

    if (apiUrl.isEmpty() || apiToken.isEmpty()) {
        errorMsg = "API URL or Token is not configured. Please set them in Settings.";
        return false;
    }

    if (apiUrl.contains(QChar('\0')) || apiToken.contains(QChar('\0'))) {
        errorMsg = "API URL or Token contains invalid characters (null bytes).";
        return false;
    }

    QJsonObject requestPayload;
    requestPayload["token"] = apiToken;
    requestPayload["action"] = "pull";
    requestPayload["last_synced_at"] = lastSyncedAt.toString("yyyy-MM-dd HH:mm:ss");

    bool success = false;
    QJsonObject response = NetworkManager::instance().post(apiUrl, requestPayload, success, errorMsg);

    if (!success) {
        return false;
    }

    if (response["status"].toString() != "success") {
        errorMsg = response["error"].toString("Unknown API Error");
        return false;
    }

    serverTime = QDateTime::fromString(response["server_time"].toString(), "yyyy-MM-dd HH:mm:ss");
    QJsonObject dataObj = response["data"].toObject();

    // 1. schedules のパース
    QJsonArray schedArray = dataObj["schedules"].toArray();
    for (const QJsonValue& val : schedArray) {
        QJsonObject obj = val.toObject();
        Schedule sched;
        sched.id = obj["id"].toString();
        // pull で返ってくる title/description は暗号化(Base64)されたまま
        sched.title = obj["title"].toString();
        sched.description = obj["description"].toString();
        sched.type = obj["type"].toString();
        sched.start_at = QDateTime::fromString(obj["start_at"].toString(), "yyyy-MM-dd HH:mm:ss");
        sched.end_at = QDateTime::fromString(obj["end_at"].toString(), "yyyy-MM-dd HH:mm:ss");
        sched.weight = obj["weight"].toDouble();
        sched.time_slot_id = obj["time_slot_id"].toString();
        sched.is_public = obj["is_public"].toInt() != 0;
        sched.is_deleted = obj["is_deleted"].toInt() != 0;
        sched.updated_at = QDateTime::fromString(obj["updated_at"].toString(), "yyyy-MM-dd HH:mm:ss");
        sched.recurrence_group_id = obj["recurrence_group_id"].toString();
        schedules.append(sched);
    }

    // 2. time_slots のパース
    QJsonArray slotArray = dataObj["time_slots"].toArray();
    for (const QJsonValue& val : slotArray) {
        QJsonObject obj = val.toObject();
        TimeSlot slot;
        slot.id = obj["id"].toString();
        slot.name = obj["name"].toString();
        slot.start_time = QTime::fromString(obj["start_time"].toString(), "HH:mm:ss");
        slot.end_time = QTime::fromString(obj["end_time"].toString(), "HH:mm:ss");
        slot.limit_hours = obj["limit_hours"].toDouble();
        slot.is_deleted = obj["is_deleted"].toInt() != 0;
        slot.updated_at = QDateTime::fromString(obj["updated_at"].toString(), "yyyy-MM-dd HH:mm:ss");
        timeSlots.append(slot);
    }

    // 3. webhooks のパース
    QJsonArray whArray = dataObj["webhooks"].toArray();
    for (const QJsonValue& val : whArray) {
        QJsonObject obj = val.toObject();
        Webhook wh;
        wh.id = obj["id"].toString();
        wh.name = obj["name"].toString();
        // url も暗号化(Base64)されたまま
        wh.url = obj["url"].toString();
        wh.is_active = true;
        wh.is_deleted = obj["is_deleted"].toInt() != 0;
        wh.updated_at = QDateTime::fromString(obj["updated_at"].toString(), "yyyy-MM-dd HH:mm:ss");
        webhooks.append(wh);
    }

    return true;
}

bool SyncClient::push(const QList<Schedule>& schedules, const QList<TimeSlot>& timeSlots, const QList<Webhook>& webhooks,
                      QString& errorMsg) {
    LocalStorage storage;
    QString apiUrl = storage.getMetadata("api_url", "").trimmed();
    QString apiToken = storage.getMetadata("api_token", "").trimmed();

    if (apiUrl.isEmpty() || apiToken.isEmpty()) {
        errorMsg = "API URL or Token is not configured. Please set them in Settings.";
        return false;
    }

    if (apiUrl.contains(QChar('\0')) || apiToken.contains(QChar('\0'))) {
        errorMsg = "API URL or Token contains invalid characters (null bytes).";
        return false;
    }

    QJsonObject requestPayload;
    requestPayload["token"] = apiToken;
    requestPayload["action"] = "push";

    QJsonObject dataObj;

    // 1. schedules のJSON化 (暗号化を適用する)
    QJsonArray schedArray;
    for (const Schedule& s : schedules) {
        QJsonObject obj;
        obj["id"] = s.id;
        
        // 暗号化してBase64にする
        QString encTitle;
        QString encDesc;
        try {
            encTitle = CryptoUtil::encrypt(s.title);
            encDesc = s.description.isEmpty() ? "" : CryptoUtil::encrypt(s.description);
        } catch (const std::exception& e) {
            qCritical() << "Encryption failed during push for schedule" << s.id << ":" << e.what();
            errorMsg = QString("Encryption failed: %1").arg(e.what());
            return false;
        }

        obj["title"] = encTitle;
        obj["description"] = encDesc;
        obj["type"] = s.type;
        obj["start_at"] = s.start_at.isValid() ? s.start_at.toString("yyyy-MM-dd HH:mm:ss") : "";
        obj["end_at"] = s.end_at.isValid() ? s.end_at.toString("yyyy-MM-dd HH:mm:ss") : "";
        obj["weight"] = s.weight;
        obj["time_slot_id"] = s.time_slot_id;
        obj["is_public"] = s.is_public ? 1 : 0;
        obj["is_deleted"] = s.is_deleted ? 1 : 0;
        obj["updated_at"] = s.updated_at.isValid() ? s.updated_at.toString("yyyy-MM-dd HH:mm:ss") : QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss");
        obj["recurrence_group_id"] = s.recurrence_group_id;
        schedArray.append(obj);
    }
    dataObj["schedules"] = schedArray;

    // 2. time_slots のJSON化
    QJsonArray slotArray;
    for (const TimeSlot& t : timeSlots) {
        QJsonObject obj;
        obj["id"] = t.id;
        obj["name"] = t.name;
        obj["start_time"] = t.start_time.toString("HH:mm:ss");
        obj["end_time"] = t.end_time.toString("HH:mm:ss");
        obj["limit_hours"] = t.limit_hours;
        obj["is_deleted"] = t.is_deleted ? 1 : 0;
        obj["updated_at"] = t.updated_at.isValid() ? t.updated_at.toString("yyyy-MM-dd HH:mm:ss") : QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss");
        slotArray.append(obj);
    }
    dataObj["time_slots"] = slotArray;

    // 3. webhooks のJSON化 (暗号化を適用する)
    QJsonArray whArray;
    for (const Webhook& w : webhooks) {
        QJsonObject obj;
        obj["id"] = w.id;
        obj["name"] = w.name;

        // 暗号化してBase64にする
        QString encUrl;
        try {
            encUrl = CryptoUtil::encrypt(w.url);
        } catch (const std::exception& e) {
            qCritical() << "Encryption failed during push for webhook" << w.id << ":" << e.what();
            errorMsg = QString("Encryption failed: %1").arg(e.what());
            return false;
        }

        obj["url"] = encUrl;
        obj["is_deleted"] = w.is_deleted ? 1 : 0;
        obj["updated_at"] = w.updated_at.isValid() ? w.updated_at.toString("yyyy-MM-dd HH:mm:ss") : QDateTime::currentDateTime().toString("yyyy-MM-dd HH:mm:ss");
        whArray.append(obj);
    }
    dataObj["webhooks"] = whArray;

    requestPayload["data"] = dataObj;

    bool success = false;
    QJsonObject response = NetworkManager::instance().post(apiUrl, requestPayload, success, errorMsg);

    if (!success) {
        return false;
    }

    if (response["status"].toString() != "success") {
        errorMsg = response["error"].toString("Unknown API Error");
        return false;
    }

    return true;
}
