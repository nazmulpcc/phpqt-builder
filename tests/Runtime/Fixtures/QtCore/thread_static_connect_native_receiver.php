<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QMetaObjectConnection::class, 'QtCore connection classes are unavailable in this build.');

foreach (['connect', 'disconnect'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$thread = new \Qt\Core\QThread();

$startedHits = 0;
$finishedHits = 0;
$thread->onStarted(static function () use (&$startedHits): void {
    $startedHits++;
});
$thread->onFinished(static function () use (&$finishedHits): void {
    $finishedHits++;
    \Qt\Core\QCoreApplication::quit();
});

$connection = \Qt\Core\QObject::connect(
    $thread,
    'started()',
    $thread,
    'quit()',
    \Qt\ConnectionType::QueuedConnection,
);

$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

qt_runtime_result([
    'wait_ok' => $waitOk,
    'started_hits' => $startedHits,
    'finished_hits' => $finishedHits,
    'connection_is_object' => $connection instanceof \Qt\Core\QMetaObjectConnection,
]);
