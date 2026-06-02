#include "db_manager.h"
#include <QSqlError>
#include <QSqlQuery>
#include <QDebug>
#include <QFile>
#include <QDir>

DBManager& DBManager::instance() {
    static DBManager instance;
    return instance;
}

bool DBManager::initialize(const QString& dbPath) {
    if (m_db.isOpen()) {
        return true;
    }

    m_db = QSqlDatabase::addDatabase("QSQLITE");
    m_db.setDatabaseName(dbPath);

    if (!m_db.open()) {
        qCritical() << "Failed to open SQLite database:" << m_db.lastError().text();
        return false;
    }

    // PRAGMA foreign_keys = ON; の実行
    QSqlQuery pragmaQuery(m_db);
    pragmaQuery.exec("PRAGMA foreign_keys = ON;");

    // テーブルがなければ自動作成する
    QSqlQuery query(m_db);

    // 1. webhooks テーブル
    bool ok = query.exec(
        "CREATE TABLE IF NOT EXISTS webhooks ("
        "  id TEXT NOT NULL PRIMARY KEY,"
        "  name TEXT NOT NULL,"
        "  url TEXT NOT NULL,"
        "  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),"
        "  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),"
        "  is_deleted INTEGER NOT NULL DEFAULT 0"
        ");"
    );
    if (!ok) {
        qCritical() << "Failed to create webhooks table:" << query.lastError().text();
        return false;
    }

    // 2. time_slots テーブル
    ok = query.exec(
        "CREATE TABLE IF NOT EXISTS time_slots ("
        "  id TEXT NOT NULL PRIMARY KEY,"
        "  name TEXT NOT NULL,"
        "  start_time TEXT NOT NULL,"
        "  end_time TEXT NOT NULL,"
        "  limit_hours REAL NOT NULL DEFAULT 0.0,"
        "  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),"
        "  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),"
        "  is_deleted INTEGER NOT NULL DEFAULT 0"
        ");"
    );
    if (!ok) {
        qCritical() << "Failed to create time_slots table:" << query.lastError().text();
        return false;
    }

    // 3. schedules テーブル
    ok = query.exec(
        "CREATE TABLE IF NOT EXISTS schedules ("
        "  id TEXT NOT NULL PRIMARY KEY,"
        "  title TEXT NOT NULL,"
        "  description TEXT DEFAULT NULL,"
        "  type TEXT NOT NULL DEFAULT 'simple',"
        "  start_at TEXT DEFAULT NULL,"
        "  end_at TEXT DEFAULT NULL,"
        "  status TEXT NOT NULL DEFAULT 'todo',"
        "  priority INTEGER NOT NULL DEFAULT 0,"
        "  weight REAL NOT NULL DEFAULT 0.0,"
        "  time_slot_id TEXT DEFAULT NULL,"
        "  category TEXT NOT NULL DEFAULT 'general',"
        "  is_public INTEGER NOT NULL DEFAULT 0,"
        "  discord_webhook_id TEXT DEFAULT NULL,"
        "  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),"
        "  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),"
        "  is_deleted INTEGER NOT NULL DEFAULT 0,"
        "  FOREIGN KEY (time_slot_id) REFERENCES time_slots (id) ON DELETE SET NULL,"
        "  FOREIGN KEY (discord_webhook_id) REFERENCES webhooks (id) ON DELETE SET NULL"
        ");"
    );
    if (!ok) {
        qCritical() << "Failed to create schedules table:" << query.lastError().text();
        return false;
    }

    // 4. sync_metadata テーブル
    ok = query.exec(
        "CREATE TABLE IF NOT EXISTS sync_metadata ("
        "  key TEXT NOT NULL PRIMARY KEY,"
        "  value TEXT NOT NULL"
        ");"
    );
    if (!ok) {
        qCritical() << "Failed to create sync_metadata table:" << query.lastError().text();
        return false;
    }

    // 同期メタデータの初期化
    query.exec("INSERT OR IGNORE INTO sync_metadata (key, value) VALUES ('last_synced_at', '1970-01-01 00:00:00');");
    query.exec("INSERT OR IGNORE INTO sync_metadata (key, value) VALUES ('api_url', 'http://localhost:8000/sync.php');");
    query.exec("INSERT OR IGNORE INTO sync_metadata (key, value) VALUES ('api_token', 'tc_f2d6ccf601dd459b515215fd');");
    query.exec("INSERT OR IGNORE INTO sync_metadata (key, value) VALUES ('secret_key', 'test_secret_key_12345');");

    return true;
}

QSqlDatabase DBManager::database() const {
    return m_db;
}

void DBManager::close() {
    if (m_db.isOpen()) {
        m_db.close();
    }
}
