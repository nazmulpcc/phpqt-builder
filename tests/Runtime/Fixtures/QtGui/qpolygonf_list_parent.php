<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Gui\QPolygonF::class, 'QPolygonF is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPointF::class, 'QPointF is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QListOfQPointF::class, 'Synthetic QListOfQPointF is unavailable in this build.');

$polygon = new \Qt\Gui\QPolygonF();
$point = new \Qt\Core\QPointF();
$point->setX(1.5);
$point->setY(2.5);
$polygon->appendItem($point);
$item = $polygon->itemAt(0);

qt_runtime_result([
    'inherits_list' => $polygon instanceof \Qt\Core\QListOfQPointF,
    'count' => $polygon->count(),
    'item_class' => get_class($item),
    'x' => $item->x(),
    'y' => $item->y(),
]);
