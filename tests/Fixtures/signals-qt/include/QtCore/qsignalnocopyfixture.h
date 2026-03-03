#pragma once

#include <QtCore/qtmetamacros.h>

class QSignalNoCopyValue
{
public:
    QSignalNoCopyValue() = default;
    QSignalNoCopyValue(const QSignalNoCopyValue &) = delete;
    QSignalNoCopyValue &operator=(const QSignalNoCopyValue &) = delete;
    ~QSignalNoCopyValue() = default;
};

class QSignalNoCopyFixture
{
    Q_OBJECT

public:
    void plainMethod();

Q_SIGNALS:
    void blocked(QSignalNoCopyValue &value);
};

inline void QSignalNoCopyFixture::plainMethod() {}
