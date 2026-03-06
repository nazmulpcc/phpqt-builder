#include <QList>
#include <QPoint>

class QPolygon : public QList<QPoint>
{
public:
    QPolygon();
    int boundingWidth() const;
};
