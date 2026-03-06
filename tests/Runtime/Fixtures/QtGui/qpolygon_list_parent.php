<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Gui\QPolygon::class, 'QPolygon is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPoint::class, 'QPoint is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QListOfQPoint::class, 'Synthetic QListOfQPoint is unavailable in this build.');

$polygon = new \Qt\Gui\QPolygon();
$point = new \Qt\Core\QPoint();
$point->setX(7);
$point->setY(9);
$polygon->appendItem($point);
$item = $polygon->itemAt(0);

qt_runtime_result([
    'inherits_list' => $polygon instanceof \Qt\Core\QListOfQPoint,
    'count' => $polygon->count(),
    'item_class' => get_class($item),
    'x' => $item->x(),
    'y' => $item->y(),
]);
