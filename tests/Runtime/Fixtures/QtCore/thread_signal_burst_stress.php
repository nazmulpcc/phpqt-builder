<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QTimerEvent::class, 'QtCore timer classes are unavailable in this build.');

$threadCount = 220;
$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);

final class BurstStressGuard extends \Qt\Core\QObject
{
    public bool $timedOut = false;
    private int $timerId = 0;

    public function __construct(int $timeoutMs)
    {
        parent::__construct();
        $this->timerId = $this->startTimer($timeoutMs);
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

$guard = new BurstStressGuard(20000);
$startedHits = 0;
$finishedHits = 0;

/** @var array<int, \Qt\Core\QThread> $threads */
$threads = [];
for ($i = 0; $i < $threadCount; $i++) {
    $thread = new \Qt\Core\QThread();

    $thread->onStarted(static function () use (&$startedHits, $thread): void {
        $startedHits++;
        $thread->quit();
    });

    $thread->onFinished(static function () use (&$finishedHits, $threadCount): void {
        $finishedHits++;
        if ($finishedHits >= $threadCount) {
            \Qt\Core\QCoreApplication::quit();
        }
    });

    $threads[] = $thread;
}

$startedAt = microtime(true);
foreach ($threads as $thread) {
    $thread->start();
}

\Qt\Core\QCoreApplication::exec();

$waitAllOk = true;
foreach ($threads as $thread) {
    if (!$thread->wait(3000)) {
        $waitAllOk = false;
    }
}

$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

qt_runtime_result([
    'thread_count' => $threadCount,
    'started_hits' => $startedHits,
    'finished_hits' => $finishedHits,
    'timed_out' => $guard->timedOut,
    'wait_all_ok' => $waitAllOk,
    'elapsed_ms' => $elapsedMs,
]);
