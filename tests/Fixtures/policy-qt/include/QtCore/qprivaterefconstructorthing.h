#include "qsizelike.h"

class QPrivateRefConstructorThing
{
public:
    QPrivateRefConstructorThing();
    int value() const;

private:
    QPrivateRefConstructorThing(const QSizeLike &size);

    int m_value = 0;
};

inline QPrivateRefConstructorThing::QPrivateRefConstructorThing() = default;
inline QPrivateRefConstructorThing::QPrivateRefConstructorThing(const QSizeLike &size) : m_value(size.width()) {}
inline int QPrivateRefConstructorThing::value() const { return m_value; }
