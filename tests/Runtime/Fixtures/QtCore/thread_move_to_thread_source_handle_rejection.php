<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_move_to_thread_phase2_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

foreach (['moveToThread', 'setObjectName', 'setProperty', 'blockSignals'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$thread = new \Qt\Core\QThread();
$worker = new RuntimeMoveToThreadPhase2Worker();
$worker->setProperty('data', 'source-native');

$moveOk = $worker->moveToThread($thread);

$probe = static function (callable $callback): array {
    try {
        $callback();

        return [
            'threw' => false,
            'message' => null,
        ];
    } catch (\Throwable $e) {
        return [
            'threw' => true,
            'message' => $e->getMessage(),
        ];
    }
};

$propertyRead = $probe(static function () use ($worker): void {
    $worker->state;
});
$propertyWrite = $probe(static function () use ($worker): void {
    $worker->state = 'main-write';
});
$setObjectName = $probe(static function () use ($worker): void {
    $worker->setObjectName('blocked');
});
$setProperty = $probe(static function () use ($worker): void {
    $worker->setProperty('data', 'blocked');
});
$blockSignals = $probe(static function () use ($worker): void {
    $worker->blockSignals(true);
});

qt_runtime_result([
    'move_ok' => $moveOk,
    'property_read' => $propertyRead,
    'property_write' => $propertyWrite,
    'set_object_name' => $setObjectName,
    'set_property' => $setProperty,
    'block_signals' => $blockSignals,
]);
