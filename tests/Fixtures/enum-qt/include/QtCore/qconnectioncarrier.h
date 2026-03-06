#pragma once

#include <QtCore/qnamespace.h>

class QConnectionCarrier
{
public:
    enum Mode {
        Idle = 0,
        Busy = 1,
    };

    Q_DECLARE_FLAGS(Modes, Mode)

    QConnectionCarrier();

    void setConnectionType(Qt::ConnectionType type);
    Qt::ConnectionType connectionType() const;

    void setConnectionFlags(Qt::ConnectionTypes flags);
    Qt::ConnectionTypes connectionFlags() const;

    void setMode(Mode mode);
    Mode mode() const;

    void setModes(Modes modes);
    Modes modes() const;
};

Q_DECLARE_OPERATORS_FOR_FLAGS(QConnectionCarrier::Modes)
