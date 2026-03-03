#include <QtCore/qobject.h>

class QStandardItemModel;

class QStandardItem
{
public:
    QStandardItem();
    QStandardItemModel *model() const;
    QStandardItem *parent() const;
};

class QStandardItemModel : public QObject
{
public:
    QStandardItemModel(QObject *parent = nullptr);
    void setItem(int row, int column, QStandardItem *item);
    void setHorizontalHeaderItem(int column, QStandardItem *item);
    void setVerticalHeaderItem(int row, QStandardItem *item);
};
