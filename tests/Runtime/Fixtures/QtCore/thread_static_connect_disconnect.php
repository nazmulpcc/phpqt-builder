<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_static_connect_phase3_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QMetaObjectConnection::class, 'QtCore connection classes are unavailable in this build.');

foreach (['connect', 'disconnect', 'moveToThread'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$thread = new \Qt\Core\QThread();
$worker = new RuntimeStaticConnectPhase3Worker();

$moveOk = $worker->moveToThread($thread);
$startConnection = \Qt\Core\QObject::connect(
    $thread,
    'started()',
    $worker,
    'doWork()',
    \Qt\ConnectionType::QueuedConnection,
);
$disconnectOk = \Qt\Core\QObject::disconnect($startConnection);

$fallbackConnection = \Qt\Core\QObject::connect(
    $thread,
    'started()',
    $thread,
    'quit()',
    \Qt\ConnectionType::QueuedConnection,
);

$thread->onFinished(static function (): void {
    \Qt\Core\QCoreApplication::quit();
});

$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

$hits = $worker->property('phase3_hits');
$receivedName = $worker->objectName();

qt_runtime_result([
    'move_ok' => $moveOk,
    'disconnect_ok' => $disconnectOk,
    'wait_ok' => $waitOk,
    'fallback_connection_is_object' => $fallbackConnection instanceof \Qt\Core\QMetaObjectConnection,
    'hits_is_null' => $hits === null,
    'received_name' => $receivedName,
]);
