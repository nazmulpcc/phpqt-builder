<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_move_to_thread_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QEvent::class, 'QtCore event classes are unavailable in this build.');

foreach (['moveToThread', 'onObjectNameChanged', 'postEvent'] as $method) {
    $target = $method === 'postEvent' ? \Qt\Core\QCoreApplication::class : \Qt\Core\QObject::class;
    if (!method_exists($target, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', $target, $method));
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = \Qt\Core\QThread::currentThreadId();
$thread = new \Qt\Core\QThread();
$worker = new RuntimeMoveToThreadWorker();
$worker->prefix = 'phase1';
$worker->payload = [
    'alpha' => 7,
    'label' => 'moved',
];

$receivedName = null;
$callbackThreadId = 0;
$startedHits = 0;
$finishedHits = 0;
$posted = false;

$worker->onObjectNameChanged(static function (string $name) use (&$receivedName, &$callbackThreadId): void {
    $receivedName = $name;
    $callbackThreadId = \Qt\Core\QThread::currentThreadId();
});

$thread->onStarted(static function () use (&$startedHits, &$posted, $worker): void {
    $startedHits++;
    \Qt\Core\QCoreApplication::postEvent($worker, new \Qt\Core\QEvent(1001));
    $posted = true;
});

$thread->onFinished(static function () use (&$finishedHits): void {
    $finishedHits++;
    \Qt\Core\QCoreApplication::quit();
});

$moveOk = $worker->moveToThread($thread);
$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

$workerThreadId = 0;
if (is_string($receivedName)) {
    $parts = explode(':', $receivedName);
    $workerThreadId = (int) end($parts);
}

qt_runtime_result([
    'posted' => $posted,
    'move_ok' => $moveOk,
    'started_hits' => $startedHits,
    'received_name' => $receivedName,
    'callback_thread_id' => $callbackThreadId,
    'main_thread_id' => $mainThreadId,
    'worker_thread_id' => $workerThreadId,
    'callback_on_main_thread' => $callbackThreadId === $mainThreadId,
    'worker_thread_differs' => $workerThreadId !== 0 && $workerThreadId !== $mainThreadId,
    'finished_hits' => $finishedHits,
    'wait_ok' => $waitOk,
]);
