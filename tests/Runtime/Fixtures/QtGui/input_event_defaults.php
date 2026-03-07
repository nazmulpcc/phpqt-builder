<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Core\QPoint;
use Qt\Core\QPointF;
use Qt\Core\QEvent;
use Qt\Gui\QMouseEvent;
use Qt\Gui\QWheelEvent;
use Qt\Widgets\QApplication;

qt_runtime_require_class('Qt\\Gui\\QMouseEvent', 'QtGui QMouseEvent is unavailable in this build.');
qt_runtime_require_class('Qt\\Gui\\QWheelEvent', 'QtGui QWheelEvent is unavailable in this build.');

$app = new QApplication();

$mouse = new QMouseEvent(
    QEvent::MouseButtonPress,
    new QPointF(20.0, 30.0),
    new QPointF(20.0, 30.0),
    \Qt\MouseButton::LeftButton,
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

qt_runtime_result([
    'mouse_x' => $mouse->x(),
    'mouse_y' => $mouse->y(),
    'wheel_delta_y' => $wheel->angleDelta()->y(),
]);
