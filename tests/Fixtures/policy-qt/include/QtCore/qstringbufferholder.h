class QChar {};

class QStringBufferHolder
{
public:
    const QChar *unicode() const;
    const QChar *constData() const;
    int length() const;
};
