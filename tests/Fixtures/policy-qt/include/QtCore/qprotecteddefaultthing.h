class QProtectedDefaultThing
{
public:
    explicit QProtectedDefaultThing(int value);
    int value() const;

protected:
    QProtectedDefaultThing() = default;
};
