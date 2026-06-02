#include "conflict_dialog.h"
#include "ui_conflict_dialog.h"

ConflictDialog::ConflictDialog(const Schedule& local, const Schedule& remote, QWidget *parent)
    : QDialog(parent), ui(new Ui::ConflictDialog), m_local(local), m_remote(remote) {
    ui->setupUi(this);

    // ローカルデータの表示
    ui->lblLocalTitle->setText(local.title);
    ui->lblLocalDesc->setText(local.description.isEmpty() ? "(No description)" : local.description);
    ui->lblLocalPeriod->setText(QString("%1 ~ %2").arg(local.start_at.toString("yyyy-MM-dd HH:mm"), local.end_at.toString("yyyy-MM-dd HH:mm")));
    ui->lblLocalWeight->setText(QString::number(local.weight));
    ui->lblLocalUpdated->setText(local.updated_at.toString("yyyy-MM-dd HH:mm:ss"));

    // リモートデータの表示
    ui->lblRemoteTitle->setText(remote.title);
    ui->lblRemoteDesc->setText(remote.description.isEmpty() ? "(No description)" : remote.description);
    ui->lblRemotePeriod->setText(QString("%1 ~ %2").arg(remote.start_at.toString("yyyy-MM-dd HH:mm"), remote.end_at.toString("yyyy-MM-dd HH:mm")));
    ui->lblRemoteWeight->setText(QString::number(remote.weight));
    ui->lblRemoteUpdated->setText(remote.updated_at.toString("yyyy-MM-dd HH:mm:ss"));

    // マージ入力エリアのデフォルト値設定 (新しい方のデータを読み込む)
    Schedule defaultSched = (local.updated_at > remote.updated_at) ? local : remote;
    ui->txtMergeTitle->setText(defaultSched.title);
    ui->txtMergeDesc->setPlainText(defaultSched.description);
    ui->spinMergeWeight->setValue(defaultSched.weight);

    // シグナル・スロットの接続
    connect(ui->btnLocalPriority, &QPushButton::clicked, this, &ConflictDialog::onLocalPriorityClicked);
    connect(ui->btnRemotePriority, &QPushButton::clicked, this, &ConflictDialog::onRemotePriorityClicked);
    connect(ui->btnMergeConfirm, &QPushButton::clicked, this, &ConflictDialog::onMergeConfirmClicked);
}

ConflictDialog::~ConflictDialog() {
    delete ui;
}

Schedule ConflictDialog::getResolvedSchedule() const {
    return m_resolved;
}

void ConflictDialog::onLocalPriorityClicked() {
    m_resolved = m_local;
    accept();
}

void ConflictDialog::onRemotePriorityClicked() {
    m_resolved = m_remote;
    accept();
}

void ConflictDialog::onMergeConfirmClicked() {
    // 共通項目はローカルから引き継ぎ、編集された値を反映
    m_resolved = m_local;
    m_resolved.title = ui->txtMergeTitle->text().trimmed();
    m_resolved.description = ui->txtMergeDesc->toPlainText().trimmed();
    m_resolved.weight = ui->spinMergeWeight->value();
    m_resolved.updated_at = QDateTime::currentDateTime();
    accept();
}
