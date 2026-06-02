#include <QApplication>
#include "ui/mainwindow.h"
#include "database/db_manager.h"
#include <QMessageBox>

int main(int argc, char *argv[]) {
    QApplication app(argc, argv);

    // データベースの初期化
    if (!DBManager::instance().initialize("taskflow.db")) {
        QMessageBox::critical(nullptr, "Database Error", "Failed to initialize SQLite local cache database.");
        return 1;
    }

    MainWindow w;
    w.show();

    int result = app.exec();

    // データベースのクローズ
    DBManager::instance().close();

    return result;
}
