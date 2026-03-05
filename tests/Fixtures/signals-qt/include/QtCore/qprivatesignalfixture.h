#pragma once

#include <QtCore/qtmetamacros.h>

class QString;

class QPrivateSignalFixture
{
    Q_OBJECT

Q_SIGNALS:
    void fileChanged(const QString &path, QPrivateSignal);
    void changed(QPrivateSignal);
};
