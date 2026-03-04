#include <QtCore/qobject.h>

class QTableWidget;

class QTableWidgetItem
{
public:
    QTableWidgetItem();
    QTableWidget *tableWidget() const;
};

class QTableWidget : public QObject
{
public:
    QTableWidget(QObject *parent = nullptr);
    void setItem(int row, int column, QTableWidgetItem *item);
};
