#include <QtTest>
#include "database/db_manager.h"
#include "database/local_storage.h"
#include "scheduler/scheduler.h"
#include "common/crypto_util.h"
#include "network/sync_client.h"
#include "sync/sync_engine.h"
#include <QFile>
#include <QProcess>
#include <stdexcept>

// イベントを非同期で受信するためのテスト用レシーバ
class TestReceiver : public QObject {
    Q_OBJECT
public:
    int lastPercentage = 0;
    bool finishedCalled = false;
    bool finishedSuccess = false;
    QString finishedMessage = "";
    bool conflictCalled = false;
    Schedule localConflictSched;
    Schedule remoteConflictSched;
    SyncEngine* engine = nullptr;

    bool event(QEvent* e) override {
        if (e->type() == static_cast<QEvent::Type>(SyncProgressType)) {
            auto* pe = static_cast<SyncProgressEvent*>(e);
            lastPercentage = pe->percentage();
            return true;
        } else if (e->type() == static_cast<QEvent::Type>(SyncFinishedType)) {
            auto* fe = static_cast<SyncFinishedEvent*>(e);
            finishedCalled = true;
            finishedSuccess = fe->success();
            finishedMessage = fe->message();
            return true;
        } else if (e->type() == static_cast<QEvent::Type>(SyncConflictType)) {
            auto* ce = static_cast<SyncConflictEvent*>(e);
            conflictCalled = true;
            localConflictSched = ce->localSchedule();
            remoteConflictSched = ce->remoteSchedule();
            if (engine) {
                // テスト用にローカル優先で競合解決をシミュレート
                engine->resolveConflict(localConflictSched);
            }
            return true;
        }
        return QObject::event(e);
    }
};

class TaskFlowTest : public QObject {
    Q_OBJECT
private slots:
    void initTestCase() {
        QFile::remove("taskflow_test_storage.db");
        QVERIFY(DBManager::instance().initialize("taskflow_test_storage.db"));
        CryptoUtil::init("test_secret_key_12345");
    }

    void cleanupTestCase() {
        DBManager::instance().close();
        QFile::remove("taskflow_test_storage.db");
    }

    void testStorageAndCrypto() {
        LocalStorage storage;
        Schedule s;
        s.id = "test-uuid-9999";
        s.title = "Test QTest Schedule";
        s.description = "Test Description";
        s.type = "simple";
        s.weight = 1.5;
        s.is_public = true;
        s.is_deleted = false;
        s.updated_at = QDateTime::currentDateTime();

        QVERIFY(storage.saveSchedule(s));

        Schedule loaded = storage.getScheduleById(s.id);
        QCOMPARE(loaded.title, s.title);
        QCOMPARE(loaded.description, s.description);
        QCOMPARE(loaded.weight, s.weight);

        QVERIFY(storage.deleteSchedule(s.id));
        Schedule deleted = storage.getScheduleById(s.id);
        QVERIFY(deleted.is_deleted);
    }

    void testSchedulerWarning() {
        LocalStorage storage;
        
        // テスト用の時間枠を設定
        TimeSlot t;
        t.id = "slot-uuid-9999";
        t.name = "Test Slot";
        t.start_time = QTime(9, 0);
        t.end_time = QTime(12, 0);
        t.limit_hours = 2.0;
        t.is_deleted = false;
        t.updated_at = QDateTime::currentDateTime();
        QVERIFY(storage.saveTimeSlot(t));

        // 制限内のタスクを保存
        Schedule s1;
        s1.id = "task-uuid-1";
        s1.title = "Task 1";
        s1.type = "task";
        s1.weight = 1.5;
        s1.time_slot_id = t.id;
        s1.start_at = QDateTime(QDate(2026, 6, 3), QTime(9, 0));
        s1.end_at = QDateTime(QDate(2026, 6, 3), QTime(10, 30));
        QVERIFY(storage.saveSchedule(s1));

        Scheduler scheduler;
        double currentLoad = 0.0;
        double limitHours = 0.0;
        
        // 1.5時間 < 2.0時間 なので警告は出ないはず
        bool overflow = scheduler.checkLoadOverflow(QDate(2026, 6, 3), t.id, currentLoad, limitHours);
        QVERIFY(!overflow);
        QCOMPARE(currentLoad, 1.5);
        QCOMPARE(limitHours, 2.0);

        // さらに負荷を追加して制限を超えるようにする
        Schedule s2;
        s2.id = "task-uuid-2";
        s2.title = "Task 2";
        s2.type = "task";
        s2.weight = 1.0;
        s2.time_slot_id = t.id;
        s2.start_at = QDateTime(QDate(2026, 6, 3), QTime(10, 30));
        s2.end_at = QDateTime(QDate(2026, 6, 3), QTime(11, 30));
        QVERIFY(storage.saveSchedule(s2));

        // 2.5時間 > 2.0時間 なので警告が出るはず
        overflow = scheduler.checkLoadOverflow(QDate(2026, 6, 3), t.id, currentLoad, limitHours);
        QVERIFY(overflow);
        QCOMPARE(currentLoad, 2.5);
    }

