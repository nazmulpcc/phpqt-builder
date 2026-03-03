#include "qjsmanagedvalue.h"
#include "qjsprimitivevalue.h"

class QJSValue
{
public:
    QJSValue() = default;
    QJSValue(const QJSValue &) = default;

    explicit QJSValue(QJSPrimitiveValue &&value);
    explicit QJSValue(QJSManagedValue &&value);

    QJSManagedValue managed() const;
};
