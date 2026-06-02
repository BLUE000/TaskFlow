#include "sync_engine.h"
#include "network/sync_client.h"
#include "database/local_storage.h"
#include "common/crypto_util.h"
#include <QThread>
#include <QCoreApplication>
#include <QDebug>

SyncEngine::SyncEngine(QObject *receiver)
    : m_receiver(receiver), m_hasResolution(false), m_isSyncing(false) {}

void SyncEngine::setReceiver(QObject *receiver) {
    m_receiver = receiver;
}

void SyncEngine::startSync() {
    if (m_isSyncing) {
        qWarning() << "Sync is already running.";
        return;
    }
    m_isSyncing = true;

    // バックグラウンドスレッドで同期処理を起動
    QThread* thread = QThread::create([this]() {
        this->runSync();
    });
    
    // スレッドが完了したら自動的に削除されるように設定
    QObject::connect(thread, &QThread::finished, thread, &QThread::deleteLater);
    thread->start();
}

void SyncEngine::resolveConflict(const Schedule& resolvedSched) {
    QMutexLocker locker(&m_mutex);
    m_resolvedSchedule = resolvedSched;
    m_hasResolution = true;
    m_waitCondition.wakeOne();
}

void SyncEngine::runSync() {
    auto notifyProgress = [this](int percentage) {
        if (m_receiver) {
            QCoreApplication::postEvent(m_receiver, new SyncProgressEvent(percentage));
        }
    };

    auto notifyFinished = [this](bool success, const QString& msg) {
        m_isSyncing = false;
        if (m_receiver) {
            QCoreApplication::postEvent(m_receiver, new SyncFinishedEvent(success, msg));
        }
    };

    notifyProgress(10);

    LocalStorage storage;
    QDateTime lastSyncedAt = storage.getLastSyncedAt();

    SyncClient client;
    QDateTime serverTime;
    QList<Schedule> R_schedules;
    QList<TimeSlot> R_timeSlots;
    QList<Webhook> R_webhooks;
    QString errorMsg;

    // 1. PULL (リモート更新データの取得)
    bool ok = client.pull(lastSyncedAt, serverTime, R_schedules, R_timeSlots, R_webhooks, errorMsg);
    if (!ok) {
        notifyFinished(false, QString("PULL Failed: %1").arg(errorMsg));
        return;
    }

    notifyProgress(30);

    // 2. ローカル更新リストの抽出 (最終同期時刻より更新日時が新しいレコード)
    // 削除済みレコードも含めて同期するため、includeDeleted=true で取得
    QList<Schedule> allLocalSchedules = storage.getAllSchedules(true);
    QList<TimeSlot> allLocalTimeSlots = storage.getAllTimeSlots(true);
    QList<Webhook> allLocalWebhooks = storage.getAllWebhooks(true);

    QList<Schedule> L_schedules;
    for (const Schedule& s : allLocalSchedules) {
        if (s.updated_at > lastSyncedAt) {
            L_schedules.append(s);
        }
    }

    QList<TimeSlot> L_timeSlots;
    for (const TimeSlot& t : allLocalTimeSlots) {
        if (t.updated_at > lastSyncedAt) {
            L_timeSlots.append(t);
        }
    }

    QList<Webhook> L_webhooks;
    for (const Webhook& w : allLocalWebhooks) {
        if (w.updated_at > lastSyncedAt) {
            L_webhooks.append(w);
        }
    }

    notifyProgress(50);

    // 送信・適用用のリスト
    QList<Schedule> toPushSchedules;
    QList<Schedule> toSaveLocalSchedules;

    // 3. schedules テーブルの同期マージ・衝突解決
    for (const Schedule& r_enc : R_schedules) {
        // リモートの暗号化されたタイトル/詳細を復号
        Schedule r = r_enc;
        try {
            r.title = CryptoUtil::decrypt(r_enc.title);
            r.description = r_enc.description.isEmpty() ? "" : CryptoUtil::decrypt(r_enc.description);
        } catch (const std::exception& e) {
            qWarning() << "Failed to decrypt remote schedule" << r_enc.id << ":" << e.what();
            r.title = "[Decryption Failed]";
            r.description = "";
        }

        // ローカル更新リストに同じIDがあるか検索
        int localIdx = -1;
        for (int i = 0; i < L_schedules.size(); ++i) {
            if (L_schedules[i].id == r.id) {
                localIdx = i;
                break;
            }
        }

        if (localIdx != -1) {
            // ローカルもリモートも更新されている ➔ 衝突判定
            Schedule l = L_schedules[localIdx];
            
            // 内容自体に差分があるか比較
            bool hasDiff = (l.title != r.title ||
                            l.description != r.description ||
                            l.start_at != r.start_at ||
                            l.end_at != r.end_at ||
                            l.type != r.type ||
                            qAbs(l.weight - r.weight) > 0.001 ||
                            l.time_slot_id != r.time_slot_id ||
                            l.is_public != r.is_public ||
                            l.is_deleted != r.is_deleted);

            if (hasDiff) {
                // 衝突発生！UIスレッドの介入を待つ
                m_mutex.lock();
                m_hasResolution = false;
                m_mutex.unlock();

                if (m_receiver) {
                    QCoreApplication::postEvent(m_receiver, new SyncConflictEvent(l, r));
                } else {
                    // 通知先がない場合はリモート優先で自動解決
                    qWarning() << "No receiver set. Auto resolved with remote priority.";
                    toSaveLocalSchedules.append(r);
                    m_mutex.lock();
                    m_hasResolution = true;
                    m_mutex.unlock();
                }

                // 解決されるまで待機
                m_mutex.lock();
                while (!m_hasResolution) {
                    m_waitCondition.wait(&m_mutex);
                }
                // 解決済みデータが m_resolvedSchedule に設定されている
                Schedule resolved = m_resolvedSchedule;
                m_mutex.unlock();

                // 解決されたデータを MySQL へのプッシュおよび SQLite への保存リストに追加
                resolved.updated_at = QDateTime::currentDateTime();
                toPushSchedules.append(resolved);
                toSaveLocalSchedules.append(resolved);
            } else {
                // 内容が同じであれば、updated_at が新しい方を適用
                if (r.updated_at > l.updated_at) {
                    toSaveLocalSchedules.append(r);
                } else if (l.updated_at > r.updated_at) {
                    toPushSchedules.append(l);
                }
            }
            // 処理済みとしてローカル更新リストから除外
            L_schedules.removeAt(localIdx);
        } else {
            // ローカルは更新されていない ➔ リモートを適用
            toSaveLocalSchedules.append(r);
        }
    }

    // ローカル更新リストに残っているものは、ローカルのみで新規作成/更新されたデータ ➔ プッシュ
    for (const Schedule& l : L_schedules) {
        toPushSchedules.append(l);
    }

    notifyProgress(70);

    // 4. time_slots の自動マージ (Last Write Wins)
    QList<TimeSlot> toPushTimeSlots;
    QList<TimeSlot> toSaveLocalTimeSlots;

    for (const TimeSlot& r : R_timeSlots) {
        int localIdx = -1;
        for (int i = 0; i < L_timeSlots.size(); ++i) {
            if (L_timeSlots[i].id == r.id) {
                localIdx = i;
                break;
            }
        }

        if (localIdx != -1) {
            TimeSlot l = L_timeSlots[localIdx];
            if (r.updated_at > l.updated_at) {
                toSaveLocalTimeSlots.append(r);
            } else {
                toPushTimeSlots.append(l);
            }
            L_timeSlots.removeAt(localIdx);
        } else {
            toSaveLocalTimeSlots.append(r);
        }
    }
    for (const TimeSlot& l : L_timeSlots) {
        toPushTimeSlots.append(l);
    }

    // 5. webhooks の自動マージ (Last Write Wins)
    QList<Webhook> toPushWebhooks;
    QList<Webhook> toSaveLocalWebhooks;

    for (const Webhook& r_enc : R_webhooks) {
        Webhook r = r_enc;
        try {
            r.url = CryptoUtil::decrypt(r_enc.url);
        } catch (const std::exception& e) {
            qWarning() << "Failed to decrypt remote webhook url" << r_enc.id << ":" << e.what();
            r.url = "[Decryption Failed]";
        }

        int localIdx = -1;
        for (int i = 0; i < L_webhooks.size(); ++i) {
            if (L_webhooks[i].id == r.id) {
                localIdx = i;
                break;
            }
        }

        if (localIdx != -1) {
            Webhook l = L_webhooks[localIdx];
            if (r.updated_at > l.updated_at) {
                toSaveLocalWebhooks.append(r);
            } else {
                toPushWebhooks.append(l);
            }
            L_webhooks.removeAt(localIdx);
        } else {
            toSaveLocalWebhooks.append(r);
        }
    }
    for (const Webhook& l : L_webhooks) {
        toPushWebhooks.append(l);
    }

    notifyProgress(80);

    // 6. DBの書き込み・適用 (SQLiteへの保存)
    for (const Schedule& s : toSaveLocalSchedules) {
        storage.saveSchedule(s);
    }
    for (const TimeSlot& t : toSaveLocalTimeSlots) {
        storage.saveTimeSlot(t);
    }
    for (const Webhook& w : toSaveLocalWebhooks) {
        storage.saveWebhook(w);
    }

    // 7. PUSH (MySQL側への反映)
    if (!toPushSchedules.isEmpty() || !toPushTimeSlots.isEmpty() || !toPushWebhooks.isEmpty()) {
        ok = client.push(toPushSchedules, toPushTimeSlots, toPushWebhooks, errorMsg);
        if (!ok) {
            notifyFinished(false, QString("PUSH Failed: %1").arg(errorMsg));
            return;
        }
    }

    notifyProgress(95);

    // 8. 最終同期日時の更新
    storage.setLastSyncedAt(serverTime);

    notifyProgress(100);
    notifyFinished(true, "Synchronization completed successfully.");
}