    void testCryptoNullPoisoning() {
        bool thrown = false;
        try {
            // キーにヌルバイト混入
            CryptoUtil::init(QString("key%1secret").arg(QChar('\0')));
        } catch (const std::invalid_argument&) {
            thrown = true;
        }
        QVERIFY(thrown);

        // 正常キーで再初期化
        CryptoUtil::init("test_secret_key_12345");

        thrown = false;
        try {
            // 平文にヌルバイト混入
            CryptoUtil::encrypt(QString("hello%1world").arg(QChar('\0')));
        } catch (const std::invalid_argument&) {
            thrown = true;
        }
        QVERIFY(thrown);

        thrown = false;
        try {
            // 暗号文にヌルバイト混入
            CryptoUtil::decrypt(QString("base64%1poisoned").arg(QChar('\0')));
        } catch (const std::invalid_argument&) {
            thrown = true;
        }
        QVERIFY(thrown);
    }

    void testMetadataNullPoisoning() {
        LocalStorage storage;
        // キーにヌルバイト混入
        QVERIFY(!storage.setMetadata(QString("api%1token").arg(QChar('\0')), "value"));
        // 値にヌルバイト混入
        QVERIFY(!storage.setMetadata("api_token", QString("token%1val").arg(QChar('\0'))));
    }

    void testSyncClientConnection() {
        LocalStorage storage;
        // 正しい設定を保存
        QVERIFY(storage.setMetadata("api_url", "http://localhost:8000/sync.php"));
        QVERIFY(storage.setMetadata("api_token", "tc_f2d6ccf601dd459b515215fd"));

        SyncClient client;
        QDateTime serverTime;
        QList<Schedule> schedules;
        QList<TimeSlot> timeSlots;
        QList<Webhook> webhooks;
        QString errorMsg;

        // 1. 正常な PULL 疎通確認
        bool ok = client.pull(QDateTime::fromSecsSinceEpoch(0), serverTime, schedules, timeSlots, webhooks, errorMsg);
        QVERIFY2(ok, errorMsg.toUtf8().constData());
        QVERIFY(serverTime.isValid());

        // 2. 不正なトークンでの拒否確認
        QVERIFY(storage.setMetadata("api_token", "wrong_token_value"));
        ok = client.pull(QDateTime::fromSecsSinceEpoch(0), serverTime, schedules, timeSlots, webhooks, errorMsg);
        QVERIFY(!ok);
        QVERIFY(errorMsg.contains("Unauthorized") || errorMsg.contains("API token") || errorMsg.contains("authentication"));

        // 3. NULL汚染トークンでの検知確認 (書き込みが拒否されること)
        QVERIFY(!storage.setMetadata("api_token", QString("wrong%1token").arg(QChar('\0'))));

        // 後始末として元の正しいトークンに戻す
        QVERIFY(storage.setMetadata("api_token", "tc_f2d6ccf601dd459b515215fd"));
    }

    void testSyncEngineConflictEvent() {
        // 1. 外部 PHP スクリプト経由で MySQL 側にリモート用の競合データを投入する
        QProcess proc;
        proc.setWorkingDirectory("..");
        proc.start("php", { "tests/setup_conflict.php" });
        QVERIFY(proc.waitForFinished(5000));
        QCOMPARE(proc.exitCode(), 0);

        // 2. ローカル SQLite にも同一IDで異なる内容（タイトル）を保存
        LocalStorage storage;
        Schedule s;
        s.id = "conflict-task-1";
        s.title = "Local Title";
        s.description = "Local Description";
        s.type = "simple";
        s.weight = 1.0;
        s.is_public = false;
        s.is_deleted = false;
        s.updated_at = QDateTime::fromString("2026-06-03 09:20:00", "yyyy-MM-dd HH:mm:ss");

        QVERIFY(storage.saveSchedule(s));

        // 同期メタデータを調整して競合判定対象にする
        storage.setLastSyncedAt(QDateTime::fromString("2026-06-03 09:00:00", "yyyy-MM-dd HH:mm:ss"));
        QVERIFY(storage.setMetadata("api_url", "http://localhost:8000/sync.php"));
        QVERIFY(storage.setMetadata("api_token", "tc_f2d6ccf601dd459b515215fd"));

        // 3. SyncEngine を起動
        TestReceiver receiver;
        SyncEngine engine(&receiver);
        receiver.engine = &engine;

        engine.startSync();

        // 非同期同期処理の完了を待機する
        int timeout = 100; // 最大10秒
        while (!receiver.finishedCalled && timeout > 0) {
            QTest::qWait(100);
            timeout--;
        }

        QVERIFY(receiver.finishedCalled);
        QVERIFY2(receiver.finishedSuccess, receiver.finishedMessage.toUtf8().constData());
        QVERIFY(receiver.conflictCalled);
        QCOMPARE(receiver.localConflictSched.title, QString("Local Title"));
        QCOMPARE(receiver.remoteConflictSched.title, QString("Remote Title"));

        // 4. 解決結果がローカル SQLite に反映されたか確認
        Schedule resolved = storage.getScheduleById("conflict-task-1");
        QCOMPARE(resolved.title, QString("Local Title")); // receiver.event 内で localConflictSched を投入したため
    }
};

QTEST_MAIN(TaskFlowTest)
#include "test_storage.moc"
