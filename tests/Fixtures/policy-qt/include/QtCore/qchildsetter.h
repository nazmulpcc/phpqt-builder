#pragma once

#include "qparentsetter.h"

class QChildSetter : public QParentSetter
{
public:
    QChildSetter();
    void setPeer(QChildSetter *peer = nullptr);
    QChildSetter *peer() const;
    int childId() const;
};
