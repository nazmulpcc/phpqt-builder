#pragma once

#include <QEvent>
#include <QMouseEvent>
#include <QWheelEvent>

class QWidget
{
public:
    QWidget() = default;
    virtual ~QWidget() = default;

protected:
    virtual bool event(QEvent *event);
    virtual void mousePressEvent(QMouseEvent *event);
    virtual void mouseReleaseEvent(QMouseEvent *event);
    virtual void mouseDoubleClickEvent(QMouseEvent *event);
    virtual void mouseMoveEvent(QMouseEvent *event);
    virtual void wheelEvent(QWheelEvent *event);
};

inline bool QWidget::event(QEvent *event)
{
    (void) event;
    return true;
}

inline void QWidget::mousePressEvent(QMouseEvent *event)
{
    (void) event;
}

inline void QWidget::mouseReleaseEvent(QMouseEvent *event)
{
    (void) event;
}

inline void QWidget::mouseDoubleClickEvent(QMouseEvent *event)
{
    (void) event;
}

inline void QWidget::mouseMoveEvent(QMouseEvent *event)
{
    (void) event;
}

inline void QWidget::wheelEvent(QWheelEvent *event)
{
    (void) event;
}
