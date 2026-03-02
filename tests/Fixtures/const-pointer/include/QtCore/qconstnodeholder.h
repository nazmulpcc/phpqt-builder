#include "qnode.h"

class QNodeConstHolder
{
public:
    QNodeConstHolder();

    const QNode *node() const;
    void setNode(QNode *node);

private:
    QNode *m_node;
};

inline QNodeConstHolder::QNodeConstHolder() : m_node(nullptr) {}
inline const QNode *QNodeConstHolder::node() const { return m_node; }
inline void QNodeConstHolder::setNode(QNode *node) { m_node = node; }
