#include <QtCore/qobject.h>

class QRenderSurfaceSelector : public QObject
{
public:
    QRenderSurfaceSelector(QObject *parent = nullptr);
    void setSurface(QObject *surfaceObject);
};
