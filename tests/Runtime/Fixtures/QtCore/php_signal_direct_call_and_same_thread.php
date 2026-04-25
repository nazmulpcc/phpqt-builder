<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QTimer::class, 'QtCore timer classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\Attributes\Signal::class, 'Qt signal attributes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPhpSignalConnection::class, 'Qt PHP signal connection class is unavailable in this build.');

foreach (['on', 'emit'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

final class RuntimePhpSignalDirectCallWorker extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['string'])]
    protected function dataChanged(): void {}

    public function invokeSignalDirectly(): void
    {
        $this->dataChanged();
    }

    public function kick(): void
    {
        $this->emit('dataChanged', ['alpha']);
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();
$worker = new RuntimePhpSignalDirectCallWorker();

$directCallThrew = false;
$directCallMessage = '';
try {
    $worker->invokeSignalDirectly();
} catch (\Throwable $e) {
    $directCallThrew = true;
    $directCallMessage = $e->getMessage();
}

$hits = 0;
$received = '';
$callbackThreadId = '';
$connection = $worker->on('dataChanged', static function (string $message) use (&$hits, &$received, &$callbackThreadId): void {
    $hits++;
    $received = $message;
    $callbackThreadId = (string) \Qt\Core\QThread::currentThreadId();
    \Qt\Core\QCoreApplication::quit();
});

$timeoutTimer = new \Qt\Core\QTimer();
$timeoutTimer->setSingleShot(true);
$timedOut = false;
$timeoutTimer->onTimeout(static function () use (&$timedOut): void {
    $timedOut = true;
    \Qt\Core\QCoreApplication::quit();
});
$timeoutTimer->start(2000);

$worker->kick();
$hitsBeforeExec = $hits;
\Qt\Core\QCoreApplication::exec();

qt_runtime_result([
    'direct_call_threw' => $directCallThrew,
    'direct_call_message' => $directCallMessage,
    'hits_before_exec' => $hitsBeforeExec,
    'queued_not_inline' => $hitsBeforeExec === 0,
    'hits' => $hits,
    'received' => $received,
    'timed_out' => $timedOut,
    'callback_thread_id' => $callbackThreadId,
    'main_thread_id' => $mainThreadId,
    'callback_on_main_thread' => $callbackThreadId === $mainThreadId,
    'connection_is_object' => $connection instanceof \Qt\Core\QPhpSignalConnection,
]);
