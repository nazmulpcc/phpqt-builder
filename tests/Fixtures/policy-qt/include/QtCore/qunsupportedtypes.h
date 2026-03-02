#include "qcomplexhost.h"

class QUnsupportedTypes
{
public:
    QUnsupportedTypes();

    QComplexHost::Iterator begin() const;
    QComplexHost::ResourceProvider provider() const;
    const float *values() const;
};

inline QUnsupportedTypes::QUnsupportedTypes() {}
inline QComplexHost::Iterator QUnsupportedTypes::begin() const { return QComplexHost::Iterator(); }
inline QComplexHost::ResourceProvider QUnsupportedTypes::provider() const { return nullptr; }
inline const float *QUnsupportedTypes::values() const { return nullptr; }
