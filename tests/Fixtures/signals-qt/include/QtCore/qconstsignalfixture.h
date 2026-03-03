#pragma once

#include <QtCore/qtmetamacros.h>

class QSignalConstFixture
{
    Q_OBJECT

public:
    void plainMethod();

Q_SIGNALS:
    void changed(int value) const;
};

inline void QSignalConstFixture::plainMethod() {}
