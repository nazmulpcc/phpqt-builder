class QMetaObject
{
public:
    class Connection {};
};

class QConnectionHolder
{
public:
    QMetaObject::Connection connect() const;
    int version() const;
};
