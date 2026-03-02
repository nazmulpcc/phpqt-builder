class QNode
{
public:
    QNode();
    explicit QNode(int value);
    int id() const;

private:
    int m_id;
};

inline QNode::QNode() : m_id(0) {}
inline QNode::QNode(int value) : m_id(value) {}
inline int QNode::id() const { return m_id; }
