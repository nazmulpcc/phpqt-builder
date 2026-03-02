#define Q_DISABLE_COPY(Class) \
    Class(const Class &) = delete; \
    Class &operator=(const Class &) = delete;

class QNoCopyThing
{
public:
    Q_DISABLE_COPY(QNoCopyThing)

    int value() const;
};
