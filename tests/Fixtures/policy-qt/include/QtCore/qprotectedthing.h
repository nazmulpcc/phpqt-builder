#pragma once

class QProtectedThing
{
public:
    QProtectedThing();
    int value() const;

protected:
    void tweak();
};
