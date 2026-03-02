class QValueReturnHolder
{
public:
    QValueReturnHolder();

    static QValueReturnHolder create();
    QValueReturnHolder normalized() const;
};
