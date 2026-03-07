#pragma once

namespace Qt3DCore {

class QNode
{
public:
    QNode();
    void setEnabled(bool enabled);
};

inline QNode::QNode() = default;
inline void QNode::setEnabled(bool enabled)
{
    (void) enabled;
}

} // namespace Qt3DCore
