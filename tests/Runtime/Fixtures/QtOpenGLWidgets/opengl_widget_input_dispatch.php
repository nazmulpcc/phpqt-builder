<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QEvent;
use Qt\Core\QPoint;
use Qt\Core\QPointF;
use Qt\Gui\QMouseEvent;
use Qt\Gui\QWheelEvent;
use Qt\OpenGLWidgets\QOpenGLWidget;
use Qt\Widgets\QApplication;

qt_runtime_require_class('Qt\\OpenGLWidgets\\QOpenGLWidget', 'QtOpenGLWidgets is unavailable in this build.');

final class RuntimeInputDispatchWidget extends QOpenGLWidget
{
    public int $presses = 0;
    public int $moves = 0;
    public int $wheels = 0;

    protected function mousePressEvent(QMouseEvent $event): void
    {
        $this->presses++;
        parent::mousePressEvent($event);
    }

    protected function mouseMoveEvent(QMouseEvent $event): void
    {
        $this->moves++;
        parent::mouseMoveEvent($event);
    }

    protected function wheelEvent(QWheelEvent $event): void
    {
        $this->wheels++;
        parent::wheelEvent($event);
    }
}

$app = new QApplication();
$widget = new RuntimeInputDispatchWidget();

$press = new QMouseEvent(
    QEvent::MouseButtonPress,
    new QPointF(20.0, 30.0),
    new QPointF(20.0, 30.0),
    \Qt\MouseButton::LeftButton,
    \Qt\MouseButtons::LeftButton,
    \Qt\KeyboardModifier::NoModifier,
);

$move = new QMouseEvent(
    QEvent::MouseMove,
    new QPointF(45.0, 55.0),
    new QPointF(45.0, 55.0),
    \Qt\MouseButton::NoButton,
    \Qt\MouseButtons::LeftButton,
    \Qt\KeyboardModifier::NoModifier,
);

$wheel = new QWheelEvent(
    new QPointF(50.0, 50.0),
    new QPointF(50.0, 50.0),
    new QPoint(0, 0),
    new QPoint(0, 120),
    \Qt\MouseButtons::NoButton,
    \Qt\KeyboardModifier::NoModifier,
    \Qt\ScrollPhase::NoScrollPhase,
    false,
);

QCoreApplication::sendEvent($widget, $press);
QCoreApplication::sendEvent($widget, $move);
QCoreApplication::sendEvent($widget, $wheel);

qt_runtime_result([
    'presses' => $widget->presses,
    'moves' => $widget->moves,
    'wheels' => $widget->wheels,
]);
