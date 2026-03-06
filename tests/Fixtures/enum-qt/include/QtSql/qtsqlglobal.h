#pragma once

#include <QtCore/qglobal.h>

QT_BEGIN_NAMESPACE

namespace QSql {
    enum ParamTypeFlag {
        In = 0x00000001,
        Out = 0x00000002,
        InOut = In | Out,
        Binary = 0x00000004,
    };
    Q_DECLARE_FLAGS(ParamType, ParamTypeFlag)

    enum TableType {
        Tables = 0x01,
        Views = 0x04,
    };
}

QT_END_NAMESPACE
