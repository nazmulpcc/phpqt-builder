<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QTimerEvent::class, 'QtCore timer classes are unavailable in this build.');

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);

final class VirtualDispatchThreadProbe extends \Qt\Core\QThread
{
    public int $hits = 0;

    public function run(): void
    {
        $this->hits++;
    }
}

final class VirtualDispatchGuard extends \Qt\Core\QObject
{
    public bool $timedOut = false;
    private int $timerId = 0;

    public function __construct()
    {
        parent::__construct();
        $this->timerId = $this->startTimer(1500);
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

$guard = new VirtualDispatchGuard();
$thread = new VirtualDispatchThreadProbe();
$finishedHits = 0;

$thread->onFinished(static function () use (&$finishedHits): void {
    $finishedHits++;
    \Qt\Core\QCoreApplication::quit();
});

$thread->start();
\Qt\Core\QCoreApplication::exec();
$waitOk = $thread->wait(3000);

qt_runtime_result([
    'wait_ok' => $waitOk,
    'run_hits' => $thread->hits,
    'finished_hits' => $finishedHits,
    'timed_out' => $guard->timedOut,
]);
