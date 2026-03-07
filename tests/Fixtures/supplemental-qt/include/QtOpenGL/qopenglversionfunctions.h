#include <QtCore/qobject.h>

class QAbstractOpenGLFunctions
{
public:
    QAbstractOpenGLFunctions();
    virtual ~QAbstractOpenGLFunctions();

    bool initializeOpenGLFunctions();

protected:
    void setOwningContext(const QObject *context);
};
