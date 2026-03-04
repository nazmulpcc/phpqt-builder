#include <QtCore/qobject.h>

class QObjectOwner : public QObject
{
public:
    QObjectOwner(QObject *parent = nullptr);
    void adopt(QObject *child);
};
