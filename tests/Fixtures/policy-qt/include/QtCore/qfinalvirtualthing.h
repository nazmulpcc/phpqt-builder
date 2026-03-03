#pragma once

class QFinalVirtualBase
{
public:
    virtual int value() const;
};

class QFinalVirtualThing : public QFinalVirtualBase
{
public:
    int value() const final;
};
