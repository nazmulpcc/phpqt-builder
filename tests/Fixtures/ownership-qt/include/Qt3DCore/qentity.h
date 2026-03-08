#include <QtCore/qobject.h>

class QEntity : public QObject
{
public:
    QEntity(QObject *parent = nullptr);
};
