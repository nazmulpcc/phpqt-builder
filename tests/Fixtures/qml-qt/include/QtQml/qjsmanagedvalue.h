#include "qjsengine.h"

class QJSManagedValue
{
public:
    QJSManagedValue() = default;
    QJSManagedValue(int value, QJSEngine *engine);
    QJSManagedValue(const QJSManagedValue &) = delete;
    QJSManagedValue(QJSManagedValue &&) = default;
    QJSManagedValue &operator=(QJSManagedValue &&) = default;

    QJSEngine *engine() const;
    int toJSValue() const;
    QJSManagedValue prototype() const;
};
