#pragma once

#include <QtGui/QTransform>
#include <Qt3DCore/QComponent>

namespace Qt3DCore {

class QTransform : public QComponent
{
public:
    QTransform();
    void setTranslation(float x, float y, float z);
};

inline QTransform::QTransform() = default;
inline void QTransform::setTranslation(float x, float y, float z)
{
    (void) x;
    (void) y;
    (void) z;
}

} // namespace Qt3DCore
