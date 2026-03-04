<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QPoint::class, 'QtCore smoke classes are unavailable in this build.');

$point = new \Qt\Core\QPoint();
$point->setX(3);
$point->setY(4);

qt_runtime_result([
    'x' => $point->x(),
    'y' => $point->y(),
    'is_null' => $point->isNull(),
    'manhattan' => $point->manhattanLength(),
]);
