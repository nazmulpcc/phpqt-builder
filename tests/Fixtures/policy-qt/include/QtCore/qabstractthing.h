#pragma once

class QAbstractThing
{
public:
    QAbstractThing();
    virtual int size() const = 0;
};
