<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Qml\QQmlEngine::class, 'QtQml classes are unavailable in this build.');

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, []);
$sales = new \Qt\Core\QObject();
$sales->objectName = 'sales-1';

$notifyHits = 0;
$connection = $sales->connectPropertyNotify('objectName', function () use (&$notifyHits): void {
    $notifyHits++;
});

$engine = new \Qt\Qml\QQmlEngine();
$context = $engine->rootContext();
$context->setContextProperty('sales', $sales);

$component = new \Qt\Qml\QQmlComponent($engine);
$component->setData(<<<'QML'
import QtQml

QtObject {
    property string seenName: sales.objectName
}
QML, new \Qt\Core\QUrl());

$root = $component->create($context);
if (!is_object($root)) {
    fwrite(STDERR, $component->errorString() . PHP_EOL);
    exit(1);
}

$initialSeenName = $root->property('seenName');
$sales->objectName = 'sales-2';
$updatedSeenName = $root->property('seenName');

qt_runtime_result([
    'initial_seen_name' => $initialSeenName,
    'updated_seen_name' => $updatedSeenName,
    'notify_hits' => $notifyHits,
    'connection_is_object' => is_object($connection),
]);
