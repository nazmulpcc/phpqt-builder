class QQualifiedTypeHolder
{
public:
    struct Element
    {
        int x;
    };

    enum Kind
    {
        A = 0,
        B = 1,
    };

    QQualifiedTypeHolder::Element elementAt(int i) const;
    QQualifiedTypeHolder::Kind kind() const;
    void setKind(QQualifiedTypeHolder::Kind kind);
};

inline QQualifiedTypeHolder::Element QQualifiedTypeHolder::elementAt(int i) const
{
    return Element{i};
}

inline QQualifiedTypeHolder::Kind QQualifiedTypeHolder::kind() const
{
    return QQualifiedTypeHolder::A;
}

inline void QQualifiedTypeHolder::setKind(QQualifiedTypeHolder::Kind kind)
{
    (void)kind;
}
