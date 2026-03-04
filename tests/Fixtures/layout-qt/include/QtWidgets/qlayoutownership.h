#include <QtCore/qobject.h>

class QWidget;

class QLayout : public QObject
{
public:
    QLayout(QWidget *parent = nullptr);
    virtual ~QLayout() = default;
};

class QWidget : public QObject
{
public:
    QWidget(QWidget *parent = nullptr);
    void setLayout(QLayout *layout);
};

class QBoxLayout : public QLayout
{
public:
    QBoxLayout(int direction = 0, QWidget *parent = nullptr);
    void addWidget(QWidget *w, int stretch = 0);
    void addLayout(QLayout *layout, int stretch = 0);
};

class QGridLayout : public QLayout
{
public:
    QGridLayout(QWidget *parent = nullptr);
    void addWidget(QWidget *w, int row, int column);
    void addLayout(QLayout *layout, int row, int column);
};
