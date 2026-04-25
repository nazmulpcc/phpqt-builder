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

final class RuntimeStaticConnectPhase31Logger extends \Qt\Core\QObject
{
    public int $hits = 0;
    public string $message = '';
    public string $threadId = '';
    public string $senderClass = '';
    public int $senderSignal = -1;

    public function logMessage(string $message): void
    {
        $this->hits++;
        $this->message = $message;
        $this->threadId = (string) \Qt\Core\QThread::currentThreadId();

        $sender = $this->sender();
        $this->senderClass = is_object($sender) ? get_class($sender) : '';
        $this->senderSignal = $this->senderSignalIndex();
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();
$thread = new \Qt\Core\QThread();
$worker = new RuntimeStaticConnectPhase3Worker();
$worker->label = 'phase31-main';
$logger = new RuntimeStaticConnectPhase31Logger();

$moveOk = $worker->moveToThread($thread);

$startedConnection = \Qt\Core\QObject::connect(
    $thread,
    'started()',
    $worker,
    'doWork()',
    \Qt\ConnectionType::QueuedConnection,
);

$loggerConnection = \Qt\Core\QObject::connect(
    $worker,
    'objectNameChanged(QString)',
    $logger,
    'logMessage(QString)',
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

qt_runtime_result([
    'move_ok' => $moveOk,
    'wait_ok' => $waitOk,
    'finished_hits' => $finishedHits,
    'main_thread_id' => $mainThreadId,
    'worker_thread_id' => (string) $worker->property('phase3_worker_thread'),
    'logger_hits' => $logger->hits,
    'logger_message' => $logger->message,
    'logger_thread_id' => $logger->threadId,
    'logger_on_main_thread' => $logger->threadId !== '' && $logger->threadId === $mainThreadId,
    'logger_sender_class' => $logger->senderClass,
    'logger_sender_signal' => $logger->senderSignal,
    'connections_are_objects' => $startedConnection instanceof \Qt\Core\QMetaObjectConnection
        && $loggerConnection instanceof \Qt\Core\QMetaObjectConnection
        && $quitConnection instanceof \Qt\Core\QMetaObjectConnection,
]);
