#include "network_manager.h"
#include <QEventLoop>
#include <QNetworkRequest>
#include <QNetworkReply>
#include <QTimer>
#include <QUrl>
#include <QDebug>

NetworkManager& NetworkManager::instance() {
    static NetworkManager inst;
    return inst;
}

NetworkManager::NetworkManager() {}

QJsonObject NetworkManager::post(const QString& url, const QJsonObject& jsonPayload, bool& success, QString& errorMsg) {
    QNetworkRequest request((QUrl(url)));
    request.setHeader(QNetworkRequest::ContentTypeHeader, "application/json");

    QJsonDocument doc(jsonPayload);
    QByteArray data = doc.toJson();

    QEventLoop loop;
    QTimer timer;
    timer.setSingleShot(true);

    QNetworkReply* reply = m_manager.post(request, data);

    QObject::connect(reply, &QNetworkReply::finished, &loop, &QEventLoop::quit);
    QObject::connect(&timer, &QTimer::timeout, &loop, &QEventLoop::quit);
    
    // 10秒のタイムアウトを設定
    timer.start(10000);
    loop.exec();

    QJsonObject responseObj;
    if (timer.isActive()) {
        // タイムアウトしていない場合
        timer.stop();
        if (reply->error() == QNetworkReply::NoError) {
            success = true;
            QByteArray responseData = reply->readAll();
            QJsonDocument responseDoc = QJsonDocument::fromJson(responseData);
            if (responseDoc.isObject()) {
                responseObj = responseDoc.object();
            }
        } else {
            success = false;
            errorMsg = reply->errorString();
            qWarning() << "Network POST request failed:" << errorMsg;
        }
    } else {
        // タイムアウトした場合
        success = false;
        errorMsg = "Request timeout";
        reply->abort();
        qWarning() << "Network POST request timed out.";
    }

    reply->deleteLater();
    return responseObj;
}
