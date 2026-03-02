#pragma once

class QAbstractParentThing
{
public:
    virtual int size() const = 0;
};

class QConcreteChildThing : public QAbstractParentThing
{
public:
    QConcreteChildThing();
    int size() const;
    int value() const;
};
