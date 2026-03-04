#pragma once

#include <QtCore/qtmetamacros.h>

class QOverloadedSignalFixture
{
    Q_OBJECT

Q_SIGNALS:
    void valueChanged(int value);
    void valueChanged(bool enabled);
};
