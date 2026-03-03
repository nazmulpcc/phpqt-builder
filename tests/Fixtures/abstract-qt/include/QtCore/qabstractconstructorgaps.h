#pragma once

class QAbstractUnsupportedCtorThing
{
public:
    QAbstractUnsupportedCtorThing();
    virtual void mutate(int &value) = 0;
};

class QAbstractCtorParentThing
{
public:
    QAbstractCtorParentThing();
    virtual int size() const = 0;
};

class QAbstractCtorChildThing : public QAbstractCtorParentThing
{
public:
    QAbstractCtorChildThing();
};
