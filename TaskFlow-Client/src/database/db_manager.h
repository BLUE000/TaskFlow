#ifndef DB_MANAGER_H
#define DB_MANAGER_H

#include <QSqlDatabase>
#include <QString>

class DBManager {
public:
    static DBManager& instance();
    bool initialize(const QString& dbPath = "taskflow.db");
    QSqlDatabase database() const;
    void close();

private:
    DBManager() = default;
    ~DBManager() = default;
    DBManager(const DBManager&) = delete;
    DBManager& operator=(const DBManager&) = delete;

    QSqlDatabase m_db;
};

#endif // DB_MANAGER_H
