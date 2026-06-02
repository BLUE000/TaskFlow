#ifndef CONFLICT_DIALOG_H
#define CONFLICT_DIALOG_H

#include <QDialog>
#include "model/schedule.h"

QT_BEGIN_NAMESPACE
namespace Ui { class ConflictDialog; }
QT_END_NAMESPACE

class ConflictDialog : public QDialog {
    Q_OBJECT
public:
    explicit ConflictDialog(const Schedule& local, const Schedule& remote, QWidget *parent = nullptr);
    ~ConflictDialog();

    Schedule getResolvedSchedule() const;

private slots:
    void onLocalPriorityClicked();
    void onRemotePriorityClicked();
    void onMergeConfirmClicked();

private:
    Ui::ConflictDialog *ui;
    Schedule m_local;
    Schedule m_remote;
    Schedule m_resolved;
};

#endif // CONFLICT_DIALOG_H
