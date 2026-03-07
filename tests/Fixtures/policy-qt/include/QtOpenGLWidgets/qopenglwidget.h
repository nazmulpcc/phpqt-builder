#pragma once

#include <QWidget>

class QOpenGLWidget : public QWidget
{
public:
    QOpenGLWidget() = default;
    ~QOpenGLWidget() override = default;

protected:
    virtual void initializeGL();
    virtual void resizeGL(int w, int h);
    virtual void paintGL();
};

inline void QOpenGLWidget::initializeGL() {}
inline void QOpenGLWidget::resizeGL(int w, int h)
{
    (void) w;
    (void) h;
}
inline void QOpenGLWidget::paintGL() {}
