<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QTimerEvent::class, 'QtCore timer event classes are unavailable in this build.');

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$thread = new \Qt\Core\QThread();

foreach (['onStarted', 'onFinished', 'start', 'quit'] as $method) {
    if (!method_exists($thread, $method)) {
        qt_runtime_skip(sprintf('QThread::%s() is unavailable in this build.', $method));
    }
}

final class RuntimeThreadGuard extends \Qt\Core\QObject
{
    public bool $timedOut = false;
    private int $timerId = 0;

    public function __construct()
    {
        parent::__construct();
        $this->timerId = $this->startTimer(750);
    }

    protected function timerEvent(\Qt\Core\QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId) {
            return;
        }

        $this->timedOut = true;
        $this->killTimer($this->timerId);
        \Qt\Core\QCoreApplication::quit();
    }
}

$startedHits = 0;
$finishedHits = 0;

$startedConnection = $thread->onStarted(static function () use (&$startedHits, $thread): void {
    $startedHits++;
    $thread->quit();
});

$finishedConnection = $thread->onFinished(static function () use (&$finishedHits): void {
    $finishedHits++;
    \Qt\Core\QCoreApplication::quit();
});

$guard = new RuntimeThreadGuard();

$thread->start();
\Qt\Core\QCoreApplication::exec();

if (method_exists($thread, 'isRunning') && $thread->isRunning()) {
    $thread->quit();
}

if (method_exists($thread, 'wait')) {
    $thread->wait(2000);
}

qt_runtime_result([
    'started_hits' => $startedHits,
    'finished_hits' => $finishedHits,
    'timed_out' => $guard->timedOut,
    'connections_are_objects' => is_object($startedConnection) && is_object($finishedConnection),
]);
