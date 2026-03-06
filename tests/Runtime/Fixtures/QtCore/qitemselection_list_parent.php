<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QItemSelection::class, 'QItemSelection is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QItemSelectionRange::class, 'QItemSelectionRange is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QListOfQItemSelectionRange::class, 'Synthetic QListOfQItemSelectionRange is unavailable in this build.');

$index = new \Qt\Core\QModelIndex();
$range = new \Qt\Core\QItemSelectionRange($index, $index);
$selection = new \Qt\Core\QItemSelection($index, $index);
$selection->appendItem($range);
$item = $selection->itemAt(0);

qt_runtime_result([
    'inherits_list' => $selection instanceof \Qt\Core\QListOfQItemSelectionRange,
    'count' => $selection->count(),
    'item_class' => get_class($item),
]);
