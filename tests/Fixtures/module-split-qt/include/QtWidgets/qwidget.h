#pragma once

#include <QtCore/qobject.h>
#include <QtCore/qtmetamacros.h>

class QWidget : public QObject
{
    Q_OBJECT

public:
    QWidget(QObject *parent = nullptr);
    QObject *peer() const;
    void setPeer(QObject *peer);

Q_SIGNALS:
    void activated(QObject *peer);
};
