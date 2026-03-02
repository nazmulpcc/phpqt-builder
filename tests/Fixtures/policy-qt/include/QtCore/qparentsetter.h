#pragma once

#include "qbasesetter.h"

class QParentSetter : public QBaseSetter
{
public:
    QParentSetter();
    int id() const;
};
