template <typename T>
class QList
{
public:
    QList() = default;
    int count() const;
    int size() const;
    bool isEmpty() const;
    void clear();
    void append(const T &value);
    const T &at(int index) const;
};
