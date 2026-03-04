#define QT_DECLARE_RO5_SMF_AS_DEFAULTED(Class) \
    Class() = default; \
    Class(const Class &) = default; \
    Class(Class &&) = default; \
    Class &operator=(const Class &) = default; \
    Class &operator=(Class &&) = default; \
    ~Class() = default;

class QProtectedRo5Thing
{
protected:
    QT_DECLARE_RO5_SMF_AS_DEFAULTED(QProtectedRo5Thing)

public:
    static int version();
};
