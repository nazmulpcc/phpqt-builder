#include "qsizelike.h"

class QRefConstructorThing
{
public:
    QRefConstructorThing() = default;
    explicit QRefConstructorThing(QSizeLike &size);

    int value() const;

private:
    int m_value = 0;
};

inline QRefConstructorThing::QRefConstructorThing(QSizeLike &size) : m_value(size.width()) {}
inline int QRefConstructorThing::value() const { return m_value; }
