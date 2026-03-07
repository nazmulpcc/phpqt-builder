#pragma once

#include <QWidget>

class QWidgetEventChild : public QWidget
{
public:
    QWidgetEventChild() = default;
    ~QWidgetEventChild() override = default;

protected:
    virtual void customPaint();
};

inline void QWidgetEventChild::customPaint() {}
