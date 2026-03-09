#include <QtCore/qobject.h>
#include "qentity.h"

template <typename T>
class QSharedPointer
{
public:
    QSharedPointer(T *ptr = nullptr);
};

typedef QSharedPointer<QEntity> QEntityPtr;

class QAspectEngine : public QObject
{
public:
    QAspectEngine(QObject *parent = nullptr);
    void setRootEntity(QEntityPtr root);
};
