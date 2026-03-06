#pragma once

#include <QtCore/qobject.h>

class QBetaWidget;

class QAlphaModel : public QObject
{
public:
    QAlphaModel(QObject *parent = nullptr);
    QBetaWidget *peer() const;
};
