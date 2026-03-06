<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QXmlStreamAttributes::class, 'QXmlStreamAttributes is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QXmlStreamAttribute::class, 'QXmlStreamAttribute is unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QListOfQXmlStreamAttribute::class, 'Synthetic QListOfQXmlStreamAttribute is unavailable in this build.');

$attributes = new \Qt\Core\QXmlStreamAttributes();
$attribute = new \Qt\Core\QXmlStreamAttribute();
$attributes->appendItem($attribute);
$item = $attributes->itemAt(0);

qt_runtime_result([
    'inherits_list' => $attributes instanceof \Qt\Core\QListOfQXmlStreamAttribute,
    'count' => $attributes->count(),
    'item_class' => get_class($item),
]);
