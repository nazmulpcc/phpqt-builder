#pragma once

#include <QtCore/qobject.h>
#include <QtCore/qtmetamacros.h>

class QBorrowedSignalFixture
{
    Q_OBJECT

Q_SIGNALS:
    void peerReady(QObject &peer);
};
