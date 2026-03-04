template <typename T>
class QList {};

class QTextOption
{
public:
    struct Tab {};
};

class QTextLayout
{
public:
    struct FormatRange {};
};

class QUnsupportedContainerElements
{
public:
    QList<QTextOption::Tab> tabPositions() const;
    void setTabPositions(const QList<QTextOption::Tab> &tabs);
    QList<QTextLayout::FormatRange> textFormats() const;
};
