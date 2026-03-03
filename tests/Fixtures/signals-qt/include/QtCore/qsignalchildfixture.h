#pragma once

#include <QtCore/qsignalbasefixture.h>

class QSignalChildFixture : public QSignalBaseFixture
{
    Q_OBJECT

Q_SIGNALS:
    void changed(int value);
};
