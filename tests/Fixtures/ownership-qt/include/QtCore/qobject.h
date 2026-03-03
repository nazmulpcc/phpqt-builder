class QObject
{
public:
    QObject(QObject *parent = nullptr);
    virtual ~QObject() = default;
    QObject *parent() const;
};
