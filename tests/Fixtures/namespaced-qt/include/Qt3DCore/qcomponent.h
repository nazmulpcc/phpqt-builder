#pragma once

#include <Qt3DCore/QNode>

namespace Qt3DCore {

class QComponent : public QNode
{
public:
    QComponent();
    void setShared(bool shared);
};

inline QComponent::QComponent() = default;
inline void QComponent::setShared(bool shared)
{
    (void) shared;
}

} // namespace Qt3DCore
