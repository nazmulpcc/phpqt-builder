<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_static_connect_phase3_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QMetaObjectConnection::class, 'QtCore connection classes are unavailable in this build.');

foreach (['connect', 'disconnect', 'moveToThread', 'onObjectNameChanged'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();
$thread = new \Qt\Core\QThread();
$worker = new RuntimeStaticConnectPhase3Worker();
$worker->label = 'phase3';

$moveOk = $worker->moveToThread($thread);

$signalHits = 0;
$callbackThreadId = '';
$receivedName = null;
$worker->onObjectNameChanged(static function (string $name) use (&$signalHits, &$callbackThreadId, &$receivedName): void {
    $signalHits++;
    $callbackThreadId = (string) \Qt\Core\QThread::currentThreadId();
    $receivedName = $name;
});

$startedConnection = \Qt\Core\QObject::connect(
    $thread,
    'started()',
    $worker,
    'doWork()',
    \Qt\ConnectionType::QueuedConnection,
);

$quitConnection = \Qt\Core\QObject::connect(
    $worker,
    'objectNameChanged(QString)',
    $thread,
    'quit()',
    \Qt\ConnectionType::QueuedConnection,
);

$finishedHits = 0;
$thread->onFinished(static function () use (&$finishedHits): void {
    $finishedHits++;
    \Qt\Core\QCoreApplication::quit();
});

$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

$workerThreadId = (string) $worker->property('phase3_worker_thread');
$senderClass = (string) $worker->property('phase3_sender_class');
$senderSignal = (int) $worker->property('phase3_sender_signal');
$hits = (int) $worker->property('phase3_hits');

qt_runtime_result([
    'move_ok' => $moveOk,
    'wait_ok' => $waitOk,
    'signal_hits' => $signalHits,
    'finished_hits' => $finishedHits,
    'received_name' => $receivedName,
    'callback_thread_id' => $callbackThreadId,
    'main_thread_id' => $mainThreadId,
    'worker_thread_id' => $workerThreadId,
    'callback_on_main_thread' => $callbackThreadId === $mainThreadId,
    'worker_thread_differs' => $workerThreadId !== '' && $workerThreadId !== $mainThreadId,
    'sender_class' => $senderClass,
    'sender_signal' => $senderSignal,
    'hits' => $hits,
    'connections_are_objects' => $startedConnection instanceof \Qt\Core\QMetaObjectConnection
        && $quitConnection instanceof \Qt\Core\QMetaObjectConnection,
]);
