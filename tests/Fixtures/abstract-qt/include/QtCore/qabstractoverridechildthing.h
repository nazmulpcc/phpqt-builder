#pragma once

class QBaseConcreteThing
{
protected:
    virtual void act();
};

class QAbstractOverrideThing : public QBaseConcreteThing
{
protected:
    virtual void act() = 0;
};
