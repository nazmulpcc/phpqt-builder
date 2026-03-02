#pragma once

#include <QtCore/qtmetamacros.h>

class QSignalFixture
{
    Q_OBJECT

public:
    void plainMethod();

public Q_SLOTS:
    void setValue(int value);

protected Q_SLOTS:
    void resetValue();

Q_SIGNALS:
    void triggered();
    void valueChanged(int value);
};

inline void QSignalFixture::plainMethod() {}
inline void QSignalFixture::setValue(int value) { (void) value; }
inline void QSignalFixture::resetValue() {}
