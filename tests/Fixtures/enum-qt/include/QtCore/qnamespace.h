#pragma once

#include <QtCore/qglobal.h>

QT_BEGIN_NAMESPACE

namespace Qt {
    enum ConnectionType {
        AutoConnection = 0,
        DirectConnection = 1,
    };

    enum ConnectionFlag {
        UniqueConnection = 0x1,
        SingleShotConnection = 0x2,
    };

    Q_DECLARE_FLAGS(ConnectionTypes, ConnectionFlag)
}

QT_END_NAMESPACE
