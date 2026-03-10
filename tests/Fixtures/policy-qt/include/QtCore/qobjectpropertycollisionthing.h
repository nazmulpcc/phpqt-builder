class QObject
{
public:
    explicit QObject(QObject *parent = nullptr);
    virtual ~QObject();
};

inline QObject::QObject(QObject *parent)
{
    (void) parent;
}

inline QObject::~QObject() = default;

class QObjectPropertyCollisionThing : public QObject
{
public:
    explicit QObjectPropertyCollisionThing(QObject *parent = nullptr);
    int property() const;
    void setProperty(int value);

private:
    int m_value = 0;
};

inline QObjectPropertyCollisionThing::QObjectPropertyCollisionThing(QObject *parent)
    : QObject(parent)
{
}

inline int QObjectPropertyCollisionThing::property() const
{
    return m_value;
}

inline void QObjectPropertyCollisionThing::setProperty(int value)
{
    m_value = value;
}

