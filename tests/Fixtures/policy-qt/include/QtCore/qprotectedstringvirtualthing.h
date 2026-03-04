#pragma once

class QProtectedStringVirtualThing
{
public:
    QProtectedStringVirtualThing();

protected:
    virtual void setName(const char *name);
};

inline QProtectedStringVirtualThing::QProtectedStringVirtualThing() = default;
inline void QProtectedStringVirtualThing::setName(const char *name)
{
    (void) name;
}
