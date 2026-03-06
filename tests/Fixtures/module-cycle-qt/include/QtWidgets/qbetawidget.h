#pragma once

#include <QtCore/qobject.h>

class QAlphaModel;

class QBetaWidget : public QObject
{
public:
    QBetaWidget(QObject *parent = nullptr);
    QAlphaModel *peer() const;
};
