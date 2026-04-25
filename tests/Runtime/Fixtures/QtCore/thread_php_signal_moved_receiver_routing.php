<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_php_signal_routing_classes.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\Attributes\Signal::class, 'Qt signal attributes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPhpSignalConnection::class, 'Qt PHP signal connection class is unavailable in this build.');

foreach (['on', 'emit', 'moveToThread'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();
$thread = new \Qt\Core\QThread();
$sender = new RuntimePhpSignalRoutingEmitter();
$receiver = new RuntimePhpSignalRoutingReceiver();

$beforeConnection = $sender->on('dataChanged', $receiver, 'captureBefore');
$moveOk = $receiver->moveToThread($thread);
$afterConnection = $sender->on('dataChanged', $receiver, 'captureAfter');

$thread->onStarted(static function () use ($sender): void {
    $sender->fire('phase4-route');
});

$thread->onFinished(static function (): void {
    \Qt\Core\QCoreApplication::quit();
});

$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

$beforeThreadId = (string) $receiver->property('before_thread_id');
$afterThreadId = (string) $receiver->property('after_thread_id');

qt_runtime_result([
    'move_ok' => $moveOk,
    'wait_ok' => $waitOk,
    'before_hits' => (int) $receiver->property('before_hits'),
    'after_hits' => (int) $receiver->property('after_hits'),
    'before_message' => (string) $receiver->property('before_message'),
    'after_message' => (string) $receiver->property('after_message'),
    'before_thread_id' => $beforeThreadId,
    'after_thread_id' => $afterThreadId,
    'main_thread_id' => $mainThreadId,
    'both_on_worker_thread' => $beforeThreadId !== '' && $beforeThreadId === $afterThreadId && $beforeThreadId !== $mainThreadId,
    'sender_class' => (string) $receiver->property('sender_class'),
    'sender_signal' => (int) $receiver->property('sender_signal'),
    'connections_are_objects' => $beforeConnection instanceof \Qt\Core\QPhpSignalConnection
        && $afterConnection instanceof \Qt\Core\QPhpSignalConnection,
]);
