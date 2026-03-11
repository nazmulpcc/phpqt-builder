#pragma once

#include <QtCore/qobject.h>
#include <QtCore/qtmetamacros.h>

class QSignalPeer : public QObject
{
    Q_OBJECT

public:
    QSignalPeer() = default;
    QSignalPeer(const QSignalPeer &) = delete;
    QSignalPeer &operator=(const QSignalPeer &) = delete;
    ~QSignalPeer() = default;
};
