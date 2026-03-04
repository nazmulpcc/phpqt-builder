class QObject
{
public:
    QObject(QObject *parent = nullptr);
    virtual ~QObject() = default;
};

class QEngineLike : public QObject
{
public:
    QEngineLike(QObject *parent = nullptr);
};

class QComponentLike : public QObject
{
public:
    QComponentLike(QObject *parent = nullptr);
    QComponentLike(QEngineLike *engine, QObject *parent = nullptr);
};
