#pragma once

#include <QtGui/qstandarditemmodel.h>

class QExternalWidget : public QStandardItemModel
{
public:
    QExternalWidget(QObject *parent = nullptr);
};
