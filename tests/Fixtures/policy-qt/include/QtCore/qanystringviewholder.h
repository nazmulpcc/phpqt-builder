class QByteArray
{
public:
    const char *constData() const;
    int size() const;
};

class QString
{
public:
    QByteArray toUtf8() const;
};

class QAnyStringView
{
public:
    QString toString() const;
};

class QAnyStringViewHolder
{
public:
    QAnyStringView view() const;
};
