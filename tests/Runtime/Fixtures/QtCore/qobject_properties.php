<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');

$parent = new \Qt\Core\QObject();
$child = new \Qt\Core\QObject();
$notifyHits = 0;
$connection = $child->connectPropertyNotify('objectName', function () use (&$notifyHits): void {
    $notifyHits++;
});

$child->setParent($parent);
$child->objectName = 'Runtime Child';

qt_runtime_result([
    'name' => $child->objectName(),
    'has_parent' => $child->parent() instanceof \Qt\Core\QObject,
    'inherits_qobject' => $child->inherits('QObject'),
    'notify_hits' => $notifyHits,
    'connection_is_object' => is_object($connection),
]);
