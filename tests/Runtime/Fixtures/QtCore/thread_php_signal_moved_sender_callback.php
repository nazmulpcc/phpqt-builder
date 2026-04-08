<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_php_signal_moved_sender_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\Attributes\Signal::class, 'Qt signal attributes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPhpSignalConnection::class, 'Qt PHP signal connection class is unavailable in this build.');

foreach (['connect', 'moveToThread', 'on', 'emit'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();
$thread = new \Qt\Core\QThread();
$worker = new RuntimePhpSignalMovedSenderWorker();

$moveOk = $worker->moveToThread($thread);

$dataHits = 0;
$finishedHits = 0;
$received = '';
$callbackThreadId = '';
$finishedThreadId = '';

$dataConnection = $worker->on('dataChanged', static function (string $message) use (&$dataHits, &$received, &$callbackThreadId): void {
    $dataHits++;
    $received = $message;
    $callbackThreadId = (string) \Qt\Core\QThread::currentThreadId();
});

$finishedConnection = $worker->on('finished', static function () use ($thread, &$finishedHits, &$finishedThreadId): void {
    $finishedHits++;
    $finishedThreadId = (string) \Qt\Core\QThread::currentThreadId();
    $thread->quit();
});

$startedConnection = \Qt\Core\QObject::connect(
    $thread,
    'started()',
    $worker,
    'doWork()',
    \Qt\ConnectionType::QueuedConnection,
);

$thread->onFinished(static function () use ($app): void {
    \Qt\Core\QCoreApplication::quit();
});

$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

$workerThreadId = (string) $worker->property('worker_thread_id');

qt_runtime_result([
    'move_ok' => $moveOk,
    'wait_ok' => $waitOk,
    'data_hits' => $dataHits,
    'finished_hits' => $finishedHits,
    'received' => $received,
    'callback_thread_id' => $callbackThreadId,
    'finished_thread_id' => $finishedThreadId,
    'main_thread_id' => $mainThreadId,
    'worker_thread_id' => $workerThreadId,
    'callback_on_main_thread' => $callbackThreadId === $mainThreadId,
    'finished_on_main_thread' => $finishedThreadId === $mainThreadId,
    'worker_thread_differs' => $workerThreadId !== '' && $workerThreadId !== $mainThreadId,
    'connections_are_objects' => $dataConnection instanceof \Qt\Core\QPhpSignalConnection
        && $finishedConnection instanceof \Qt\Core\QPhpSignalConnection
        && $startedConnection instanceof \Qt\Core\QMetaObjectConnection,
]);
