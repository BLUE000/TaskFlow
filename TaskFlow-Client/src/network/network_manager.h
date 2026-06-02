#ifndef NETWORK_MANAGER_H
#define NETWORK_MANAGER_H

#include <QNetworkAccessManager>
#include <QNetworkReply>
#include <QJsonDocument>
#include <QJsonObject>
#include <QString>

class NetworkManager {
public:
    static NetworkManager& instance();
    QJsonObject post(const QString& url, const QJsonObject& jsonPayload, bool& success, QString& errorMsg);

private:
    NetworkManager();
    ~NetworkManager() = default;
    NetworkManager(const NetworkManager&) = delete;
    NetworkManager& operator=(const NetworkManager&) = delete;

    QNetworkAccessManager m_manager;
};

#endif // NETWORK_MANAGER_H
