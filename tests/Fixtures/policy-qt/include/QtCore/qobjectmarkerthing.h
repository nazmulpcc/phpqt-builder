#pragma once

#include "qmetamacromarkers.h"

class QObject
{
public:
    virtual ~QObject() = default;
};

class QObjectMarkerThing : public QObject
{
    Q_OBJECT

public:
    int ping() const { return 42; }
};
