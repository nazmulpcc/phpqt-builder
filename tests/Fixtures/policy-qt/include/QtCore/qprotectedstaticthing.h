#pragma once

class QProtectedStaticThing
{
public:
    static void callDoThing(int value);

protected:
    static void doThing(int value);
};

inline void QProtectedStaticThing::callDoThing(int value)
{
    doThing(value);
}

inline void QProtectedStaticThing::doThing(int value)
{
    (void) value;
}
