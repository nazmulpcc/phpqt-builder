<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_move_to_thread_phase2_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QEvent::class, 'QtCore event classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QMetaObjectConnection::class, 'QtCore connection classes are unavailable in this build.');

foreach (['moveToThread', 'connectPropertyNotify', 'onObjectNameChanged'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

if (!method_exists(\Qt\Core\QCoreApplication::class, 'postEvent')) {
    qt_runtime_skip(\Qt\Core\QCoreApplication::class . '::postEvent() is unavailable in this build.');
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = \Qt\Core\QThread::currentThreadId();
$thread = new \Qt\Core\QThread();
$worker = new RuntimeMoveToThreadPhase2Worker();

$moveOk = $worker->moveToThread($thread);

$notifyHits = 0;
$notifyThreadId = 0;
$signalHits = 0;
$signalThreadId = 0;

$notifyConnection = $worker->connectPropertyNotify('objectName', static function () use (&$notifyHits, &$notifyThreadId): void {
    $notifyHits++;
    $notifyThreadId = \Qt\Core\QThread::currentThreadId();
});

$signalConnection = $worker->onObjectNameChanged(static function (string $name) use (&$signalHits, &$signalThreadId): void {
    $signalHits++;
    $signalThreadId = \Qt\Core\QThread::currentThreadId();
});

$thread->onStarted(static function () use ($worker): void {
    \Qt\Core\QCoreApplication::postEvent($worker, new \Qt\Core\QEvent(1001));
});

$thread->onFinished(static function (): void {
    \Qt\Core\QCoreApplication::quit();
});

$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

qt_runtime_result([
    'move_ok' => $moveOk,
    'wait_ok' => $waitOk,
    'notify_hits' => $notifyHits,
    'notify_thread_id' => $notifyThreadId,
    'signal_hits' => $signalHits,
    'signal_thread_id' => $signalThreadId,
    'main_thread_id' => $mainThreadId,
    'notify_on_main_thread' => $notifyThreadId === $mainThreadId,
    'signal_on_main_thread' => $signalThreadId === $mainThreadId,
    'connections_are_objects' => $notifyConnection instanceof \Qt\Core\QMetaObjectConnection
        && $signalConnection instanceof \Qt\Core\QMetaObjectConnection,
]);
