<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_move_to_thread_phase2_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QEvent::class, 'QtCore event classes are unavailable in this build.');

foreach (['moveToThread', 'thread', 'objectName', 'property', 'signalsBlocked', 'dynamicPropertyNames', 'inherits'] as $method) {
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
$worker->setObjectName('phase2:before');
$worker->setProperty('data', 'source-native');

$thread->onStarted(static function () use ($worker): void {
    \Qt\Core\QCoreApplication::postEvent($worker, new \Qt\Core\QEvent(1001));
});

$thread->onFinished(static function (): void {
    \Qt\Core\QCoreApplication::quit();
});

$moveOk = $worker->moveToThread($thread);
$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

$threadObject = $worker->thread();
$objectName = $worker->objectName();
$dynamicNames = $worker->dynamicPropertyNames();
$nameParts = explode(':', $objectName);
$workerThreadId = (int) end($nameParts);

qt_runtime_result([
    'move_ok' => $moveOk,
    'wait_ok' => $waitOk,
    'thread_is_qthread' => $threadObject instanceof \Qt\Core\QThread,
    'object_name' => $objectName,
    'property_value' => $worker->property('data'),
    'signals_blocked' => $worker->signalsBlocked(),
    'dynamic_names' => $dynamicNames,
    'has_dynamic_data' => is_array($dynamicNames) && in_array('data', $dynamicNames, true),
    'inherits_qobject' => $worker->inherits('QObject'),
    'worker_thread_id' => $workerThreadId,
    'main_thread_id' => $mainThreadId,
]);
