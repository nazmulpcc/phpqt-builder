#pragma once

class QProtectedVirtualThing
{
public:
    QProtectedVirtualThing();
    int trigger() const;

protected:
    virtual int value() const;
};

inline QProtectedVirtualThing::QProtectedVirtualThing() = default;
inline int QProtectedVirtualThing::trigger() const { return value(); }
inline int QProtectedVirtualThing::value() const { return 7; }
