class QSelfParentThing
{
public:
    explicit QSelfParentThing(QSelfParentThing *parent = nullptr);
    void setName(const char *name);
};
