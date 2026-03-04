<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore smoke classes are unavailable in this build.');

$parent = new \Qt\Core\QObject();
$child = new \Qt\Core\QObject();
$child->setObjectName('Smoke');
$child->setParent($parent);

qt_runtime_result([
    'name' => $child->objectName(),
    'has_parent' => $child->parent() instanceof \Qt\Core\QObject,
    'inherits_qobject' => $child->inherits('QObject'),
]);
